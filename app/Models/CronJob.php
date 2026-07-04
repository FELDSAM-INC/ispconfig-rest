<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * cron — a scheduled job for a website. Table `cron`, primary key `id`
 * (contract: api/components/schemas/CronJob.yaml; legacy:
 * source_code/interface/web/sites/form/cron.tform.php + cron_edit.php).
 *
 * The static validators are exact ports of
 * source_code/interface/lib/classes/validate_cron.inc.php
 * (run_time_format / run_month_format / command_format).
 */
class CronJob extends BaseModel
{
    use HasSitesDisplayFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cron';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * `type` is derived server-side (never fillable); server_id and
     * sys_groupid come from the parent domain.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_domain_id',
        'run_min',
        'run_hour',
        'run_mday',
        'run_month',
        'run_wday',
        'command',
        'log',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'parent_domain_id' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'log' => YesNoBoolean::class,
        'active' => YesNoBoolean::class,
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'server_name',
        'parent_domain',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'url',
        'log' => 'n',
        'active' => 'y',
    ];

    protected function serverName(): Attribute
    {
        return Attribute::get(fn () => $this->lookupServerName((int) ($this->getAttributes()['server_id'] ?? 0)));
    }

    protected function parentDomain(): Attribute
    {
        return Attribute::get(fn () => $this->lookupDomainName((int) ($this->getAttributes()['parent_domain_id'] ?? 0)));
    }

    /**
     * Port of validate_cron::run_time_format. Returns true when the value
     * is a valid cron time expression for the given field
     * (run_min/run_hour/run_mday/run_month/run_wday).
     */
    public static function isValidRunTime(string $fieldName, string $value): bool
    {
        $value = str_replace(' ', '', $value);

        // Allowed characters 0-9 , - / * — and separators never adjacent.
        if (! preg_match("'^[0-9\-\,\/\*]+$'", $value)) {
            return false;
        }
        if (preg_match("'[\-\,\/][\-\,\/]'", $value)) {
            return false;
        }

        [$minEntry, $maxEntry] = match ($fieldName) {
            'run_min' => [0, 59],
            'run_hour' => [0, 23],
            'run_mday' => [1, 31],
            'run_month' => [1, 12],
            'run_wday' => [0, 7],
            default => [0, 0],
        };

        if ($maxEntry === 0) {
            return false;
        }

        foreach (explode(',', $value) as $entry) {
            // x | x-y | x/y | x-y/z | */x  (combined legacy regex)
            if (! preg_match("'^(((\d+)(\-(\d+))?)|\*)(\/([1-9]\d*))?$'", $entry, $matches)) {
                return false;
            }

            if ($matches[1] !== '*') {
                if ((int) $matches[3] < $minEntry || (int) $matches[3] > $maxEntry) {
                    return false;
                }
                if (! empty($matches[4]) && ((int) $matches[5] < $minEntry || (int) $matches[5] > $maxEntry || (int) $matches[5] <= (int) $matches[3])) {
                    return false;
                }
            }

            if (! empty($matches[6]) && ((int) $matches[7] < 2 || (int) $matches[7] > $maxEntry - 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Port of validate_cron::run_month_format — like run_time_format but
     * additionally accepting the literal '@reboot'.
     */
    public static function isValidRunMonth(string $value): bool
    {
        if ($value === '@reboot') {
            return true;
        }

        return self::isValidRunTime('run_month', $value);
    }

    /**
     * Port of validate_cron::command_format. URL commands must parse with
     * scheme http/https and a hostname (after substituting {DOMAIN} with
     * the parent domain); backslashes in URLs and CR/LF/NUL anywhere are
     * rejected.
     */
    public static function isValidCommand(string $command, ?string $parentDomain): bool
    {
        if (preg_match("'^(\w+):\/\/'", $command)) {
            $checkValue = $command;

            if (str_contains($checkValue, '{DOMAIN}') && $parentDomain !== null) {
                $checkValue = strtr($checkValue, ['{DOMAIN}' => $parentDomain]);
            }

            $parsed = parse_url($checkValue);

            if ($parsed === false) {
                return false;
            }
            if (($parsed['scheme'] ?? '') !== 'http' && ($parsed['scheme'] ?? '') !== 'https') {
                return false;
            }
            if (! preg_match("'^([a-z0-9][a-z0-9\-]{0,62}\.)+([A-Za-z0-9\-]{2,63})$'i", $parsed['host'] ?? '')) {
                return false;
            }
            if (str_contains($checkValue, '\\')) {
                return false;
            }
        }

        return ! str_contains($command, "\n")
            && ! str_contains($command, "\r")
            && ! str_contains($command, chr(0));
    }
}
