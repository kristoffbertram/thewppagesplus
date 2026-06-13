<?php
/**
 * Plugin Name: The WP Pages Plus
 * Description: Fixes the WP Admin list-table gripes: a sortable "Modified" column, the full URL path under every title, and a parent filter + sortable parent column for hierarchical content.
 * Author: Kristoff Bertram
 * Author URI: https://kristoffbertram.be
 * Plugin URI: https://github.com/kristoffbertram/thewppagesplus
 * Version: 1.0.0
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: thewppagesplus
 */

defined('ABSPATH') || exit;

const THEWPPAGESPLUS_COL_MODIFIED = 'thewppagesplus_modified';
const THEWPPAGESPLUS_COL_PARENT   = 'thewppagesplus_parent';
const THEWPPAGESPLUS_PARENT_QV    = 'thewppagesplus_parent';

/**
 * Post types we touch: anything public with an admin UI, minus attachments
 * (the media library is a different screen with its own column plumbing).
 */
function thewppagesplus_target_post_types(): array {
	$types = get_post_types(['public' => true, 'show_ui' => true], 'names');
	unset($types['attachment']);
	return $types;
}

function thewppagesplus_is_hierarchical(string $post_type): bool {
	return $post_type !== '' && is_post_type_hierarchical($post_type);
}

/**
 * Relative URL path for a post, decoded for readability.
 * Published/private/future use the real permalink; drafts fall back to the
 * intended pretty path so you still see where the content will live.
 */
function thewppagesplus_relative_path(WP_Post $post): string {
	if ( in_array(get_post_status($post), ['publish', 'private', 'future'], true) ) {
		$url = get_permalink($post);
	} else {
		if ( ! function_exists('get_sample_permalink') ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$sample = get_sample_permalink($post->ID);
		$name   = $post->post_name ?: sanitize_title($post->post_title);
		$url    = is_array($sample)
			? str_replace(['%pagename%', '%postname%'], $name, $sample[0])
			: get_permalink($post);
	}

	return urldecode( wp_make_link_relative( (string) $url ) );
}

/**
 * Wire everything up only on edit.php, only for the screen's post type.
 * Keeps the dynamic filters (and the broad `the_title` hook) tightly scoped.
 */
add_action('load-edit.php', function () {
	$screen = get_current_screen();
	$pt     = $screen ? $screen->post_type : '';

	if ( ! in_array($pt, thewppagesplus_target_post_types(), true) ) {
		return;
	}

	add_filter("manage_{$pt}_posts_columns", 'thewppagesplus_columns');
	add_action("manage_{$pt}_posts_custom_column", 'thewppagesplus_render_column', 10, 2);
	add_filter("manage_edit-{$pt}_sortable_columns", 'thewppagesplus_sortable_columns');
	add_filter('the_title', 'thewppagesplus_append_path', 10, 2);
	add_action('restrict_manage_posts', 'thewppagesplus_parent_filter', 10, 2);
	add_action('admin_head', 'thewppagesplus_styles');
});

/**
 * Parent filter runs on the main edit.php query regardless of post type, so it
 * stays a top-level hook and guards itself.
 */
add_action('pre_get_posts', function (WP_Query $query) {
	global $pagenow;
	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return;
	}
	$parent = isset($_GET[THEWPPAGESPLUS_PARENT_QV]) ? (int) $_GET[THEWPPAGESPLUS_PARENT_QV] : 0;
	if ( $parent > 0 ) {
		$query->set('post_parent', $parent);
	}
});

function thewppagesplus_columns(array $columns): array {
	$screen       = get_current_screen();
	$hierarchical = $screen && thewppagesplus_is_hierarchical($screen->post_type);
	$new          = [];

	foreach ( $columns as $key => $label ) {
		$new[$key] = $label;
		if ( 'title' === $key && $hierarchical ) {
			$new[THEWPPAGESPLUS_COL_PARENT] = __('Parent', 'thewppagesplus');
		}
		if ( 'date' === $key ) {
			$new[THEWPPAGESPLUS_COL_MODIFIED] = __('Modified', 'thewppagesplus');
		}
	}
	if ( ! isset($new[THEWPPAGESPLUS_COL_MODIFIED]) ) {
		$new[THEWPPAGESPLUS_COL_MODIFIED] = __('Modified', 'thewppagesplus');
	}

	return $new;
}

function thewppagesplus_sortable_columns(array $columns): array {
	$columns[THEWPPAGESPLUS_COL_MODIFIED] = 'modified';

	$screen = get_current_screen();
	if ( $screen && thewppagesplus_is_hierarchical($screen->post_type) ) {
		// Groups siblings together (ORDER BY post_parent).
		$columns[THEWPPAGESPLUS_COL_PARENT] = 'parent';
	}

	return $columns;
}

function thewppagesplus_render_column(string $column, int $post_id): void {
	if ( THEWPPAGESPLUS_COL_MODIFIED === $column ) {
		$ts = (int) get_post_modified_time('U', true, $post_id);
		echo esc_html( get_the_modified_date('', $post_id) );
		if ( $ts ) {
			echo '<br><span class="thewppagesplus-ago">';
			printf(
				/* translators: %s: human-readable time difference, e.g. "3 days". */
				esc_html__('%s ago', 'thewppagesplus'),
				esc_html( human_time_diff($ts) )
			);
			echo '</span>';
		}
		return;
	}

	if ( THEWPPAGESPLUS_COL_PARENT === $column ) {
		$post = get_post($post_id);
		if ( ! $post || ! $post->post_parent ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		// Raw title on purpose: avoids re-triggering the_title path append.
		$parent = get_post($post->post_parent);
		if ( ! $parent ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		$label = $parent->post_title !== '' ? $parent->post_title : __('(no title)', 'thewppagesplus');
		$url   = add_query_arg(
			['post_type' => $parent->post_type, THEWPPAGESPLUS_PARENT_QV => $parent->ID],
			admin_url('edit.php')
		);
		printf('<a href="%s">%s</a>', esc_url($url), esc_html($label));
	}
}

/**
 * Append the relative URL path under the row title. Scoped to the list table
 * screen and to the matching post type; the inline/quick-edit data uses the raw
 * post_title (not get_the_title), so it stays clean.
 */
function thewppagesplus_append_path($title, $post_id = 0) {
	if ( ! $post_id ) {
		return $title;
	}
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if ( ! $screen || 'edit' !== $screen->base ) {
		return $title;
	}
	$post = get_post($post_id);
	if ( ! $post || $post->post_type !== $screen->post_type ) {
		return $title;
	}
	$path = thewppagesplus_relative_path($post);
	if ( '' === $path ) {
		return $title;
	}

	return $title . '<span class="thewppagesplus-path">' . esc_html($path) . '</span>';
}

function thewppagesplus_parent_filter(string $post_type, string $which): void {
	if ( 'top' !== $which || ! thewppagesplus_is_hierarchical($post_type) ) {
		return;
	}
	$selected = isset($_GET[THEWPPAGESPLUS_PARENT_QV]) ? (int) $_GET[THEWPPAGESPLUS_PARENT_QV] : 0;
	$dropdown = wp_dropdown_pages([
		'post_type'        => $post_type,
		'selected'         => $selected,
		'name'             => THEWPPAGESPLUS_PARENT_QV,
		'id'               => THEWPPAGESPLUS_PARENT_QV,
		'show_option_none' => __('All parents', 'thewppagesplus'),
		'option_none_value' => '0',
		'sort_column'      => 'menu_order, post_title',
		'echo'             => 0,
	]);
	if ( $dropdown ) {
		echo $dropdown; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_dropdown_pages returns escaped markup.
	}
}

function thewppagesplus_styles(): void {
	echo '<style>
.column-title .thewppagesplus-path{display:block;margin:.25em 0 0;font-weight:400;font-size:12px;color:#646970;}
a.row-title .thewppagesplus-path{font-weight:400;}
.thewppagesplus-ago{color:#646970;}
.fixed .column-' . THEWPPAGESPLUS_COL_MODIFIED . ',.fixed .column-' . THEWPPAGESPLUS_COL_PARENT . '{width:12%;}
</style>';
}
