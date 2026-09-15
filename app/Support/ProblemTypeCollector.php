<?php

namespace App\Support;

/**
 * Field problem types of the current request (spec 023 research R3): rules
 * that refuse a field for a typed reason tag it here, and the 422 renderer
 * lists the tags of fields that ended up in the error bag as `error_types`.
 * A tag without a matching error is ignored. Scoped per request
 * (AppServiceProvider).
 */
class ProblemTypeCollector
{
    /** @var array<string, string> field => ProblemType name */
    protected array $types = [];

    public function tag(string $field, string $type): void
    {
        $this->types[$field] = $type;
    }

    /**
     * Type URIs of the tagged fields among $fields.
     *
     * @param  array<int, string>  $fields
     * @return array<string, string> field => type URI
     */
    public function uris(array $fields): array
    {
        $uris = [];

        foreach ($fields as $field) {
            if (isset($this->types[$field])) {
                $uris[$field] = ProblemType::uri($this->types[$field]);
            }
        }

        return $uris;
    }
}
