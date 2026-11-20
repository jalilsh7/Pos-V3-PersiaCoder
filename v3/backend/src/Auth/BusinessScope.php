<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

final readonly class BusinessScope
{
    public function __construct(
        public int $businessId,
        public string $businessPublicId,
        public ?int $branchId,
        public ?string $branchPublicId,
    ) {}
}
