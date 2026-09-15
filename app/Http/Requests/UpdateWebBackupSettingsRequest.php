<?php

namespace App\Http\Requests;

use App\Models\WebDomain;
use App\Services\WebBackupService;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /sites/web-domains/{id}/backup-settings (api/modules/sites/web-backups.yaml).
 *
 * Values and the excludes regex come from web_vhost_domain.tform.php (Backup
 * tab, research R11). Requires update permission on the website, checked
 * before validation.
 */
class UpdateWebBackupSettingsRequest extends SitesRequest
{
    public const FORMATS_WEB = ['default', 'zip', 'zip_bzip2', 'tar_gzip', 'tar_bzip2', 'tar_xz', 'tar_7z_lzma2', 'tar_7z_lzma', 'tar_7z_ppmd', 'tar_7z_bzip2'];

    public const FORMATS_DB = ['zip', 'zip_bzip2', 'gzip', 'bzip2', 'xz', '7z_lzma2', '7z_lzma', '7z_ppmd', '7z_bzip2'];

    public function authorize(): bool
    {
        $website = $this->website();

        return $website !== null
            && app(IspContext::class)->authScope()->allows($website->getAttributes(), 'u');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('You do not have permission to update this resource.');
    }

    protected function booleanFields(): array
    {
        return ['backup_encrypt'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'backup_interval' => ['sometimes', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'backup_copies' => ['sometimes', 'integer', Rule::in(WebBackupService::BACKUP_COPIES)],
            'backup_excludes' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:@^(?!.*\.\.)[-a-zA-Z0-9_/.~,*]*$@'],
            'backup_format_web' => ['sometimes', Rule::in(self::FORMATS_WEB)],
            'backup_format_db' => ['sometimes', Rule::in(self::FORMATS_DB)],
            'backup_encrypt' => ['sometimes', 'boolean'],
            'backup_password' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Enabling encryption needs a stored or supplied password.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('backup_encrypt') || ! $this->boolean('backup_encrypt')) {
                return;
            }

            $supplied = trim((string) $this->input('backup_password', ''));
            $stored = trim((string) ($this->website()?->getAttributes()['backup_password'] ?? ''));

            if ($supplied === '' && $stored === '') {
                $validator->errors()->add('backup_password', 'A backup password is required to encrypt backups.');
            }
        });
    }

    protected function website(): ?WebDomain
    {
        $website = $this->route('webDomain');

        return $website instanceof WebDomain ? $website : null;
    }
}
