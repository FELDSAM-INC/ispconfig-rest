<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Http\Requests\Concerns\ValidatesPosixEre;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared behavior for mail user filter writes (contract:
 * api/modules/mail/user-filters.yaml; legacy:
 * source_code/interface/web/mail/form/mail_user_filter.tform.php).
 *
 * The enums are the values legacy STORES (Subject/From/... — not UI language
 * keys, C-2 resolution); target uses the legacy unicode pattern.
 *
 * `op=regex` searchterms must compile as POSIX ERE (spec 013 FR-019) — a
 * documented stricter-than-legacy deviation: 3.3.1p1 has no compile check
 * (mail_user_filter.tform.php:99-113), but one invalid pattern makes
 * Dovecot reject the mailbox's entire custom_mailfilter sieve script.
 */
abstract class MailUserFilterRequest extends FormRequest
{
    use NormalizesMailInput;
    use ValidatesPosixEre;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active']);

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'rulename' => [$presence, 'string', 'max:64'],
            'source' => [$presence, 'string', Rule::in(['Subject', 'From', 'To', 'List-Id', 'Header', 'Size'])],
            'op' => [$presence, 'string', Rule::in(['contains', 'is', 'begins', 'ends', 'regex', 'localpart', 'domain']), $this->regexOpCompileRule()],
            'searchterm' => [$presence, 'string', 'max:255', $this->regexOpCompileRule()],
            'action' => [$presence, 'string', Rule::in(['move', 'delete', 'keep', 'reject'])],
            'target' => ['sometimes', 'nullable', 'string', 'max:100', "regex:/^[\\p{Latin}0-9\\.\\'\\-\\_\\ \\&\\/]{0,100}$/u"],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * FR-019: when the effective op is 'regex', the effective searchterm
     * must compile as a POSIX ERE (no PCRE-only inline flags — the legacy
     * UI hint promises POSIX ERE). Attached to both `op` and `searchterm`
     * so switching a stored filter's op to regex re-validates the stored
     * pattern, while updates that touch neither field stay tolerant of
     * stored garbage (FR-012, deactivation recovery).
     */
    protected function regexOpCompileRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $op = $this->has('op') ? $this->input('op') : $this->storedFilterValue('op');

            if (! is_string($op) || strtolower($op) !== 'regex') {
                return;
            }

            $searchterm = $this->has('searchterm') ? $this->input('searchterm') : $this->storedFilterValue('searchterm');

            if (! is_string($searchterm) || $searchterm === '') {
                return;
            }

            if (($error = $this->posixEreError($searchterm)) !== null) {
                $fail("The searchterm must be a valid POSIX extended regular expression (ERE) when op is 'regex': {$error}. An invalid pattern would disable the mailbox's entire sieve filter script.");
            }
        };
    }

    /**
     * A stored field value of the route-bound filter (null on store — the
     * update request overrides this with the persisted row).
     */
    protected function storedFilterValue(string $field): mixed
    {
        return null;
    }
}
