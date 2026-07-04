<?php

namespace App\Http\Requests;

use App\Models\CronJob;
use Illuminate\Validation\Rule;

/**
 * PUT /sites/cron-jobs/{id} (api/modules/sites/cron-jobs.yaml). Partial
 * updates; `type` is re-derived server-side when the command changes.
 */
class UpdateCronJobRequest extends StoreCronJobRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_domain_id' => [
                'sometimes',
                'integer',
                Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias']),
            ],
            'run_min' => ['sometimes', 'string', 'max:100', $this->runTimeRule('run_min')],
            'run_hour' => ['sometimes', 'string', 'max:100', $this->runTimeRule('run_hour')],
            'run_mday' => ['sometimes', 'string', 'max:100', $this->runTimeRule('run_mday')],
            'run_month' => ['sometimes', 'string', 'max:100', $this->runMonthRule()],
            'run_wday' => ['sometimes', 'string', 'max:100', $this->runTimeRule('run_wday')],
            'command' => ['sometimes', 'string', $this->commandRule()],
            'log' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function currentParentDomainId(): int
    {
        $cronJob = $this->route('cronJob');

        return $cronJob instanceof CronJob
            ? (int) $cronJob->getAttributes()['parent_domain_id']
            : 0;
    }
}
