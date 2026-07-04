<?php

namespace App\Http\Requests;

use App\Services\SystemConfigService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /system/config/{sites|mail|dns|domains|misc}
 * (api/modules/system/*-config.yaml).
 *
 * The body is one flat section object; every field is optional (absent key =
 * leave unchanged). Rules and legacy SAVE-filter normalization (STRIPTAGS/
 * STRIPNL/trim) come from SystemConfigService's per-section field map, which
 * mirrors legacy system_config.tform.php. Keys the contract does not expose
 * are ignored (never merged into the blob).
 */
class UpdateSystemConfigSectionRequest extends FormRequest
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
        return $this->service()->rulesFor($this->section());
    }

    /**
     * Legacy SAVE filters before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->replace($this->service()->normalizeInput($this->section(), $this->all()));
    }

    /**
     * Validated, exposed-only section changes ready for the blob merge.
     *
     * @return array<string, mixed>
     */
    public function sectionChanges(): array
    {
        return array_intersect_key($this->validated(), $this->service()->fields($this->section()));
    }

    public function section(): string
    {
        return (string) $this->route('section');
    }

    protected function service(): SystemConfigService
    {
        return app(SystemConfigService::class);
    }
}
