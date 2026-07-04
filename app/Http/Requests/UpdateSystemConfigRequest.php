<?php

namespace App\Http\Requests;

use App\Services\SystemConfigService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /system/config (api/modules/system/system-config.yaml).
 *
 * The body is a SystemConfig object with any subset of the five section
 * objects; within each section every field is optional (absent key = leave
 * unchanged). All submitted sections are merged into the blob in ONE
 * read-merge-write, producing exactly one sys_datalog 'u' entry.
 */
class UpdateSystemConfigRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->service();
        $rules = [];

        foreach (SystemConfigService::SECTIONS as $section) {
            $rules[$section] = ['sometimes', 'array'];
            $rules += $service->rulesFor($section, $section.'.');
        }

        return $rules;
    }

    /**
     * Legacy SAVE filters before validation, applied per submitted section.
     */
    protected function prepareForValidation(): void
    {
        $service = $this->service();
        $input = $this->all();

        foreach (SystemConfigService::SECTIONS as $section) {
            if (isset($input[$section]) && is_array($input[$section])) {
                $input[$section] = $service->normalizeInput($section, $input[$section]);
            }
        }

        $this->replace($input);
    }

    /**
     * Validated changes per section, restricted to the exposed field set
     * (unknown keys are never merged into the blob).
     *
     * @return array<string, array<string, mixed>>
     */
    public function sectionChanges(): array
    {
        $service = $this->service();
        $validated = $this->validated();
        $changes = [];

        foreach (SystemConfigService::SECTIONS as $section) {
            if (! isset($validated[$section]) || ! is_array($validated[$section])) {
                continue;
            }

            $known = array_intersect_key($validated[$section], $service->fields($section));

            if ($known !== []) {
                $changes[$section] = $known;
            }
        }

        return $changes;
    }

    protected function service(): SystemConfigService
    {
        return app(SystemConfigService::class);
    }
}
