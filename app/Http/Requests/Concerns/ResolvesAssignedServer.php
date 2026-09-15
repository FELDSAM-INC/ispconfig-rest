<?php

namespace App\Http\Requests\Concerns;

use App\Services\ServerAssignmentService;
use App\Support\AuthScope;
use App\Support\IspContext;
use App\Support\ProblemType;
use App\Support\ProblemTypeCollector;
use Closure;

/**
 * Server selection for client and reseller keys (spec 016, research.md R1/R4).
 *
 * Non-admin requests get the account's default server merged into the input
 * before validation (so every downstream per-server check sees it) and a
 * rule that accepts only servers assigned to the account. Unassigned,
 * nonexistent, mirror and wrong-role servers all fail with the same message,
 * so server ids cannot be probed. Admin keys keep their existing rules.
 */
trait ResolvesAssignedServer
{
    public const SERVER_NOT_AVAILABLE = 'The selected server is not available for this account.';

    public const SERVER_IMMUTABLE = 'The server cannot be changed after creation.';

    public const NO_SLAVE_DNS_SERVER = 'No secondary DNS server is assigned to this account.';

    private ?ServerAssignmentService $serverAssignment = null;

    protected function serverScope(): AuthScope
    {
        return app(IspContext::class)->authScope();
    }

    protected function serverAssignment(): ServerAssignmentService
    {
        return $this->serverAssignment ??= app(ServerAssignmentService::class);
    }

    protected function usesAssignedServers(): bool
    {
        return ! $this->serverScope()->isAdmin;
    }

    /**
     * Merge the account's default server when a non-admin request omits
     * server_id (call from prepareForValidation after base normalization).
     */
    protected function mergeAssignedServerDefault(string $service): void
    {
        if (! $this->usesAssignedServers() || $this->has('server_id')) {
            return;
        }

        $default = $this->serverAssignment()->defaultServerId($this->serverScope(), $service);

        if ($default !== null) {
            $this->merge(['server_id' => $default]);
        } else {
            // server_id stays missing, so `required` reports "no server".
            $this->tagServerNotAssigned();
        }
    }

    /**
     * Merge the account's secondary DNS server when a non-admin request
     * omits server_id.
     */
    protected function mergeSlaveDnsServerDefault(): void
    {
        if (! $this->usesAssignedServers() || $this->has('server_id')) {
            return;
        }

        $slave = $this->serverAssignment()->slaveDnsServerId($this->serverScope());

        if ($slave !== null) {
            $this->merge(['server_id' => $slave]);
        } else {
            $this->tagServerNotAssigned();
        }
    }

    /**
     * server_id rules: the caller's admin rules unchanged for admin keys,
     * the assignment check for client and reseller keys.
     *
     * @param  array<int, mixed>  $adminRules
     * @return array<int, mixed>
     */
    protected function assignedServerRules(string $service, array $adminRules): array
    {
        if (! $this->usesAssignedServers()) {
            return $adminRules;
        }

        return ['bail', 'required', 'integer', 'min:1', $this->assignedServerRule($service)];
    }

    /**
     * server_id rules for secondary DNS zones.
     *
     * @param  array<int, mixed>  $adminRules
     * @return array<int, mixed>
     */
    protected function slaveDnsServerRules(array $adminRules): array
    {
        if (! $this->usesAssignedServers()) {
            return $adminRules;
        }

        return ['bail', 'required', 'integer', 'min:1', function (string $attribute, mixed $value, Closure $fail): void {
            $slave = $this->serverAssignment()->slaveDnsServerId($this->serverScope());

            if ($slave === null) {
                $fail(self::NO_SLAVE_DNS_SERVER);
                $this->tagServerNotAssigned($attribute);
            } elseif ((int) $value !== $slave) {
                $fail(self::SERVER_NOT_AVAILABLE);
                $this->tagServerNotAssigned($attribute);
            }
        }];
    }

    /**
     * server_id rules on updates of server-bound records: non-admin keys may
     * only re-send the stored value.
     *
     * @param  Closure(): (int|null)  $currentValue
     * @param  array<int, mixed>  $adminRules
     * @return array<int, mixed>
     */
    protected function immutableServerRules(Closure $currentValue, array $adminRules): array
    {
        if (! $this->usesAssignedServers()) {
            return $adminRules;
        }

        return ['sometimes', 'bail', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($currentValue): void {
            $current = $currentValue();

            if ($current !== null && (int) $value !== $current) {
                $fail(self::SERVER_IMMUTABLE);
            }
        }];
    }

    /**
     * Validation messages for the non-admin required case ("no server
     * assigned") — merge into the request's messages().
     *
     * @return array<string, string>
     */
    protected function assignedServerMessages(string $service): array
    {
        if (! $this->usesAssignedServers()) {
            return [];
        }

        return ['server_id.required' => $this->noServerMessage($service)];
    }

    /**
     * @return array<string, string>
     */
    protected function slaveDnsServerMessages(): array
    {
        if (! $this->usesAssignedServers()) {
            return [];
        }

        return ['server_id.required' => self::NO_SLAVE_DNS_SERVER];
    }

    protected function assignedServerRule(string $service): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($service): void {
            $assigned = $this->serverAssignment()->assignedServerIds($this->serverScope(), $service);

            if ($assigned === []) {
                $fail($this->noServerMessage($service));
                $this->tagServerNotAssigned($attribute);
            } elseif (! in_array((int) $value, $assigned, true)) {
                $fail(self::SERVER_NOT_AVAILABLE);
                $this->tagServerNotAssigned($attribute);
            }
        };
    }

    protected function noServerMessage(string $service): string
    {
        return 'No '.$this->serverAssignment()->serviceLabel($service).' server is assigned to this account.';
    }

    /**
     * Type the server field's error as `server-not-assigned` (spec 023).
     */
    protected function tagServerNotAssigned(string $attribute = 'server_id'): void
    {
        app(ProblemTypeCollector::class)->tag($attribute, ProblemType::SERVER_NOT_ASSIGNED);
    }
}
