<?php

namespace App\Http\Requests\Concerns;

use App\Models\ShellUser;
use App\Services\SitesConfigService;
use App\Support\IspContext;
use App\Support\ProblemType;
use App\Support\ProblemTypeCollector;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Spec 037: the installation's SSH authentication mode decides which
 * credential an SSH account may carry. Client and reseller keys sending the
 * other one are refused on that field instead of having it discarded silently;
 * administrator keys keep the clearing behaviour of
 * ShellUserController::applySshAuthenticationMode().
 *
 * An empty value is not a refusal (there is nothing to discard), and on update
 * the stored value may be re-sent unchanged (specs 016 and 033).
 */
trait EnforcesSshAuthenticationMode
{
    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $field = match (app(SitesConfigService::class)->sshAuthenticationMode()) {
                    'key' => 'password',
                    'password' => 'ssh_rsa',
                    default => null,
                };

                if ($field === null || app(IspContext::class)->authScope()->isAdmin || ! $this->exists($field)) {
                    return;
                }

                $value = trim((string) ($this->input($field) ?? ''));

                if ($value === '') {
                    return;
                }

                $stored = $this->storedShellUser()?->getRawOriginal()[$field] ?? null;

                if ($stored !== null && $value === trim((string) $stored)) {
                    return;
                }

                app(ProblemTypeCollector::class)->tag($field, ProblemType::FEATURE_NOT_ALLOWED);
                $validator->errors()->add($field, $field === 'password'
                    ? 'This hosting accepts an SSH key only; a password cannot be set.'
                    : 'This hosting accepts a password only; an SSH key cannot be set.');
            },
        ];
    }

    /**
     * The shell user being changed, for the unchanged-value exception. A create
     * has none; the update request overrides this with its route model.
     */
    protected function storedShellUser(): ?ShellUser
    {
        return null;
    }
}
