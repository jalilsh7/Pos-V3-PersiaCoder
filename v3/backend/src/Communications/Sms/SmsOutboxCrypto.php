<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use RuntimeException;

final readonly class SmsOutboxCrypto
{
    private string $encryptionKey;
    private string $integrityKey;

    public function __construct(string $applicationKey)
    {
        if (strlen($applicationKey) < 32 || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('sms_outbox_crypto_configuration_invalid');
        }
        $this->encryptionKey = hash_hmac('sha256', 'pos-v3-sms-outbox-encryption', $applicationKey, true);
        $this->integrityKey = hash_hmac('sha256', 'pos-v3-sms-outbox-integrity', $applicationKey, true);
    }

    /** @return array{ciphertext:string,nonce:string,tag:string,hash:string} */
    public function encrypt(string $plaintext): array
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('sms_outbox_encryption_failed');
        }
        return [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'tag' => $tag,
            'hash' => hash_hmac('sha256', $plaintext, $this->integrityKey),
        ];
    }

    public function decrypt(string $ciphertext, string $nonce, string $tag, string $expectedHash): string
    {
        if (strlen($nonce) !== 12 || strlen($tag) !== 16 || !preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
            throw new RuntimeException('sms_outbox_ciphertext_metadata_invalid');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if (!is_string($plaintext)
            || !hash_equals($expectedHash, hash_hmac('sha256', $plaintext, $this->integrityKey))) {
            throw new RuntimeException('sms_outbox_ciphertext_integrity_failed');
        }
        return $plaintext;
    }

    public function hashRecipient(string $mobile): string
    {
        return hash_hmac('sha256', $mobile, $this->integrityKey);
    }
}
