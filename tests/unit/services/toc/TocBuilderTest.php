<?php

namespace ContAI\Tests\Unit\Services\Toc;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use TocBuilder;

class TocBuilderTest extends TestCase {

    private TocBuilder $builder;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('esc_attr')->andReturnArg(0);
        WP_Mock::userFunction('esc_html')->andReturnArg(0);

        $this->builder = new TocBuilder();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_build_returns_empty_string_for_empty_headings(): void {
        $this->assertSame('', $this->builder->build([]));
    }

    public function test_build_creates_flat_list_for_same_level(): void {
        $headings = [
            ['level' => 2, 'anchor' => 'first', 'clean_text' => 'First'],
            ['level' => 2, 'anchor' => 'second', 'clean_text' => 'Second'],
        ];

        $html = $this->builder->build($headings);

        $this->assertStringContainsString('<ul class="toc-list toc-list-2">', $html);
        $this->assertStringContainsString('<a href="#first" class="toc-link">First</a>', $html);
        $this->assertStringContainsString('<a href="#second" class="toc-link">Second</a>', $html);
        $this->assertStringContainsString('toc-level-2', $html);
    }

    public function test_build_creates_nested_list_for_different_levels(): void {
        $headings = [
            ['level' => 2, 'anchor' => 'section', 'clean_text' => 'Section'],
            ['level' => 3, 'anchor' => 'subsection', 'clean_text' => 'Subsection'],
        ];

        $html = $this->builder->build($headings);

        $this->assertStringContainsString('toc-list-2', $html);
        $this->assertStringContainsString('toc-list-3', $html);
        $this->assertStringContainsString('toc-level-2', $html);
        $this->assertStringContainsString('toc-level-3', $html);
    }

    public function test_build_handles_single_heading(): void {
        $headings = [
            ['level' => 2, 'anchor' => 'only', 'clean_text' => 'Only Heading'],
        ];

        $html = $this->builder->build($headings);

        $this->assertStringContainsString('<a href="#only" class="toc-link">Only Heading</a>', $html);
    }

    public function test_build_creates_proper_html_structure(): void {
        $headings = [
            ['level' => 2, 'anchor' => 'test', 'clean_text' => 'Test'],
        ];

        $html = $this->builder->build($headings);

        $this->assertStringStartsWith('<ul', $html);
        $this->assertStringEndsWith('</ul>', $html);
        $this->assertStringContainsString('<li', $html);
        $this->assertStringContainsString('</li>', $html);
    }
}
