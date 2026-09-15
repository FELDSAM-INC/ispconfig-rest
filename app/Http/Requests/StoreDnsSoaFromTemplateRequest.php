<?php

namespace App\Http\Requests;

use App\Exceptions\InvalidZoneTemplateException;
use App\Http\Requests\Concerns\ResolvesAssignedServer;
use App\Models\DnsTemplate;
use App\Services\DnsZoneWizardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /dns/soa/from-template (api/modules/dns/soa.yaml; spec 029).
 *
 * The template decides which values the wizard needs: every placeholder token
 * it declares in `fields` must be supplied, and a value the template does not
 * declare is refused. Legacy skips a value that was not posted at all and then
 * writes the literal `{IP}` into the zone (dns_wizard.inc.php) — refused here
 * instead (spec 029 deviation 2).
 *
 * Normalization and the value regexes are the legacy ones; server selection
 * follows spec 016 exactly as StoreDnsSoaRequest does.
 */
class StoreDnsSoaFromTemplateRequest extends FormRequest
{
    use ResolvesAssignedServer;

    /** Placeholder token => request field. */
    public const PLACEHOLDER_FIELDS = [
        'DOMAIN' => 'domain',
        'IP' => 'ip',
        'IPV6' => 'ipv6',
        'NS1' => 'ns1',
        'NS2' => 'ns2',
        'EMAIL' => 'email',
        'DKIM' => 'dkim',
        'DNSSEC' => 'dnssec',
    ];

    /** Fields that are flags, not placeholder values. */
    public const FLAG_FIELDS = ['dkim', 'dnssec'];

    protected ?DnsTemplate $resolvedTemplate = null;

    protected bool $templateResolved = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Legacy filters: IDN-encode and lower-case the host-ish values, and give
     * the request the account's assigned DNS server when none was named.
     */
    protected function prepareForValidation(): void
    {
        $input = [];

        foreach (['domain', 'ns1', 'ns2'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $input[$field] = $this->idnLower(trim($this->input($field)));
            }
        }

        if ($this->has('email') && is_string($this->input('email'))) {
            $email = trim($this->input('email'));

            if (str_contains($email, '@')) {
                [$local, $domain] = explode('@', $email, 2);
                $email = $local.'@'.$this->idnLower($domain);
            }

            $input['email'] = strtolower($email);
        }

        if ($input !== []) {
            $this->merge($input);
        }

        $this->mergeAssignedServerDefault('dns');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $declared = $this->declaredFields();

        $rules = [
            'template_id' => [
                'required',
                'integer',
                // Only templates the wizard offers can be expanded; legacy
                // loads any posted id, visible or not (deviation 3).
                Rule::exists('dns_template', 'template_id')->where('visible', 'Y'),
            ],
            // Legacy dns_wizard.inc.php domain regex.
            'domain' => ['required', 'string', 'max:255', 'regex:/^[\w\.\-]{1,64}\.[a-zA-Z0-9\-]{2,63}$/'],
            'client_id' => ['sometimes', 'integer', Rule::exists('client', 'client_id')],
            'server_id' => $this->assignedServerRules('dns', [
                'required',
                'integer',
                Rule::exists('server', 'server_id')
                    ->where('dns_server', 1)
                    ->where('mirror_server_id', 0),
            ]),
        ];

        foreach ($this->placeholderRules() as $field => $fieldRules) {
            if (! in_array($field, $declared, true)) {
                // The template does not ask for this value.
                $rules[$field] = ['prohibited'];

                continue;
            }

            array_unshift($fieldRules, in_array($field, self::FLAG_FIELDS, true) ? 'sometimes' : 'required');
            $rules[$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->assignedServerMessages('dns'), [
            'template_id.exists' => 'The selected zone template does not exist.',
        ]);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $template = $this->template();

                if ($template === null) {
                    return;
                }

                // A template the wizard cannot expand is a 422 on the
                // template, not a 500 (deviation 4).
                try {
                    app(DnsZoneWizardService::class)->expand($template, $this->placeholderValues());
                } catch (InvalidZoneTemplateException $e) {
                    $validator->errors()->add('template_id', $e->getMessage());
                }
            },
        ];
    }

    /**
     * The validated, visible template (null while `template_id` is invalid).
     */
    public function template(): ?DnsTemplate
    {
        if ($this->templateResolved) {
            return $this->resolvedTemplate;
        }

        $this->templateResolved = true;
        $id = $this->input('template_id');

        if (! is_numeric($id)) {
            return $this->resolvedTemplate = null;
        }

        return $this->resolvedTemplate = DnsTemplate::query()
            ->where('template_id', (int) $id)
            ->where('visible', 'Y')
            ->first();
    }

    /**
     * Placeholder values and flags for the wizard service.
     *
     * @return array<string, mixed>
     */
    public function placeholderValues(): array
    {
        $values = [];

        foreach (self::PLACEHOLDER_FIELDS as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $values[$field] = in_array($field, self::FLAG_FIELDS, true)
                ? $this->boolean($field)
                : (string) $this->input($field);
        }

        return $values;
    }

    /**
     * The request fields the template declares (`dns_template.fields`).
     *
     * @return array<int, string>
     */
    protected function declaredFields(): array
    {
        $template = $this->template();

        if ($template === null) {
            return [];
        }

        $fields = [];

        foreach (explode(',', (string) $template->fields) as $token) {
            $token = strtoupper(trim($token));

            if (isset(self::PLACEHOLDER_FIELDS[$token])) {
                $fields[] = self::PLACEHOLDER_FIELDS[$token];
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Value rules per placeholder field (legacy regexes; the flags are
     * booleans). `domain` is always required and handled in rules().
     *
     * @return array<string, array<int, mixed>>
     */
    protected function placeholderRules(): array
    {
        $host = ['string', 'max:255', 'regex:/^[\w\.\-]{1,64}\.[a-zA-Z0-9]{2,63}$/'];

        return [
            'ip' => ['string', 'ipv4'],
            'ipv6' => ['string', 'ipv6'],
            'ns1' => $host,
            'ns2' => $host,
            'email' => ['string', 'email', 'max:255'],
            'dkim' => ['boolean'],
            'dnssec' => ['boolean'],
        ];
    }

    protected function idnLower(string $value): string
    {
        if ($value !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value);

            if ($ascii !== false) {
                $value = $ascii;
            }
        }

        return strtolower($value);
    }
}
