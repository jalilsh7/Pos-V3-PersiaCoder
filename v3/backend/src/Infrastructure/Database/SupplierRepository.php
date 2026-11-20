<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class SupplierRepository
{
    public function __construct(private PDO $connection) {}

    public function listForBusiness(int $businessId, bool $includeInactive = true): array
    {
        $sql = 'SELECT public_id, name, phone, notes, is_active
                FROM pos_suppliers WHERE business_id = :business_id';
        if (!$includeInactive) $sql .= ' AND is_active = 1';
        $sql .= ' ORDER BY is_active DESC, name ASC, id ASC';
        $s = $this->connection->prepare($sql);
        $s->execute(['business_id' => $businessId]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'], 'name' => (string) $row['name'],
            'phone' => $row['phone'] === null ? null : (string) $row['phone'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'is_active' => (bool) $row['is_active'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, public_id, name, phone, notes, is_active
             FROM pos_suppliers WHERE business_id = :business_id AND public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $businessId, string $publicId, string $name, ?string $phone, ?string $notes): void
    {
        $s = $this->connection->prepare(
            'INSERT INTO pos_suppliers (business_id, public_id, name, phone, notes, is_active)
             VALUES (:business_id, :public_id, :name, :phone, :notes, 1)'
        );
        $s->execute([
            'business_id' => $businessId, 'public_id' => $publicId,
            'name' => $name, 'phone' => $phone, 'notes' => $notes,
        ]);
    }

    public function update(int $businessId, int $id, string $name, ?string $phone, ?string $notes, bool $active): void
    {
        $s = $this->connection->prepare(
            'UPDATE pos_suppliers SET name = :name, phone = :phone, notes = :notes, is_active = :active
             WHERE business_id = :business_id AND id = :id'
        );
        $s->execute([
            'name' => $name, 'phone' => $phone, 'notes' => $notes,
            'active' => $active ? 1 : 0, 'business_id' => $businessId, 'id' => $id,
        ]);
    }
}
