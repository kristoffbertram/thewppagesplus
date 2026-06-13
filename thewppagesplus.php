<?php
/**
 * Plugin Name: The WP Pages Plus
 * Description: Fixes the WP Admin list-table gripes: a sortable "Modified" column (with author), the full URL path under every title, a parent filter + sortable Parent column for hierarchical content, slug/path search, and one-click + bulk Duplicate.
 * Author: Kristoff Bertram
 * Author URI: https://kristoffbertram.be
 * Plugin URI: https://github.com/kristoffbertram/thewppagesplus
 * Version: 1.2.0
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: thewppagesplus
 */

defined('ABSPATH') || exit;

const THEWPPAGESPLUS_COL_MODIFIED = 'thewppagesplus_modified';
const THEWPPAGESPLUS_COL_PARENT   = 'thewppagesplus_parent';
const THEWPPAGESPLUS_PARENT_QV    = 'thewppagesplus_parent';
const THEWPPAGESPLUS_PARENT_SORT  = 'thewppagesplus_parent';
const THEWPPAGESPLUS_DUP_ACTION   = 'thewppagesplus_duplicate';

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
 * Per-request store of post ID => ['path' => string, 'href' => string].
 * Filled while the list table renders titles, drained by the footer injector.
 */
function &thewppagesplus_path_store(): array {
	static $paths = [];
	return $paths;
}

/**
 * Wire everything up only on edit.php, only for the screen's post type.
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
	add_filter('the_title', 'thewppagesplus_capture_path', 10, 2);
	add_action('restrict_manage_posts', 'thewppagesplus_parent_filter', 10, 2);
	add_filter('post_row_actions', 'thewppagesplus_duplicate_link', 10, 2);
	add_filter('page_row_actions', 'thewppagesplus_duplicate_link', 10, 2);
	add_filter("bulk_actions-edit-{$pt}", 'thewppagesplus_register_bulk_duplicate');
	add_filter("handle_bulk_actions-edit-{$pt}", 'thewppagesplus_handle_bulk_duplicate', 10, 3);
	add_action('admin_notices', 'thewppagesplus_bulk_notice');
	add_action('admin_head', 'thewppagesplus_styles');
	add_action('admin_footer', 'thewppagesplus_print_paths');
});

/**
 * Parent filter + keep the manual (menu) order intact when viewing a parent.
 * Top-level hook: the main edit.php query runs after load-edit.php.
 */
add_action('pre_get_posts', function (WP_Query $query) {
	global $pagenow;
	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return;
	}
	$parent = isset($_GET[THEWPPAGESPLUS_PARENT_QV]) ? (int) $_GET[THEWPPAGESPLUS_PARENT_QV] : 0;
	if ( $parent > 0 ) {
		$query->set('post_parent', $parent);
		// No explicit sort chosen -> mirror the manual page order, not A-Z.
		if ( ! isset($_GET['orderby']) ) {
			$query->set('orderby', 'menu_order title');
			$query->set('order', 'ASC');
		}
	}
});

/**
 * Also match the slug when searching the list table, so you can find a page by
 * its URL segment on sites full of similarly-titled content.
 */
add_filter('posts_search', function ($search, WP_Query $query) {
	global $pagenow, $wpdb;
	if ( '' === $search || ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return $search;
	}
	$terms = $query->get('search_terms');
	if ( empty($terms) ) {
		$terms = array_filter([ (string) $query->get('s') ]);
	}
	if ( empty($terms) ) {
		return $search;
	}

	$parts = [];
	foreach ( $terms as $term ) {
		$parts[] = $wpdb->prepare("{$wpdb->posts}.post_name LIKE %s", '%' . $wpdb->esc_like($term) . '%');
	}
	$slug_clause = '(' . implode(' AND ', $parts) . ')';

	// WP wraps the whole search in one trailing ")". Inject " OR (slug)" just
	// inside it so a row matches the normal search OR a slug containing all terms.
	$trimmed = rtrim($search);
	$pos     = strrpos($trimmed, ')');
	if ( false === $pos ) {
		return $search;
	}

	return substr($trimmed, 0, $pos) . ' OR ' . $slug_clause . substr($trimmed, $pos) . ' ';
}, 10, 2);

/**
 * Sort the Parent column by the parent's title (not its ID) via a self-join.
 */
add_filter('posts_clauses', function (array $clauses, WP_Query $query): array {
	global $pagenow, $wpdb;
	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
		return $clauses;
	}
	if ( THEWPPAGESPLUS_PARENT_SORT !== $query->get('orderby') ) {
		return $clauses;
	}
	$order = 'DESC' === strtoupper((string) $query->get('order')) ? 'DESC' : 'ASC';
	$clauses['join']    .= " LEFT JOIN {$wpdb->posts} AS twpp_parent ON {$wpdb->posts}.post_parent = twpp_parent.ID ";
	$clauses['orderby']  = "twpp_parent.post_title {$order}, {$wpdb->posts}.post_title ASC";

	return $clauses;
}, 10, 2);

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
		$columns[THEWPPAGESPLUS_COL_PARENT] = THEWPPAGESPLUS_PARENT_SORT;
	}

	return $columns;
}

function thewppagesplus_render_column(string $column, int $post_id): void {
	if ( THEWPPAGESPLUS_COL_MODIFIED === $column ) {
		echo esc_html( get_the_modified_date('', $post_id) );

		$ts     = (int) get_post_modified_time('U', true, $post_id);
		$author = get_the_author_meta('display_name', (int) get_post_field('post_author', $post_id));
		$meta   = [];
		if ( $ts ) {
			/* translators: %s: human-readable time difference, e.g. "3 days". */
			$meta[] = sprintf(__('%s ago', 'thewppagesplus'), human_time_diff($ts));
		}
		if ( $author ) {
			/* translators: %s: author display name. */
			$meta[] = sprintf(__('by %s', 'thewppagesplus'), $author);
		}
		if ( $meta ) {
			echo '<br><span class="thewppagesplus-sub">' . esc_html( implode(' · ', $meta) ) . '</span>';
		}
		return;
	}

	if ( THEWPPAGESPLUS_COL_PARENT === $column ) {
		$post = get_post($post_id);
		if ( ! $post || ! $post->post_parent ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		// Raw title on purpose: avoids re-triggering the_title path capture.
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
 * Record the relative path (and a live link when viewable) for the row, but
 * return the title untouched. WP wraps the list-table title in esc_html() (via
 * _draft_or_post_title), so markup appended here would show literally; we inject
 * it with JS instead.
 */
function thewppagesplus_capture_path($title, $post_id = 0) {
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
	if ( '' !== $path ) {
		$linkable        = in_array(get_post_status($post), ['publish', 'private'], true);
		$store           =& thewppagesplus_path_store();
		$store[$post_id] = ['path' => $path, 'href' => $linkable ? $path : ''];
	}

	return $title;
}

function thewppagesplus_print_paths(): void {
	$paths = thewppagesplus_path_store();
	if ( empty($paths) ) {
		return;
	}
	echo '<script>window.thewppagesplusPaths=' . wp_json_encode($paths) . ';';
	echo <<<'JS'
(function(){
  var p = window.thewppagesplusPaths || {};
  for (var id in p) {
    if (!Object.prototype.hasOwnProperty.call(p, id)) continue;
    var row = document.getElementById('post-' + id);
    if (!row) continue;
    var cell = row.querySelector('.column-title');
    if (!cell || cell.querySelector('.thewppagesplus-path')) continue;
    var strong = cell.querySelector('strong');
    if (!strong) continue;
    var el;
    if (p[id].href) {
      el = document.createElement('a');
      el.href = p[id].href;
      el.target = '_blank';
      el.rel = 'noopener';
    } else {
      el = document.createElement('span');
    }
    el.className = 'thewppagesplus-path';
    el.textContent = p[id].path;
    strong.insertAdjacentElement('afterend', el);
  }
})();
JS;
	echo '</script>';
}

function thewppagesplus_parent_filter(string $post_type, string $which): void {
	if ( 'top' !== $which || ! thewppagesplus_is_hierarchical($post_type) ) {
		return;
	}
	$selected = isset($_GET[THEWPPAGESPLUS_PARENT_QV]) ? (int) $_GET[THEWPPAGESPLUS_PARENT_QV] : 0;
	$dropdown = wp_dropdown_pages([
		'post_type'         => $post_type,
		'selected'          => $selected,
		'name'              => THEWPPAGESPLUS_PARENT_QV,
		'id'                => THEWPPAGESPLUS_PARENT_QV,
		'show_option_none'  => __('All parents', 'thewppagesplus'),
		'option_none_value' => '0',
		'sort_column'       => 'menu_order, post_title',
		'echo'              => 0,
	]);
	if ( $dropdown ) {
		echo $dropdown; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_dropdown_pages returns escaped markup.
	}
}

/**
 * Core clone routine, shared by the single row action and the bulk action.
 * Copies content, parent, menu order, taxonomy terms and meta into a new draft.
 *
 * @return int|WP_Error New post ID, or WP_Error on failure.
 */
function thewppagesplus_duplicate_post(int $source_id) {
	$post = get_post($source_id);
	if ( ! $post ) {
		return new WP_Error('thewppagesplus_no_source', __('Item not found.', 'thewppagesplus'));
	}

	$new_id = wp_insert_post([
		'comment_status' => $post->comment_status,
		'ping_status'    => $post->ping_status,
		'post_author'    => get_current_user_id(),
		'post_content'   => $post->post_content,
		'post_excerpt'   => $post->post_excerpt,
		'post_name'      => '',
		'post_parent'    => $post->post_parent,
		'post_password'  => $post->post_password,
		'post_status'    => 'draft',
		'post_title'     => $post->post_title . ' (Copy)',
		'post_type'      => $post->post_type,
		'to_ping'        => $post->to_ping,
		'menu_order'     => $post->menu_order,
	], true);

	if ( is_wp_error($new_id) ) {
		return $new_id;
	}

	// Copy taxonomy terms.
	foreach ( get_object_taxonomies($post->post_type) as $taxonomy ) {
		$terms = wp_get_post_terms($source_id, $taxonomy, ['fields' => 'ids']);
		if ( ! is_wp_error($terms) ) {
			wp_set_object_terms($new_id, $terms, $taxonomy);
		}
	}

	// Copy post meta (skip old-slug history).
	foreach ( get_post_meta($source_id) as $meta_key => $meta_values ) {
		if ( '_wp_old_slug' === $meta_key ) {
			continue;
		}
		foreach ( $meta_values as $meta_value ) {
			add_post_meta($new_id, $meta_key, maybe_unserialize($meta_value));
		}
	}

	return $new_id;
}

/**
 * "Duplicate" row action. Ported from the TKToolboxes theme module.
 */
function thewppagesplus_duplicate_link(array $actions, WP_Post $post): array {
	if ( ! current_user_can('edit_post', $post->ID) ) {
		return $actions;
	}
	$url = wp_nonce_url(
		admin_url('admin.php?action=' . THEWPPAGESPLUS_DUP_ACTION . '&post=' . $post->ID),
		THEWPPAGESPLUS_DUP_ACTION . '_' . $post->ID,
		'thewppagesplus_nonce'
	);
	$actions['thewppagesplus_duplicate'] = sprintf(
		'<a href="%s" rel="permalink">%s</a>',
		esc_url($url),
		esc_html__('Duplicate', 'thewppagesplus')
	);

	return $actions;
}

function thewppagesplus_duplicate_handler(): void {
	$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
	if ( ! $post_id ) {
		wp_die(esc_html__('Missing post ID.', 'thewppagesplus'));
	}
	check_admin_referer(THEWPPAGESPLUS_DUP_ACTION . '_' . $post_id, 'thewppagesplus_nonce');
	if ( ! current_user_can('edit_post', $post_id) ) {
		wp_die(esc_html__('You are not allowed to duplicate this item.', 'thewppagesplus'));
	}

	$new_id = thewppagesplus_duplicate_post($post_id);
	if ( is_wp_error($new_id) ) {
		wp_die(esc_html__('Error duplicating item: ', 'thewppagesplus') . esc_html($new_id->get_error_message()));
	}

	wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
	exit;
}
add_action('admin_action_' . THEWPPAGESPLUS_DUP_ACTION, 'thewppagesplus_duplicate_handler');

function thewppagesplus_register_bulk_duplicate(array $actions): array {
	$actions['thewppagesplus_duplicate'] = __('Duplicate', 'thewppagesplus');
	return $actions;
}

function thewppagesplus_handle_bulk_duplicate(string $redirect, string $action, array $post_ids): string {
	if ( 'thewppagesplus_duplicate' !== $action ) {
		return $redirect;
	}
	$done = 0;
	foreach ( $post_ids as $post_id ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can('edit_post', $post_id) ) {
			continue;
		}
		if ( ! is_wp_error( thewppagesplus_duplicate_post($post_id) ) ) {
			$done++;
		}
	}

	return add_query_arg('thewppagesplus_duplicated', $done, $redirect);
}

function thewppagesplus_bulk_notice(): void {
	if ( ! isset($_GET['thewppagesplus_duplicated']) ) {
		return;
	}
	$count = (int) $_GET['thewppagesplus_duplicated'];
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html( sprintf(
			/* translators: %s: number of duplicated items. */
			_n('%s item duplicated to draft.', '%s items duplicated to draft.', $count, 'thewppagesplus'),
			number_format_i18n($count)
		) )
	);
}

function thewppagesplus_styles(): void {
	echo '<style>
.column-title .thewppagesplus-path{display:block;margin:.25em 0 0;font-weight:400;font-size:12px;color:#646970;}
a.thewppagesplus-path{text-decoration:none;}
a.thewppagesplus-path:hover{text-decoration:underline;color:#2271b1;}
.thewppagesplus-sub{color:#646970;}
.fixed .column-' . THEWPPAGESPLUS_COL_MODIFIED . ',.fixed .column-' . THEWPPAGESPLUS_COL_PARENT . '{width:12%;}
</style>';
}
