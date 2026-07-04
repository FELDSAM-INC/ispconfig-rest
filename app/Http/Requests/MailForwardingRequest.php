<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMailInput;
use App\Models\MailForwarding;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for /mail/forwards writes (contract:
 * api/modules/mail/forwards.yaml; legacy:
 * source_code/interface/web/mail/mail_forward_edit.php +
 * mail_forward/mail_alias/mail_domain_catchall tforms):
 *
 *  - source is IDN-encoded + lowercased; a full email for forward/alias,
 *    @domain.tld for catchall (legacy regex);
 *  - destination is split on /[,;\s]+/, each part validated as an email,
 *    stored re-joined with ', ' (FR-016);
 *  - the source must not equal an active mailbox address (FR-017);
 *  - source + type unique (application-level, FR-015).
 */
abstract class MailForwardingRequest extends FormRequest
{
    use NormalizesMailInput;

    public const CATCHALL_REGEX = '/^\@[\w\.\-]{1,255}\.[a-zA-Z\-]{2,63}$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeFlags(['active', 'allow_send_as', 'greylisting']);

        if ($this->has('source') && is_string($this->input('source')) && $this->input('source') !== '') {
            $source = trim($this->input('source'));

            $input['source'] = str_starts_with($source, '@')
                ? '@'.$this->idnLower(substr($source, 1))
                : $this->idnLowerEmail($source);
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    /**
     * Validated data with the destination normalized to the legacy ', '
     * joined form.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('destination', $data) && is_string($data['destination'])) {
            $targets = preg_split('/[,;\s]+/', trim($data['destination'])) ?: [];
            $data['destination'] = implode(', ', array_map(
                fn (string $target): string => $this->idnLowerEmail($target),
                array_filter($targets, fn (string $t): bool => $t !== '')
            ));
        }

        return $data;
    }

    /**
     * The route-bound record being updated (null on store).
     */
    protected function currentForwarding(): ?MailForwarding
    {
        $record = $this->route('mailForwarding');

        return $record instanceof MailForwarding ? $record : null;
    }

    /**
     * Legacy destination validation: split on /[,;\s]+/ and validate every
     * part with FILTER_VALIDATE_EMAIL (mail_forward_edit.php:130-141).
     */
    protected function destinationListRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                $fail('The destination must contain at least one email address.');

                return;
            }

            $targets = preg_split('/[,;\s]+/', trim($value)) ?: [];

            foreach ($targets as $target) {
                if ($target === '' || filter_var($this->idnLowerEmail($target), FILTER_VALIDATE_EMAIL) === false) {
                    $fail("The destination contains an invalid email address: '{$target}'.");

                    return;
                }
            }
        };
    }
}
