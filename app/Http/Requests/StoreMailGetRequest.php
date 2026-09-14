<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesAssignedServer;
use Closure;
use Illuminate\Validation\Rule;

/**
 * POST /mail/fetchmail (api/modules/mail/fetchmail.yaml).
 *
 * Server selection (spec 016): for client and reseller keys the fetchmail
 * job always runs on the destination mailbox's server (legacy
 * mail_get_edit.php:97); it is merged when omitted and any other value is
 * rejected. Nothing is merged when the destination is missing or unreadable
 * (the destination rule reports that).
 */
class StoreMailGetRequest extends MailGetRequest
{
    use ResolvesAssignedServer;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->usesAssignedServers() || $this->has('server_id')) {
            return;
        }

        $serverId = $this->destinationServerId();

        if ($serverId !== null) {
            $this->merge(['server_id' => $serverId]);
        }
    }

    /**
     * Server of the readable destination mailbox, or null.
     */
    protected function destinationServerId(): ?int
    {
        $destination = $this->input('destination');

        if (! is_string($destination) || $destination === '') {
            return null;
        }

        $serverId = $this->readableMailboxQuery($destination)->value('server_id');

        return $serverId === null ? null : (int) $serverId;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => $this->usesAssignedServers()
                ? ['sometimes', 'bail', 'integer', $this->destinationServerRule()]
                : ['required', 'integer', $this->mailServerRule()],
            'type' => ['required', 'string', Rule::in(['pop3', 'imap', 'pop3ssl', 'imapssl'])],
            'source_server' => ['required', 'string', 'max:255', 'regex:'.self::SOURCE_SERVER_REGEX],
            'source_username' => ['required', 'string', 'max:255'],
            'source_password' => ['required', 'string', 'max:64'],
            'source_delete' => ['sometimes', 'boolean'],
            'source_read_all' => ['sometimes', 'boolean'],
            'destination' => ['required', 'string', 'max:255', 'email:rfc', $this->existingMailboxRule()],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function destinationServerRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $serverId = $this->destinationServerId();

            if ($serverId !== null && (int) $value !== $serverId) {
                $fail(self::SERVER_NOT_AVAILABLE);
            }
        };
    }
}
