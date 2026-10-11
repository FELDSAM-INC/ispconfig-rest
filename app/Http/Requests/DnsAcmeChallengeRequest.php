<?php

namespace App\Http\Requests;

use App\Services\AcmeChallengeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /dns/acme-challenges (body) and DELETE /dns/acme-challenges (query): a challenge name and its TXT value
 * (api/modules/dns/acme-challenges.yaml, spec 057).
 */
class DnsAcmeChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:254'],
            'value' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('name') && AcmeChallengeService::normalizeName((string) $this->input('name')) === null) {
                    $validator->errors()->add('name', 'The name must be a DNS name starting with _acme-challenge.');
                }
            },
        ];
    }

    public function challengeName(): string
    {
        return (string) AcmeChallengeService::normalizeName((string) $this->input('name'));
    }

    public function challengeValue(): string
    {
        return (string) $this->input('value');
    }
}
