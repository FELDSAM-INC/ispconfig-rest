<?php

namespace App\Extensions;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Installed extensions (spec 058): directories `<path>/<name>/` with an `extension.json` describing the extension's
 * version, the core versions it supports, its PSR-4 autoload and its service providers. Compatible extensions are
 * autoloaded and their providers registered at boot; an incompatible or broken one is skipped and reported.
 */
final class ExtensionRegistry
{
    /** @var array<string, array{name: string, version: string, path: string, loaded: bool, error: ?string}> */
    private array $extensions = [];

    public function __construct(private readonly string $path, private readonly string $coreVersion) {}

    /**
     * Read every extension and register the compatible ones with the application.
     */
    public function boot(Application $app): void
    {
        foreach (glob(rtrim($this->path, '/').'/*/extension.json') ?: [] as $file) {
            $dir = dirname($file);
            $manifest = json_decode((string) file_get_contents($file), true);
            $name = is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : basename($dir);
            $entry = ['name' => $name, 'version' => (string) ($manifest['version'] ?? ''), 'path' => $dir, 'loaded' => false, 'error' => null];

            try {
                if (! is_array($manifest)) {
                    throw new \RuntimeException('extension.json is not valid JSON.');
                }
                $requires = (string) ($manifest['requires']['ispconfig-rest'] ?? '');
                if (! self::satisfies($this->coreVersion, $requires)) {
                    throw new \RuntimeException("Needs ispconfig-rest {$requires}, this is {$this->coreVersion}.");
                }
                $loader = self::loader();
                foreach ((array) ($manifest['autoload']['psr-4'] ?? []) as $prefix => $relative) {
                    $loader->addPsr4((string) $prefix, $dir.'/'.trim((string) $relative, '/'));
                }
                $providers = (array) ($manifest['providers'] ?? []);
                foreach ($providers as $provider) {
                    if (! class_exists((string) $provider)) {
                        throw new \RuntimeException("Service provider {$provider} not found.");
                    }
                }
                foreach ($providers as $provider) {
                    $app->register((string) $provider);
                }
                $entry['loaded'] = true;
            } catch (Throwable $error) {
                $entry['error'] = $error->getMessage();
                error_log('ispconfig-rest: extension '.$name.' not loaded: '.$error->getMessage());
            }

            $this->extensions[$name] = $entry;
        }
    }

    /**
     * @return array<int, array{name: string, version: string, path: string, loaded: bool, error: ?string}>
     */
    public function all(): array
    {
        return array_values($this->extensions);
    }

    /**
     * Directories of the loaded extensions, by name.
     *
     * @return array<string, string>
     */
    public function loadedPaths(): array
    {
        $paths = [];
        foreach ($this->extensions as $name => $extension) {
            if ($extension['loaded']) {
                $paths[$name] = $extension['path'];
            }
        }

        return $paths;
    }

    /**
     * Whether $version satisfies space-separated comparisons such as ">=1.1.0 <1.2.0" (empty: any).
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        foreach (preg_split('/\s+/', trim($constraint), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/\A(>=|<=|>|<|==|=|!=)?\s*v?(\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?)\z/', $part, $match) !== 1) {
                return false;
            }
            $operator = $match[1] === '' || $match[1] === '=' ? '==' : $match[1];
            if (! version_compare($version, $match[2], $operator)) {
                return false;
            }
        }

        return true;
    }

    private static function loader(): ClassLoader
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (! $loader instanceof ClassLoader) {
            throw new \RuntimeException('Composer class loader not found.');
        }

        return $loader;
    }
}
