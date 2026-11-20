<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\IdempotencyRepository;
use PersiaCoder\PosV3\Infrastructure\Database\TransactionManager;

final readonly class IdempotencyService
{
    private IdempotencyRepository $records;
    private TransactionManager $transactions;
    private string $encryptionKey;

    public function __construct(
        PDO $connection,
        string $applicationKey,
        private int $retentionSeconds = 86400,
    ) {
        if (strlen($applicationKey) < 32 || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new \InvalidArgumentException('Application key must contain at least 32 bytes.');
        }
        if ($retentionSeconds < 60 || $retentionSeconds > 604800) {
            throw new \InvalidArgumentException('Idempotency retention must be between 60 and 604800 seconds.');
        }
        $this->records = new IdempotencyRepository($connection);
        $this->transactions = new TransactionManager($connection);
        $this->encryptionKey = hash('sha256', $applicationKey, true);
    }

    /**
     * The callback must perform all domain writes using the supplied PDO connection.
     * Its result is committed atomically with the encrypted idempotency response.
     *
     * @param callable(PDO): array{status:int,body:array<string,mixed>} $operation
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function execute(
        int $actorUserId,
        BusinessScope $scope,
        string $endpointCode,
        string $idempotencyKey,
        string $requestHash,
        callable $operation,
    ): array {
        if ($actorUserId < 1 || $scope->businessId < 1) {
            throw new \InvalidArgumentException('Idempotency requires a valid actor and business scope.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $endpointCode)) {
            throw new \InvalidArgumentException('Idempotency endpoint code is invalid.');
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $idempotencyKey)) {
            throw new HttpException(400, 'idempotency_key_invalid', 'A valid Idempotency-Key header is required.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw new \InvalidArgumentException('The request fingerprint must be a SHA-256 hex digest.');
        }

        $scopeHash = hash('sha256', $actorUserId . ':' . $scope->businessId . ':' . ($scope->branchId ?? 0));
        $keyHash = hash('sha256', $idempotencyKey);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $this->retentionSeconds . ' seconds')
            ->format('Y-m-d H:i:s.u');

        return $this->transactions->run(function (PDO $connection) use (
            $actorUserId,
            $scope,
            $endpointCode,
            $keyHash,
            $requestHash,
            $scopeHash,
            $expiresAt,
            $operation,
        ): array {
            $record = $this->records->createOrLock(
                $actorUserId,
                $scope->businessId,
                $scope->branchId,
                $scopeHash,
                $endpointCode,
                $keyHash,
                $requestHash,
                $expiresAt,
            );

            if (!$record['created']) {
                if (!hash_equals($record['request_hash'], $requestHash)) {
                    throw new HttpException(409, 'idempotency_key_reused', 'This idempotency key was already used with a different request.');
                }
                if ($record['response_status'] === null
                    || $record['response_ciphertext'] === null
                    || $record['response_nonce'] === null
                    || $record['response_tag'] === null) {
                    throw new HttpException(409, 'idempotency_in_progress', 'A request with this idempotency key is still being processed.');
                }

                return [
                    'status' => $record['response_status'],
                    'body' => $this->decryptResponse(
                        $record['response_ciphertext'],
                        $record['response_nonce'],
                        $record['response_tag'],
                    ),
                    'replayed' => true,
                ];
            }

            $result = $operation($connection);
            if (!is_array($result) || !is_int($result['status'] ?? null)
                || $result['status'] < 200 || $result['status'] > 299
                || !is_array($result['body'] ?? null)) {
                throw new \UnexpectedValueException('An idempotent operation must return a successful HTTP status and array body.');
            }

            [$ciphertext, $nonce, $tag] = $this->encryptResponse($result['body']);
            $this->records->complete(
                $record['id'],
                $result['status'],
                $ciphertext,
                $nonce,
                $tag,
            );

            return [
                'status' => $result['status'],
                'body' => $result['body'],
                'replayed' => false,
            ];
        });
    }

    /** @param array<string,mixed> $body @return array{string,string,string} */
    private function encryptResponse(array $body): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $json,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('The idempotency response could not be encrypted.');
        }
        return [$ciphertext, $nonce, $tag];
    }

    /** @return array<string,mixed> */
    private function decryptResponse(string $ciphertext, string $nonce, string $tag): array
    {
        $json = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if (!is_string($json)) {
            throw new \RuntimeException('The stored idempotency response failed authentication.');
        }
        $body = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        if (!$body instanceof \stdClass) {
            throw new \RuntimeException('The stored idempotency response has an invalid shape.');
        }
        return get_object_vars($body);
    }
}
