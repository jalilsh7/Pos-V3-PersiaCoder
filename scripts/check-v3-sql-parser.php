<?php
declare(strict_types=1);
require_once __DIR__ . '/../database/v3/bin/bootstrap.php';

$sample = "-- comment with fake semicolon ;\n"
    . "SELECT 'value;inside';\n"
    . "/* another ; comment */ SELECT \"double;quoted\";\n"
    . 'SELECT ' . chr(96) . 'identifier;quoted' . chr(96) . ';';

$statements = PosV3DatabaseTools::statements($sample);
if (count($statements) !== 3) {
    throw new RuntimeException('SQL parser did not preserve quoted semicolons or remove comments correctly.');
}

$schemaPath = __DIR__ . '/../database/v3/schema.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) throw new RuntimeException('Cannot read V3 schema baseline.');
$schemaStatements = PosV3DatabaseTools::statements($schema);
$tableCount = substr_count($schema, 'CREATE TABLE ');
if ($tableCount < 61 || count($schemaStatements) < 65) {
    throw new RuntimeException('Unexpectedly low table or statement count in V3 baseline.');
}
if (str_contains(strtoupper($schema), 'DELIMITER ')) throw new RuntimeException('V3 baseline must not contain unsupported DELIMITER directives.');

try {
    PosV3DatabaseTools::quoteIdentifier('bad' . chr(96) . 'identifier');
    throw new RuntimeException('Unsafe SQL identifier was accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Unsafe SQL identifier was accepted.') throw $error;
}

echo 'V3 SQL parser checks passed: ' . count($schemaStatements) . " baseline statements.\n";
