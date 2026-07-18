<?php
/**
 * Category-to-nav-menu selection helpers.
 *
 * @package 1Platform_Content_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decide whether a category must be kept out of the generated nav menu because
 * it is still WordPress' untouched default ("Uncategorized") bucket.
 *
 * Why this predicate exists instead of a blanket exclusion.
 *
 * setupNavigation() used to build its category list with
 * get_categories( array( 'exclude' => array( get_option( 'default_category' ) ) ) ).
 * The intent was right — an empty "Uncategorized" bucket has no business in a
 * site's main navigation — but the wizard itself invalidates the assumption
 * behind it: ContaiCategoryService::replaceUncategorizedWithFirstCategory()
 * RENAMES the default term IN PLACE (wp_update_term on the same term_id) into
 * the first category returned by the API, and nothing ever repoints the
 * default_category option. So by the time setupNavigation() runs, the id in
 * default_category is no longer a placeholder: it is a real, post-bearing
 * category — and excluding it dropped it from the menu on every generated site.
 * Silently, because an exclusion leaves no error and no log (#48).
 *
 * The fix keeps the original intent and drops the bad assumption: exclude the
 * default category only when it is demonstrably still an unused placeholder.
 *
 * Two independent signals, either of which proves the term was repurposed:
 *
 * 1. $repurposed_category_id — recorded by CategoryService at the moment it
 *    renames the term. Deterministic, and locale-proof: it does not depend on
 *    the placeholder being named "Uncategorized" (a Spanish install names it
 *    "Sin categoria", which is why a name-based test would be unreliable here).
 * 2. $post_count — a pristine placeholder holds nothing, while a repurposed
 *    category receives posts through
 *    PostGenerationOrchestrator::assignCategoryIfExists(). This covers sites
 *    generated BEFORE the flag existed, whose option is absent.
 *
 * @param int $term_id                The category being considered.
 * @param int $post_count             Published posts in that category.
 * @param int $default_category_id    Value of the default_category option.
 * @param int $repurposed_category_id Term id the wizard repurposed, 0 if none.
 * @return bool True if the category must be excluded from the nav menu.
 */
function contai_category_is_unused_default( int $term_id, int $post_count, int $default_category_id, int $repurposed_category_id ): bool {
	// Not the default category → never excluded on these grounds.
	if ( $default_category_id <= 0 || $term_id !== $default_category_id ) {
		return false;
	}

	// Signal 1: the wizard renamed this exact term into a real category.
	if ( $repurposed_category_id > 0 && $term_id === $repurposed_category_id ) {
		return false;
	}

	// Signal 2: it holds posts, so it is in use whatever its name is.
	if ( $post_count > 0 ) {
		return false;
	}

	return true;
}
