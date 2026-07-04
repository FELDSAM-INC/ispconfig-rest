<?php

namespace App\Http\Requests;

/**
 * PUT /servers/{id}/firewall (api/modules/server/firewall.yaml) — the
 * singleton upsert (201 create / 200 update, ServerFirewallController).
 *
 * Mirrors legacy firewall.tform.php: tcp_port/udp_port must match
 * /^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/ (comma-separated
 * ports and low:high ranges; the empty string is valid and means "no
 * ports" — Laravel skips non-implicit rules on '', which yields exactly
 * that semantic). server_id is immutable/path-sourced (422 on mismatch,
 * legacy firewall_edit.php::onBeforeUpdate).
 */
class PutServerFirewallRequest extends ServerModuleRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeYesNoFlags(['active']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'server_id' => ['sometimes', 'integer', $this->serverIdMatchesPathRule()],
            'tcp_port' => ['sometimes', 'nullable', 'string', 'regex:/^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/'],
            'udp_port' => ['sometimes', 'nullable', 'string', 'regex:/^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Null port lists map to the empty string ("no ports") — the columns
     * are TEXT and legacy stores ''.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        foreach (['tcp_port', 'udp_port'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        return $data;
    }
}
