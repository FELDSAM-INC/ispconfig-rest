<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\SpamfilterWBList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/spamfilter/wblist writes (contract:
 * api/modules/mail/spamfilter-wblist.yaml; legacy:
 * source_code/interface/web/mail/form/spamfilter_blacklist.tform.php +
 * spamfilter_whitelist.tform.php).
 *
 * `wb` is an UPPERCASE W/B string stored with exact casing (FR-005);
 * a non-zero rid must reference an existing spamfilter user (404 per the
 * YAML, checked in the controller). rid=0 is accepted but Rspamd-inert
 * (C-10) — use /mail/access-rules for global rules.
 */
abstract class SpamfilterWBListRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active']);

        if ($this->has('email') && is_string($this->input('email')) && $this->input('email') !== '') {
            $input['email'] = $this->idnLowerEmail($this->input('email'));
        }

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
     * The route-bound record being updated (null on store).
     */
    protected function currentWBList(): ?SpamfilterWBList
    {
        $record = $this->route('spamfilterWblist');

        return $record instanceof SpamfilterWBList ? $record : null;
    }
}
