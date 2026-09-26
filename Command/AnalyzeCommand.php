<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Command;

use KimaiPlugin\FarbfaecherBundle\Configuration\FarbfaecherConfiguration;
use KimaiPlugin\FarbfaecherBundle\Service\ColorEngine;
use KimaiPlugin\FarbfaecherBundle\Service\ColorPlanner;
use KimaiPlugin\FarbfaecherBundle\Service\GraphBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:farbfaecher:analyze', description: 'Lists color clashes and optionally previews a recoloring (read-only)')]
final class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly GraphBuilder $graphBuilder,
        private readonly ColorEngine $engine,
        private readonly ColorPlanner $planner,
        private readonly FarbfaecherConfiguration $configuration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of clashes to show', '25')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Preview a plan: missing, clashes or all')
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'hierarchical or distinct (default: system setting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $graph = $this->graphBuilder->build($this->configuration->getWindowDays());
        $clashes = $this->engine->analyze($graph);

        $io->title(\sprintf('%d entities, %d clashes below ΔE %s', \count($graph->all()), \count($clashes), $this->configuration->getThreshold()));
        $rows = [];
        foreach (\array_slice($clashes, 0, (int) $input->getOption('limit')) as $clash) {
            $rows[] = [
                $clash->getLevel(),
                $clash->a->type,
                $graph->path($clash->a->getKey()) . ' ' . $clash->colorA,
                $graph->path($clash->b->getKey()) . ' ' . $clash->colorB,
                number_format($clash->distance, 1),
                $clash->cooccurrenceWeeks,
            ];
        }
        $io->table(['level', 'type', 'A', 'B', 'ΔE', 'weeks'], $rows);

        $scope = $input->getOption('plan');
        if (\is_string($scope)) {
            $started = microtime(true);
            $strategy = $input->getOption('strategy');
            $plan = $this->planner->plan($graph, $scope, \is_string($strategy) ? $strategy : null);
            $rows = [];
            foreach ($plan['changes'] as $change) {
                $rows[] = [$change->node->type, $change->path, $change->oldColor . ' (' . $change->oldSource . ')', $change->newColor, $change->reason];
            }
            $levels = function (array $clashes): string {
                $count = ['critical' => 0, 'warning' => 0, 'info' => 0];
                foreach ($clashes as $clash) {
                    $count[$clash->getLevel()]++;
                }

                return \sprintf('%d/%d/%d', $count['critical'], $count['warning'], $count['info']);
            };
            $io->section(\sprintf(
                'Plan "%s": %d changes, clashes (critical/warning/info) %s → %s (%.2fs)',
                $scope,
                \count($plan['changes']),
                $levels($plan['before']),
                $levels($plan['after']),
                microtime(true) - $started
            ));
            $io->table(['type', 'entity', 'old', 'new', 'reason'], $rows);

            $rows = [];
            foreach (\array_slice($plan['after'], 0, (int) $input->getOption('limit')) as $clash) {
                $rows[] = [$clash->getLevel(), $clash->a->type, $clash->a->name . ' ' . $clash->colorA, $clash->b->name . ' ' . $clash->colorB, number_format($clash->distance, 1), $clash->cooccurrenceWeeks];
            }
            $io->section('Remaining clashes');
            $io->table(['level', 'type', 'A', 'B', 'ΔE', 'weeks'], $rows);
        }

        return Command::SUCCESS;
    }
}
