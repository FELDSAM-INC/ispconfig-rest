<?php

namespace App\Http\Requests;

use App\Models\ClientTemplate;
use Closure;

/**
 * PUT /clients/templates/{template_id} (api/modules/client/templates.yaml).
 *
 * template_type is immutable after creation (legacy
 * client_template_edit.php::onBeforeUpdate: "The template type can not be
 * changed."). Sending the current value is accepted.
 */
class UpdateClientTemplateRequest extends ClientTemplateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        $rules['template_type'][] = $this->immutableTypeRule();

        return $rules;
    }

    protected function immutableTypeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $template = $this->route('template');

            if ($template instanceof ClientTemplate
                && $value !== $template->getAttributes()['template_type']) {
                $fail('The template type can not be changed.');
            }
        };
    }
}
