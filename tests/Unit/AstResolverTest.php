<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Support\AstResolver;
use Cofa\ApiDocs\Support\ModelSchemaInspector;
use Cofa\ApiDocs\Support\ResourceSchemaInspector;
use Cofa\ApiDocs\Tests\Fixtures\Models\User;
use Cofa\ApiDocs\Tests\Fixtures\Requests\StoreUserRequest;
use Cofa\ApiDocs\Tests\Fixtures\Resources\AuthResource;
use Cofa\ApiDocs\Tests\Fixtures\Resources\UserResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AstResolverTest extends TestCase
{
    protected AstResolver $ast;

    protected function setUp(): void
    {
        $this->ast = new AstResolver();
    }

    #[Test]
    public function it_finds_a_method_in_a_class(): void
    {
        $this->assertNotNull($this->ast->findMethod(StoreUserRequest::class, 'rules'));
        $this->assertNull($this->ast->findMethod(StoreUserRequest::class, 'nope'));
        $this->assertNull($this->ast->findMethod('App\\Does\\Not\\Exist', 'rules'));
    }

    #[Test]
    public function it_resolves_a_returned_array_into_php_values(): void
    {
        $rules = $this->ast->returnedArray($this->ast->findMethod(StoreUserRequest::class, 'rules'));

        $this->assertSame('required|string|min:2|max:60', $rules['name']);
        $this->assertSame(['required', 'email', 'unique:users,email'], $rules['email']);
        $this->assertArrayHasKey('address.city', $rules);
    }

    #[Test]
    public function unresolvable_expressions_fall_back_to_the_printed_source(): void
    {
        $rules = $this->ast->returnedArray($this->ast->findMethod(StoreUserRequest::class, 'rules'));

        $this->assertStringContainsString('Rule::in', $rules['status'][1]);
    }

    #[Test]
    public function a_custom_fallback_can_interpret_those_expressions(): void
    {
        $resolver = new \Cofa\ApiDocs\Support\RuleExpressionResolver($this->ast);
        $rules = $this->ast->returnedArray($this->ast->findMethod(StoreUserRequest::class, 'rules'), $resolver);

        $this->assertSame('in:active,suspended', $rules['status'][1]);
        $this->assertSame('in:active,suspended,banned', $rules['role'][1], 'Rule::enum() is expanded.');
    }

    #[Test]
    public function it_rebuilds_the_payload_of_an_api_resource(): void
    {
        $inspector = new ResourceSchemaInspector($this->ast, new ModelSchemaInspector());
        $shape = $inspector->shapeFor(UserResource::class);

        $this->assertSame(1, $shape['id']);
        $this->assertSame('John Doe', $shape['name']);
        $this->assertSame('john@example.com', $shape['email']);
        $this->assertSame(['avatar' => 'https://example.com/avatars/1.png', 'city' => 'London'], $shape['profile']);
        $this->assertIsArray($shape['posts']);
        $this->assertSame(1, $shape['posts'][0]['id'], 'Nested resource collections are followed.');
        $this->assertSame('2026-01-15T09:30:00.000000Z', $shape['posts'][0]['created_at']);
    }

    #[Test]
    public function it_preserves_model_values_in_resource_payloads_as_objects_or_collections(): void
    {
        $inspector = new ResourceSchemaInspector($this->ast, new ModelSchemaInspector());
        $shape = $inspector->shapeFor(AuthResource::class);

        $this->assertSame(1, $shape['user']['id']);
        $this->assertSame('John Doe', $shape['user']['name']);
        $this->assertIsArray($shape['users']);
        $this->assertSame(1, $shape['users'][0]['id']);
        $this->assertSame('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9', $shape['access_token']);
        $this->assertSame(3600, $shape['expires_in']);
        $this->assertSame('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9', $shape['refresh_token']);
        $this->assertSame(3600, $shape['refresh_expires_in']);
    }

    #[Test]
    public function it_finds_models_from_nested_resource_namespaces(): void
    {
        $inspector = new ResourceSchemaInspector($this->ast, new ModelSchemaInspector());
        $shape = $inspector->shapeFor('Cofa\\ApiDocs\\Tests\\Fixtures\\Resources\\Accounts\\AuthResource');

        $this->assertSame(1, $shape['user']['id']);
        $this->assertSame('John Doe', $shape['user']['name']);
    }

    #[Test]
    public function a_resource_without_to_array_falls_back_to_the_model_it_is_named_after(): void
    {
        $inspector = new ResourceSchemaInspector($this->ast, new ModelSchemaInspector());
        $shape = $inspector->shapeFor(\Cofa\ApiDocs\Tests\Fixtures\Resources\UserCollection::class);

        $this->assertSame(1, $shape['id']);
        $this->assertSame('john@example.com', $shape['email']);
        $this->assertArrayNotHasKey('password', $shape);
    }

    #[Test]
    public function it_recognises_resources_and_models(): void
    {
        $inspector = new ResourceSchemaInspector($this->ast, new ModelSchemaInspector());

        $this->assertTrue($inspector->isResource(UserResource::class));
        $this->assertFalse($inspector->isResource(User::class));
        $this->assertFalse($inspector->isResource('Nope\\Missing'));
    }

    #[Test]
    public function it_describes_a_model_from_its_casts_and_fillable(): void
    {
        $shape = (new ModelSchemaInspector())->shapeFor(User::class);

        $this->assertSame(1, $shape['id']);
        $this->assertSame('John Doe', $shape['name']);
        $this->assertSame('john@example.com', $shape['email']);
        $this->assertTrue($shape['is_admin']);
        $this->assertSame([], $shape['settings']);
        $this->assertSame('2026-01-15T09:30:00.000000Z', $shape['verified_at']);
        $this->assertArrayHasKey('created_at', $shape);
        $this->assertArrayNotHasKey('password', $shape, 'Hidden attributes stay hidden.');
    }

    #[Test]
    public function an_unparsable_file_is_reported_as_null_rather_than_thrown(): void
    {
        $path = sys_get_temp_dir() . '/api-docs-broken-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, '<?php class Broken { function (');

        try {
            $this->assertNull($this->ast->parseFile($path));
            $this->assertNull($this->ast->parseFile('/tmp/not-a-real-file.php'));
        } finally {
            @unlink($path);
        }
    }
}
