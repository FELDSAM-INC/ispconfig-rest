<?php

namespace App\Services;

/**
 * The installation's password policy (spec 038), read from the `[misc]`
 * section of the system configuration: `min_password_length` (legacy
 * `auth::get_min_password_length()`, default 8) and `min_password_strength`
 * (`auth::get_min_password_strength()`, default 0 = no strength requirement).
 *
 * One source for every credential type. The mailbox policy of spec 025/028
 * adds `ascii_only` on top of these two values.
 */
class PasswordPolicyService
{
    /** auth::get_min_password_length() without a system setting (auth.inc.php). */
    public const DEFAULT_MIN_LENGTH = 8;

    public function __construct(protected SystemConfigService $system) {}

    /**
     * @return array{min_length: int, min_strength: int}
     */
    public function policy(): array
    {
        return self::fromMisc($this->system->rawConfig()['misc'] ?? []);
    }

    /**
     * @param  array<string, string>  $misc  the `[misc]` section
     * @return array{min_length: int, min_strength: int}
     */
    public static function fromMisc(array $misc): array
    {
        return [
            'min_length' => array_key_exists('min_password_length', $misc)
                ? max(0, (int) $misc['min_password_length'])
                : self::DEFAULT_MIN_LENGTH,
            'min_strength' => min(5, max(0, (int) ($misc['min_password_strength'] ?? 0))),
        ];
    }
}
