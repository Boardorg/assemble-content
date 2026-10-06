<?php
/**
 * Support for listing Field Reports in the existing blog layout.
 *
 * The blog index (page 1862) is built with Elementor Pro loop widgets whose card
 * templates call two site shortcodes — `[category_color]` and `[category_list]` —
 * both of which use get_the_category() and therefore return nothing for a custom
 * post type. This file exposes the equivalents for Field Reports so those
 * shortcodes can defer to them, and supplies a placeholder featured image while
 * no report has one in Contentful.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Listing {

	/** Attachment ID used when a report has no featured image of its own. */
	public const OPTION_PLACEHOLDER = 'afr_placeholder_image_id';

	public static function init(): void {
		// Makes has_post_thumbnail() and every Elementor dynamic image tag resolve.
		add_filter( 'post_thumbnail_id', [ self::class, 'placeholder_thumbnail' ], 10, 2 );
	}

	/**
	 * Fall back to the placeholder for Field Reports with no featured image.
	 *
	 * Contentful's `featuredImage` is optional and no report has one yet, so
	 * without this every card in the loop grid renders imageless.
	 *
	 * @param int|false        $thumbnail_id
	 * @param int|WP_Post|null $post
	 * @return int|false
	 */
	public static function placeholder_thumbnail( $thumbnail_id, $post ) {
		if ( $thumbnail_id ) {
			return $thumbnail_id;
		}

		$post = get_post( $post );
		if ( ! $post || $post->post_type !== AFR_CPT::POST_TYPE ) {
			return $thumbnail_id;
		}

		$placeholder = self::placeholder_id();

		return $placeholder ?: $thumbnail_id;
	}

	/** The configured placeholder attachment, if it still exists. */
	public static function placeholder_id(): int {
		$id = (int) get_option( self::OPTION_PLACEHOLDER, 0 );

		/**
		 * Filter the placeholder attachment ID for reports without a featured image.
		 *
		 * @param int $id
		 */
		$id = (int) apply_filters( 'afr_placeholder_image_id', $id );

		if ( $id <= 0 || get_post_type( $id ) !== 'attachment' ) {
			return 0;
		}

		return $id;
	}

	/**
	 * Accent colour for a report's card, from its primary community.
	 *
	 * Mirrors what the single-report template uses, so a card and the report it
	 * links to carry the same colour.
	 */
	public static function accent( ?int $post_id = null ): string {
		$post_id = $post_id ?: get_the_ID();

		if ( ! $post_id || get_post_type( $post_id ) !== AFR_CPT::POST_TYPE ) {
			return '';
		}

		$data = AFR_Renderer::data( (int) $post_id );

		return $data ? AFR_Renderer::accent_for( $data ) : '';
	}

	/**
	 * Community names for a report's card label — the Field Report equivalent of
	 * the category list on a blog card.
	 *
	 * Capped at the primary community by default. A shared report can carry four
	 * communities, which overflows a card; the primary one is the brand the card
	 * should read as. Raise the cap with `afr_community_label_limit` (0 = all).
	 */
	public static function label( ?int $post_id = null ): string {
		$post_id = $post_id ?: get_the_ID();

		if ( ! $post_id || get_post_type( $post_id ) !== AFR_CPT::POST_TYPE ) {
			return '';
		}

		$terms = get_the_terms( (int) $post_id, AFR_CPT::TAXONOMY );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		// Primary community first — it is the one that supplies the branding.
		$data    = AFR_Renderer::data( (int) $post_id );
		$primary = '';
		if ( $data ) {
			$community = AFR_Contentful::field( $data, 'primaryCommunity' );
			if ( is_array( $community ) ) {
				$primary = sanitize_title( (string) ( AFR_Contentful::field( $community, 'slug' ) ?? '' ) );
			}
		}

		$names = [];
		foreach ( $terms as $term ) {
			// Term names are stored HTML-encoded by WordPress; decode for display.
			$name = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );

			if ( $primary !== '' && $term->slug === $primary ) {
				array_unshift( $names, $name );
			} else {
				$names[] = $name;
			}
		}

		$limit = (int) apply_filters( 'afr_community_label_limit', 1 );
		if ( $limit > 0 ) {
			$names = array_slice( $names, 0, $limit );
		}

		return implode( ', ', $names );
	}
}

/**
 * Global wrappers so the site's card shortcodes can call these without knowing
 * about the plugin's classes, and degrade to their existing behaviour if the
 * plugin is deactivated.
 */

if ( ! function_exists( 'afr_community_accent' ) ) {
	function afr_community_accent( ?int $post_id = null ): string {
		return AFR_Listing::accent( $post_id );
	}
}

if ( ! function_exists( 'afr_community_label' ) ) {
	function afr_community_label( ?int $post_id = null ): string {
		return AFR_Listing::label( $post_id );
	}
}
