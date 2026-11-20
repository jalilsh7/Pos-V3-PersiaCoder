<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;

final readonly class SameOriginGuard
{
    public function __construct(private bool $allowInsecureLocalhost = false) {}

    public function assert(Request $request): void
    {
        $hostHeader = trim($request->header('host') ?? '');
        $source = trim($request->header('origin') ?? '');
        if ($source === '') {
            $referer = trim($request->header('referer') ?? '');
            $source = $referer === '' ? '' : $referer;
        }

        if ($hostHeader === '' || $source === '') {
            throw new HttpException(403, 'origin_required', 'A same-origin request is required.');
        }

        $sourceParts = parse_url($source);
        $hostParts = parse_url('https://' . $hostHeader);
        if (!is_array($sourceParts) || !is_array($hostParts) || empty($sourceParts['scheme']) || empty($sourceParts['host'])) {
            throw new HttpException(403, 'origin_invalid', 'The request origin is invalid.');
        }

        $scheme = strtolower((string) $sourceParts['scheme']);
        $sourceHost = strtolower((string) $sourceParts['host']);
        $requestHost = strtolower((string) ($hostParts['host'] ?? ''));
        $sourcePort = (int) ($sourceParts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $secureScheme = $scheme === 'https';
        $localHttp = $this->allowInsecureLocalhost
            && $scheme === 'http'
            && in_array($sourceHost, ['localhost', '127.0.0.1', '::1'], true);
        $requestPort = (int) ($hostParts['port'] ?? ($localHttp ? 80 : 443));

        if ((!$secureScheme && !$localHttp)
            || $sourceHost !== $requestHost
            || $sourcePort !== $requestPort
            || isset($sourceParts['user'])
            || isset($sourceParts['pass'])) {
            throw new HttpException(403, 'origin_mismatch', 'The request origin does not match this application.');
        }
    }
}
