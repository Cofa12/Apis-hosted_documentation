<?php

namespace Cofa\ApiDocs\Support;

/**
 * A deliberately tiny inline markdown renderer. The descriptions the generator
 * produces only ever use backticks, bold and links, so pulling in a full
 * markdown parser would be a dependency the package does not need.
 */
class Markdown
{
    public static function inline(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $replacements = [
            // `code`
            '/`([^`]+)`/' => '<code>$1</code>',
            // **bold**
            '/\*\*([^*]+)\*\*/' => '<strong>$1</strong>',
            // [label](https://example.com)
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/' => '<a href="$2" rel="noopener noreferrer" target="_blank">$1</a>',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html) ?? $html;
        }

        return $html;
    }

    /** Block level rendering: paragraphs separated by blank lines. */
    public static function blocks(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }

            $html .= '<p>' . self::inline(trim($paragraph)) . '</p>';
        }

        return $html;
    }
}
