<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use DateTimeImmutable;

final readonly class AuthenticatedSession
{
    public function __construct(
        public string $sessionToken,
        public string $csrfToken,
        public DateTimeImmutable $expiresAt,
        public string $userPublicId,
        public string $username,
        public string $displayName,
        public bool $mustChangePassword,
    ) {}
}
