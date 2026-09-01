<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Support\JsonHighlighter;
use Cofa\ApiDocs\Support\Markdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RenderingHelpersTest extends TestCase
{
    #[Test]
    public function it_highlights_json_tokens(): void
    {
        $html = JsonHighlighter::of(['id' => 1, 'name' => 'Ada', 'ok' => true, 'nope' => null, 'score' => 1.5]);

        $this->assertStringContainsString('<span class="tok-key">&quot;id&quot;:</span>', $html);
        $this->assertStringContainsString('<span class="tok-num">1</span>', $html);
        $this->assertStringContainsString('<span class="tok-str">&quot;Ada&quot;</span>', $html);
        $this->assertStringContainsString('<span class="tok-lit">true</span>', $html);
        $this->assertStringContainsString('<span class="tok-lit">null</span>', $html);
        $this->assertStringContainsString('<span class="tok-num">1.5</span>', $html);
    }

    #[Test]
    public function it_escapes_html_in_the_payload(): void
    {
        $html = JsonHighlighter::of(['bio' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function it_survives_escaped_quotes_inside_strings(): void
    {
        $html = JsonHighlighter::of(['name' => 'John "JD" Doe', 'after' => 1]);

        $this->assertStringContainsString('<span class="tok-key">&quot;after&quot;:</span>', $html);
    }

    #[Test]
    public function an_empty_payload_renders_nothing(): void
    {
        $this->assertSame('', JsonHighlighter::of(null));
    }

    #[Test]
    public function it_renders_inline_markdown_safely(): void
    {
        $html = Markdown::inline('Must be `active` or **suspended** — see [docs](https://example.com).');

        $this->assertStringContainsString('<code>active</code>', $html);
        $this->assertStringContainsString('<strong>suspended</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com"', $html);
    }

    #[Test]
    public function markdown_escapes_everything_else(): void
    {
        $html = Markdown::inline('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    #[Test]
    public function markdown_links_only_accept_http_urls(): void
    {
        $html = Markdown::inline('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('<a href', $html);
    }

    #[Test]
    public function it_renders_paragraph_blocks(): void
    {
        $html = Markdown::blocks("First line.\n\nSecond `block`.");

        $this->assertSame('<p>First line.</p><p>Second <code>block</code>.</p>', $html);
    }
}
