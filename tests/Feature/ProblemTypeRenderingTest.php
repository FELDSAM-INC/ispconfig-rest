<?php

namespace Tests\Feature;

use App\Exceptions\ProblemAuthorizationException;
use App\Support\ProblemType;
use App\Support\ProblemTypeCollector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Problem type rendering (spec 023 FR-001, FR-002, FR-006, FR-008): typed
 * refusals carry the documented type URI and extension members next to the
 * unchanged status, title and detail; every other problem keeps about:blank.
 */
class ProblemTypeRenderingTest extends TestCase
{
    private const BASE = 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('api/v1/problem-type-probe/{case}', function (string $case): void {
            match ($case) {
                'locked' => throw new ProblemAuthorizationException(
                    'The account is locked; its services cannot be enabled or added.',
                    ProblemType::ACCOUNT_LOCKED
                ),
                'limit' => throw new ProblemAuthorizationException(
                    'You have reached the maximum number of websites allowed for your account.',
                    ProblemType::LIMIT_REACHED,
                    ['limit' => ['name' => 'limit_web_domain', 'scope' => 'client', 'max' => 1, 'used' => 1]]
                ),
                'plain' => throw new AuthorizationException('You do not have permission to update this resource.'),
                'tagged' => $this->throwTagged(),
                'validation' => throw ValidationException::withMessages(['ssl' => 'The ssl field must be true or false.']),
            };
        });
    }

    protected function throwTagged(): never
    {
        $collector = app(ProblemTypeCollector::class);
        $collector->tag('ssl', ProblemType::FEATURE_NOT_ALLOWED);
        // Tagged without an error: must not appear in error_types.
        $collector->tag('server_id', ProblemType::SERVER_NOT_ASSIGNED);

        throw ValidationException::withMessages([
            'ssl' => "The SSL option is not included in the account's plan.",
            'domain' => 'The domain field is required.',
        ]);
    }

    public function test_typed_refusal_renders_type_and_extension_members(): void
    {
        $this->getJson('/api/v1/problem-type-probe/limit')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => self::BASE.'limit-reached',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'You have reached the maximum number of websites allowed for your account.',
                'limit' => ['name' => 'limit_web_domain', 'scope' => 'client', 'max' => 1, 'used' => 1],
            ]);

        $this->getJson('/api/v1/problem-type-probe/locked')
            ->assertStatus(403)
            ->assertExactJson([
                'type' => self::BASE.'account-locked',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'The account is locked; its services cannot be enabled or added.',
            ]);
    }

    public function test_typed_refusal_is_still_an_authorization_exception(): void
    {
        $this->assertInstanceOf(
            AuthorizationException::class,
            new ProblemAuthorizationException('Denied.', ProblemType::ACCOUNT_LOCKED)
        );
    }

    public function test_untyped_refusal_keeps_about_blank(): void
    {
        $this->getJson('/api/v1/problem-type-probe/plain')
            ->assertStatus(403)
            ->assertExactJson([
                'type' => 'about:blank',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'You do not have permission to update this resource.',
            ]);
    }

    public function test_validation_failure_uses_validation_failed_without_error_types(): void
    {
        $this->getJson('/api/v1/problem-type-probe/validation')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => self::BASE.'validation-failed',
                'title' => 'Validation failed',
                'status' => 422,
                'detail' => 'One or more fields are invalid.',
                'errors' => ['ssl' => ['The ssl field must be true or false.']],
            ]);
    }

    public function test_error_types_list_only_tagged_fields_with_errors(): void
    {
        $response = $this->getJson('/api/v1/problem-type-probe/tagged')->assertStatus(422);

        $this->assertSame(['ssl' => self::BASE.'feature-not-allowed'], $response->json('error_types'));
        $this->assertSame(['ssl', 'domain'], array_keys($response->json('errors')));
    }

    public function test_tags_do_not_leak_into_the_next_request(): void
    {
        $this->getJson('/api/v1/problem-type-probe/tagged')->assertJsonPath('error_types.ssl', self::BASE.'feature-not-allowed');

        $this->getJson('/api/v1/problem-type-probe/validation')
            ->assertStatus(422)
            ->assertJsonMissingPath('error_types');
    }

    public function test_type_names_match_the_documentation_and_contract(): void
    {
        preg_match_all('/^## (\S+)$/m', (string) file_get_contents(base_path('docs/problems.md')), $headings);
        $this->assertSame(ProblemType::NAMES, $headings[1]);

        $contract = (string) file_get_contents(base_path('api/components/schemas/Problem.yaml'));

        foreach (ProblemType::NAMES as $name) {
            $this->assertSame(self::BASE.$name, ProblemType::uri($name));
            $this->assertStringContainsString("`{$name}`", $contract);
        }
    }
}
