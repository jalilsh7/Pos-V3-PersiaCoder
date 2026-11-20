<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use DateTimeZone;
use PDO;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\BusinessRepository;
use PersiaCoder\PosV3\Infrastructure\Database\TransactionManager;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Security\AuditLogService;

final readonly class BusinessSetupService
{
    private BusinessRepository $businesses;
    private TransactionManager $transactions;
    private AuditLogService $audit;

    public function __construct(private PDO $connection)
    {
        $this->businesses = new BusinessRepository($connection);
        $this->transactions = new TransactionManager($connection);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    /** @return array{business_id:string,business_name:string,branch_id:string,branch_name:string} */
    public function createInitialBusiness(
        int $ownerUserId,
        string $name,
        string $code,
        string $timezone,
        string $currency,
        string $branchName,
        string $branchCode,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!$this->businesses->isInstallationOwner($ownerUserId)) {
            throw new HttpException(403, 'owner_required', 'Only the installation owner can complete initial setup.');
        }

        $name = trim($name);
        $code = strtolower(trim($code));
        $branchName = trim($branchName);
        $branchCode = strtolower(trim($branchCode));
        $timezone = trim($timezone);
        $currency = strtoupper(trim($currency));

        if ($name === '' || self::unicodeLength($name) > 160) {
            throw new HttpException(422, 'invalid_business_name', 'Business name is required and must not exceed 160 characters.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $code)) {
            throw new HttpException(422, 'invalid_business_code', 'Business code must use 2-63 lowercase letters, digits or hyphens.');
        }
        if ($branchName === '' || self::unicodeLength($branchName) > 160) {
            throw new HttpException(422, 'invalid_branch_name', 'Branch name is required and must not exceed 160 characters.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $branchCode)) {
            throw new HttpException(422, 'invalid_branch_code', 'Branch code must use 2-63 lowercase letters, digits or hyphens.');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new HttpException(422, 'invalid_timezone', 'The timezone is not recognized.');
        }
        if ($currency !== 'IRT') {
            throw new HttpException(422, 'unsupported_currency', 'The initial V3 release supports Iranian toman (IRT) only.');
        }

        $businessPublicId = self::uuidV4();
        $branchPublicId = self::uuidV4();
        $this->transactions->run(function (PDO $connection) use (
            $businessPublicId,
            $branchPublicId,
            $code,
            $name,
            $timezone,
            $currency,
            $branchCode,
            $branchName,
            $ownerUserId,
            $requestId,
            $ipAddress,
        ): void {
            if ($this->businesses->lockInitialBusinessId() !== null) {
                throw new HttpException(409, 'business_already_configured', 'The initial business has already been configured.');
            }

            $businessId = $this->businesses->createBusiness(
                $businessPublicId,
                $code,
                $name,
                $timezone,
                $currency,
            );
            $branchId = $this->businesses->createBranch(
                $branchPublicId,
                $businessId,
                $branchCode,
                $branchName,
                $timezone,
            );
            $this->businesses->markInitialBusinessCreated($businessId);
            $this->businesses->assignBusinessOwner($ownerUserId, $businessId);
            $this->businesses->removeInstallationOwnerRole($ownerUserId);
            $this->audit->record(
                $ownerUserId,
                'business.initialized',
                'business',
                $businessPublicId,
                null,
                [
                    'name' => $name,
                    'code' => $code,
                    'timezone' => $timezone,
                    'currency' => $currency,
                    'initial_branch_id' => $branchPublicId,
                ],
                null,
                $requestId,
                $ipAddress,
                $businessId,
                $branchId,
            );
        });

        return [
            'business_id' => $businessPublicId,
            'business_name' => $name,
            'branch_id' => $branchPublicId,
            'branch_name' => $branchName,
        ];
    }

    private static function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
