<?php

namespace App\Http\Requests;

use App\Models\ApiKey;
use App\Services\AcmeChallengeService;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /system/api-keys (api/modules/system/api-keys.yaml, spec 014).
 *
 * The secret and the bound identity are never client-supplied: key,
 * key_hash, sys_userid, sys_groupid, id and scope are rejected with 422.
 * `access` acme limits the key to ACME DNS challenges, `zones` (acme only)
 * to those zones (spec 057).
 * client_id must resolve to a control-panel identity exactly like
 * api:key:create --client-id (spec 011 FR-019).
 */
class StoreApiKeyRequest extends FormRequest
{
    /**
     * Body fields that must never be sent on create or update.
     */
    public const PROHIBITED = ['key', 'key_hash', 'sys_userid', 'sys_groupid', 'id', 'scope'];

    /**
     * Authentication and the admin gate happen in middleware.
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
        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'access' => ['sometimes', 'string', Rule::in([ApiKey::ACCESS_FULL, ApiKey::ACCESS_ACME])],
            'zones' => ['sometimes', 'array', 'max:100'],
            'zones.*' => ['string', 'max:254'],
        ], array_fill_keys(self::PROHIBITED, ['prohibited']));
    }

    /**
     * An existing, well-formed client_id must also resolve to the client's
     * control-panel identity (unknown client or client without a user → 422).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('zones') && ! $validator->errors()->has('zones') && ! $validator->errors()->has('access')) {
                    if ($this->access() !== ApiKey::ACCESS_ACME && $this->input('zones') !== []) {
                        $validator->errors()->add('zones', 'Zones only apply to keys with access acme.');
                    } elseif (is_array($this->input('zones'))) {
                        foreach ($this->input('zones') as $index => $zone) {
                            if (! is_string($zone) || AcmeChallengeService::normalizeZone($zone) === null) {
                                $validator->errors()->add('zones.'.$index, 'Each zone must be a DNS name.');
                            }
                        }
                    }
                }

                if (! $this->has('client_id') || $validator->errors()->has('client_id')) {
                    return;
                }

                if (app(ApiKeyService::class)->resolveClientIdentity((int) $this->input('client_id')) === null) {
                    $validator->errors()->add('client_id', 'The selected client does not exist or has no control-panel user.');
                }
            },
        ];
    }

    /**
     * The client id the key is bound to, or null for an admin key.
     */
    public function clientId(): ?int
    {
        return $this->has('client_id') ? (int) $this->input('client_id') : null;
    }

    public function access(): string
    {
        return $this->input('access') === ApiKey::ACCESS_ACME ? ApiKey::ACCESS_ACME : ApiKey::ACCESS_FULL;
    }

    /**
     * The normalized zone list of an ACME key (duplicates removed).
     *
     * @return array<int, string>
     */
    public function zones(): array
    {
        $zones = is_array($this->input('zones')) ? $this->input('zones') : [];

        return array_values(array_unique(array_filter(array_map(static fn ($zone): ?string => is_string($zone) ? AcmeChallengeService::normalizeZone($zone) : null, $zones))));
    }
}
