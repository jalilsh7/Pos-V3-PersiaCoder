<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Http;

use JsonException;

final readonly class Request
{
    private const MAX_JSON_BODY_BYTES = 65536;

    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public string $body,
        public string $requestId,
        public array $attributes = [],
        public array $queryParameters = [],
        public array $uploadedFiles = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            $path = '/';
        }
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString) && strlen($queryString) > 4096) {
            throw new HttpException(414, 'query_string_too_large', 'The query string exceeds the allowed size.');
        }
        $queryParameters = [];
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $queryParameters);
            if (count($queryParameters) > 20) {
                throw new HttpException(400, 'too_many_query_parameters', 'The query contains too many parameters.');
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $candidate = $headers['x-request-id'] ?? '';
        $requestId = is_string($candidate) && preg_match('/^[A-Za-z0-9._-]{8,36}$/', $candidate)
            ? $candidate
            : bin2hex(random_bytes(16));

        $contentType = strtolower(trim($headers['content-type'] ?? ''));
        if (str_starts_with($contentType, 'multipart/form-data')) {
            $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
            $postLimit = self::iniBytes((string) ini_get('post_max_size'));
            if (is_string($contentLength) && ctype_digit($contentLength)
                && $postLimit > 0 && (int) $contentLength > $postLimit) {
                throw new HttpException(413, 'request_body_too_large', 'The multipart request exceeds the PHP post_max_size limit.');
            }
        }
        $body = str_starts_with($contentType, 'application/json')
            ? file_get_contents('php://input', false, null, 0, self::MAX_JSON_BODY_BYTES + 1)
            : '';
        $uploadedFiles = [];
        foreach ($_FILES as $field => $file) {
            if (!is_string($field) || !is_array($file) || is_array($file['tmp_name'] ?? null)) {
                continue;
            }
            $uploadedFiles[$field] = [
                'tmp_name' => is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '',
                'size' => is_int($file['size'] ?? null) ? $file['size'] : 0,
                'error' => is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            ];
        }
        $attributes = [
            'remote_ip' => is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
        ];

        return new self(
            $method,
            $path,
            $headers,
            $body === false ? '' : $body,
            $requestId,
            $attributes,
            $queryParameters,
            $uploadedFiles,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function query(string $name): ?string
    {
        $value = $this->queryParameters[$name] ?? null;
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;
        return new self($this->method, $this->path, $this->headers, $this->body, $this->requestId, $attributes, $this->queryParameters, $this->uploadedFiles);
    }

    /** @return array{tmp_name:string,size:int,error:int}|null */
    public function uploadedFile(string $name): ?array
    {
        $file = $this->uploadedFiles[$name] ?? null;
        return is_array($file)
            && is_string($file['tmp_name'] ?? null)
            && is_int($file['size'] ?? null)
            && is_int($file['error'] ?? null)
            ? $file
            : null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (strlen($this->body) > self::MAX_JSON_BODY_BYTES) {
            throw new HttpException(413, 'request_body_too_large', 'The JSON request body exceeds the allowed size.');
        }
        if (trim($this->body) === '') {
            return [];
        }

        try {
            $decoded = json_decode($this->body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'invalid_json', 'Request body must contain valid JSON.');
        }
        if (!$decoded instanceof \stdClass) {
            throw new HttpException(400, 'invalid_json_shape', 'JSON request body must be an object.');
        }

        return get_object_vars($decoded);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') return 0;
        if (!preg_match('/^(\\d+)\\s*([KMG])?$/i', $value, $matches)) return 0;
        $bytes = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');
        return match ($unit) {
            'K' => $bytes * 1024,
            'M' => $bytes * 1024 * 1024,
            'G' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }

}
