<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Http;

use JsonException;

final readonly class Response
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function success(array $data, array $meta = [], int $status = 200): self
    {
        return self::json(['data' => $data, 'meta' => (object) $meta], $status);
    }

    /** @param array<string, mixed> $error */
    public static function error(array $error, int $status): self
    {
        return self::json(['error' => $error], $status);
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $body = '{"error":{"code":"response_encoding_failed","message":"The response could not be encoded."}}';
            $status = 500;
        }

        return new self($status, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function withHeader(string $name, string|array $value): self
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new \InvalidArgumentException('Header values must be strings.');
                }
            }
        }

        $headers = $this->headers;
        $headers[$name] = $value;
        return new self($this->status, $this->body, $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $index => $value) {
                header($name . ': ' . $value, $index === 0);
            }
        }
        echo $this->body;
    }
}
