<?php

namespace ContAI\Tests\Unit\Services\Toc;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use HeadingParser;

class HeadingParserTest extends TestCase {

    private HeadingParser $parser;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->parser = new HeadingParser();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_extract_returns_empty_array_for_empty_content(): void {
        $this->assertSame([], $this->parser->extract(''));
    }

    public function test_extract_returns_empty_array_for_content_without_headings(): void {
        $this->assertSame([], $this->parser->extract('<p>Just a paragraph.</p>'));
    }

    public function test_extract_finds_single_heading(): void {
        $content = '<h2>Introduction</h2>';
        $result = $this->parser->extract($content);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['level']);
        $this->assertSame('Introduction', $result[0]['text']);
        $this->assertSame('<h2>Introduction</h2>', $result[0]['full_tag']);
    }

    public function test_extract_finds_multiple_heading_levels(): void {
        $content = '<h1>Title</h1><h2>Section</h2><h3>Subsection</h3>';
        $result = $this->parser->extract($content);

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]['level']);
        $this->assertSame(2, $result[1]['level']);
        $this->assertSame(3, $result[2]['level']);
    }

    public function test_extract_captures_heading_attributes(): void {
        $content = '<h2 class="title" id="intro">Introduction</h2>';
        $result = $this->parser->extract($content);

        $this->assertCount(1, $result);
        $this->assertSame('class="title" id="intro"', $result[0]['attributes']);
    }

    public function test_extract_handles_headings_with_html_content(): void {
        $content = '<h2><strong>Bold</strong> heading</h2>';
        $result = $this->parser->extract($content);

        $this->assertCount(1, $result);
        $this->assertSame('<strong>Bold</strong> heading', $result[0]['text']);
    }

    public function test_filter_by_levels(): void {
        $headings = [
            ['level' => 1, 'text' => 'H1'],
            ['level' => 2, 'text' => 'H2'],
            ['level' => 3, 'text' => 'H3'],
            ['level' => 4, 'text' => 'H4'],
        ];

        $result = $this->parser->filterByLevels($headings, [2, 3]);

        $this->assertCount(2, $result);
        $texts = array_column($result, 'text');
        $this->assertContains('H2', $texts);
        $this->assertContains('H3', $texts);
    }

    public function test_filter_by_levels_returns_empty_when_no_match(): void {
        $headings = [
            ['level' => 1, 'text' => 'H1'],
        ];

        $result = $this->parser->filterByLevels($headings, [5, 6]);

        $this->assertEmpty($result);
    }

    public function test_exclude_by_patterns_with_exact_match(): void {
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnArg(0);

        $headings = [
            ['level' => 2, 'text' => 'Introduction'],
            ['level' => 2, 'text' => 'Table of Contents'],
            ['level' => 2, 'text' => 'Conclusion'],
        ];

        $result = $this->parser->excludeByPatterns($headings, ['Table of Contents']);

        $this->assertCount(2, $result);
    }

    public function test_exclude_by_patterns_with_wildcard(): void {
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnArg(0);

        $headings = [
            ['level' => 2, 'text' => 'Chapter 1'],
            ['level' => 2, 'text' => 'Chapter 2'],
            ['level' => 2, 'text' => 'Summary'],
        ];

        $result = $this->parser->excludeByPatterns($headings, ['Chapter*']);

        $this->assertCount(1, $result);
    }

    public function test_exclude_by_patterns_returns_all_when_patterns_empty(): void {
        $headings = [
            ['level' => 2, 'text' => 'Test'],
        ];

        $result = $this->parser->excludeByPatterns($headings, []);

        $this->assertCount(1, $result);
    }

    public function test_remove_empty_headings(): void {
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnArg(0);

        $headings = [
            ['level' => 2, 'text' => 'Valid Heading'],
            ['level' => 2, 'text' => ''],
            ['level' => 2, 'text' => '   '],
            ['level' => 2, 'text' => 'Another Valid'],
        ];

        $result = $this->parser->removeEmpty($headings);

        $this->assertCount(2, $result);
    }

    public function test_clean_text_strips_tags_and_decodes_entities(): void {
        WP_Mock::userFunction('wp_strip_all_tags')
            ->with('<strong>Bold</strong> &amp; text')
            ->andReturn('Bold &amp; text');

        $result = $this->parser->cleanText('<strong>Bold</strong> &amp; text');

        $this->assertSame('Bold & text', $result);
    }

    public function test_clean_text_trims_whitespace(): void {
        WP_Mock::userFunction('wp_strip_all_tags')
            ->with('  spaced  ')
            ->andReturn('  spaced  ');

        $result = $this->parser->cleanText('  spaced  ');

        $this->assertSame('spaced', $result);
    }

    public function test_extract_all_heading_levels_h1_to_h6(): void {
        $content = '<h1>H1</h1><h2>H2</h2><h3>H3</h3><h4>H4</h4><h5>H5</h5><h6>H6</h6>';
        $result = $this->parser->extract($content);

        $this->assertCount(6, $result);
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame($i + 1, $result[$i]['level']);
        }
    }
}
