<?php

namespace ContAI\Tests\Unit\Services\Toc;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use AnchorGenerator;

class AnchorGeneratorTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('wp_strip_all_tags')->andReturnArg(0);
        WP_Mock::userFunction('remove_accents')->andReturnArg(0);
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_generate_creates_lowercase_hyphenated_anchor(): void {
        $generator = new AnchorGenerator();
        $result = $generator->generate('Hello World');

        $this->assertSame('hello-world', $result);
    }

    public function test_generate_removes_special_characters(): void {
        $generator = new AnchorGenerator();
        $result = $generator->generate('Hello! @World #2025');

        $this->assertSame('hello-world-2025', $result);
    }

    public function test_generate_handles_multiple_spaces(): void {
        $generator = new AnchorGenerator();
        $result = $generator->generate('Hello   World');

        $this->assertSame('hello-world', $result);
    }

    public function test_generate_creates_unique_anchors(): void {
        $generator = new AnchorGenerator();

        $first = $generator->generate('Introduction');
        $second = $generator->generate('Introduction');

        $this->assertSame('introduction', $first);
        $this->assertSame('introduction-2', $second);
    }

    public function test_generate_creates_sequential_unique_anchors(): void {
        $generator = new AnchorGenerator();

        $generator->generate('Section');
        $generator->generate('Section');
        $third = $generator->generate('Section');

        $this->assertSame('section-3', $third);
    }

    public function test_generate_with_uppercase_disabled(): void {
        $generator = new AnchorGenerator(false);
        $result = $generator->generate('Hello World');

        $this->assertSame('Hello-World', $result);
    }

    public function test_generate_with_underscore_separator(): void {
        $generator = new AnchorGenerator(true, false);
        $result = $generator->generate('Hello World');

        $this->assertSame('hello_world', $result);
    }

    public function test_generate_returns_heading_for_empty_string(): void {
        $generator = new AnchorGenerator();
        $result = $generator->generate('!!!');

        $this->assertSame('heading', $result);
    }

    public function test_reset_clears_used_anchors(): void {
        $generator = new AnchorGenerator();

        $generator->generate('Test');
        $generator->reset();
        $result = $generator->generate('Test');

        $this->assertSame('test', $result);
    }

    public function test_generate_trims_separators(): void {
        $generator = new AnchorGenerator();
        $result = $generator->generate(' Hello ');

        $this->assertSame('hello', $result);
    }

    public function test_generate_with_numbers(): void {
        $generator = new AnchorGenerator();
        $result = $generator->generate('Step 1 Getting Started');

        $this->assertSame('step-1-getting-started', $result);
    }

    public function test_unique_anchors_with_underscore_separator(): void {
        $generator = new AnchorGenerator(true, false);

        $first = $generator->generate('Test');
        $second = $generator->generate('Test');

        $this->assertSame('test', $first);
        $this->assertSame('test_2', $second);
    }
}
