<?php

namespace ContAI\Tests\Unit\Models;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use WPContentAI\Database\Models\InternalLink;

class InternalLinkTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('current_time')->andReturn('2025-01-15 10:00:00');
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_constructor_with_defaults(): void {
        $link = new InternalLink();

        $this->assertNull($link->getId());
        $this->assertSame(0, $link->getSourcePostId());
        $this->assertSame(0, $link->getTargetPostId());
        $this->assertSame(0, $link->getKeywordId());
        $this->assertSame('active', $link->getStatus());
    }

    public function test_constructor_fills_from_data(): void {
        $data = [
            'id' => 5,
            'source_post_id' => 10,
            'target_post_id' => 20,
            'keyword_id' => 3,
            'status' => 'inactive',
        ];

        $link = new InternalLink($data);

        $this->assertSame(5, $link->getId());
        $this->assertSame(10, $link->getSourcePostId());
        $this->assertSame(20, $link->getTargetPostId());
        $this->assertSame(3, $link->getKeywordId());
        $this->assertSame('inactive', $link->getStatus());
    }

    public function test_set_status_with_valid_status(): void {
        $link = new InternalLink();
        $link->setStatus('inactive');

        $this->assertSame('inactive', $link->getStatus());
    }

    public function test_set_status_with_invalid_status_throws_exception(): void {
        $link = new InternalLink();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status: unknown');
        $link->setStatus('unknown');
    }

    public function test_is_active(): void {
        $link = new InternalLink(['status' => 'active']);
        $this->assertTrue($link->isActive());

        $link->setStatus('inactive');
        $this->assertFalse($link->isActive());
    }

    public function test_activate(): void {
        $link = new InternalLink(['status' => 'inactive']);
        $result = $link->activate();

        $this->assertSame('active', $link->getStatus());
        $this->assertSame($link, $result);
    }

    public function test_deactivate(): void {
        $link = new InternalLink(['status' => 'active']);
        $result = $link->deactivate();

        $this->assertSame('inactive', $link->getStatus());
        $this->assertSame($link, $result);
    }

    public function test_validate_passes_for_valid_data(): void {
        $link = new InternalLink([
            'source_post_id' => 10,
            'target_post_id' => 20,
            'keyword_id' => 3,
        ]);

        $this->assertTrue($link->validate());
    }

    public function test_validate_fails_for_zero_source_post_id(): void {
        $link = new InternalLink([
            'source_post_id' => 0,
            'target_post_id' => 20,
            'keyword_id' => 3,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source post ID must be greater than 0');
        $link->validate();
    }

    public function test_validate_fails_for_zero_target_post_id(): void {
        $link = new InternalLink([
            'source_post_id' => 10,
            'target_post_id' => 0,
            'keyword_id' => 3,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target post ID must be greater than 0');
        $link->validate();
    }

    public function test_validate_fails_for_same_source_and_target(): void {
        $link = new InternalLink([
            'source_post_id' => 10,
            'target_post_id' => 10,
            'keyword_id' => 3,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source and target post IDs cannot be the same');
        $link->validate();
    }

    public function test_validate_fails_for_zero_keyword_id(): void {
        $link = new InternalLink([
            'source_post_id' => 10,
            'target_post_id' => 20,
            'keyword_id' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword ID must be greater than 0');
        $link->validate();
    }

    public function test_to_array(): void {
        $link = new InternalLink([
            'id' => 1,
            'source_post_id' => 10,
            'target_post_id' => 20,
            'keyword_id' => 3,
            'status' => 'active',
        ]);

        $array = $link->toArray();

        $this->assertSame(1, $array['id']);
        $this->assertSame(10, $array['source_post_id']);
        $this->assertSame(20, $array['target_post_id']);
        $this->assertSame(3, $array['keyword_id']);
        $this->assertSame('active', $array['status']);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
    }

    public function test_fluent_setters(): void {
        $link = new InternalLink();

        $result = $link->setId(1)
            ->setSourcePostId(10)
            ->setTargetPostId(20)
            ->setKeywordId(3);

        $this->assertSame($link, $result);
        $this->assertSame(1, $link->getId());
        $this->assertSame(10, $link->getSourcePostId());
        $this->assertSame(20, $link->getTargetPostId());
        $this->assertSame(3, $link->getKeywordId());
    }
}
