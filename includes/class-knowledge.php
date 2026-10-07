<?php
/**
 * Knowledge Base management.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the plugin knowledge base.
 */
class WP_RapidRescue_Chat_Knowledge {

	const POST_TYPE = 'rr_knowledge';
	const TAXONOMY  = 'rr_knowledge_category';

	public static function register() {

		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'               => 'Knowledge Base',
					'singular_name'      => 'Knowledge Entry',
					'menu_name'          => 'Knowledge Base',
					'name_admin_bar'     => 'Knowledge Entry',
					'add_new'            => 'Add New',
					'add_new_item'       => 'Add Knowledge Entry',
					'new_item'           => 'New Knowledge Entry',
					'edit_item'          => 'Edit Knowledge Entry',
					'view_item'          => 'View Knowledge Entry',
					'all_items'          => 'Knowledge Base',
					'search_items'       => 'Search Knowledge Base',
					'not_found'          => 'No knowledge entries found.',
					'not_found_in_trash' => 'No knowledge entries found in Trash.',
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-book-alt',
				'supports'            => array(
					'title',
					'editor',
					'revisions',
				),
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'labels' => array(
					'name'              => 'Knowledge Categories',
					'singular_name'     => 'Knowledge Category',
					'search_items'      => 'Search Knowledge Categories',
					'all_items'         => 'All Knowledge Categories',
					'parent_item'       => 'Parent Knowledge Category',
					'parent_item_colon' => 'Parent Knowledge Category:',
					'edit_item'         => 'Edit Knowledge Category',
					'update_item'       => 'Update Knowledge Category',
					'add_new_item'      => 'Add Knowledge Category',
					'new_item_name'     => 'New Knowledge Category Name',
					'menu_name'         => 'Categories',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
			)
		);
	}

	public static function search( $query = '', $limit = 5, $category = 0 ) {

		$query = sanitize_text_field( $query );
		$limit = absint( $limit );

		if ( $limit < 1 ) {
			$limit = 5;
		}

		if ( $limit > 20 ) {
			$limit = 20;
		}

		$args = array(
			'post_type'           => self::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		$category = absint( $category );

		if ( $category > 0 ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => $category,
				),
			);
		}

		/*
		 * First try WordPress's normal search.
		 */
		if ( '' !== $query ) {

			$args['s'] = $query;

			$posts = get_posts( $args );

			/*
			 * If WordPress search did not find anything,
			 * fall back to all published knowledge entries.
			 *
			 * This is important because the AI will perform
			 * the relevance matching, not WordPress.
			 */
			if ( empty( $posts ) ) {

				unset( $args['s'] );

				$args['posts_per_page'] = $limit;

				$posts = get_posts( $args );
			}
		} else {
			$posts = get_posts( $args );
		}

		$results = array();

		foreach ( $posts as $post ) {

			$categories = wp_get_post_terms(
				$post->ID,
				self::TAXONOMY,
				array(
					'fields' => 'names',
				)
			);

			$results[] = array(
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'content'    => apply_filters(
					'the_content',
					$post->post_content
				),
				'categories' => is_wp_error( $categories )
					? array()
					: $categories,
			);
		}

		return $results;
	}
}