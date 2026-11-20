<?php
declare(strict_types=1);

$backendRoot = dirname(__DIR__, 3);
spl_autoload_register(static function (string $class) use ($backendRoot): void {
    $prefix = "PersiaCoder\\PosV3\\";
    if (!str_starts_with($class, $prefix)) return;
    $file = $backendRoot . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

use PersiaCoder\PosV3\Communications\Sms\NationalPayamakSharedNumberProvider;
use PersiaCoder\PosV3\Communications\Sms\SharedNumberConfig;
use PersiaCoder\PosV3\Communications\Sms\SmsFormHttpClient;
use PersiaCoder\PosV3\Communications\Sms\SmsHttpResponse;
use PersiaCoder\PosV3\Communications\Sms\SmsProviderException;

final class FakeSharedNumberHttpClient implements SmsFormHttpClient
{
    /** @var list<array{url:string,payload:array,timeout:int}> */
    public array $calls = [];
    public ?Throwable $failure = null;
    public function __construct(public SmsHttpResponse $response) {}
    public function postJson(string $url, array $payload, int $timeoutSeconds): SmsHttpResponse
    {
        return $this->postForm($url, $payload, $timeoutSeconds);
    }
    public function postForm(string $url, array $payload, int $timeoutSeconds): SmsHttpResponse
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeoutSeconds];
        if ($this->failure !== null) throw $this->failure;
        return $this->response;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$config = new SharedNumberConfig('ci-user', 'ci-shared-api-key', 15);
$http = new FakeSharedNumberHttpClient(new SmsHttpResponse(
    200,
    json_encode(['Value' => '123456789012345678', 'RetStatus' => 1, 'StrRetStatus' => 'OK'], JSON_THROW_ON_ERROR),
));
$provider = new NationalPayamakSharedNumberProvider($config, $http);
$result = $provider->sendTemplate('+989123456789', 4321, ['سلام', 'علی']);
$assert($result['accepted'] === true && $result['mobile'] === '09123456789'
    && $result['provider_id'] === '123456789012345678',
    'SharedNumber successful template response was not mapped.');
$call = $http->calls[0];
$assert($call['url'] === 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
    'SharedNumber REST endpoint is incorrect.');
$assert($call['payload']['bodyId'] === 4321
    && $call['payload']['text'] === 'سلام;علی'
    && $call['payload']['to'] === '09123456789'
    && $call['payload']['password'] === 'ci-shared-api-key',
    'SharedNumber payload does not follow the documented contract.');

$errorHttp = new FakeSharedNumberHttpClient(new SmsHttpResponse(
    200,
    json_encode(['Value' => -5, 'RetStatus' => 35, 'StrRetStatus' => 'InvalidData'], JSON_THROW_ON_ERROR),
));
$errorProvider = new NationalPayamakSharedNumberProvider($config, $errorHttp);
$variablesMismatch = false;
try {
    $errorProvider->sendTemplate('09123456789', 4321, ['too many values']);
} catch (SmsProviderException $exception) {
    $variablesMismatch = $exception->providerCode === -5
        && $exception->getMessage() === 'sms_shared_template_variables_mismatch';
}
$assert($variablesMismatch, 'SharedNumber template variable error was not mapped.');

$noCall = new FakeSharedNumberHttpClient(new SmsHttpResponse(200, '{}'));
$noCallProvider = new NationalPayamakSharedNumberProvider($config, $noCall);
$invalidTemplate = false;
try {
    $noCallProvider->sendTemplate('09123456789', 0, ['value']);
} catch (SmsProviderException $exception) {
    $invalidTemplate = $exception->getMessage() === 'sms_shared_template_id_invalid';
}
$invalidMobile = false;
try {
    $noCallProvider->sendTemplate('12345', 4321, ['value']);
} catch (SmsProviderException $exception) {
    $invalidMobile = $exception->getMessage() === 'sms_recipient_invalid';
}
$assert($invalidTemplate && $invalidMobile && $noCall->calls === [],
    'Invalid SharedNumber template/mobile reached the provider.');

$unknownHttp = new FakeSharedNumberHttpClient(new SmsHttpResponse(503, '{}'));
$unknownProvider = new NationalPayamakSharedNumberProvider($config, $unknownHttp);
$unknown = false;
try {
    $unknownProvider->sendTemplate('09123456789', 4321, ['value']);
} catch (SmsProviderException $exception) {
    $unknown = $exception->deliveryUnknown && !$exception->retryable;
}
$assert($unknown, 'SharedNumber HTTP 5xx outcome must be treated as unknown.');


$largeIdHttp = new FakeSharedNumberHttpClient(new SmsHttpResponse(
    200,
    json_encode(['Value' => '9999999999999999999', 'RetStatus' => 1, 'StrRetStatus' => 'OK'], JSON_THROW_ON_ERROR),
));
$largeIdResult = (new NationalPayamakSharedNumberProvider($config, $largeIdHttp))
    ->sendTemplate('09123456789', 4321, ['value']);
$assert($largeIdResult['provider_id'] === '9999999999999999999',
    'A valid 19-digit provider receipt ID must remain an opaque string.');

echo 'National Payamak SharedNumber template adapter tests passed.' . PHP_EOL;
