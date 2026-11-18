<?php
/**
 * Contentful Delivery API client.
 *
 * The CDA returns linked entries and assets in a sibling `includes` block rather
 * than inline, so every fetch here resolves those links into the entries before
 * handing anything back — the rest of the plugin never sees a raw Link.
 *
 * The source for Field Reports, playbooks, communities and Site Features. Content
 * types reach it through fetch_all() and fetch_one(); field() and asset_url() are
 * static so renderers can read resolved entries without an instance.
 */

defined( 'ABSPATH' ) || exit;

class AC_Contentful extends AC_Source {

	private const BASE       = 'https://cdn.contentful.com';
	private const PREVIEW    = 'https://preview.contentful.com';
	private const MAX_DEPTH  = 4;
	private const TIMEOUT    = 20;

	/** Hard stop for paging: 100 pages of 100 is the Free plan's 10K-record ceiling. */
	private const MAX_PAGES  = 100;

	public function id(): string {
		return 'contentful';
	}

	public function label(): string {
		return 'Contentful';
	}

	public function is_configured(): bool {
		return AC_Settings::is_configured();
	}

	/**
	 * Every published entry of one content type, links resolved.
	 *
	 * @param array $query content_type (required), include, page_size, order, and `extra` CDA args.
	 */
	public function fetch_all( array $query ): array {
		$content_type = (string) ( $query['content_type'] ?? '' );

		if ( $content_type === '' ) {
			return $this->failed( 'No Contentful content type given.' );
		}

		return self::get_all(
			$content_type,
			(int) ( $query['include'] ?? 2 ),
			(int) ( $query['page_size'] ?? 100 ),
			(string) ( $query['order'] ?? 'sys.id' ),
			(array) ( $query['extra'] ?? [] )
		) + [ 'not_modified' => false, 'meta' => [] ];
	}

	/** One published entry, links resolved. */
	public function fetch_one( string $id, array $query = [] ): array {
		$result = self::get_entry_result( $id );

		return [ 'item' => $result['entry'], 'error' => $result['error'] ];
	}

	public function describe( array $item ): array {
		return [
			'id'         => (string) ( $item['sys']['id'] ?? '' ),
			'revision'   => (string) ( $item['sys']['revision'] ?? '' ),
			'updated_at' => (string) ( $item['sys']['updatedAt'] ?? '' ),
			'type'       => (string) ( $item['sys']['contentType']['sys']['id'] ?? '' ),
		];
	}

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

	/** Fetch one entry by ID, links resolved. Null when absent or when the request failed. */
	public static function get_entry( string $entry_id ): ?array {
		return self::get_entry_result( $entry_id )['entry'];
	}

	/**
	 * Fetch one entry by ID, telling a failed request apart from an entry that
	 * isn't published. Only `entry: null` with `error: ''` means unpublished.
	 *
	 * @return array{entry: ?array, error: string}
	 */
	public static function get_entry_result( string $entry_id ): array {
		$result = self::get_entries(
			[
				'sys.id'  => $entry_id,
				'include' => 3,
				'limit'   => 1,
			]
		);

		return [
			'entry' => $result['items'][0] ?? null,
			'error' => $result['error'],
		];
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
		$settings = AC_Settings::all();
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

	/**
	 * One page of the Sync API: everything published, unpublished or deleted since
	 * the token, or with no token, everything there is. Items are not link-resolved.
	 *
	 * @return array{items: array<int,array>, token: string, done: bool, expired: bool, error: string}
	 */
	public static function sync_page( string $token ): array {
		$result = [ 'items' => [], 'token' => $token, 'done' => false, 'expired' => false, 'error' => '' ];
		$body   = self::request( 'sync', $token !== '' ? [ 'sync_token' => $token ] : [ 'initial' => 'true' ] );

		if ( $body['error'] !== '' ) {
			// A token the API no longer accepts can't be retried, only replaced.
			$result['expired'] = $token !== '' && in_array( $body['code'], [ 400, 404, 422 ], true );
			$result['error']   = $body['error'];

			return $result;
		}

		$next = (string) ( $body['data']['nextSyncUrl'] ?? $body['data']['nextPageUrl'] ?? '' );
		parse_str( (string) wp_parse_url( $next, PHP_URL_QUERY ), $args );

		if ( empty( $args['sync_token'] ) || ! is_array( $body['data']['items'] ?? null ) ) {
			$result['error'] = 'Unexpected response shape from the Contentful Sync API.';

			return $result;
		}

		$result['items'] = $body['data']['items'];
		$result['token'] = (string) $args['sync_token'];
		$result['done']  = isset( $body['data']['nextSyncUrl'] );

		return $result;
	}

	/**
	 * The published entries that link to an entry or an asset.
	 *
	 * @param string $link 'entry' or 'asset'.
	 * @return array{entries: array<int,array{id:string,type:string}>, error: string}
	 */
	public static function linking_entries( string $link, string $id ): array {
		// An empty ID matches every entry.
		if ( $id === '' ) {
			return [ 'entries' => [], 'error' => 'no ID given' ];
		}

		$body = self::request(
			'entries',
			[
				'asset' === $link ? 'links_to_asset' : 'links_to_entry' => $id,
				'include' => 0,
				'limit'   => 1000,
				'select'  => 'sys',
			]
		);

		if ( $body['error'] !== '' ) {
			return [ 'entries' => [], 'error' => $body['error'] ];
		}

		$entries = [];
		foreach ( (array) ( $body['data']['items'] ?? [] ) as $item ) {
			$entries[] = [
				'id'   => (string) ( $item['sys']['id'] ?? '' ),
				'type' => (string) ( $item['sys']['contentType']['sys']['id'] ?? '' ),
			];
		}

		return [ 'entries' => array_values( array_filter( $entries, static fn( $e ) => $e['id'] !== '' ) ), 'error' => '' ];
	}

	/**
	 * A GET against the Delivery API for this space and environment.
	 *
	 * @return array{code: int, data: array, error: string}
	 */
	private static function request( string $path, array $query ): array {
		$settings = AC_Settings::all();

		if ( $settings['space_id'] === '' || $settings['delivery_token'] === '' ) {
			return [ 'code' => 0, 'data' => [], 'error' => 'Contentful space ID or delivery token is not configured.' ];
		}

		$url = sprintf(
			'%s/spaces/%s/environments/%s/%s',
			self::BASE,
			rawurlencode( $settings['space_id'] ),
			rawurlencode( $settings['environment'] ),
			$path
		);

		$response = wp_remote_get(
			add_query_arg( array_map( 'strval', $query ), $url ),
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $settings['delivery_token'],
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'code' => 0, 'data' => [], 'error' => 'Request failed: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! is_array( $data ) ) {
			$detail = is_array( $data ) ? ( $data['message'] ?? '' ) : '';

			return [ 'code' => $code, 'data' => [], 'error' => trim( sprintf( 'Contentful returned HTTP %d. %s', $code, (string) $detail ) ) ];
		}

		return [ 'code' => $code, 'data' => $data, 'error' => '' ];
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
