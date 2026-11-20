<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class AuditLogRepository
{
    public function __construct(private PDO $connection) {}

    public function append(
        ?int $actorUserId,
        string $action,
        ?string $entityType,
        ?string $entityId,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $reason,
        ?string $requestId,
        ?string $ipAddress,
        ?int $businessId = null,
        ?int $branchId = null,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO app_audit_logs
                (actor_user_id, business_id, branch_id, action, entity_type, entity_id, before_json, after_json, reason, request_id, ip_address)
             VALUES
                (:actor_user_id, :business_id, :branch_id, :action, :entity_type, :entity_id, :before_json, :after_json, :reason, :request_id, :ip_address)'
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $beforeJson,
            'after_json' => $afterJson,
            'reason' => $reason,
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listForBusiness(int $businessId, ?int $branchId, ?int $beforeId, int $limit): array
    {
        $sql = 'SELECT l.id, l.action, l.entity_type, l.entity_id, l.before_json, l.after_json,
                       l.reason, l.request_id, l.ip_address, l.created_at, b.public_id AS branch_public_id,
                       u.public_id AS actor_public_id, u.username AS actor_username,
                       u.display_name AS actor_display_name
                FROM app_audit_logs l
                LEFT JOIN app_users u ON u.id = l.actor_user_id
                LEFT JOIN pos_branches b ON b.id = l.branch_id
                WHERE l.business_id = :business_id';
        if ($branchId !== null) {
            $sql .= ' AND (l.branch_id IS NULL OR l.branch_id = :branch_id)';
        }
        if ($beforeId !== null) {
            $sql .= ' AND l.id < :before_id';
        }
        $sql .= ' ORDER BY l.id DESC LIMIT :limit';
        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':business_id', $businessId, PDO::PARAM_INT);
        if ($branchId !== null) {
            $statement->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        if ($beforeId !== null) {
            $statement->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            foreach (['before_json', 'after_json'] as $field) {
                $row[$field] = $row[$field] === null ? null : json_decode((string) $row[$field], true);
            }
            return [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'entity_type' => $row['entity_type'] === null ? null : (string) $row['entity_type'],
                'entity_id' => $row['entity_id'] === null ? null : (string) $row['entity_id'],
                'before' => $row['before_json'],
                'after' => $row['after_json'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'request_id' => $row['request_id'] === null ? null : (string) $row['request_id'],
                'ip_address' => $row['ip_address'] === null ? null : (string) $row['ip_address'],
                'branch_id' => $row['branch_public_id'] === null ? null : (string) $row['branch_public_id'],
                'actor' => $row['actor_public_id'] === null ? null : [
                    'id' => (string) $row['actor_public_id'],
                    'username' => (string) $row['actor_username'],
                    'display_name' => (string) $row['actor_display_name'],
                ],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }
}
