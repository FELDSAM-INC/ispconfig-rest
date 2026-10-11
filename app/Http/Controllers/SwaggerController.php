<?php

namespace App\Http\Controllers;

use App\Extensions\ExtensionRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * The multi-file OpenAPI contract, served as files so Swagger UI resolves the relative `$ref`s itself. Installed
 * extensions (spec 058) add their `api/openapi.yaml` paths and tags to the root document; module and component files
 * missing in core are served from the extensions' `api/` directories, and `api/patches/<file>` adds to a served file.
 */
class SwaggerController extends Controller
{
    /**
     * Display Swagger UI.
     *
     * @return Response
     */
    public function index()
    {
        return view('swagger');
    }

    /**
     * Get the OpenAPI specification directly from YAML files.
     *
     * @return Response
     */
    public function getSpec(Request $request)
    {
        $yamlFile = base_path('api/openapi.yaml');

        if (! File::exists($yamlFile)) {
            return response()->json(['error' => 'OpenAPI specification not found'], 404);
        }

        try {
            // The servers: block is the absolute request origin, so the Swagger UI "Try it out" targets the actual
            // host/scheme/port the docs are being viewed from — not a hardcoded localhost:8000.
            $base = rtrim($request->getSchemeAndHttpHost(), '/').'/api/v1';
            $fragments = array_filter(array_map(fn (string $dir): string => $dir.'/api/openapi.yaml', $this->extensionDirs()), 'is_file');

            if ($fragments === []) {
                $servers = "servers:\n  - url: {$base}\n    description: This ISPConfig REST API server\n";
                $content = preg_replace('/^servers:\n(?:[ \t]+.*\n)*/m', $servers, File::get($yamlFile), 1);
            } else {
                $spec = Yaml::parseFile($yamlFile, Yaml::PARSE_OBJECT_FOR_MAP);
                foreach ($fragments as $fragment) {
                    $this->addFragment($spec, Yaml::parseFile($fragment, Yaml::PARSE_OBJECT_FOR_MAP));
                }
                $spec->servers = [(object) ['url' => $base, 'description' => 'This ISPConfig REST API server']];
                $content = $this->dump($spec);
            }

            return response($content)
                ->header('Content-Type', 'application/yaml')
                ->header('Content-Disposition', 'inline; filename="openapi.yaml"');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reading OpenAPI specification: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get a specific module YAML file.
     * This handles requests for module YAML files from Swagger UI.
     *
     * @return Response
     */
    public function getModuleSpec(Request $request)
    {
        // Remove the 'api/' prefix to get the relative path within the api directory
        $relativePath = preg_replace('/^api\//', '', $request->path());

        // Make sure the file has .yaml extension
        if (! Str::endsWith($relativePath, '.yaml')) {
            $relativePath .= '.yaml';
        }

        $filePath = $this->resolve(base_path('api'), $relativePath);
        foreach ($this->extensionDirs() as $dir) {
            $filePath ??= $this->resolve($dir.'/api', $relativePath);
        }

        if ($filePath === null) {
            return response()->json(['error' => 'Module specification not found: '.$relativePath], 404);
        }

        try {
            $patches = array_filter(array_map(fn (string $dir): ?string => $this->resolve($dir.'/api/patches', $relativePath), $this->extensionDirs()));

            if ($patches === []) {
                // Return the raw YAML content with proper headers
                $content = File::get($filePath);
            } else {
                $document = Yaml::parseFile($filePath, Yaml::PARSE_OBJECT_FOR_MAP);
                foreach ($patches as $patch) {
                    $document = $this->merge($document, Yaml::parseFile($patch, Yaml::PARSE_OBJECT_FOR_MAP));
                }
                $content = $this->dump($document);
            }

            return response($content)
                ->header('Content-Type', 'application/yaml')
                ->header('Content-Disposition', 'inline; filename="'.basename($filePath).'"');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reading module specification: '.$e->getMessage()], 500);
        }
    }

    /**
     * A file below $root, refusing path traversal.
     */
    private function resolve(string $root, string $relativePath): ?string
    {
        $root = realpath($root);
        $file = $root === false ? false : realpath($root.'/'.$relativePath);

        return $file !== false && is_file($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR) ? $file : null;
    }

    /**
     * @return array<int, string>
     */
    private function extensionDirs(): array
    {
        return array_values(App::make(ExtensionRegistry::class)->loadedPaths());
    }

    /**
     * Paths, tags and schema registrations of an extension's root document; core's own entries win.
     */
    private function addFragment(stdClass $spec, mixed $fragment): void
    {
        if (! $fragment instanceof stdClass) {
            return;
        }
        $spec->paths ??= new stdClass;
        foreach ((array) ($fragment->paths ?? []) as $path => $item) {
            $spec->paths->{$path} ??= $item;
        }
        foreach ((array) ($fragment->components ?? []) as $section => $entries) {
            $spec->components ??= new stdClass;
            $spec->components->{$section} ??= new stdClass;
            foreach ((array) $entries as $name => $entry) {
                $spec->components->{$section}->{$name} ??= $entry;
            }
        }
        $names = array_map(fn ($tag) => $tag->name ?? null, $spec->tags ?? []);
        foreach ($fragment->tags ?? [] as $tag) {
            if (! in_array($tag->name ?? null, $names, true)) {
                $spec->tags[] = $tag;
            }
        }
    }

    /**
     * Maps merge recursively; any other patch value replaces the served one.
     */
    private function merge(mixed $document, mixed $patch): mixed
    {
        if (! $document instanceof stdClass || ! $patch instanceof stdClass) {
            return $patch;
        }
        foreach (get_object_vars($patch) as $key => $value) {
            $document->{$key} = property_exists($document, $key) ? $this->merge($document->{$key}, $value) : $value;
        }

        return $document;
    }

    private function dump(mixed $document): string
    {
        return Yaml::dump($document, 64, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }
}
