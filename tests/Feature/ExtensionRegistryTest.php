<?php

namespace Tests\Feature;

use App\Extensions\ExtensionRegistry;
use App\Extensions\Hooks;
use App\Models\Client;
use App\Models\ClientReseller;
use App\Models\WebChildDomain;
use App\Models\WebDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Spec 058: extension discovery, compatibility and the hook points. */
class ExtensionRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ispconfig-rest-extensions-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_version_constraints(): void
    {
        $this->assertTrue(ExtensionRegistry::satisfies('1.1.0', ''));
        $this->assertTrue(ExtensionRegistry::satisfies('1.1.0', '>=1.1.0 <1.2.0'));
        $this->assertTrue(ExtensionRegistry::satisfies('1.1.4', '>=1.1 <1.2'));
        $this->assertFalse(ExtensionRegistry::satisfies('1.2.0', '>=1.1.0 <1.2.0'));
        $this->assertFalse(ExtensionRegistry::satisfies('1.0.7', '>=1.1.0'));
        $this->assertTrue(ExtensionRegistry::satisfies('1.1.0', 'v1.1.0'));
        $this->assertFalse(ExtensionRegistry::satisfies('1.1.0', '^1.1'));
    }

    public function test_compatible_extensions_are_autoloaded_and_registered_and_others_reported(): void
    {
        $namespace = 'ExtensionFixture'.bin2hex(random_bytes(4));
        $this->extension('good', ['version' => '2.0.0', 'requires' => ['ispconfig-rest' => '>=1.1.0 <1.2.0'],
            'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']], 'providers' => [$namespace.'\\Provider']]);
        file_put_contents($this->dir.'/good/src/Provider.php', "<?php\nnamespace {$namespace};\n"
            ."class Provider extends \\Illuminate\\Support\\ServiceProvider {\n"
            ."    public function register(): void { \$this->app->instance('fixture.registered', true); }\n}\n");
        $this->extension('old', ['version' => '1.0.0', 'requires' => ['ispconfig-rest' => '>=1.2.0']]);
        $this->extension('broken', ['version' => '1.0.0', 'providers' => ['Missing\\Provider']]);
        mkdir($this->dir.'/invalid');
        file_put_contents($this->dir.'/invalid/extension.json', '{');

        $registry = new ExtensionRegistry($this->dir, '1.1.0');
        $registry->boot($this->app);

        $this->assertTrue($this->app->bound('fixture.registered'));
        $byName = collect($registry->all())->keyBy('name');
        $this->assertSame(['broken', 'good', 'invalid', 'old'], $byName->keys()->sort()->values()->all());
        $this->assertTrue($byName['good']['loaded']);
        $this->assertSame('2.0.0', $byName['good']['version']);
        $this->assertSame('Needs ispconfig-rest >=1.2.0, this is 1.1.0.', $byName['old']['error']);
        $this->assertSame('Service provider Missing\\Provider not found.', $byName['broken']['error']);
        $this->assertSame('extension.json is not valid JSON.', $byName['invalid']['error']);
        $this->assertSame(['good' => $this->dir.'/good'], $registry->loadedPaths());
    }

    public function test_model_hooks_follow_the_class_not_the_table(): void
    {
        $hooks = new Hooks;
        $hooks->attributes(WebDomain::class, fn (): array => ['extra' => true]);
        $hooks->attributes(Client::class, fn (Client $client): array => ['client_hook' => $client::class]);

        $this->assertSame(['extra' => true], $hooks->attributesFor(new WebDomain));
        $this->assertSame([], $hooks->attributesFor(new WebChildDomain));
        $this->assertSame(['client_hook' => ClientReseller::class], $hooks->attributesFor(new ClientReseller));
    }

    public function test_extension_fields_are_validated_authorized_and_split_from_the_core_payload(): void
    {
        $hooks = new Hooks;
        $hooks->fields(WebDomain::class, fn (Request $request, string $action): array => $action === 'update'
            ? ['settings' => ['sometimes', 'array'], 'settings.mode' => ['string'], 'flag' => ['boolean']] : [], ['flag']);
        $hooks->prepare(WebDomain::class, fn (array $fields): array => ['settings' => ['mode' => strtoupper($fields['settings']['mode'])]] + $fields);
        $hooks->authorize(WebDomain::class, fn (Request $request): bool => ! $request->exists('forbidden'));

        $request = Request::create('/', 'PUT');
        [$fields, $payload] = $hooks->split(WebDomain::class, ['domain' => 'a.test', 'settings' => ['mode' => 'on'], 'flag' => true], $request, 'update');
        $this->assertSame(['settings' => ['mode' => 'ON'], 'flag' => true], $fields);
        $this->assertSame(['domain' => 'a.test'], $payload);
        $this->assertSame([[], ['domain' => 'a.test']], $hooks->split(WebDomain::class, ['domain' => 'a.test'], $request, 'create'));
        $this->assertSame(['flag'], $hooks->booleanFields(WebDomain::class));
        $this->assertTrue($hooks->authorizes(WebDomain::class, $request, 'update'));
        $this->assertFalse($hooks->authorizes(WebDomain::class, Request::create('/', 'PUT', ['forbidden' => 1]), 'update'));
    }

    public function test_list_command_reports_and_fails_for_unloaded_extensions(): void
    {
        $this->extension('good', ['version' => '1.2.3']);
        $this->extension('old', ['version' => '0.1.0', 'requires' => ['ispconfig-rest' => '>=9.0.0']]);
        $registry = new ExtensionRegistry($this->dir, '1.1.0');
        $registry->boot($this->app);
        $this->app->instance(ExtensionRegistry::class, $registry);

        $this->artisan('extension:list', ['name' => 'good'])->expectsOutputToContain('1.2.3')->assertSuccessful();
        $this->artisan('extension:list', ['name' => 'old'])->expectsOutputToContain('Needs ispconfig-rest >=9.0.0')->assertFailed();
        $this->artisan('extension:list', ['name' => 'missing'])->expectsOutput('Extension missing is not installed.')->assertFailed();
        $this->artisan('extension:list')->assertFailed();
    }

    /** @param  array<string, mixed>  $manifest */
    private function extension(string $name, array $manifest): void
    {
        mkdir($this->dir.'/'.$name.'/src', 0777, true);
        file_put_contents($this->dir.'/'.$name.'/extension.json', json_encode(['name' => $name] + $manifest));
    }
}
