<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/v3/bin/bootstrap.php';
$pdo = PosV3DatabaseTools::connect();
$row = $pdo->query(
    'SELECT response_nonce, response_tag, response_ciphertext
     FROM app_idempotency_keys
     WHERE response_nonce IS NOT NULL AND response_tag IS NOT NULL AND response_ciphertext IS NOT NULL
     ORDER BY id ASC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    throw new RuntimeException('Restored backup does not contain a completed idempotency response.');
}
if (strlen((string) $row['response_nonce']) !== 12) {
    throw new RuntimeException('Restored idempotency response nonce must be exactly 12 binary bytes.');
}
if (strlen((string) $row['response_tag']) !== 16) {
    throw new RuntimeException('Restored idempotency response tag must be exactly 16 binary bytes.');
}
if (strlen((string) $row['response_ciphertext']) < 1) {
    throw new RuntimeException('Restored idempotency response ciphertext is empty.');
}
echo "V3 backup binary column restore checks passed.\n";
