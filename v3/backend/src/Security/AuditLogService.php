<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use JsonException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;

final readonly class AuditLogService
{
    private const MAX_SNAPSHOT_BYTES = 16384;

    public function __construct(private AuditLogRepository $repository) {}

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function record(
        ?int $actorUserId,
        string $action,
        ?string $entityType,
        ?string $entityId,
        ?array $before,
        ?array $after,
        ?string $reason,
        string $requestId,
        string $ipAddress,
        ?int $businessId = null,
        ?int $branchId = null,
    ): void {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $action)) {
            throw new \InvalidArgumentException('Audit action code is invalid.');
        }
        if ($entityType !== null && !preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $entityType)) {
            throw new \InvalidArgumentException('Audit entity type is invalid.');
        }

        if ($businessId !== null && $businessId < 1) {
            throw new \InvalidArgumentException('Audit business scope is invalid.');
        }
        if ($branchId !== null && ($branchId < 1 || $businessId === null)) {
            throw new \InvalidArgumentException('Audit branch scope requires a valid business scope.');
        }

        $beforeJson = $before === null ? null : self::encodeSafeSnapshot($before);
        $afterJson = $after === null ? null : self::encodeSafeSnapshot($after);
        $safeRequestId = preg_match('/^[A-Za-z0-9._-]{8,36}$/', $requestId) ? $requestId : null;
        $safeIp = filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
        $safeReason = null;
        if ($reason !== null) {
            $reasonCount = preg_match_all('/./us', $reason, $reasonCharacters);
            $safeReason = $reasonCount === false
                ? null
                : implode('', array_slice($reasonCharacters[0], 0, 500));
        }

        $this->repository->append(
            $actorUserId,
            $action,
            $entityType,
            $entityId === null ? null : substr($entityId, 0, 100),
            $beforeJson,
            $afterJson,
            $safeReason,
            $safeRequestId,
            $safeIp,
            $businessId,
            $branchId,
        );
    }

    /** @param array<string, mixed> $snapshot */
    private static function encodeSafeSnapshot(array $snapshot): string
    {
        self::assertNoSecrets($snapshot);
        try {
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new \InvalidArgumentException('Audit snapshot cannot be encoded.');
        }
        if (strlen($json) > self::MAX_SNAPSHOT_BYTES) {
            throw new \InvalidArgumentException('Audit snapshot exceeds the allowed size.');
        }
        return $json;
    }

    /** @param array<string, mixed> $value */
    private static function assertNoSecrets(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/password|token|secret|credential|authorization|cookie|api[_-]?key/i', $key)) {
                throw new \InvalidArgumentException('Sensitive fields cannot be written to audit snapshots.');
            }
            if (is_array($item)) {
                self::assertNoSecrets($item);
            }
        }
    }
}
