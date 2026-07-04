<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailUser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /mail/users/{id}/autoresponder (api/modules/mail/user-autoresponder.yaml).
 *
 * Legacy parity (mail_user.tform.php autoresponder tab + mail_user_edit.php):
 * ISDATETIME with allowempty on both dates, validate_autoresponder::end_date
 * (end > start), dates cleared when the autoresponder is disabled (handled
 * in the controller).
 */
class UpdateMailUserAutoresponderRequest extends FormRequest
{
    use NormalizesMailInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['autoresponder']);

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'autoresponder' => ['required', 'boolean'],
            'autoresponder_start_date' => ['sometimes', 'nullable', 'date'],
            'autoresponder_end_date' => [
                'sometimes',
                'nullable',
                'date',
                $this->endAfterStartRule(),
            ],
            'autoresponder_subject' => ['sometimes', 'string', 'max:255'],
            'autoresponder_text' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Validated data with the dates normalized to the DATETIME column format.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['autoresponder_start_date', 'autoresponder_end_date'] as $field) {
            if (array_key_exists($field, $data) && ! blank($data[$field])) {
                $data[$field] = date('Y-m-d H:i:s', strtotime((string) $data[$field]));
            }
        }

        return $data;
    }

    /**
     * Legacy validate_autoresponder::end_date — the end date must be later
     * than the start date. Compares against the request's start date, or the
     * stored one on a partial PUT.
     */
    protected function endAfterStartRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            $start = $this->input('autoresponder_start_date');

            if (blank($start)) {
                $user = $this->route('mailUser');
                $start = $user instanceof MailUser
                    ? ($user->getRawOriginal()['autoresponder_start_date'] ?? null)
                    : null;
            }

            if (blank($start)) {
                return; // no start date = starts immediately, any end is fine
            }

            if (strtotime((string) $value) <= strtotime((string) $start)) {
                $fail('The autoresponder end date must be later than the start date.');
            }
        };
    }
}
