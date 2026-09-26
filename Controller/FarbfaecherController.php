<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use KimaiPlugin\FarbfaecherBundle\Color\Oklab;
use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration as Config;
use KimaiPlugin\FarbfaecherBundle\Form\PlanForm;
use KimaiPlugin\FarbfaecherBundle\Model\Clash;
use KimaiPlugin\FarbfaecherBundle\Model\ColorGraph;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;
use KimaiPlugin\FarbfaecherBundle\Model\PlannedChange;
use KimaiPlugin\FarbfaecherBundle\Model\WriteResult;
use KimaiPlugin\FarbfaecherBundle\Service\ColorEngine;
use KimaiPlugin\FarbfaecherBundle\Service\ColorPlanner;
use KimaiPlugin\FarbfaecherBundle\Service\ColorWriter;
use KimaiPlugin\FarbfaecherBundle\Service\GraphBuilder;
use KimaiPlugin\FarbfaecherBundle\Service\SessionStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Overview ──► plan (preview, stored in session) ──► apply ──► undo (15 min) / restore from history
 */
#[Route(path: '/admin/farbfaecher')]
#[IsGranted('farbfaecher')]
final class FarbfaecherController extends AbstractController
{
    public const HELP_URL = 'https://github.com/shrippen/kimai-farbfaecher-bundle#readme';
    public const CSRF = 'farbfaecher';

    private const MAX_CLASHES = 150;
    private const MAX_REMAINING = 30;
    private const LEVELS = ['critical', 'warning', 'info'];
    /** below this chroma a color is drawn as gray on the left edge of the color map */
    private const GRAY_CHROMA = 0.03;

    public function __construct(
        private readonly GraphBuilder $graphBuilder,
        private readonly ColorEngine $engine,
        private readonly ColorPlanner $planner,
        private readonly ColorWriter $writer,
        private readonly SessionStore $sessionStore,
        private readonly Config $configuration,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route(path: '', name: 'farbfaecher', methods: ['GET'])]
    public function index(): Response
    {
        $graph = $this->graphBuilder->build($this->configuration->getWindowDays());
        $clashes = $this->engine->analyze($graph);

        $form = $this->createFormForGetRequest(PlanForm::class, $this->planDefaults(), [
            'action' => $this->generateUrl('farbfaecher_plan'),
        ]);

        return $this->render('@Farbfaecher/index.html.twig', [
            'page_setup' => $this->page('farbfaecher.title', 'farbfaecher'),
            'config' => $this->configuration,
            'themed' => $this->engine->getStyle()->isThemed(),
            'palette' => array_map(fn ($c) => $c->hex, $this->engine->getStyle()->colors()),
            'graph' => $graph,
            'form' => $form->createView(),
            'clashes' => \array_slice($clashes, 0, self::MAX_CLASHES),
            'clash_count' => \count($clashes),
            'levels' => $this->countLevels($clashes),
            'stats' => $this->stats($graph),
            'tree' => $this->tree($graph),
            'scatter' => $this->scatter($graph),
            'backups' => $this->writer->listBackups(),
        ]);
    }

    #[Route(path: '/plan', name: 'farbfaecher_plan', methods: ['GET'])]
    public function plan(Request $request): Response
    {
        $form = $this->createFormForGetRequest(PlanForm::class, $this->planDefaults());
        $form->submit($request->query->all(), false);
        if (!$form->isValid()) {
            $this->addFlash('error', (string) $form->getErrors(true));

            return $this->redirectToRoute('farbfaecher');
        }

        /** @var array{scope: string, strategy: string} $values */
        $values = $form->getData();
        $graph = $this->graphBuilder->build($this->configuration->getWindowDays());
        $plan = $this->planner->plan($graph, $values['scope'], $values['strategy']);

        // the apply request writes exactly what is shown here, not a recomputed plan
        $colors = [];
        foreach ($plan['changes'] as $change) {
            $colors[$change->node->getKey()] = $change->newColor;
        }
        $scopeLabel = $this->translator->trans('farbfaecher.scope.' . $values['scope']);
        $strategyLabel = $this->translator->trans('farbfaecher.strategy.' . $values['strategy']);
        $planId = $this->sessionStore->savePlan($colors, $scopeLabel . ' · ' . $strategyLabel);

        return $this->render('@Farbfaecher/plan.html.twig', [
            'page_setup' => $this->page('farbfaecher.title_plan', 'farbfaecher_plan'),
            'scope_label' => $scopeLabel,
            'strategy_label' => $strategyLabel,
            'themed' => $this->engine->getStyle()->isThemed(),
            'plan_id' => $planId,
            'groups' => $this->groupChanges($plan['changes']),
            'change_count' => \count($plan['changes']),
            'before' => $this->countLevels($plan['before']),
            'after' => $this->countLevels($plan['after']),
            'remaining' => \array_slice($plan['after'], 0, self::MAX_REMAINING),
        ]);
    }

    /**
     * Bulk action of the plan page (kit.bulk_bar, ajax): ids[] = selected node keys.
     */
    #[Route(path: '/apply/{plan}', name: 'farbfaecher_apply', requirements: ['plan' => '[0-9a-f]{12}'], methods: ['POST'])]
    public function apply(string $plan, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->fail($request, $this->translator->trans('action.csrf.error', [], 'flashmessages'));
        }

        $stored = $this->sessionStore->getPlan($plan);
        if ($stored === null) {
            return $this->fail($request, $this->translator->trans('farbfaecher.error.plan_expired'));
        }

        $selected = array_flip(array_map('strval', $request->request->all('ids')));
        $colors = array_intersect_key($stored['colors'], $selected);
        $result = $this->writer->apply($colors, $stored['label'], $this->getUser()->getUserIdentifier());
        $this->sessionStore->forgetPlan($plan);

        return $this->done($request, $this->translator->trans('farbfaecher.result.applied', ['%count%' => $result->count]), $result);
    }

    /**
     * Undo of the toast: only the own action, same session, within 15 minutes.
     */
    #[Route(path: '/undo/{backup}', name: 'farbfaecher_undo', methods: ['POST'])]
    public function undo(string $backup, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->fail($request, $this->translator->trans('action.csrf.error', [], 'flashmessages'));
        }
        if (!$this->sessionStore->canUndo($backup, (int) $this->getUser()->getId())) {
            return $this->fail($request, $this->translator->trans('farbfaecher.error.undo_expired'));
        }

        $label = $this->translator->trans('farbfaecher.history.undone');
        $result = $this->writer->restore($backup, $label, $this->getUser()->getUserIdentifier());
        $this->sessionStore->forgetUndo($backup);

        return $this->done($request, $this->restoredMessage($result), null);
    }

    /**
     * "…" menu of the history: restores an older state, itself undoable.
     */
    #[Route(path: '/restore/{backup}', name: 'farbfaecher_restore', methods: ['POST'])]
    public function restore(string $backup, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->fail($request, $this->translator->trans('action.csrf.error', [], 'flashmessages'));
        }
        if (!$this->writer->hasBackup($backup)) {
            throw $this->createNotFoundException();
        }

        $label = $this->translator->trans('farbfaecher.history.restored');
        $result = $this->writer->restore($backup, $label, $this->getUser()->getUserIdentifier());

        return $this->done($request, $this->restoredMessage($result), $result);
    }

    /**
     * Used by the color field in the customer/project/activity forms.
     */
    #[Route(path: '/suggest', name: 'farbfaecher_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $type = (string) $request->query->get('type');
        if (!\in_array($type, ColorNode::TYPES, true)) {
            return new JsonResponse(['message' => 'invalid type'], Response::HTTP_BAD_REQUEST);
        }

        $graph = $this->graphBuilder->build($this->configuration->getWindowDays());
        $node = $this->nodeFromRequest($graph, $type, $request);

        $result = $this->engine->suggest($graph, $node, (string) $request->query->get('color', ''));
        $result['threshold'] = $this->configuration->getThreshold();

        return new JsonResponse($result);
    }

    /**
     * The edited entity, or a virtual one while creating. "parent" is the customer/project currently chosen in the form.
     */
    private function nodeFromRequest(ColorGraph $graph, string $type, Request $request): ColorNode
    {
        $id = $request->query->get('id');
        $key = ColorNode::makeKey($type, is_numeric($id) ? (int) $id : null);

        $parent = $request->query->get('parent');
        $parentType = $type === ColorNode::ACTIVITY ? ColorNode::PROJECT : ColorNode::CUSTOMER;
        $parentKey = is_numeric($parent) && $type !== ColorNode::CUSTOMER ? ColorNode::makeKey($parentType, (int) $parent) : null;

        if (!$graph->has($key)) {
            return new ColorNode($type, null, (string) $request->query->get('name', ''), null, GraphBuilder::NO_COLOR, $parentKey, true, false);
        }

        $node = $graph->get($key);
        if ($type !== ColorNode::CUSTOMER && $request->query->has('parent')) {
            $node = $node->withParent($parentKey);
        }

        return $node;
    }

    /**
     * @return array{scope: string, strategy: string}
     */
    private function planDefaults(): array
    {
        return ['scope' => ColorPlanner::SCOPE_CLASHES, 'strategy' => $this->configuration->getStrategy()];
    }

    private function page(string $title, string $actionName): PageSetup
    {
        $page = new PageSetup($this->translator->trans($title));
        $page->setActionName($actionName);
        $page->setHelp(self::HELP_URL);

        return $page;
    }

    /**
     * Kit contract: JSON {message, undo?} for kit.js, otherwise flash + redirect (without JS).
     */
    private function done(Request $request, string $message, ?WriteResult $result): Response
    {
        $undo = null;
        if ($result !== null && $result->backupId !== null) {
            $this->sessionStore->allowUndo($result->backupId, (int) $this->getUser()->getId());
            $undo = [
                'url' => $this->generateUrl('farbfaecher_undo', ['backup' => $result->backupId]),
                'token' => $this->csrfTokenManager->getToken(self::CSRF)->getValue(),
            ];
        }

        if ($this->wantsJson($request)) {
            return new JsonResponse(['message' => $message, 'undo' => $undo]);
        }

        $this->addFlash('kpu_result', $message);

        return $this->redirectToRoute('farbfaecher');
    }

    private function fail(Request $request, string $message): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['message' => $message], Response::HTTP_BAD_REQUEST);
        }

        $this->addFlash('error', $message);

        return $this->redirectToRoute('farbfaecher');
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private function restoredMessage(WriteResult $result): string
    {
        $message = $this->translator->trans('farbfaecher.result.restored', ['%count%' => $result->count]);
        if ($result->skipped === 0) {
            return $message;
        }

        return $message . ' ' . $this->translator->trans('farbfaecher.result.skipped', ['%count%' => $result->skipped]);
    }

    /**
     * @param list<Clash> $clashes
     * @return array<string, int>
     */
    private function countLevels(array $clashes): array
    {
        $levels = array_fill_keys(self::LEVELS, 0);
        foreach ($clashes as $clash) {
            $levels[$clash->getLevel()]++;
        }

        return $levels;
    }

    /**
     * @param list<PlannedChange> $changes
     * @return array<string, list<PlannedChange>> by entity type, in hierarchy order
     */
    private function groupChanges(array $changes): array
    {
        $groups = array_fill_keys(ColorNode::TYPES, []);
        foreach ($changes as $change) {
            $groups[$change->node->type][] = $change;
        }

        return array_filter($groups);
    }

    /**
     * @return array<string, array{total: int, own: int, inherited: int, generated: int, locked: int}>
     */
    private function stats(ColorGraph $graph): array
    {
        $stats = [];
        foreach (ColorNode::TYPES as $type) {
            $row = ['total' => 0, 'own' => 0, 'inherited' => 0, 'generated' => 0, 'locked' => 0];
            foreach ($graph->byType($type, $this->configuration->isIncludeHidden()) as $key => $node) {
                $row['total']++;
                $row[$graph->colorSource($key)]++;
                if ($node->locked) {
                    $row['locked']++;
                }
            }
            $stats[$type] = $row;
        }

        return $stats;
    }

    /**
     * Customers with their projects and activities, plus global activities.
     *
     * @return array{customers: list<array<string, mixed>>, global: list<array<string, mixed>>}
     */
    private function tree(ColorGraph $graph): array
    {
        $includeHidden = $this->configuration->isIncludeHidden();
        $entry = fn (ColorNode $n) => [
            'node' => $n,
            'color' => $graph->effectiveColor($n->getKey()),
            'source' => $graph->colorSource($n->getKey()),
        ];
        $shown = fn (ColorNode $n) => $includeHidden || $n->visible;
        $byName = fn (ColorNode $a, ColorNode $b) => strcasecmp($a->name, $b->name);

        $customers = [];
        $nodes = array_values($graph->byType(ColorNode::CUSTOMER, $includeHidden));
        usort($nodes, $byName);
        foreach ($nodes as $customer) {
            $projects = [];
            $children = array_values(array_filter($graph->children($customer->getKey()), $shown));
            usort($children, $byName);
            foreach ($children as $project) {
                $activities = array_values(array_filter($graph->children($project->getKey()), $shown));
                usort($activities, $byName);
                $projects[] = $entry($project) + ['activities' => array_map($entry, $activities)];
            }
            $customers[] = $entry($customer) + ['projects' => $projects];
        }

        $global = array_values(array_filter($graph->byType(ColorNode::ACTIVITY, $includeHidden), fn (ColorNode $n) => $n->isGlobalActivity()));
        usort($global, $byName);

        return ['customers' => $customers, 'global' => array_map($entry, $global)];
    }

    /**
     * Position of every color on a hue (x) / lightness (y) plane in percent, per type.
     *
     * @return array<string, list<array{hex: string, name: string, x: float, y: float, own: bool}>>
     */
    private function scatter(ColorGraph $graph): array
    {
        $result = [];
        foreach (ColorNode::TYPES as $type) {
            $points = [];
            foreach ($graph->byType($type, $this->configuration->isIncludeHidden()) as $key => $node) {
                [$l, $c, $h] = Oklab::toLch($graph->effectiveLab($key));
                $points[] = [
                    'hex' => $graph->effectiveColor($key),
                    'name' => $graph->path($key),
                    // grays have no meaningful hue: left edge (2 %, fully visible); colors: 4 … 100 %
                    'x' => $c < self::GRAY_CHROMA ? 2.0 : round(4 + $h / 360 * 96, 2),
                    // lightness 1 … 0 → 5 … 95 %, so marks at the edges stay visible
                    'y' => round(5 + (1 - $l) * 90, 2),
                    'own' => $graph->colorSource($key) === 'own',
                ];
            }
            $result[$type] = $points;
        }

        return $result;
    }
}
