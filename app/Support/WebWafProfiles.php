<?php

namespace App\Support;

/** Fixed upstream CRS 3 profiles; never accept paths or rule text from customers. */
final class WebWafProfiles
{
    public const FILES = [
        'wordpress' => 'REQUEST-903.9002-WORDPRESS-EXCLUSION-RULES.conf',
        'drupal' => 'REQUEST-903.9001-DRUPAL-EXCLUSION-RULES.conf',
        'nextcloud' => 'REQUEST-903.9003-NEXTCLOUD-EXCLUSION-RULES.conf',
        'dokuwiki' => 'REQUEST-903.9004-DOKUWIKI-EXCLUSION-RULES.conf',
        'cpanel' => 'REQUEST-903.9005-CPANEL-EXCLUSION-RULES.conf',
        'xenforo' => 'REQUEST-903.9006-XENFORO-EXCLUSION-RULES.conf',
    ];

    public static function configuration(): string
    {
        $lines = ['Include /etc/modsecurity/crs/crs-setup.conf'];
        $reset = 'id:19970,phase:1,pass,t:none,nolog';
        foreach (self::FILES as $profile => $file) {
            $reset .= ',setvar:tx.crs_exclusions_'.$profile.'=0';
        }
        // Run after the administrator's setup and before the application exclusions.
        // A missing selector (older website configuration) means no application profile.
        $lines[] = 'SecAction "'.$reset.'"';
        $id = 19971;
        foreach (self::FILES as $profile => $file) {
            $lines[] = 'SecRule TX:ispcp_application_profile "@streq '.$profile.'" "id:'.$id++.',phase:1,pass,t:none,nolog,setvar:tx.crs_exclusions_'.$profile.'=1"';
        }
        $lines[] = 'Include /usr/share/modsecurity-crs/rules/*.conf';

        return implode("\n", $lines)."\n";
    }

    /** Called only on the webserver: a master server's filesystem is not authoritative. */
    public static function installed(string $config = '/etc/ispconfig-waf/owasp.conf', string $rules = '/usr/share/modsecurity-crs/rules'): array
    {
        if (self::trustedContents($config) !== self::configuration()) {
            return [];
        }
        $profiles = [];
        foreach (self::FILES as $profile => $file) {
            $text = self::trustedContents($rules.'/'.$file);
            if ($text !== null && preg_match('/Core Rule Set ver\.3\./', $text)
                && str_contains($text, 'SecRule &TX:crs_exclusions_'.$profile.'|TX:crs_exclusions_'.$profile)) {
                $profiles[] = $profile;
            }
        }

        return $profiles;
    }

    private static function trustedContents(string $file): ?string
    {
        clearstatcache(true, $file);
        $stat = @lstat($file);
        if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0 || $stat['size'] > 1048576) {
            return null;
        }
        // Reject writable or symlinked parents too. All deployed paths are root-owned.
        for ($dir = dirname($file); $dir !== '/'; $dir = dirname($dir)) {
            $parent = @lstat($dir);
            if (! $parent || ($parent['mode'] & 0170000) !== 0040000 || $parent['uid'] !== 0 || ($parent['mode'] & 0022) !== 0) {
                return null;
            }
        }
        $text = @file_get_contents($file);

        return $text === false ? null : $text;
    }
}
