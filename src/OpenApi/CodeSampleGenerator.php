<?php

namespace Cofa\ApiDocs\OpenApi;

/**
 * Ready to paste request samples for each operation.
 */
class CodeSampleGenerator
{
    public const LANGUAGES = [
        'curl' => ['label' => 'cURL', 'language' => 'bash'],
        'javascript' => ['label' => 'JavaScript', 'language' => 'javascript'],
        'php' => ['label' => 'PHP', 'language' => 'php'],
        'python' => ['label' => 'Python', 'language' => 'python'],
    ];

    /** @param array<int, string> $languages */
    public function __construct(protected array $languages = ['curl', 'javascript', 'php'])
    {
    }

    /**
     * @return array<int, array{id: string, label: string, language: string, code: string}>
     */
    public function for(Operation $operation, string $baseUrl = ''): array
    {
        $samples = [];

        foreach ($this->languages as $language) {
            if (! isset(self::LANGUAGES[$language])) {
                continue;
            }

            $samples[] = [
                'id' => $language,
                'label' => self::LANGUAGES[$language]['label'],
                'language' => self::LANGUAGES[$language]['language'],
                'code' => $this->render($language, $operation, $baseUrl),
            ];
        }

        return $samples;
    }

    public function render(string $language, Operation $operation, string $baseUrl = ''): string
    {
        return match ($language) {
            'curl' => $this->curl($operation, $baseUrl),
            'javascript' => $this->javascript($operation, $baseUrl),
            'php' => $this->php($operation, $baseUrl),
            'python' => $this->python($operation, $baseUrl),
            default => '',
        };
    }

    public function url(Operation $operation, string $baseUrl = ''): string
    {
        $url = $operation->resolvedUrl($baseUrl !== '' ? $baseUrl : null);
        $query = $this->queryString($operation);

        return $query === '' ? $url : $url . '?' . $query;
    }

    protected function queryString(Operation $operation): string
    {
        $pairs = [];

        foreach ($operation->queryParameters() as $parameter) {
            if (! ($parameter['required'] ?? false)) {
                continue;
            }

            $name = (string) ($parameter['name'] ?? '');
            $example = $parameter['example'] ?? null;

            if ($example === null && isset($parameter['schema_object'])) {
                /** @var SchemaObject $schema */
                $schema = $parameter['schema_object'];
                $example = $schema->example();
            }

            if ($name === '' || ! is_scalar($example)) {
                continue;
            }

            $pairs[] = rawurlencode($name) . '=' . rawurlencode((string) $example);
        }

        return implode('&', $pairs);
    }

    /** @return array<string, string> */
    protected function headers(Operation $operation): array
    {
        $headers = [];

        foreach ($operation->headers() as $header) {
            if ($header['value'] === '') {
                continue;
            }

            $headers[$header['name']] = $header['value'];
        }

        return $headers;
    }

    protected function curl(Operation $operation, string $baseUrl): string
    {
        $lines = ["curl --request {$operation->method} \\"];
        $lines[] = "  --url '" . $this->url($operation, $baseUrl) . "'";

        foreach ($this->headers($operation) as $name => $value) {
            $lines[count($lines) - 1] .= " \\";
            $lines[] = "  --header '{$name}: {$value}'";
        }

        $body = $operation->requestExampleJson();

        if ($body !== '') {
            $lines[count($lines) - 1] .= " \\";
            $lines[] = "  --data '" . str_replace("'", "'\\''", $body) . "'";
        }

        return implode("\n", $lines);
    }

    protected function javascript(Operation $operation, string $baseUrl): string
    {
        $headers = $this->headers($operation);
        $body = $operation->requestExample();

        $options = ["  method: '{$operation->method}'"];

        if ($headers !== []) {
            $entries = [];

            foreach ($headers as $name => $value) {
                $entries[] = "    '{$name}': '" . addslashes($value) . "'";
            }

            $options[] = "  headers: {\n" . implode(",\n", $entries) . "\n  }";
        }

        if ($body !== null && $body !== []) {
            $json = (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $json = str_replace("\n", "\n  ", $json);
            $options[] = "  body: JSON.stringify({$json})";
        }

        return "const response = await fetch('" . $this->url($operation, $baseUrl) . "', {\n"
            . implode(",\n", $options) . "\n});\n\n"
            . "const data = await response.json();\n"
            . "console.log(data);";
    }

    protected function php(Operation $operation, string $baseUrl): string
    {
        $headers = $this->headers($operation);
        $body = $operation->requestExample();

        $options = [];

        if ($headers !== []) {
            $entries = [];

            foreach ($headers as $name => $value) {
                $entries[] = "        '{$name}' => '" . addslashes($value) . "',";
            }

            $options[] = "    'headers' => [\n" . implode("\n", $entries) . "\n    ],";
        }

        if (is_array($body) && $body !== []) {
            $options[] = "    'json' => " . $this->phpArray($body, 2) . ',';
        }

        return "\$client = new \\GuzzleHttp\\Client();\n\n"
            . "\$response = \$client->request('{$operation->method}', '" . $this->url($operation, $baseUrl) . "'"
            . ($options === [] ? '' : ", [\n" . implode("\n", $options) . "\n]")
            . ");\n\n"
            . "\$data = json_decode((string) \$response->getBody(), true);";
    }

    protected function python(Operation $operation, string $baseUrl): string
    {
        $headers = $this->headers($operation);
        $body = $operation->requestExample();

        $lines = ['import requests', ''];

        if ($headers !== []) {
            $entries = [];

            foreach ($headers as $name => $value) {
                $entries[] = "    '{$name}': '" . addslashes($value) . "',";
            }

            $lines[] = "headers = {\n" . implode("\n", $entries) . "\n}";
        }

        $arguments = ["'" . $this->url($operation, $baseUrl) . "'"];

        if ($headers !== []) {
            $arguments[] = 'headers=headers';
        }

        if ($body !== null && $body !== []) {
            $lines[] = 'payload = ' . $this->pythonValue($body, 0);
            $arguments[] = 'json=payload';
        }

        $lines[] = '';
        $lines[] = 'response = requests.' . strtolower($operation->method) . '(' . implode(', ', $arguments) . ')';
        $lines[] = 'print(response.json())';

        return implode("\n", $lines);
    }

    /** @param array<mixed> $value */
    protected function phpArray(array $value, int $indent): string
    {
        $value = $this->normalise($value);

        $pad = str_repeat('    ', $indent);
        $innerPad = str_repeat('    ', $indent + 1);
        $isList = array_is_list($value);
        $lines = [];

        foreach ($value as $key => $item) {
            $rendered = is_array($item)
                ? $this->phpArray($item, $indent + 1)
                : $this->phpScalar($item);

            $lines[] = $isList ? $innerPad . $rendered . ',' : $innerPad . "'" . $key . "' => " . $rendered . ',';
        }

        return $lines === [] ? '[]' : "[\n" . implode("\n", $lines) . "\n" . $pad . ']';
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected function normalise(array $value): array
    {
        return array_map(fn ($item) => is_object($item) ? (array) $item : $item, $value);
    }

    protected function phpScalar(mixed $value): string
    {
        if (is_object($value)) {
            return $this->phpArray((array) $value, 0);
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . addslashes((string) $value) . "'",
        };
    }

    protected function pythonValue(mixed $value, int $indent): string
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $pad = str_repeat('    ', $indent);
            $innerPad = str_repeat('    ', $indent + 1);
            $isList = array_is_list($value);
            $lines = [];

            foreach ($value as $key => $item) {
                $rendered = $this->pythonValue($item, $indent + 1);
                $lines[] = $isList ? $innerPad . $rendered . ',' : $innerPad . "'" . $key . "': " . $rendered . ',';
            }

            $open = $isList ? '[' : '{';
            $close = $isList ? ']' : '}';

            return $lines === [] ? $open . $close : $open . "\n" . implode("\n", $lines) . "\n" . $pad . $close;
        }

        return match (true) {
            is_bool($value) => $value ? 'True' : 'False',
            $value === null => 'None',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . addslashes((string) $value) . "'",
        };
    }
}
