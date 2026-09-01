<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\OpenApi\CodeSampleGenerator;
use Cofa\ApiDocs\OpenApi\Spec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CodeSampleGeneratorTest extends TestCase
{
    protected function spec(): Spec
    {
        return Spec::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Demo', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://api.demo.test']],
            'paths' => [
                '/users/{user}' => [
                    'post' => [
                        'summary' => 'Update user',
                        'parameters' => [
                            ['name' => 'user', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'example' => 42],
                            ['name' => 'notify', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'boolean'], 'example' => 'true'],
                        ],
                        'x-headers' => [
                            ['name' => 'Accept', 'value' => 'application/json', 'required' => true],
                            ['name' => 'Authorization', 'value' => 'Bearer {TOKEN}', 'required' => true],
                        ],
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                    'example' => ['name' => "O'Brien", 'age' => 34],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ]);
    }

    protected function operation(): \Cofa\ApiDocs\OpenApi\Operation
    {
        return $this->spec()->operations()[0];
    }

    #[Test]
    public function it_only_renders_the_configured_languages(): void
    {
        $samples = (new CodeSampleGenerator(['curl', 'python', 'nope']))->for($this->operation());

        $this->assertSame(['curl', 'python'], array_column($samples, 'id'));
    }

    #[Test]
    public function it_substitutes_path_parameters_and_required_query_values(): void
    {
        $url = (new CodeSampleGenerator())->url($this->operation());

        $this->assertSame('https://api.demo.test/users/42?notify=true', $url);
    }

    #[Test]
    public function the_base_url_can_be_overridden(): void
    {
        $url = (new CodeSampleGenerator())->url($this->operation(), 'http://localhost:8000');

        $this->assertStringStartsWith('http://localhost:8000/users/42', $url);
    }

    #[Test]
    public function it_renders_a_curl_command(): void
    {
        $code = (new CodeSampleGenerator())->render('curl', $this->operation());

        $this->assertStringContainsString('curl --request POST', $code);
        $this->assertStringContainsString("--header 'Authorization: Bearer {TOKEN}'", $code);
        $this->assertStringContainsString('--data', $code);
        $this->assertStringContainsString("'\\''", $code, 'Single quotes in the body are escaped for the shell.');
    }

    #[Test]
    public function it_renders_a_fetch_call(): void
    {
        $code = (new CodeSampleGenerator())->render('javascript', $this->operation());

        $this->assertStringContainsString("await fetch('https://api.demo.test/users/42?notify=true'", $code);
        $this->assertStringContainsString("method: 'POST'", $code);
        $this->assertStringContainsString('JSON.stringify', $code);
    }

    #[Test]
    public function it_renders_a_guzzle_request(): void
    {
        $code = (new CodeSampleGenerator())->render('php', $this->operation());

        $this->assertStringContainsString('GuzzleHttp\\Client', $code);
        $this->assertStringContainsString("'json' => [", $code);
        $this->assertStringContainsString("'age' => 34,", $code);
    }

    #[Test]
    public function it_renders_a_python_request(): void
    {
        $code = (new CodeSampleGenerator(['python']))->render('python', $this->operation());

        $this->assertStringContainsString('import requests', $code);
        $this->assertStringContainsString('requests.post(', $code);
        $this->assertStringContainsString('json=payload', $code);
    }

    #[Test]
    public function it_renders_objects_inside_an_example_payload(): void
    {
        $spec = Spec::fromArray([
            'servers' => [['url' => 'https://api.demo.test']],
            'paths' => ['/things' => ['post' => [
                'requestBody' => ['content' => ['application/json' => [
                    'schema' => ['type' => 'object'],
                    'example' => ['options' => (object) [], 'meta' => (object) ['page' => 1]],
                ]]],
                'responses' => ['201' => ['description' => 'Created']],
            ]]],
        ]);

        $generator = new CodeSampleGenerator(['curl', 'javascript', 'php', 'python']);
        $samples = $generator->for($spec->operations()[0]);

        $this->assertCount(4, $samples, 'An object in the payload must not break sample generation.');
        $this->assertStringContainsString("'page' => 1,", $generator->render('php', $spec->operations()[0]));
        $this->assertStringContainsString("'page': 1,", $generator->render('python', $spec->operations()[0]));
    }

    #[Test]
    public function optional_query_parameters_stay_out_of_the_sample_url(): void
    {
        $spec = Spec::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://api.demo.test']],
            'paths' => ['/users' => ['get' => [
                'parameters' => [['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer'], 'example' => 2]],
                'responses' => ['200' => ['description' => 'OK']],
            ]]],
        ]);

        $this->assertSame('https://api.demo.test/users', (new CodeSampleGenerator())->url($spec->operations()[0]));
    }
}
