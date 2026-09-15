<?php

namespace App\Http\Requests\Concerns;

use App\Support\IspContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Spec 026: a spam filter level must be 0 (inherit / no policy) or a
 * spamfilter_policy the acting key can read — the options of the legacy
 * policy select (mail_user_edit.php:101, mail_domain_edit.php:195,
 * getAuthSQL('r')). An unreadable policy fails exactly like a nonexistent
 * one (spec 024); admin scopes see every policy.
 */
trait ValidatesSpamfilterPolicy
{
    /**
     * @return array<int, mixed>
     */
    protected function spamfilterPolicyRules(): array
    {
        return [
            'sometimes',
            'bail',
            'integer',
            'min:0',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ((int) $value === 0) {
                    return;
                }

                $readable = app(IspContext::class)->authScope()
                    ->applyReadPredicate(DB::table('spamfilter_policy'), 'r')
                    ->where('id', (int) $value)
                    ->exists();

                if (! $readable) {
                    $fail('The selected :attribute is invalid.');
                }
            },
        ];
    }

    /**
     * The validated level, or null when the request does not set it.
     */
    protected function validatedPolicy(string $field): ?int
    {
        $data = $this->validated();

        return array_key_exists($field, $data) ? (int) $data[$field] : null;
    }
}
