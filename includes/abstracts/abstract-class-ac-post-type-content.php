<?php
/**
 * A content type stored as one post per item, in a hidden, read-only post type.
 *
 * This class is the sync engine for every such type: fetching through the
 * type's source, matching items to posts, revision checks, the shared `_ac_*`
 * bookkeeping meta, placement and orphan drafting. A subclass says only what
 * differs: map() (the post fields and meta an item becomes) and, optionally,
 * after_save() (terms), register_taxonomies() and content_for() (what
 * `the_content` serves).
 *
 * Posts are matched to items by the `_ac_entry_id` meta key, never by slug, so a
 * renamed headline updates the existing post instead of orphaning it. Nothing is
 * ever deleted: an item the source no longer publishes moves its post to draft,
 * and the sync log says why.
 *
 * Synced post types are a cache of the source: no admin menus or edit screens, no
 * REST exposure, no authoring in WordPress.
 */

defined( 'ABSPATH' ) || exit;

abstract class AC_Post_Type_Content extends AC_Content_Type {

	/** Bookkeeping meta every synced post carries. Underscored keys stay out of the meta box UI. */
	public const META_ENTRY_ID       = '_ac_entry_id';
	public const META_REVISION       = '_ac_revision';
	public const META_UPDATED_AT     = '_ac_updated_at';
	public const META_DATA           = '_ac_data';
	public const META_SYNCED_AT      = '_ac_synced_at';
	public const META_DRAFTED_REASON = '_ac_drafted_reason';

	/** Who may read a gated piece: the source's audience names (Contentful `availableTo`). */
	public const META_AUDIENCES = '_ac_available_to';

	/**
	 * One row per value, so `meta_query` can match a single slug or key: topic
	 * slugs (the Member Center content-topic vocabulary) and the practice areas
	 * of the piece's communities.
	 */
	public const META_TOPICS         = '_ac_topic';
	public const META_PRACTICE_AREAS = '_ac_practice_area';

	/**
	 * Config keys a post type adds to AC_Content_Type::base_defaults():
	 *
	 * - post_type      (string)  WordPress post type the items become.
	 * - slug           (string)  URL base, also the archive slug.
	 * - include        (int)     Contentful link depth to resolve.
	 * - page_size      (int)     Items per page. Lower it if Contentful responses near the ~7MB cap.
	 * - order          (string)  Order for the full fetch. Keep it immutable (default sys.id).
	 * - gated          (bool)    Whether items carry audience-gated content.
	 * - placed         (bool)    Whether items sync only where `publishTo` (or the older
	 *                            `showOn`) places them. See AC_Placement.
	 * - register       (bool)    Register the post type from this config (default true).
	 * - post_type_args (array)   Extra register_post_type() args, merged over the defaults.
	 */
	public static function base_defaults(): array {
		return array_merge(
			parent::base_defaults(),
			[
				'post_type'      => '',
				'slug'           => '',
				'include'        => 2,
				'page_size'      => 100,
				'order'          => 'sys.id',
				'gated'          => false,
				'placed'         => false,
				'register'       => true,
				'post_type_args' => [],
			]
		);
	}

	public function post_type(): string {
		return (string) $this->config['post_type'];
	}

	public function is_gated(): bool {
		return (bool) $this->config['gated'];
	}

	public function is_placed(): bool {
		return (bool) $this->config['placed'];
	}

	public function is_valid(): bool {
		return parent::is_valid() && $this->post_type() !== '';
	}

	/**
	 * How an item maps onto a post.
	 *
	 * @return array{post: array, meta: array<string,mixed>} Post fields (title required) and
	 *                                                       type-specific post meta.
	 */
	abstract public function map( array $item ): array;

	/** After an item is written to its post: terms and multi-row meta. */
	public function after_save( int $post_id, array $item ): void {}

	// ---------------------------------------------------------- registration

	public function init(): void {
		// Taxonomies first, deliberately. Rewrite rules are matched in registration
		// order, and a post type's `<slug>/[^/]+/([^/]+)` attachment rule would
		// otherwise swallow a taxonomy URL under the same base and 404 it.
		add_action( 'init', [ $this, 'register_taxonomies' ], 9 );
		add_action( 'init', [ $this, 'register_post_type' ], 10 );
		add_filter( 'the_content', [ $this, 'filter_content' ], 9 );
	}

	/** Taxonomies this type owns. Runs at `init` priority 9, before the post type. */
	public function register_taxonomies(): void {}

	public function register_post_type(): void {
		if ( ! $this->config['register'] || post_type_exists( $this->post_type() ) ) {
			return;
		}

		register_post_type( $this->post_type(), $this->post_type_args() );
	}

	/**
	 * Defaults shared by every synced type.
	 *
	 * `public` stays true so URLs, archives, queries and Yoast sitemaps work.
	 * `show_ui` is false because the source is the only source of truth: there is
	 * nothing to manage here. `show_in_rest` is false because REST would serve
	 * `content.rendered` to anonymous visitors, bypassing the audience gate.
	 */
	protected function post_type_args(): array {
		$slug = (string) $this->config['slug'];
		$args = [
			'labels'            => [
				'name'          => $this->label(),
				'singular_name' => (string) ( $this->config['singular'] ?: $this->label() ),
			],
			'public'            => true,
			'show_ui'           => false,
			'show_in_menu'      => false,
			'show_in_nav_menus' => false,
			'show_in_admin_bar' => false,
			'show_in_rest'      => false,
			'has_archive'       => $slug !== '' ? $slug : true,
			'rewrite'           => [
				'slug'       => $slug !== '' ? $slug : $this->post_type(),
				'with_front' => false,
			],
			'supports'          => [ 'title', 'editor', 'excerpt', 'custom-fields' ],
			'capability_type'   => 'post',
			'map_meta_cap'      => true,
			'capabilities'      => [ 'create_posts' => 'do_not_allow' ],
		];

		// Shallow merge: a config that sets `supports` or `labels` replaces the default list whole.
		$args = array_merge( $args, (array) $this->config['post_type_args'] );

		// Never let a config re-open REST on a gated type.
		if ( $this->is_gated() ) {
			$args['show_in_rest'] = false;
		}

		return $args;
	}

	// ------------------------------------------------------------ the_content

	/**
	 * What `the_content` serves for this type's posts, in every context (single
	 * page, archive, feed, search), so a gated body can't leak through a listing.
	 */
	public function filter_content( string $content ): string {
		$post = get_post();

		if ( ! $post || $post->post_type !== $this->post_type() ) {
			return $content;
		}

		// Leave the editor and REST alone; this is a presentation-layer filter.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $content;
		}

		return $this->content_for( $post, $content );
	}

	/**
	 * A gated type with no plugin document serves its teaser: the site renders
	 * the page from the type's view model, and the stored body is never served.
	 */
	protected function content_for( WP_Post $post, string $content ): string {
		return $this->is_gated()
			? '<p class="ac-teaser">' . esc_html( $post->post_excerpt ) . '</p>'
			: $content;
	}

	// ------------------------------------------------------------------ sync

	/** The query the source's fetch_all() gets for this type. */
	public function query(): array {
		return [
			'content_type' => $this->key,
			'include'      => (int) $this->config['include'],
			'page_size'    => (int) $this->config['page_size'],
			'order'        => (string) $this->config['order'],
		];
	}

	/**
	 * Every published item of this type.
	 *
	 * @return array{items: array<int,array>, error: string, total: int, complete: bool, not_modified: bool, meta: array}
	 */
	public function fetch(): array {
		$source = $this->source();

		// Placement is decided after the fetch (on_site()), because "any Member Center
		// place" and the showOn fallback can't be a source filter.
		if ( $this->is_placed() && AC_Settings::site_channel() === '' ) {
			// Incomplete on purpose: nothing syncs and nothing is drafted until the site says what it is.
			return [ 'items' => [], 'error' => 'site channel not set (AC_SITE_CHANNEL or ac_settings[site_channel]); nothing synced.', 'total' => 0, 'complete' => false, 'not_modified' => false, 'meta' => [] ];
		}

		return $source->fetch_all( $this->query() );
	}

	/**
	 * An item as the type reads it. A Contentful item has its functional areas
	 * copied into the Community link fields the renderers read (AC_Placement).
	 */
	public function prepare( array $item ): array {
		return $this->config['source'] === 'contentful' ? AC_Placement::normalize( $item ) : $item;
	}

	/** Is this item placed on this site? Always true for a type that isn't placed. */
	public function on_site( array $item ): bool {
		return ! $this->is_placed() || AC_Placement::on_site( $item );
	}

	/**
	 * Problems an editor should hear about. The item still syncs.
	 *
	 * @return string[]
	 */
	public function warnings( array $item ): array {
		return $this->is_placed() ? AC_Placement::warnings( $item ) : [];
	}

	/**
	 * `post_date` and `post_date_gmt` for a new or updated post, unless map() sets them.
	 *
	 * @return array{post_date:string,post_date_gmt:string}
	 */
	protected function post_date( array $item ): array {
		return AC_Post_Date::for_entry( $item );
	}

	/**
	 * Fetch, upsert and orphan-draft this type.
	 *
	 * Orphans are drafted only when the fetch was complete. A failed or partial
	 * fetch drafts nothing, and a sync of one type never touches another type's
	 * posts. A dry run writes nothing and adds `rows`: one per item, with the
	 * action a real sync would take.
	 *
	 * @return array{created:int,updated:int,unchanged:int,drafted:int,errors:int,messages:string[],rows:array}
	 */
	public function sync( bool $force = false, bool $dry_run = false ): array {
		$result   = AC_Sync::blank_result();
		$fetch    = $this->fetch();
		$seen     = [];
		$off_site = [];

		foreach ( $fetch['items'] as $item ) {
			$item = $this->prepare( $item );

			// Not placed on this site: left out of $seen, so its post is drafted below.
			if ( ! $this->on_site( $item ) ) {
				$entry_id   = $this->source()->describe( $item )['id'];
				$off_site[] = $entry_id;

				if ( $dry_run ) {
					$post_id          = AC_Sync::find_post_by_entry_id( $entry_id );
					$result['rows'][] = $this->dry_run_row( $item, $post_id, $post_id && get_post_status( $post_id ) === 'publish' ? 'draft (not on this site)' : 'skip (not on this site)' );
				}
				continue;
			}

			$outcome                      = $this->sync_item( $item, $force, $dry_run );
			$result[ $outcome['status'] ] = ( $result[ $outcome['status'] ] ?? 0 ) + 1;
			$result['messages'][]         = $outcome['message'];

			if ( $dry_run ) {
				$result['rows'][] = $this->dry_run_row( $item, $outcome['post_id'], $outcome['action'] );
				foreach ( $this->warnings( $item ) as $warning ) {
					$result['warnings'][] = sprintf( '%s: %s', $outcome['entry_id'], $warning );
				}
			}

			// Items that fail to save still count as seen, so they aren't drafted.
			if ( $outcome['entry_id'] !== '' ) {
				$seen[] = $outcome['entry_id'];
			}
		}

		if ( ! $fetch['complete'] ) {
			$result['errors']++;
			$result['messages'][] = sprintf(
				'%s: %s Orphan drafting skipped, so nothing was drafted.',
				$this->key,
				$fetch['error'] !== '' ? $fetch['error'] : 'incomplete fetch.'
			);

			return $result;
		}

		// Anything here that the source no longer publishes gets drafted, not deleted.
		foreach ( $this->orphans( $seen ) as $post_id ) {
			$entry_id = (string) get_post_meta( $post_id, self::META_ENTRY_ID, true );
			$off      = in_array( $entry_id, $off_site, true );

			// Off-site items already have a dry-run row.
			if ( $dry_run && ! $off ) {
				$result['rows'][] = [
					'type'      => $this->key,
					'entry'     => $entry_id,
					'headline'  => wp_trim_words( get_the_title( $post_id ), 9 ),
					'post'      => $post_id,
					'rev'       => '',
					'action'    => 'draft',
					'audiences' => '',
					'places'    => '',
				];
			}

			$reason = $off ? 'not published to this site' : sprintf( 'no longer published in %s', $this->source()->label() );

			if ( ! $dry_run ) {
				$this->remove( $post_id, $reason );
			}

			$result['drafted']++;
			$result['messages'][] = sprintf( '%s #%d (%s)', $dry_run ? 'would draft' : 'drafted', $post_id, $reason );
		}

		return $result;
	}

	/**
	 * Map one prepared item onto its post.
	 *
	 * @return array{status:string,message:string,post_id:int,entry_id:string,action:string}
	 */
	public function sync_item( array $item, bool $force = false, bool $dry_run = false ): array {
		$about    = $this->source()->describe( $item );
		$entry_id = $about['id'];
		$mapped   = $this->map( $item );
		$fields   = (array) ( $mapped['post'] ?? [] );
		$meta     = (array) ( $mapped['meta'] ?? [] );

		if ( $entry_id === '' || (string) ( $fields['post_title'] ?? '' ) === '' ) {
			return $this->outcome( 'errors', sprintf( 'skipped %s: missing ID or title', $entry_id ?: '(unknown)' ), 0, $entry_id, 'skip (no ID or title)' );
		}

		$post_id  = AC_Sync::find_post_by_entry_id( $entry_id );
		$revision = $about['revision'];
		$updated  = $about['updated_at'];

		if ( $post_id && ! $force ) {
			$known_rev = (string) get_post_meta( $post_id, self::META_REVISION, true );
			$known_at  = (string) get_post_meta( $post_id, self::META_UPDATED_AT, true );

			if ( $known_rev === $revision && $known_at === $updated && get_post_status( $post_id ) === 'publish' ) {
				return $this->outcome( 'unchanged', sprintf( 'unchanged #%d %s (rev %s)', $post_id, $entry_id, $revision ), $post_id, $entry_id, 'unchanged' );
			}
		}

		if ( $dry_run ) {
			$status = $post_id ? 'updated' : 'created';

			return $this->outcome( $status, sprintf( 'would %s %s (rev %s)', $post_id ? 'update #' . $post_id : 'create', $entry_id, $revision ), $post_id, $entry_id, $post_id ? 'update' : 'create' );
		}

		// A type that dates its own posts keeps that date.
		$postarr = array_merge(
			isset( $fields['post_date'] ) ? [] : $this->post_date( $item ),
			$fields,
			[
				'post_type'   => $this->post_type(),
				'post_status' => 'publish',
			]
		);

		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$saved         = wp_update_post( $postarr, true );
			$status        = 'updated';
		} else {
			$saved  = wp_insert_post( $postarr, true );
			$status = 'created';
		}

		if ( is_wp_error( $saved ) ) {
			return $this->outcome( 'errors', sprintf( 'failed %s: %s', $entry_id, $saved->get_error_message() ), 0, $entry_id, 'error' );
		}

		$post_id = (int) $saved;

		// Bookkeeping every synced type shares.
		update_post_meta( $post_id, self::META_ENTRY_ID, $entry_id );
		update_post_meta( $post_id, self::META_REVISION, $revision );
		update_post_meta( $post_id, self::META_UPDATED_AT, $updated );
		update_post_meta( $post_id, self::META_DATA, wp_slash( wp_json_encode( $item ) ) );
		update_post_meta( $post_id, self::META_SYNCED_AT, current_time( 'mysql' ) );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
		}

		$this->after_save( $post_id, $item );

		if ( $this->is_placed() ) {
			AC_Placement::apply( $post_id, $item );
		}

		/**
		 * After an item is written to its post: a site's seam for its own
		 * mappings (search indexing, extra terms). Not fired for unchanged items.
		 *
		 * @param int    $post_id
		 * @param array  $item         The item as the source served it.
		 * @param string $content_type The type's registry key (the Contentful content type ID).
		 */
		do_action( 'ac_synced', $post_id, $item, $this->key );

		$warnings = $this->warnings( $item );

		return $this->outcome(
			$status,
			sprintf( '%s #%d %s (rev %s)', $status, $post_id, $entry_id, $revision ) . ( $warnings ? ' — warning: ' . implode( '; ', $warnings ) : '' ),
			$post_id,
			$entry_id,
			$status
		);
	}

	/** Move a post to draft, saying why. */
	public function remove( int $post_id, string $reason ): void {
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
		update_post_meta( $post_id, self::META_DRAFTED_REASON, $reason );
		update_post_meta( $post_id, self::META_SYNCED_AT, current_time( 'mysql' ) );
	}

	/**
	 * Published posts of this type whose entry IDs were not in its last full fetch.
	 *
	 * @param string[] $seen_entry_ids
	 * @return int[]
	 */
	protected function orphans( array $seen_entry_ids ): array {
		$posts = get_posts(
			[
				'post_type'        => $this->post_type(),
				'post_status'      => 'publish',
				'numberposts'      => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.nopaging_numberposts -- every synced post must be checked; the set is small.
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);

		$orphans = [];
		foreach ( $posts as $post_id ) {
			$entry_id = (string) get_post_meta( (int) $post_id, self::META_ENTRY_ID, true );

			// Posts with no entry ID were not created by this plugin — leave them be.
			if ( $entry_id !== '' && ! in_array( $entry_id, $seen_entry_ids, true ) ) {
				$orphans[] = (int) $post_id;
			}
		}

		return $orphans;
	}

	// --------------------------------------------------------------- reading

	public function summary(): string {
		$counts = wp_count_posts( $this->post_type() );

		return sprintf( '%d published, %d draft', (int) ( $counts->publish ?? 0 ), (int) ( $counts->draft ?? 0 ) );
	}

	/** The decoded source item stored for a synced post, or null. */
	public static function data( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, self::META_DATA, true );

		if ( ! $raw ) {
			return null;
		}

		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/** Replace a multi-row meta key with one row per unique, non-empty value. */
	protected static function set_multi_meta( int $post_id, string $key, array $values ): void {
		delete_post_meta( $post_id, $key );
		foreach ( array_unique( array_filter( array_map( 'strval', $values ) ) ) as $value ) {
			add_post_meta( $post_id, $key, $value );
		}
	}

	// --------------------------------------------------------------- helpers

	private function outcome( string $status, string $message, int $post_id, string $entry_id, string $action ): array {
		return [
			'status'   => $status,
			'message'  => $message,
			'post_id'  => $post_id,
			'entry_id' => $entry_id,
			'action'   => $action,
		];
	}

	/** One dry-run row. */
	public function dry_run_row( array $item, int $post_id, string $action ): array {
		$about  = $this->source()->describe( $item );
		$mapped = $this->map( $item );

		return [
			'type'      => $this->key,
			'entry'     => $about['id'] ?: '?',
			'headline'  => wp_trim_words( (string) ( $mapped['post']['post_title'] ?? '' ), 9 ),
			'post'      => $post_id ?: '—',
			'rev'       => $about['revision'],
			'action'    => $action,
			'audiences' => implode( '/', (array) ( $mapped['meta'][ self::META_AUDIENCES ] ?? [] ) ),
			'places'    => $this->is_placed() ? implode( '/', AC_Placement::places( $item ) ) : '',
		];
	}
}
