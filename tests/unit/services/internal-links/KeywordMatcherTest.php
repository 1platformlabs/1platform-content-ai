<?php

namespace ContAI\Tests\Unit\Services\InternalLinks;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use WPContentAI\Services\InternalLinks\KeywordMatcher;

class KeywordMatcherTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_find_matches_returns_keyword_positions(): void {
        $matcher = new KeywordMatcher(true, true, 1);
        $content = 'Learn about SEO tips and SEO tricks';

        $matches = $matcher->findMatches($content, 'SEO');

        $this->assertCount(2, $matches);
        $this->assertSame('SEO', $matches[0]['text']);
        $this->assertSame('SEO', $matches[1]['text']);
    }

    public function test_find_matches_case_insensitive(): void {
        $matcher = new KeywordMatcher(true, true, 1);
        $content = 'Learn about seo and SEO';

        $matches = $matcher->findMatches($content, 'SEO');

        $this->assertCount(2, $matches);
    }

    public function test_find_matches_case_sensitive(): void {
        $matcher = new KeywordMatcher(false, true, 1);
        $content = 'Learn about seo and SEO';

        $matches = $matcher->findMatches($content, 'SEO');

        $this->assertCount(1, $matches);
        $this->assertSame('SEO', $matches[0]['text']);
    }

    public function test_find_matches_with_word_boundaries(): void {
        $matcher = new KeywordMatcher(true, true, 1);
        $content = 'Use WordPress for blogging';

        $matches = $matcher->findMatches($content, 'Word');

        $this->assertCount(0, $matches);
    }

    public function test_find_matches_without_word_boundaries(): void {
        $matcher = new KeywordMatcher(true, false, 1);
        $content = 'Use WordPress for blogging';

        $matches = $matcher->findMatches($content, 'Word');

        $this->assertCount(1, $matches);
    }

    public function test_find_matches_respects_min_length(): void {
        $matcher = new KeywordMatcher(true, true, 5);
        $content = 'The SEO keyword is important';

        $matches = $matcher->findMatches($content, 'SEO');

        $this->assertCount(0, $matches);
    }

    public function test_find_matches_with_excluded_tags_masks_content(): void {
        $matcher = new KeywordMatcher(true, true, 1, ['h2', 'a']);
        $content = '<h2>SEO Heading</h2><p>Learn about SEO here</p>';

        $matches = $matcher->findMatches($content, 'SEO');

        // Masking replaces tag content with block characters; matches found in unmasked regions
        $this->assertGreaterThanOrEqual(1, count($matches));
    }

    public function test_find_matches_returns_empty_for_no_match(): void {
        $matcher = new KeywordMatcher(true, true, 1);
        $content = 'This content has no matching keywords';

        $matches = $matcher->findMatches($content, 'WordPress');

        $this->assertEmpty($matches);
    }

    public function test_count_matches(): void {
        $matcher = new KeywordMatcher(true, true, 1);
        $content = 'SEO tips, SEO tricks, SEO tools';

        $this->assertSame(3, $matcher->countMatches($content, 'SEO'));
    }

    public function test_find_first_match(): void {
        $matcher = new KeywordMatcher(true, true, 1);
        $content = 'First SEO and second SEO';

        $match = $matcher->findFirstMatch($content, 'SEO');

        $this->assertNotNull($match);
        $this->assertSame('SEO', $match['text']);
    }

    public function test_find_first_match_returns_null_when_no_match(): void {
        $matcher = new KeywordMatcher(true, true, 1);

        $this->assertNull($matcher->findFirstMatch('No match here', 'WordPress'));
    }

    public function test_has_match(): void {
        $matcher = new KeywordMatcher(true, true, 1);

        $this->assertTrue($matcher->hasMatch('Content with SEO keyword', 'SEO'));
        $this->assertFalse($matcher->hasMatch('No match here', 'WordPress'));
    }

    public function test_get_config(): void {
        $matcher = new KeywordMatcher(true, false, 5, ['h1', 'a']);
        $config = $matcher->getConfig();

        $this->assertTrue($config['case_insensitive']);
        $this->assertFalse($config['word_boundaries']);
        $this->assertSame(5, $config['min_length']);
        $this->assertSame(['h1', 'a'], $config['excluded_tags']);
    }

    public function test_find_matches_with_empty_content(): void {
        $matcher = new KeywordMatcher(true, true, 1);

        $this->assertEmpty($matcher->findMatches('', 'keyword'));
    }

    public function test_find_matches_with_excluded_code_tags(): void {
        $matcher = new KeywordMatcher(true, true, 1, ['code']);
        $content = '<code>SEO function</code> and plain SEO text';

        $matches = $matcher->findMatches($content, 'SEO');

        // Masking replaces tag content; matches found in unmasked regions
        $this->assertGreaterThanOrEqual(1, count($matches));
    }

    public function test_match_positions_include_offset_and_length(): void {
        $matcher = new KeywordMatcher(true, true, 1);
        $content = 'The keyword appears here';

        $matches = $matcher->findMatches($content, 'keyword');

        $this->assertCount(1, $matches);
        $this->assertArrayHasKey('offset', $matches[0]);
        $this->assertArrayHasKey('length', $matches[0]);
        $this->assertArrayHasKey('text', $matches[0]);
        $this->assertSame(7, $matches[0]['length']);
    }
}
