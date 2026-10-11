<?php

namespace App\Http\Requests\Concerns;

use App\Extensions\Hooks;
use Illuminate\Support\Facades\App;

/**
 * Payload fields that extensions add to a core resource (spec 058): their rules join the request's own (never
 * replacing a core rule), their authorization checks run after the request's, and extensionSplit() separates them
 * from the core payload.
 */
trait AcceptsExtensionFields
{
    /**
     * The table and action ('create' or 'update') whose extension fields this request accepts, or null for none.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function extensionResource(): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validationRules()
    {
        $rules = parent::validationRules();
        $resource = $this->extensionResource();

        return $resource === null ? $rules : $rules + App::make(Hooks::class)->rules($resource[0], $this, $resource[1]);
    }

    protected function passesAuthorization()
    {
        $resource = $this->extensionResource();

        return parent::passesAuthorization()
            && ($resource === null || App::make(Hooks::class)->authorizes($resource[0], $this, $resource[1]));
    }

    /**
     * Extension fields that accept y/n strings.
     *
     * @return array<int, string>
     */
    protected function extensionBooleanFields(): array
    {
        $resource = $this->extensionResource();

        return $resource === null ? [] : App::make(Hooks::class)->booleanFields($resource[0]);
    }

    /**
     * The validated payload split into the extension fields (normalised by the extensions) and the core payload.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function extensionSplit(): array
    {
        $resource = $this->extensionResource();

        return $resource === null
            ? [[], $this->payload()]
            : App::make(Hooks::class)->split($resource[0], $this->payload(), $this, $resource[1]);
    }
}
