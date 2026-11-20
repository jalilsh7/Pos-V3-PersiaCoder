<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use PersiaCoder\PosV3\Communications\Sms\NationalPayamakSmartSmsProvider;
use PersiaCoder\PosV3\Communications\Sms\SmartSmsConfig;
use PersiaCoder\PosV3\Communications\Sms\SmsHttpClient;
use PersiaCoder\PosV3\Communications\Sms\SmsHttpResponse;
use PersiaCoder\PosV3\Communications\Sms\SmsProviderException;

final class FakeSmsHttpClient implements SmsHttpClient
{
    /** @var list<array{url:string,payload:array,timeout:int}> */
    public array $calls = [];
    public ?Throwable $failure = null;
    public function __construct(public SmsHttpResponse $response) {}
    public function postJson(string $url, array $payload, int $timeoutSeconds): SmsHttpResponse
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeoutSeconds];
        if ($this->failure !== null) throw $this->failure;
        return $this->response;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$config = new SmartSmsConfig('ci-user', 'ci-api-key-secret', '1000', '2000', '3000', 17);

$sendHttp = new FakeSmsHttpClient(new SmsHttpResponse(
    200,
    json_encode(['Value' => '10001,10002', 'RetStatus' => 1, 'StrRetStatus' => 'OK'], JSON_THROW_ON_ERROR),
));
$provider = new NationalPayamakSmartSmsProvider($config, $sendHttp);
$sent = $provider->send('پیام آزمایشی', ['09123456789', '+989361234567']);
$assert($sent['accepted'] === true && count($sent['messages']) === 2, 'SmartSMS send result was not accepted.');
$assert($sent['messages'][0]['provider_id'] === '10001' && $sent['messages'][1]['mobile'] === '09361234567',
    'SmartSMS send IDs/mobile mapping is incorrect.');
$call = $sendHttp->calls[0];
$assert($call['url'] === 'https://rest.payamak-panel.com/api/SmartSMS/Send', 'SmartSMS Send URL is incorrect.');
$assert($call['payload']['password'] === 'ci-api-key-secret'
    && $call['payload']['to'] === '09123456789,09361234567'
    && $call['payload']['fromSupportOne'] === '2000'
    && $call['payload']['fromSupportTwo'] === '3000',
    'SmartSMS Send payload does not follow the documented contract.');
$assert($call['timeout'] === 17, 'Configured HTTP timeout was not passed to the transport.');

$multipleHttp = new FakeSmsHttpClient(new SmsHttpResponse(
    200,
    json_encode([
        'ReqStatus' => '1',
        'Result' => [
            ['Mobile' => '09123456789', 'ID' => 20001],
            ['Mobile' => '09361234567', 'ID' => 20002],
        ],
    ], JSON_THROW_ON_ERROR),
));
$multipleProvider = new NationalPayamakSmartSmsProvider($config, $multipleHttp);
$multiple = $multipleProvider->sendPersonalized([
    ['to' => '09123456789', 'text' => 'سلام علی'],
    ['to' => '09361234567', 'text' => 'سلام سارا'],
]);
$assert($multiple['accepted'] === true && $multiple['messages'][1]['provider_id'] === '20002',
    'SmartSMS SendMultiple response mapping is incorrect.');
$assert($multipleHttp->calls[0]['payload']['to'] === ['09123456789', '09361234567']
    && $multipleHttp->calls[0]['payload']['text'] === ['سلام علی', 'سلام سارا'],
    'SmartSMS SendMultiple did not preserve recipient/text ordering.');

$deliveryHttp = new FakeSmsHttpClient(new SmsHttpResponse(
    200,
    json_encode(['RetStatus' => 1, 'StrRetStatus' => 'OK', 'Value' => [1, 0, 2, 35, 400, 100]], JSON_THROW_ON_ERROR),
));
$deliveryProvider = new NationalPayamakSmartSmsProvider($config, $deliveryHttp);
$deliveries = $deliveryProvider->getDeliveryStatuses([10001, 10002, 10003, 10004, 10005, 10006]);
$assert(array_column($deliveries, 'status') === [
    'delivered', 'sent_to_carrier', 'failed', 'rejected', 'queued', 'unknown',
], 'SmartSMS delivery status mapping is incorrect.');

$errorProvider = new NationalPayamakSmartSmsProvider(
    $config,
    new FakeSmsHttpClient(new SmsHttpResponse(
        400,
        json_encode(['Value' => 14, 'RetStatus' => 0, 'StrRetStatus' => 'Error'], JSON_THROW_ON_ERROR),
    )),
);
$linkBlocked = false;
try {
    $errorProvider->send('https://example.test', ['09123456789']);
} catch (SmsProviderException $exception) {
    $linkBlocked = $exception->providerCode === 14
        && $exception->getMessage() === 'sms_provider_link_not_allowed'
        && !$exception->deliveryUnknown;
}
$assert($linkBlocked, 'SmartSMS provider error code was not mapped safely.');

$noCallHttp = new FakeSmsHttpClient(new SmsHttpResponse(200, '{}'));
$noCallProvider = new NationalPayamakSmartSmsProvider($config, $noCallHttp);
$batchRejected = false;
try {
    $noCallProvider->send('bulk', array_fill(0, 101, '09123456789'));
} catch (SmsProviderException $exception) {
    $batchRejected = $exception->getMessage() === 'sms_batch_size_invalid';
}
$assert($batchRejected && $noCallHttp->calls === [], 'Oversized SMS batch reached the provider transport.');

$duplicateRejected = false;
try {
    $noCallProvider->send('duplicate', ['09123456789', '+989123456789']);
} catch (SmsProviderException $exception) {
    $duplicateRejected = $exception->getMessage() === 'sms_duplicate_recipient';
}
$assert($duplicateRejected && $noCallHttp->calls === [], 'Duplicate recipient was not rejected before sending.');

$unknownHttp = new FakeSmsHttpClient(new SmsHttpResponse(200, '{}'));
$unknownHttp->failure = new SmsProviderException('sms_provider_transport_outcome_unknown', null, false, true);
$unknownProvider = new NationalPayamakSmartSmsProvider($config, $unknownHttp);
$unknownOutcome = false;
try {
    $unknownProvider->send('may have been accepted', ['09123456789']);
} catch (SmsProviderException $exception) {
    $unknownOutcome = $exception->deliveryUnknown && !$exception->retryable;
}
$assert($unknownOutcome, 'Uncertain transport outcome must not be blindly retried.');

echo 'National Payamak SmartSMS adapter tests passed.' . PHP_EOL;
