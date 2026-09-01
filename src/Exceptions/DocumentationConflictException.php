<?php

namespace Cofa\ApiDocs\Exceptions;

use Cofa\ApiDocs\Scanning\Conflict;
use RuntimeException;

/**
 * Thrown by `api-docs:generate` when `strict_precedence` is on and a docblock
 * and an attribute describe the same thing differently — for teams who want
 * documentation drift to fail the build rather than resolve itself quietly.
 */
class DocumentationConflictException extends RuntimeException
{
    /** @param array<int, Conflict> $conflicts */
    public function __construct(protected array $conflicts = [])
    {
        parent::__construct($this->build($conflicts));
    }

    /** @return array<int, Conflict> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @param array<int, Conflict> $conflicts */
    protected function build(array $conflicts): string
    {
        $count = count($conflicts);

        $message = $count . ' documentation ' . ($count === 1 ? 'conflict' : 'conflicts')
            . " between docblocks and attributes (strict_precedence is on):\n";

        foreach ($conflicts as $conflict) {
            $message .= "\n  - " . $conflict->message();
        }

        return $message;
    }
}
