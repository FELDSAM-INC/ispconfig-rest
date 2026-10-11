<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionRegistry;
use Illuminate\Console\Command;

class ListExtensions extends Command
{
    protected $signature = 'extension:list {name? : Only this extension} {--json : Machine-readable output}';

    protected $description = 'List installed extensions (spec 058); fails when one, or the named one, is not loaded';

    public function handle(ExtensionRegistry $registry): int
    {
        $name = $this->argument('name');
        $extensions = array_values(array_filter($registry->all(), fn (array $extension): bool => $name === null || $extension['name'] === $name));

        if ($this->option('json')) {
            $this->line((string) json_encode($extensions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($extensions === []) {
            $this->line($name === null ? 'No extensions installed.' : "Extension {$name} is not installed.");
        } else {
            $this->table(['Extension', 'Version', 'Loaded', 'Problem'], array_map(fn (array $extension): array => [
                $extension['name'], $extension['version'], $extension['loaded'] ? 'yes' : 'no', $extension['error'] ?? '',
            ], $extensions));
        }

        $failed = $extensions === [] ? $name !== null : in_array(false, array_column($extensions, 'loaded'), true);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
