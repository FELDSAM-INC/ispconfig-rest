<?php

namespace App\Extensions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Points where extensions add to core resources (spec 058). Keys are model classes (`WebDomain::class`, …), not
 * tables: `WebChildDomain` rows share `web_domain` but are a separate resource. Callbacks for a model also apply to
 * its subclasses (a `ClientReseller` is a `Client`). Core calls the matching method at each point, extensions register
 * callbacks from their service providers. Without registrations every call is a no-op, so core behaves as a plain
 * ISPConfig API.
 */
final class Hooks
{
    /** @var array<string, array<string, array<int, Closure>>> kind => model class => callbacks */
    private array $callbacks = [];

    /** @var array<string, array<int, string>> model class => boolean payload fields */
    private array $booleans = [];

    // ---------------------------------------------------------------- registration

    /** Extra JSON attributes of a model: fn (Model $model): array. */
    public function attributes(string $model, Closure $resolver): void
    {
        $this->callbacks['attributes'][$model][] = $resolver;
    }

    /** Filter of one attribute's value: fn (mixed $value, Model $model): mixed. */
    public function attribute(string $model, string $attribute, Closure $filter): void
    {
        $this->callbacks['attribute'][$model.'.'.$attribute][] = $filter;
    }

    /** Extra keys of a resource's show response: fn (Model $model): array. */
    public function detail(string $model, Closure $resolver): void
    {
        $this->callbacks['detail'][$model][] = $resolver;
    }

    /**
     * Payload fields: fn (Request $request, string $action): array of validation rules ($action 'create' or
     * 'update'). The rules' top-level keys are the extension's fields; $booleans lists those that accept y/n strings.
     *
     * @param  array<int, string>  $booleans
     */
    public function fields(string $model, Closure $rules, array $booleans = []): void
    {
        $this->callbacks['fields'][$model][] = $rules;
        $this->booleans[$model] = array_values(array_unique(array_merge($this->booleans[$model] ?? [], $booleans)));
    }

    /** Refuse a write request with 403: fn (Request $request, string $action): bool (false denies). */
    public function authorize(string $model, Closure $callback): void
    {
        $this->callbacks['authorize'][$model][] = $callback;
    }

    /** Normalisation of the validated extension fields sent: fn (array $fields, Request $request): array. */
    public function prepare(string $model, Closure $prepare): void
    {
        $this->callbacks['prepare'][$model][] = $prepare;
    }

    /** Check before the write transaction: fn (?Model $current, array $fields, array $payload): void. */
    public function preflight(string $model, Closure $callback): void
    {
        $this->callbacks['preflight'][$model][] = $callback;
    }

    /** Before the model is saved, inside the transaction: fn (Model $model, array $fields, string $action): void. */
    public function saving(string $model, Closure $callback): void
    {
        $this->callbacks['saving'][$model][] = $callback;
    }

    /** After the model is saved (on create with its new key), inside the transaction: fn (Model $model, array $fields, string $action): void. */
    public function saved(string $model, Closure $callback): void
    {
        $this->callbacks['saved'][$model][] = $callback;
    }

    /** Refuse a write by throwing: fn (Model $model, string $action): void ($action 'update' or 'delete'). */
    public function guard(string $model, Closure $callback): void
    {
        $this->callbacks['guard'][$model][] = $callback;
    }

    /** After a record is removed (also through the datalog, e.g. child websites): fn (int $id): void. */
    public function deleted(string $model, Closure $callback): void
    {
        $this->callbacks['deleted'][$model][] = $callback;
    }

    /** A named filter for a rare point: fn (mixed $value, mixed ...$arguments): mixed. */
    public function filter(string $name, Closure $callback): void
    {
        $this->callbacks['filter'][$name][] = $callback;
    }

    // ---------------------------------------------------------------- invocation by core

    /** @return array<string, mixed> */
    public function attributesFor(Model $model): array
    {
        $attributes = [];
        foreach ($this->matching('attributes', $model) as $resolver) {
            $attributes = array_merge($attributes, $resolver($model));
        }

        return $attributes;
    }

    public function filterAttribute(Model $model, string $attribute, mixed $value): mixed
    {
        foreach ($this->matching('attribute', $model, '.'.$attribute) as $filter) {
            $value = $filter($value, $model);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function detailFor(Model $model): array
    {
        $detail = [];
        foreach ($this->matching('detail', $model) as $resolver) {
            $detail = array_merge($detail, $resolver($model));
        }

        return $detail;
    }

    /** @return array<string, mixed> validation rules of the extension fields */
    public function rules(string $model, Request $request, string $action): array
    {
        $rules = [];
        foreach ($this->callbacks['fields'][$model] ?? [] as $resolver) {
            $rules = array_merge($rules, $resolver($request, $action));
        }

        return $rules;
    }

    public function authorizes(string $model, Request $request, string $action): bool
    {
        foreach ($this->callbacks['authorize'][$model] ?? [] as $callback) {
            if ($callback($request, $action) === false) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    public function booleanFields(string $model): array
    {
        return $this->booleans[$model] ?? [];
    }

    /**
     * Split a validated payload into the extension fields (normalised) and the core payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [extension fields, core payload]
     */
    public function split(string $model, array $payload, Request $request, string $action): array
    {
        $keys = [];
        foreach (array_keys($this->rules($model, $request, $action)) as $rule) {
            $keys[strtok($rule, '.')] = true;
        }

        $fields = array_intersect_key($payload, $keys);
        foreach ($fields === [] ? [] : $this->callbacks['prepare'][$model] ?? [] as $prepare) {
            $fields = $prepare($fields, $request);
        }

        return [$fields, array_diff_key($payload, $keys)];
    }

    /** @param  array<string, mixed>  $fields  @param  array<string, mixed>  $payload */
    public function runPreflight(string $model, ?Model $current, array $fields, array $payload): void
    {
        foreach ($this->callbacks['preflight'][$model] ?? [] as $callback) {
            $callback($current, $fields, $payload);
        }
    }

    /** @param  array<string, mixed>  $fields */
    public function runSaving(Model $model, array $fields, string $action): void
    {
        foreach ($this->matching('saving', $model) as $callback) {
            $callback($model, $fields, $action);
        }
    }

    /** @param  array<string, mixed>  $fields */
    public function runSaved(Model $model, array $fields, string $action): void
    {
        foreach ($this->matching('saved', $model) as $callback) {
            $callback($model, $fields, $action);
        }
    }

    public function runGuards(Model $model, string $action): void
    {
        foreach ($this->matching('guard', $model) as $callback) {
            $callback($model, $action);
        }
    }

    public function runDeleted(string $model, int $id): void
    {
        foreach ($this->callbacks['deleted'][$model] ?? [] as $callback) {
            $callback($id);
        }
    }

    public function applyFilter(string $name, mixed $value, mixed ...$arguments): mixed
    {
        foreach ($this->callbacks['filter'][$name] ?? [] as $callback) {
            $value = $callback($value, ...$arguments);
        }

        return $value;
    }

    /**
     * Callbacks of a kind registered for the model's class or one of its parents.
     *
     * @return array<int, Closure>
     */
    private function matching(string $kind, Model $model, string $suffix = ''): array
    {
        $callbacks = [];
        foreach ([$model::class, ...array_values(class_parents($model))] as $class) {
            array_push($callbacks, ...($this->callbacks[$kind][$class.$suffix] ?? []));
        }

        return $callbacks;
    }
}
