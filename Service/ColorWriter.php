<?php

/*
 * This file is part of the "FarbfaecherBundle" for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\FarbfaecherBundle\Service;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\FarbfaecherBundle\Color\Oklab;
use KimaiPlugin\FarbfaecherBundle\Model\ColorNode;
use KimaiPlugin\FarbfaecherBundle\Model\WriteResult;

/**
 * Writes colors directly to the entities and keeps a JSON backup of every batch, so it can be undone.
 */
final class ColorWriter
{
    private const BACKUP_ID = '/^backup-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/';
    private const ENTITIES = [
        ColorNode::CUSTOMER => Customer::class,
        ColorNode::PROJECT => Project::class,
        ColorNode::ACTIVITY => Activity::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $dataDirectory,
    ) {
    }

    /**
     * @param array<string, string|null> $colors node key ("project:12") => hex color or null to remove the color
     */
    public function apply(array $colors, string $label, string $user): WriteResult
    {
        $backup = [];
        foreach ($colors as $key => $color) {
            $entity = $this->find($key);
            $color = $color === null ? null : Oklab::normalizeHex($color);
            if ($entity === null || $entity->getColor() === $color) {
                continue;
            }

            $backup[] = ['key' => $key, 'name' => $entity->getName(), 'old' => $entity->getColor(), 'new' => $color];
            $entity->setColor($color);
            // Kimai entities use the DEFERRED_EXPLICIT change tracking policy
            $this->entityManager->persist($entity);
        }

        if (\count($backup) === 0) {
            return new WriteResult(0, null);
        }

        $this->entityManager->flush();
        $backupId = $this->writeBackup([
            'created' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'user' => $user,
            'label' => $label,
            'changes' => $backup,
        ]);

        return new WriteResult(\count($backup), $backupId);
    }

    /**
     * @return list<array{id: string, created: string, user: string, label: string, count: int}>
     */
    public function listBackups(): array
    {
        $files = glob($this->getDirectory() . '/backup-*.json') ?: [];
        rsort($files);
        $result = [];
        foreach (\array_slice($files, 0, 20) as $file) {
            $data = $this->readFile($file);
            if ($data === null) {
                continue;
            }
            $result[] = [
                'id' => basename($file, '.json'),
                'created' => (string) ($data['created'] ?? ''),
                'user' => (string) ($data['user'] ?? ''),
                'label' => (string) ($data['label'] ?? ''),
                'count' => \count($data['changes'] ?? []),
            ];
        }

        return $result;
    }

    public function hasBackup(string $backupId): bool
    {
        return $this->readBackup($backupId) !== null;
    }

    /**
     * Restores the colors from before the given batch. The restore itself is backed up as well.
     * Entities whose color was changed again since then are left alone and counted as skipped.
     */
    public function restore(string $backupId, string $label, string $user): WriteResult
    {
        $data = $this->readBackup($backupId);
        if ($data === null) {
            return new WriteResult(0, null);
        }

        $colors = [];
        $skipped = 0;
        foreach ($data['changes'] ?? [] as $change) {
            $key = (string) $change['key'];
            $entity = $this->find($key);
            if ($entity === null || $entity->getColor() !== ($change['new'] ?? null)) {
                $skipped++;
                continue;
            }
            $colors[$key] = $change['old'] ?? null;
        }

        $result = $this->apply($colors, $label, $user);

        return new WriteResult($result->count, $result->backupId, $skipped);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readBackup(string $backupId): ?array
    {
        if (preg_match(self::BACKUP_ID, $backupId) !== 1) {
            return null;
        }

        return $this->readFile($this->getDirectory() . '/' . $backupId . '.json');
    }

    /**
     * @param string $key node key, e.g. "project:12"
     */
    private function find(string $key): Customer|Project|Activity|null
    {
        [$type, $id] = explode(':', $key, 2) + [1 => ''];
        if (!\array_key_exists($type, self::ENTITIES) || !ctype_digit($id)) {
            return null;
        }

        return $this->entityManager->find(self::ENTITIES[$type], (int) $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return string backup id, e.g. "backup-20260926-113346-9a3c35"
     */
    private function writeBackup(array $data): string
    {
        $dir = $this->getDirectory();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create backup directory ' . $dir);
        }

        $id = 'backup-' . (new \DateTimeImmutable())->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
        file_put_contents($dir . '/' . $id . '.json', json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }

    private function getDirectory(): string
    {
        return rtrim($this->dataDirectory, '/') . '/farbfaecher';
    }
}
