<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Communications\Sms\CurlSmsHttpClient;
use PersiaCoder\PosV3\Communications\Sms\NationalPayamakSmartSmsProvider;
use PersiaCoder\PosV3\Communications\Sms\SmartSmsConfig;
use PersiaCoder\PosV3\Communications\Sms\SmsOutboxWorker;

$root = dirname(__DIR__);
$configuration = require $root . '/v3/backend/bootstrap.php';
require_once $root . '/database/v3/bin/bootstrap.php';

$providerConfig = SmartSmsConfig::fromEnvironment();
if ($providerConfig === null) {
    fwrite(STDERR, "National Payamak SmartSMS is not configured in the server environment." . PHP_EOL);
    exit(2);
}
$connection = PosV3DatabaseTools::connect();
$provider = new NationalPayamakSmartSmsProvider($providerConfig, new CurlSmsHttpClient());
$worker = new SmsOutboxWorker($connection, $provider, $configuration->applicationKey);
$result = $worker->processBatch(100);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
