<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use DateTimeImmutable;

final readonly class AuthenticatedPrincipal
{
    public function __construct(
        public int $sessionId,
        public int $userId,
        public string $userPublicId,
        public string $username,
        public string $displayName,
        public bool $mustChangePassword,
        public string $csrfTokenHash,
        public DateTimeImmutable $expiresAt,
    ) {}
}
