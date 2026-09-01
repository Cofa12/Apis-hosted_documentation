<?php

namespace Cofa\ApiDocs\Support;

/**
 * A small, dependency free docblock parser that keeps multi line tag values
 * intact – which is what makes `@response 200 { ... }` blocks work.
 */
class DocBlockParser
{
    public function parse(string|false|null $comment): DocBlock
    {
        if (! is_string($comment) || trim($comment) === '') {
            return new DocBlock();
        }

        $lines = $this->clean($comment);

        $summaryLines = [];
        $descriptionLines = [];
        /** @var array<string, array<int, string>> $tags */
        $tags = [];
        $currentTag = null;
        $currentValue = [];

        $inSummary = true;

        foreach ($lines as $line) {
            if (preg_match('/^@([A-Za-z_][A-Za-z0-9_-]*)\s*(.*)$/', $line, $matches) === 1) {
                if ($currentTag !== null) {
                    $tags[$currentTag][] = trim(implode("\n", $currentValue));
                }

                $currentTag = strtolower($matches[1]);
                $currentValue = [$matches[2]];
                $inSummary = false;

                continue;
            }

            if ($currentTag !== null) {
                $currentValue[] = $line;

                continue;
            }

            if ($inSummary) {
                if (trim($line) === '') {
                    if ($summaryLines !== []) {
                        $inSummary = false;
                    }

                    continue;
                }

                $summaryLines[] = $line;

                continue;
            }

            $descriptionLines[] = $line;
        }

        if ($currentTag !== null) {
            $tags[$currentTag][] = trim(implode("\n", $currentValue));
        }

        return new DocBlock(
            summary: trim(implode(' ', array_map('trim', $summaryLines))),
            description: trim(implode("\n", $descriptionLines)),
            tags: $tags,
        );
    }

    /**
     * Strip the comment markers and leading asterisks.
     *
     * @return array<int, string>
     */
    protected function clean(string $comment): array
    {
        $comment = preg_replace('/^\s*\/\*\*?/', '', $comment) ?? $comment;
        $comment = preg_replace('/\*\/\s*$/', '', $comment) ?? $comment;

        $lines = preg_split('/\R/', $comment) ?: [];

        $cleaned = [];

        foreach ($lines as $line) {
            $line = preg_replace('/^\s*\*\s?/', '', $line) ?? $line;
            $cleaned[] = rtrim($line);
        }

        // Drop the empty lines the comment markers leave behind.
        while ($cleaned !== [] && trim($cleaned[0]) === '') {
            array_shift($cleaned);
        }

        while ($cleaned !== [] && trim(end($cleaned)) === '') {
            array_pop($cleaned);
        }

        return $cleaned;
    }

    /**
     * Parse a Scribe style parameter tag:
     *   name type required description. Example: value
     *
     * The `declared` list says which fields the author actually wrote, so that
     * a tag naming a parameter without a type does not silently claim one.
     *
     * @return array{name: string, type: string, required: bool, description: string, example: mixed, declared: array<int, string>}|null
     */
    public function parseParamTag(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $declared = [];
        $example = null;

        if (preg_match('/\bExample:\s*(.+)$/is', $value, $matches) === 1) {
            $example = $this->castExample(trim($matches[1]));
            $declared[] = 'example';
            $value = trim(substr($value, 0, (int) strpos($value, $matches[0])));
        }

        $parts = preg_split('/\s+/', $value, 4) ?: [];
        $name = array_shift($parts) ?? '';

        if ($name === '') {
            return null;
        }

        $known = ['string', 'integer', 'int', 'number', 'float', 'boolean', 'bool', 'array', 'object', 'file', 'date', 'datetime'];
        $type = 'string';

        if ($parts !== [] && in_array(strtolower(rtrim($parts[0], '[]')), $known, true)) {
            $type = strtolower(array_shift($parts));
            $declared[] = 'type';
        }

        $required = false;

        if ($parts !== [] && in_array(strtolower($parts[0]), ['required', 'optional'], true)) {
            $required = strtolower(array_shift($parts)) === 'required';
            $declared[] = 'required';
        }

        $description = trim(implode(' ', $parts));

        if ($description !== '') {
            $declared[] = 'description';
        }

        return [
            'name' => $name,
            'type' => $this->normaliseType($type),
            'required' => $required,
            'description' => $description,
            'example' => $example,
            'declared' => array_values(array_unique($declared)),
        ];
    }

    public function normaliseType(string $type): string
    {
        $type = strtolower(trim($type));
        $isList = str_ends_with($type, '[]');
        $base = rtrim($type, '[]');

        $base = match ($base) {
            'int' => 'integer',
            'float', 'double' => 'number',
            'bool' => 'boolean',
            'datetime' => 'date',
            '' => 'string',
            default => $base,
        };

        return $isList ? $base . '[]' : $base;
    }

    public function castExample(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === 'null') {
            return null;
        }

        if ($trimmed === 'true' || $trimmed === 'false') {
            return $trimmed === 'true';
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return trim($trimmed, "\"'");
    }

    /**
     * Parse a `@response [status] [description] {json}` tag.
     *
     * @return array{status: int, description: string, content: mixed}
     */
    public function parseResponseTag(string $value): array
    {
        $value = trim($value);
        $status = 200;

        if (preg_match('/^(\d{3})\b/', $value, $matches) === 1) {
            $status = (int) $matches[1];
            $value = trim(substr($value, strlen($matches[1])));
        }

        $content = null;
        $description = $value;

        $jsonStart = $this->firstJsonPosition($value);

        if ($jsonStart !== null) {
            $description = trim(substr($value, 0, $jsonStart));
            $json = trim(substr($value, $jsonStart));
            $decoded = json_decode($json, true);
            $content = json_last_error() === JSON_ERROR_NONE ? $decoded : $json;
        }

        return [
            'status' => $status,
            'description' => trim($description, " \t\n\r-–:"),
            'content' => $content,
        ];
    }

    protected function firstJsonPosition(string $value): ?int
    {
        $brace = strpos($value, '{');
        $bracket = strpos($value, '[');

        $positions = array_filter([$brace, $bracket], fn ($position) => $position !== false);

        return $positions === [] ? null : (int) min($positions);
    }
}
