<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailContentFilter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/content-filters writes (contract:
 * api/modules/mail/content-filters.yaml; legacy:
 * source_code/interface/web/mail/form/mail_content_filter.tform.php).
 */
abstract class MailContentFilterRequest extends FormRequest
{
    use NormalizesMailInput;

    public const TYPES = ['header', 'body', 'mime_header', 'nested_header'];

    public const ACTIONS = ['DISCARD', 'DUNNO', 'FILTER', 'HOLD', 'IGNORE', 'PREPEND', 'REDIRECT', 'REPLACE', 'REJECT', 'WARN'];

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
        $data = $this->validated();

        if (array_key_exists('data', $data) && $data['data'] === null) {
            $data['data'] = '';
        }

        return $data;
    }

    /**
     * The route-bound record being updated (null on store).
     */
    protected function currentContentFilter(): ?MailContentFilter
    {
        $record = $this->route('mailContentFilter');

        return $record instanceof MailContentFilter ? $record : null;
    }
}
