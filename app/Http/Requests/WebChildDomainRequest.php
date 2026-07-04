<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Validation\Rule;

/**
 * Shared rules for web child domains
 * (api/modules/sites/web-child-domains.yaml; legacy
 * form/web_childdomain.tform.php + web_childdomain_edit.php).
 */
abstract class WebChildDomainRequest extends SitesRequest
{
    protected function booleanFields(): array
    {
        return ['ssl_letsencrypt_exclude', 'active'];
    }

    protected function normalizesDomain(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        return [
            'subdomain' => ['sometimes', Rule::in(['none', 'www', '*'])],
            'redirect_type' => ['sometimes', 'nullable', Rule::in(['', 'no', 'R', 'L', 'R,L', 'R=301,L', 'last', 'break', 'redirect', 'permanent', 'proxy'])],
            'redirect_path' => [
                'sometimes', 'nullable', 'string', 'max:255',
                'regex:@^(([\.]{0})|((ftp|https?|\[scheme\])://([-\w\.]+)+(:\d+)?(/([\w/_\.\,\-\+\?\~!:%]*(\?\S+)?)?)?)(?:#\S*)?|(/(?!.*\.\.)[\w/_\.\-]{1,255}/))$@',
                $this->proxyRequiresUrlRule(),
            ],
            'seo_redirect' => ['sometimes', 'nullable', Rule::in(['', 'non_www_to_www', 'www_to_non_www', '*_domain_tld_to_domain_tld', '*_domain_tld_to_www_domain_tld', '*_to_domain_tld', '*_to_www_domain_tld'])],
            'ssl_letsencrypt_exclude' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Legacy: redirect_type=proxy requires a URL, not a path
     * (web_childdomain_edit.php error_proxy_requires_url).
     */
    protected function proxyRequiresUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->input('redirect_type') === 'proxy' && is_string($value) && str_starts_with($value, '/')) {
                $fail('A proxy redirect requires a URL, not a path.');
            }
        };
    }

    /**
     * Alias domains submit the full FQDN (legacy validate_domain::
     * alias_domain, no wildcard); subdomains submit only the label —
     * the FQDN is composed server-side and validated after composition.
     */
    protected function childDomainFormatRule(Closure $resolveType): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($resolveType): void {
            if (! is_string($value) || trim($value) === '') {
                $fail('The :attribute is required.');

                return;
            }

            if ($resolveType() === 'alias') {
                if (! preg_match('/^[\w\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}$/', $value)) {
                    $fail('The :attribute must be a valid domain name.');
                }
            } else {
                // Subdomain label(s) — allow wildcard per legacy sub_domain
                // (the composed FQDN is checked again in the controller).
                if (! preg_match('/^(\*\.)?[\w\.\-]{1,255}$/', $value)) {
                    $fail('The :attribute must be a valid subdomain label.');
                }
            }

            if (preg_match('/\.acme\.invalid$/', $value)) {
                $fail('The :attribute must not end in .acme.invalid.');
            }
        };
    }
}
