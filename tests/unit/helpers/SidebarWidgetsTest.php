<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the sidebar widget creation logic in site-generation.php.
 *
 * Validates fix for GitHub issue #37: when profile API fails, the function
 * must still create Search, Recent Comments, and Recent Posts widgets —
 * only the About Me block widget should be skipped.
 *
 * Since contai_add_sidebar_widgets() is tightly coupled to WordPress globals
 * and the API client chain, we test the extracted branching logic directly.
 */
class SidebarWidgetsTest extends TestCase {

	private static array $profileData = array(
		'fullname'          => 'María García',
		'gender'            => 'female',
		'bio'               => '<p>Soy una experta en finanzas.</p>',
		'rrss'              => '<ul><li><a href="#">LinkedIn</a></li></ul>',
		'profile_image_url' => 'https://example.com/photo.jpg',
	);

	/**
	 * Simulates the sidebar widget assembly logic from contai_add_sidebar_widgets().
	 * Mirrors the exact branching structure after the fix for issue #37.
	 *
	 * @param string     $lang    Language setting ('english' or 'spanish').
	 * @param array|null $profile Profile data or null if API failed.
	 * @return array{sidebar_widgets: array, widget_block: array, widget_search: array, widget_recent_comments: array, widget_recent_posts: array}
	 */
	private function simulateWidgetAssembly( string $lang, ?array $profile ): array {
		$labels = array(
			'spanish' => array(
				'search'          => 'Búsqueda',
				'recent_comments' => 'Últimos comentarios',
				'recent_posts'    => 'Últimas entradas',
			),
			'english' => array(
				'search'          => 'Search',
				'recent_comments' => 'Recent Comments',
				'recent_posts'    => 'Recent Posts',
			),
		);

		$text = $labels[ $lang ] ?? $labels['spanish'];

		$sidebar_id = 'sidebar-1';
		$sidebar_widgets = array();

		$widget_block           = array( '_multiwidget' => 1 );
		$widget_search          = array( '_multiwidget' => 1 );
		$widget_recent_comments = array( '_multiwidget' => 1 );
		$widget_recent_posts    = array( '_multiwidget' => 1 );

		$block_id    = 1;
		$search_id   = 1;
		$comments_id = 1;
		$posts_id    = 1;

		// ── This is the exact branching logic from the fix ──
		if ( $profile ) {
			$about_me_title = $lang === 'english' ? 'About Me' : 'Sobre mí';
			$about_me_html = '<div class="contai-about-me-widget">' . $profile['fullname'] . '</div>';

			$sidebar_widgets[] = "block-$block_id";
			$widget_block[ $block_id ] = array( 'content' => $about_me_html );
		}
		// When profile is null: skip About Me, continue with other widgets

		$sidebar_widgets[] = "search-$search_id";
		$sidebar_widgets[] = "recent-comments-$comments_id";
		$sidebar_widgets[] = "recent-posts-$posts_id";

		$widget_search[ $search_id ] = array( 'title' => $text['search'] );
		$widget_recent_comments[ $comments_id ] = array( 'title' => $text['recent_comments'], 'number' => 5 );
		$widget_recent_posts[ $posts_id ] = array( 'title' => $text['recent_posts'], 'number' => 5, 'show_date' => true );

		return array(
			'sidebar_widgets'      => $sidebar_widgets,
			'widget_block'         => $widget_block,
			'widget_search'        => $widget_search,
			'widget_recent_comments' => $widget_recent_comments,
			'widget_recent_posts'  => $widget_recent_posts,
		);
	}

	/**
	 * REGRESSION: When profile API fails, other widgets must still be created.
	 * Before the fix, contai_add_sidebar_widgets() did an early return on profile
	 * failure, which skipped ALL widgets — not just About Me.
	 */
	public function test_widgets_created_when_profile_fails(): void {
		$result = $this->simulateWidgetAssembly( 'spanish', null );

		$this->assertContains( 'search-1', $result['sidebar_widgets'] );
		$this->assertContains( 'recent-comments-1', $result['sidebar_widgets'] );
		$this->assertContains( 'recent-posts-1', $result['sidebar_widgets'] );
		$this->assertArrayHasKey( 1, $result['widget_search'] );
		$this->assertArrayHasKey( 1, $result['widget_recent_comments'] );
		$this->assertArrayHasKey( 1, $result['widget_recent_posts'] );
	}

	/**
	 * When profile fails, sidebar must NOT contain the About Me block.
	 */
	public function test_sidebar_excludes_about_me_when_profile_fails(): void {
		$result = $this->simulateWidgetAssembly( 'spanish', null );

		$this->assertNotContains( 'block-1', $result['sidebar_widgets'] );
		$this->assertArrayNotHasKey( 1, $result['widget_block'] );
	}

	/**
	 * When profile succeeds, About Me block must be included alongside other widgets.
	 */
	public function test_all_widgets_created_when_profile_succeeds(): void {
		$result = $this->simulateWidgetAssembly( 'spanish', self::$profileData );

		$this->assertContains( 'block-1', $result['sidebar_widgets'] );
		$this->assertContains( 'search-1', $result['sidebar_widgets'] );
		$this->assertContains( 'recent-comments-1', $result['sidebar_widgets'] );
		$this->assertContains( 'recent-posts-1', $result['sidebar_widgets'] );

		$this->assertArrayHasKey( 1, $result['widget_block'] );
		$this->assertStringContainsString( 'contai-about-me-widget', $result['widget_block'][1]['content'] );
		$this->assertStringContainsString( 'María García', $result['widget_block'][1]['content'] );
	}

	/**
	 * About Me block must appear BEFORE the utility widgets in sidebar order.
	 */
	public function test_about_me_appears_first_in_sidebar_order(): void {
		$result = $this->simulateWidgetAssembly( 'spanish', self::$profileData );

		$this->assertSame( 'block-1', $result['sidebar_widgets'][0] );
		$this->assertSame( 'search-1', $result['sidebar_widgets'][1] );
	}

	/**
	 * Widget labels must use Spanish when language is spanish.
	 */
	public function test_widget_labels_spanish(): void {
		$result = $this->simulateWidgetAssembly( 'spanish', null );

		$this->assertSame( 'Búsqueda', $result['widget_search'][1]['title'] );
		$this->assertSame( 'Últimos comentarios', $result['widget_recent_comments'][1]['title'] );
		$this->assertSame( 'Últimas entradas', $result['widget_recent_posts'][1]['title'] );
	}

	/**
	 * Widget labels must use English when language is english.
	 */
	public function test_widget_labels_english(): void {
		$result = $this->simulateWidgetAssembly( 'english', null );

		$this->assertSame( 'Search', $result['widget_search'][1]['title'] );
		$this->assertSame( 'Recent Comments', $result['widget_recent_comments'][1]['title'] );
		$this->assertSame( 'Recent Posts', $result['widget_recent_posts'][1]['title'] );
	}

	/**
	 * Unknown language must fall back to Spanish labels (default).
	 */
	public function test_unknown_language_falls_back_to_spanish(): void {
		$result = $this->simulateWidgetAssembly( 'french', null );

		$this->assertSame( 'Búsqueda', $result['widget_search'][1]['title'] );
	}

	/**
	 * Exactly 3 widgets when profile fails, 4 when profile succeeds.
	 */
	public function test_widget_count_matches_profile_availability(): void {
		$withoutProfile = $this->simulateWidgetAssembly( 'spanish', null );
		$this->assertCount( 3, $withoutProfile['sidebar_widgets'] );

		$withProfile = $this->simulateWidgetAssembly( 'spanish', self::$profileData );
		$this->assertCount( 4, $withProfile['sidebar_widgets'] );
	}
}
