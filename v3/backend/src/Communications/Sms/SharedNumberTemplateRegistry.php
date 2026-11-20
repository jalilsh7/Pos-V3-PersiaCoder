<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use InvalidArgumentException;
use JsonException;

/**
 * Allow-list of provider-approved shared-number templates.
 *
 * Provider body IDs are account-specific and must be supplied by the
 * installation operator after approval in the National Payamak panel.
 */
final readonly class SharedNumberTemplateRegistry
{
    /** @param array<string, array{body_id:int,variable_count:int}> $templates */
    public function __construct(private array $templates)
    {
        foreach ($templates as $key => $definition) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $key)
                || !is_array($definition)
                || !isset($definition['body_id'], $definition['variable_count'])
                || !is_int($definition['body_id']) || $definition['body_id'] < 1
                || !is_int($definition['variable_count']) || $definition['variable_count'] < 0
                || $definition['variable_count'] > 20) {
                throw new InvalidArgumentException('sms_shared_template_registry_invalid');
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $raw = getenv('POS_V3_SMS_SHARED_TEMPLATES');
        if (!is_string($raw) || trim($raw) === '') {
            return new self([]);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('sms_shared_template_registry_invalid');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('sms_shared_template_registry_invalid');
        }
        return new self($decoded);
    }

    /** @return array{body_id:int,variable_count:int} */
    public function require(string $key, int $actualVariableCount): array
    {
        $definition = $this->templates[$key] ?? null;
        if (!is_array($definition)) {
            throw new SmsProviderException('sms_shared_template_not_configured');
        }
        if ($actualVariableCount !== $definition['variable_count']) {
            throw new SmsProviderException('sms_shared_template_variables_mismatch');
        }
        return $definition;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->templates);
    }
}
