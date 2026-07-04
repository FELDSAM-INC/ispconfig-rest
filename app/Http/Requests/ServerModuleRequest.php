<?php

namespace App\Http\Requests;

use App\Models\Server;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for server-module writes (api/modules/server/*.yaml):
 *
 *  - the y/n flag columns of the child tables are modeled as booleans by
 *    the schemas (x-db-format: y/n) — input accepts booleans as well as
 *    legacy 'y'/'n' strings, YesNoBoolean stores the native enum;
 *  - `server_id` always comes from the {id} path parameter; a request body
 *    value that differs is rejected with 422 (legacy
 *    server_ip_edit.php / firewall_edit.php ::onBeforeUpdate);
 *  - STRIPTAGS/STRIPNL legacy save filters for the fields that declare
 *    them (server_name, server_php name/path fields).
 */
abstract class ServerModuleRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware; per-record
     * sys_perm_* enforcement is out of scope (spec 007 assumption).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validated data ready for Model::fill().
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        // server_id is never mass assigned — it comes from the path.
        unset($data['server_id']);

        return $data;
    }

    /**
     * The route-bound parent server ({server} path parameter).
     */
    protected function routeServer(): ?Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : null;
    }

    /**
     * Accept legacy 'y'/'n' strings for boolean-modeled flags (the
     * YesNoBoolean pattern used by every module).
     *
     * @param  array<int, string>  $flags
     */
    protected function normalizeYesNoFlags(array $flags): void
    {
        $input = [];

        foreach ($flags as $flag) {
            if ($this->has($flag) && is_string($this->input($flag))) {
                $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value === null) {
                    $value = match (strtolower($this->input($flag))) {
                        'y' => true,
                        'n' => false,
                        default => $this->input($flag), // left invalid -> boolean rule fails
                    };
                }

                $input[$flag] = $value;
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * Legacy STRIPTAGS + STRIPNL save filters.
     *
     * @param  array<int, string>  $fields
     */
    protected function stripTagsAndNewlines(array $fields): void
    {
        $input = [];

        foreach ($fields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $input[$field] = str_replace(["\r", "\n"], '', strip_tags($this->input($field)));
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * Reject a body server_id that differs from the {id} path parameter
     * (immutable / path-sourced, 422 per the contract).
     */
    protected function serverIdMatchesPathRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $server = $this->routeServer();

            if ($server !== null && (int) $value !== (int) $server->getKey()) {
                $fail('The server_id is taken from the URL path and cannot be changed.');
            }
        };
    }

    /**
     * Legacy validate_server::check_server_ip — the address must validate
     * for the effective ip_type (FILTER_VALIDATE_IP with the matching
     * flag).
     *
     * @param  Closure(): string  $effectiveType  resolves the effective ip_type
     */
    protected function ipMatchesTypeRule(Closure $effectiveType): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($effectiveType): void {
            $type = $effectiveType();

            $flag = match ($type) {
                'IPv4' => FILTER_FLAG_IPV4,
                'IPv6' => FILTER_FLAG_IPV6,
                default => null,
            };

            if ($flag === null || ! is_string($value) || filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
                $fail("The :attribute must be a valid {$type} address.");
            }
        };
    }
}
