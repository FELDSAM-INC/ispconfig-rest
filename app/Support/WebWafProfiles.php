<?php

namespace App\Support;

/**
 * Application profiles backed by the official OWASP CRS 4 rule exclusion plugins pinned in waf-server/crs.json. Every
 * plugin is loaded on every managed website and switched off, then only the website's selected profile is switched on;
 * customers never supply paths or rule text.
 */
final class WebWafProfiles
{
    /** Application profile => official CRS plugin name (`tx.<name>-plugin_enabled`) */
    public const PLUGINS = [
        'wordpress' => 'wordpress-rule-exclusions',
        'drupal' => 'drupal-rule-exclusions',
        'nextcloud' => 'nextcloud-rule-exclusions',
        'dokuwiki' => 'dokuwiki-rule-exclusions',
        'cpanel' => 'cpanel-rule-exclusions',
        'xenforo' => 'xenforo-rule-exclusions',
    ];

    /** Verified CRS versions live side by side (WebWafCrs); `current` points at the active one. */
    public const ROOT = '/usr/local/share/ispconfig-waf/crs';

    public const CRS = self::ROOT.'/current';

    public static function configuration(string $crs = self::CRS): string
    {
        // Administrator settings (paranoia level, thresholds) belong in crs-setup.local.conf, which updates keep.
        $lines = ['Include /etc/ispconfig-waf/crs-setup.conf', 'Include /etc/ispconfig-waf/crs-setup.local.conf'];
        $reset = 'id:19970,phase:1,pass,t:none,nolog';
        foreach (self::PLUGINS as $plugin) {
            $reset .= ",setvar:'tx.".$plugin."-plugin_enabled=0'";
        }
        // Runs after the administrator's setup and before the plugins: a global setting cannot enable a profile on
        // other websites. A missing selector (older website configuration) means no application profile.
        $lines[] = 'SecAction "'.$reset.'"';
        $id = 19971;
        foreach (self::PLUGINS as $profile => $plugin) {
            $lines[] = 'SecRule TX:ispcp_application_profile "@streq '.$profile.'" "id:'.$id++.",phase:1,pass,t:none,nolog,setvar:'tx.".$plugin."-plugin_enabled=1'\"";
        }
        // The documented CRS 4 plugin order; the empty-* placeholders keep every pattern matching.
        foreach (['plugins/*-config.conf', 'plugins/*-before.conf', 'rules/*.conf', 'plugins/*-after.conf'] as $pattern) {
            $lines[] = 'Include '.$crs.'/'.$pattern;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Profiles whose plugin is installed as this release expects. Called only on the webserver: a master server's
     * filesystem is not authoritative.
     *
     * @return string[]
     */
    public static function installed(string $config = '/etc/ispconfig-waf/owasp.conf', string $crs = self::CRS): array
    {
        $real = realpath($crs);
        if ($real === false || self::trustedContents($config) !== self::configuration($crs)) {
            return [];
        }
        $initialization = self::trustedContents($real.'/rules/REQUEST-901-INITIALIZATION.conf');
        if ($initialization === null || ! str_contains($initialization, "ver:'OWASP_CRS/4.")) {
            return [];
        }
        $profiles = [];
        foreach (self::PLUGINS as $profile => $plugin) {
            $before = self::trustedContents($real.'/plugins/'.$plugin.'-before.conf');
            if ($before !== null && self::trustedContents($real.'/plugins/'.$plugin.'-config.conf') !== null
                && str_contains($before, 'SecRule TX:'.$plugin.'-plugin_enabled "@eq 0"')) {
                $profiles[] = $profile;
            }
        }

        return $profiles;
    }

    private static function trustedContents(string $file): ?string
    {
        clearstatcache(true, $file);
        $stat = @lstat($file);
        if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0 || $stat['size'] > 2097152) {
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
