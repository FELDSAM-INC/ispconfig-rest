<?php

namespace App\Http\Requests;

use App\Models\CronJob;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POST /sites/cron-jobs (api/modules/sites/cron-jobs.yaml; legacy
 * form/cron.tform.php + interface/lib/classes/validate_cron.inc.php).
 * `type` is derived server-side (SitesService::deriveCronType) and never
 * accepted from input.
 */
class StoreCronJobRequest extends SitesRequest
{
    protected function booleanFields(): array
    {
        return ['log', 'active'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_domain_id' => [
                'required',
                'integer',
                Rule::exists('web_domain', 'domain_id')->whereIn('type', ['vhost', 'vhostsubdomain', 'vhostalias']),
            ],
            'run_min' => ['required', 'string', 'max:100', $this->runTimeRule('run_min')],
            'run_hour' => ['required', 'string', 'max:100', $this->runTimeRule('run_hour')],
            'run_mday' => ['required', 'string', 'max:100', $this->runTimeRule('run_mday')],
            'run_month' => ['required', 'string', 'max:100', $this->runMonthRule()],
            'run_wday' => ['required', 'string', 'max:100', $this->runTimeRule('run_wday')],
            'command' => ['required', 'string', $this->commandRule()],
            'log' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function runTimeRule(string $fieldName): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($fieldName): void {
            if (! is_string($value) || ! CronJob::isValidRunTime($fieldName, $value)) {
                $fail('The :attribute is not a valid cron time expression.');
            }
        };
    }

    protected function runMonthRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! CronJob::isValidRunMonth($value)) {
                $fail('The :attribute is not a valid cron month expression.');
            }
        };
    }

    protected function commandRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $parentDomain = DB::table('web_domain')
                ->where('domain_id', (int) $this->input('parent_domain_id', $this->currentParentDomainId()))
                ->value('domain');

            if (! CronJob::isValidCommand($value, $parentDomain)) {
                $fail('The :attribute is not a valid cron command.');
            }
        };
    }

    protected function currentParentDomainId(): int
    {
        return 0;
    }
}
