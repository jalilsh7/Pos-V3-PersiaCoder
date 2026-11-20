<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Communications\Sms\CurlSmsHttpClient;
use PersiaCoder\PosV3\Communications\Sms\NationalPayamakSharedNumberProvider;
use PersiaCoder\PosV3\Communications\Sms\SharedNumberConfig;
use PersiaCoder\PosV3\Communications\Sms\SmsServiceTemplateWorker;

$root = dirname(__DIR__);
$configuration = require $root . '/v3/backend/bootstrap.php';
require_once $root . '/database/v3/bin/bootstrap.php';
$config = SharedNumberConfig::fromEnvironment();
if ($config === null) {
    fwrite(STDERR, "National Payamak Shared Number is not configured." . PHP_EOL);
    exit(2);
}
$db = PosV3DatabaseTools::connect();
$provider = new NationalPayamakSharedNumberProvider($config, new CurlSmsHttpClient());
$worker = new SmsServiceTemplateWorker($db, $provider, $configuration->applicationKey);
echo json_encode($worker->processBatch(50), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
