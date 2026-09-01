<?php

namespace Cofa\ApiDocs\Support;

/**
 * The parsed result of a PHP docblock: a summary, a longer description and
 * every tag with its (possibly multi line) value.
 */
class DocBlock
{
    /** @param array<string, array<int, string>> $tags */
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public array $tags = [],
    ) {
    }

    public function hasTag(string $name): bool
    {
        return isset($this->tags[strtolower($name)]);
    }

    /** @return array<int, string> */
    public function tags(string $name): array
    {
        return $this->tags[strtolower($name)] ?? [];
    }

    public function tag(string $name): ?string
    {
        return $this->tags(strtolower($name))[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->summary === '' && $this->description === '' && $this->tags === [];
    }
}
