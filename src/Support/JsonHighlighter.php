<?php

namespace Cofa\ApiDocs\Support;

/**
 * Server side JSON syntax highlighting, so the documentation needs no
 * third party highlighter and works with a strict content security policy.
 */
class JsonHighlighter
{
    public static function highlight(string $json): string
    {
        $escaped = htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $pattern = '/(&quot;(?:\\\\u[a-zA-Z0-9]{4}|\\\\[^u]|(?!&quot;).)*&quot;\s*:)'
            . '|(&quot;(?:\\\\u[a-zA-Z0-9]{4}|\\\\[^u]|(?!&quot;).)*&quot;)'
            . '|\b(true|false|null)\b'
            . '|(-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?)/';

        $highlighted = preg_replace_callback($pattern, static function (array $matches): string {
            if (($matches[1] ?? '') !== '') {
                return '<span class="tok-key">' . $matches[1] . '</span>';
            }

            if (($matches[2] ?? '') !== '') {
                return '<span class="tok-str">' . $matches[2] . '</span>';
            }

            if (($matches[3] ?? '') !== '') {
                return '<span class="tok-lit">' . $matches[3] . '</span>';
            }

            return '<span class="tok-num">' . ($matches[4] ?? '') . '</span>';
        }, $escaped);

        return $highlighted ?? $escaped;
    }

    /** Pretty print any value and highlight it in one step. */
    public static function of(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $json = is_string($value)
            ? $value
            : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::highlight($json);
    }
}
