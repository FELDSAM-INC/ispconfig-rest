<?php

namespace Tests\Feature;

use App\Extensions\ExtensionRegistry;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Spec 058: the served contract includes installed extensions' paths, files and patches. */
class SwaggerExtensionSpecTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ispconfig-rest-spec-'.bin2hex(random_bytes(6));
        $files = [
            'demo/extension.json' => json_encode(['name' => 'demo', 'version' => '1.0.0']),
            'demo/api/openapi.yaml' => "paths:\n  /demo/things:\n    \$ref: './modules/demo/things.yaml#/~1demo~1things'\n  /clients:\n    \$ref: './modules/demo/things.yaml#/~1demo~1things'\n",
            'demo/api/modules/demo/things.yaml' => "/demo/things:\n  get:\n    summary: Demo things\n",
            'demo/api/patches/components/schemas/Client.yaml' => "properties:\n  demo_flag:\n    type: boolean\n    readOnly: true\n",
            'demo/secret.yaml' => "secret: true\n",
        ];
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($this->dir.'/'.$path));
            file_put_contents($this->dir.'/'.$path, $content);
        }
        $registry = new ExtensionRegistry($this->dir, '1.1.0');
        $registry->boot($this->app);
        $this->app->instance(ExtensionRegistry::class, $registry);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_root_document_adds_extension_paths_without_replacing_core_ones(): void
    {
        $spec = Yaml::parse($this->get('https://panel.example.com:8090/api/spec')->assertOk()->getContent());

        $this->assertSame('./modules/demo/things.yaml#/~1demo~1things', $spec['paths']['/demo/things']['$ref']);
        $this->assertStringStartsWith('./modules/client/clients.yaml', $spec['paths']['/clients']['$ref']);
        $this->assertSame('https://panel.example.com:8090/api/v1', $spec['servers'][0]['url']);
        $this->assertSame([['apiKeyAuth' => []]], $spec['security']);
    }

    public function test_extension_files_are_served_and_patches_add_to_core_files(): void
    {
        $this->get('/api/modules/demo/things.yaml')->assertOk()->assertSee('Demo things');

        $client = Yaml::parse($this->get('/api/components/schemas/Client.yaml')->assertOk()->getContent());
        $this->assertSame(['type' => 'boolean', 'readOnly' => true], $client['properties']['demo_flag']);
        $this->assertArrayHasKey('contact_name', $client['properties']);

        $this->get('/api/modules/../../secret.yaml')->assertNotFound();
        $this->get('/api/modules/patches/components/schemas/Client.yaml')->assertNotFound();
    }
}
