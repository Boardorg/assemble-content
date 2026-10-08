<?php
/**
 * Contentful Delivery API client.
 *
 * The CDA returns linked entries and assets in a sibling `includes` block rather
 * than inline, so every fetch here resolves those links into the entries before
 * handing anything back — the rest of the plugin never sees a raw Link.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Contentful {

	private const BASE       = 'https://cdn.contentful.com';
	private const PREVIEW    = 'https://preview.contentful.com';
	private const MAX_DEPTH  = 4;
	private const TIMEOUT    = 20;

	/** Hard stop for paging: 100 pages of 100 is the Free plan's 10K-record ceiling. */
	private const MAX_PAGES  = 100;

	/**
	 * Fetch every published entry of one content type, page by page.
	 *
	 * `complete` is true only when every page arrived and the number of items
	 * matches the `total` Contentful reported. Callers must not treat anything
	 * missing from an incomplete fetch as unpublished: that is how a sync would
	 * draft live content after one bad page (handoff §10.1).
	 *
	 * Order by something immutable (the default, `sys.id`). Ordering by
	 * `sys.updatedAt` lets an entry edited mid-fetch jump pages, so one entry is
	 * read twice and another never: the count still matches, the set does not.
	 * Items are de-duplicated by ID and only unique IDs count towards `total`.
	 *
	 * @param array<string,string|int> $extra Additional CDA query args.
	 * @return array{items: array<int,array>, error: string, total: int, complete: bool, pages: int}
	 */
	public static function get_all( string $content_type, int $include = 2, int $page_size = 100, string $order = 'sys.id', array $extra = [] ): array {
		$page_size = max( 1, min( 1000, $page_size ) );
		$items     = [];
		$fetched   = 0;
		$total     = 0;
		$pages     = 0;

		do {
			$page = self::get_entries(
				array_merge(
					$extra,
					[
						'content_type' => $content_type,
						'include'      => $include,
						'limit'        => $page_size,
						'skip'         => $fetched,
						'order'        => $order,
					]
				)
			);
			$pages++;

			if ( $page['error'] !== '' ) {
				return [
					'items'    => array_values( $items ),
					'error'    => sprintf( '%s (page %d)', $page['error'], $pages ),
					'total'    => $total,
					'complete' => false,
					'pages'    => $pages,
				];
			}

			$total    = $page['total'];
			$fetched += count( $page['items'] );

			foreach ( $page['items'] as $item ) {
				$id = (string) ( $item['sys']['id'] ?? '' );
				if ( $id !== '' ) {
					$items[ $id ] = $item;
				}
			}

			// A short or empty page ends the walk even if `total` says otherwise.
			$more = count( $page['items'] ) === $page_size && $fetched < $total;
		} while ( $more && $pages < self::MAX_PAGES );

		$complete = count( $items ) === $total;

		return [
			'items'    => array_values( $items ),
			'error'    => $complete ? '' : sprintf( 'Incomplete fetch of %s: got %d of %d entries.', $content_type, count( $items ), $total ),
			'total'    => $total,
			'complete' => $complete,
			'pages'    => $pages,
		];
	}

	/**
	 * Fetch every published entry of a registered type, using its registry settings.
	 *
	 * @return array{items: array<int,array>, error: string, total: int, complete: bool, pages: int}
	 */
	public static function get_type( string $content_type ): array {
		$type = AFR_Types::get( $content_type );

		if ( ! $type ) {
			return [ 'items' => [], 'error' => sprintf( 'Content type %s is not registered.', $content_type ), 'total' => 0, 'complete' => false, 'pages' => 0 ];
		}

		return self::get_all( $content_type, (int) $type['include'], (int) $type['page_size'], (string) $type['order'] );
	}

	/**
	 * Fetch every published Field Report, links resolved.
	 *
	 * @return array{items: array<int,array>, error: string, total: int, complete: bool, pages: int}
	 */
	public static function get_field_reports(): array {
		return self::get_type( 'fieldReport' );
	}

	/**
	 * Fetch every published Site Feature.
	 *
	 * include=0: Site Features carry no entry or asset links, so there is nothing
	 * to resolve and no reason to make the CDA assemble an includes block.
	 *
	 * @return array{items: array<int,array>, error: string, total: int, complete: bool, pages: int}
	 */
	public static function get_site_features(): array {
		return self::get_all( 'siteFeature', 0, 100, 'fields.rank,sys.id' );
	}

	/** Fetch one entry by ID, links resolved. Returns null when absent. */
	public static function get_entry( string $entry_id ): ?array {
		$result = self::get_entries(
			[
				'sys.id'  => $entry_id,
				'include' => 3,
				'limit'   => 1,
			]
		);

		return $result['items'][0] ?? null;
	}

	/**
	 * Fetch one entry's latest draft from the Preview API, links resolved. Used
	 * only for draft preview: never stored, never synced.
	 */
	public static function get_preview_entry( string $entry_id ): ?array {
		$result = self::get_entries(
			[
				'sys.id'  => $entry_id,
				'include' => 3,
				'limit'   => 1,
			],
			true
		);

		return $result['items'][0] ?? null;
	}

	/**
	 * @param array<string,string|int> $query
	 * @param bool                     $preview Read drafts from the Preview API (CPA) instead of the Delivery API.
	 * @return array{items: array<int,array>, error: string, total: int}
	 */
	public static function get_entries( array $query, bool $preview = false ): array {
		$settings = AFR_Settings::all();
		$token    = $preview ? $settings['preview_token'] : $settings['delivery_token'];

		if ( $settings['space_id'] === '' || $token === '' ) {
			return [ 'items' => [], 'error' => $preview ? 'Contentful space ID or preview token is not configured.' : 'Contentful space ID or delivery token is not configured.', 'total' => 0 ];
		}

		$url = sprintf(
			'%s/spaces/%s/environments/%s/entries',
			$preview ? self::PREVIEW : self::BASE,
			rawurlencode( $settings['space_id'] ),
			rawurlencode( $settings['environment'] )
		);

		$response = wp_remote_get(
			add_query_arg( array_map( 'strval', $query ), $url ),
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'items' => [], 'error' => 'Request failed: ' . $response->get_error_message(), 'total' => 0 ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$detail = is_array( $body ) ? ( $body['message'] ?? wp_json_encode( $body ) ) : '';

			return [ 'items' => [], 'error' => sprintf( 'Contentful returned HTTP %d. %s', $code, (string) $detail ), 'total' => 0 ];
		}

		if ( ! is_array( $body ) || ! isset( $body['items'] ) ) {
			return [ 'items' => [], 'error' => 'Unexpected response shape from Contentful.', 'total' => 0 ];
		}

		$index = self::index_includes( $body );
		$items = array_map(
			static fn( $item ) => self::resolve_links( $item, $index, 0 ),
			$body['items']
		);

		return [ 'items' => $items, 'error' => '', 'total' => (int) ( $body['total'] ?? count( $items ) ) ];
	}

	/** Build id => payload lookups for the includes block. */
	private static function index_includes( array $body ): array {
		$index = [ 'Entry' => [], 'Asset' => [] ];

		foreach ( [ 'Entry', 'Asset' ] as $type ) {
			foreach ( $body['includes'][ $type ] ?? [] as $item ) {
				$id = $item['sys']['id'] ?? null;
				if ( $id ) {
					$index[ $type ][ $id ] = $item;
				}
			}
		}

		// Top-level items can also be link targets in a circular model.
		foreach ( $body['items'] ?? [] as $item ) {
			$id = $item['sys']['id'] ?? null;
			if ( $id && ! isset( $index['Entry'][ $id ] ) ) {
				$index['Entry'][ $id ] = $item;
			}
		}

		return $index;
	}

	/**
	 * Walk a payload replacing Link nodes with their resolved targets.
	 * Unresolvable links (unpublished target, or beyond the `include` depth) are
	 * dropped rather than left as Link stubs, so templates never have to check.
	 */
	private static function resolve_links( $node, array $index, int $depth ) {
		if ( $depth > self::MAX_DEPTH || ! is_array( $node ) ) {
			return $node;
		}

		if ( self::is_link( $node ) ) {
			$type   = $node['sys']['linkType'];
			$id     = $node['sys']['id'];
			$target = $index[ $type ][ $id ] ?? null;

			return $target ? self::resolve_links( $target, $index, $depth + 1 ) : null;
		}

		$out = [];
		foreach ( $node as $key => $value ) {
			$resolved = is_array( $value ) ? self::resolve_links( $value, $index, $depth ) : $value;

			// Drop nulls left behind by unresolvable links inside lists.
			if ( is_array( $node ) && array_is_list( $node ) && $resolved === null ) {
				continue;
			}

			$out[ $key ] = $resolved;
		}

		return array_is_list( $node ) ? array_values( $out ) : $out;
	}

	/**
	 * Only Entry and Asset links are resolvable. `sys.contentType`, `sys.space`
	 * and `sys.environment` are also Link nodes but have no `includes` entry —
	 * resolving them would null out sys metadata the sync relies on.
	 */
	private static function is_link( array $node ): bool {
		return ( $node['sys']['type'] ?? '' ) === 'Link'
			&& isset( $node['sys']['linkType'], $node['sys']['id'] )
			&& in_array( $node['sys']['linkType'], [ 'Entry', 'Asset' ], true );
	}

	/**
	 * Pull a field off a resolved entry, tolerating both localized and flat shapes.
	 */
	public static function field( ?array $entry, string $name, $default = null ) {
		if ( ! $entry || ! isset( $entry['fields'][ $name ] ) ) {
			return $default;
		}

		$value = $entry['fields'][ $name ];

		return ( $value === '' || $value === [] ) ? $default : $value;
	}

	/** Resolve a Contentful asset payload to a usable URL. */
	public static function asset_url( ?array $asset ): string {
		$url = $asset['fields']['file']['url'] ?? '';

		if ( $url === '' ) {
			return '';
		}

		return str_starts_with( $url, '//' ) ? 'https:' . $url : (string) $url;
	}
}
