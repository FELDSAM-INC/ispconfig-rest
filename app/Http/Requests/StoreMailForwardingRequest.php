<?php

namespace App\Http\Requests;

use App\Models\MailForwarding;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /mail/forwards (api/modules/mail/forwards.yaml).
 */
class StoreMailForwardingRequest extends MailForwardingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(MailForwarding::FORWARD_TYPES)],
            'source' => ['required', 'string', 'max:255', $this->sourceFormatRule(), $this->noActiveMailboxRule()],
            'destination' => ['required', 'string', $this->destinationListRule()],
            'active' => ['sometimes', 'boolean'],
            'allow_send_as' => ['sometimes', 'boolean'],
            'greylisting' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * source + type uniqueness (FR-015, application-level like legacy's
     * UNIQUE validators).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $duplicate = DB::table('mail_forwarding')
                    ->where('source', (string) $this->input('source'))
                    ->where('type', (string) $this->input('type'))
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('source', 'A rule of this type with this source already exists.');
                }
            },
        ];
    }

    /**
     * Per-type source format: full email for forward/alias, the legacy
     * catchall regex for catchall (mail_domain_catchall.tform.php).
     */
    protected function sourceFormatRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $type = (string) $this->input('type');

            if ($type === 'catchall') {
                if (! is_string($value) || ! preg_match(self::CATCHALL_REGEX, $value)) {
                    $fail('A catchall source must be in @domain.tld form.');
                }

                return;
            }

            if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $fail('The source must be a valid email address.');
            }
        };
    }

    /**
     * Legacy duplicate_mailbox_txt check: no active mailbox may own the
     * source address (mail_forward_edit.php:144-146, FR-017).
     */
    protected function noActiveMailboxRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $collision = DB::table('mail_user')
                ->where('postfix', 'y')
                ->where('email', (string) $value)
                ->exists();

            if ($collision) {
                $fail('An active mailbox with this address already exists.');
            }
        };
    }
}
