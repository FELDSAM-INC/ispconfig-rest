<?php

namespace App\Services;

use App\Models\MailDomain;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * DKIM of mail domains (spec 027; contract api/modules/mail/domain-dkim.yaml):
 * the status view and server-side key generation, mirroring legacy
 * ajax_get_json.php `create_dkim` followed by a mail_domain_edit.php save.
 * No response built here contains the private key.
 */
class MailDomainDkimService
{
    /** Key sizes offered by the server configuration form (server_config.tform.php dkim_strength). */
    public const KEY_SIZES = [1024, 2048, 4096];

    /** Legacy default when the server has no usable dkim_strength. */
    public const DEFAULT_KEY_SIZE = 2048;

    public function __construct(
        protected MailDomainService $domains,
        protected AccountMailService $mail,
        protected ServerIniConfigService $serverConfig,
    ) {}

    /**
     * The DKIM status of a domain (FR-001, research R4).
     *
     * @return array<string, mixed>
     */
    public function view(MailDomain $domain): array
    {
        $raw = $domain->getAttributes();
        $name = (string) $raw['domain'];
        $selector = blank($raw['dkim_selector'] ?? null) ? 'default' : (string) $raw['dkim_selector'];
        $public = blank($raw['dkim_public'] ?? null) ? null : (string) $raw['dkim_public'];
        $key = $public === null ? false : openssl_pkey_get_public($public);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        return [
            'id' => (int) $domain->getKey(),
            'domain' => $name,
            'enabled' => ($raw['dkim'] ?? 'n') === 'y',
            'selector' => $selector,
            'public_key' => $public,
            'key_bits' => $details === false ? null : (int) $details['bits'],
            'dns_record' => $public === null ? null : [
                'name' => $selector.'._domainkey.'.$name.'.',
                'type' => 'TXT',
                'value' => 'v=DKIM1; t=s; p='.self::dnsKey($public),
            ],
            'dns_managed' => $this->domains->findSoaZone($name) !== null,
            'available' => $this->mail->dkimPathUsable((int) $raw['server_id']),
        ];
    }

    /**
     * Generate a key pair and switch DKIM on (FR-002, FR-003, research R1–R3):
     * one mail_domain datalog update, then the existing DKIM DNS update in
     * the same transaction. The selector defaults to the stored one.
     */
    public function generate(MailDomain $domain, ?string $selector): void
    {
        $raw = $domain->getAttributes();
        $serverId = (int) $raw['server_id'];

        if (! $this->mail->dkimPathUsable($serverId)) {
            throw new ConflictHttpException('DKIM signing is not available on the mail server of this domain.');
        }

        [$privateKey, $publicKey] = $this->keyPair($this->keySize($serverId));
        $oldRecord = $domain->getRawOriginal();

        $domain->dkim = true;
        $domain->dkim_private = $privateKey;
        $domain->dkim_public = $publicKey;
        $domain->dkim_selector = $selector ?? (blank($raw['dkim_selector'] ?? null) ? 'default' : (string) $raw['dkim_selector']);

        DB::transaction(function () use ($domain, $oldRecord): void {
            // BaseModel::save() enforces update permission on the domain.
            $domain->save();
            $this->domains->syncDnsAfterUpdate($domain, $oldRecord);
        });
    }

    /**
     * RSA key size for a mail server: its dkim_strength when offered by the
     * form, otherwise 2048 (legacy intval + default).
     */
    public function keySize(int $serverId): int
    {
        $bits = (int) ($this->serverConfig->getSection($serverId, 'mail')['dkim_strength'] ?? 0);

        return in_array($bits, self::KEY_SIZES, true) ? $bits : self::DEFAULT_KEY_SIZE;
    }

    /**
     * The public key as published in DNS: PEM armor and line breaks removed
     * (legacy mail_domain_edit.php update_dns()).
     */
    public static function dnsKey(string $publicKey): string
    {
        return str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"], '', $publicKey);
    }

    /**
     * A new RSA key pair: PKCS#8 private key and SubjectPublicKeyInfo public
     * key, the formats `openssl genrsa` (OpenSSL 3) and `openssl rsa -pubout`
     * produce for legacy.
     *
     * @return array{0: string, 1: string}
     */
    protected function keyPair(int $bits): array
    {
        $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $privateKey = '';
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($key === false || $details === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new HttpException(500, 'The DKIM key could not be generated.');
        }

        return [$privateKey, (string) $details['key']];
    }
}
