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
	private const MAX_DEPTH  = 4;
	private const TIMEOUT    = 20;

	/**
	 * Fetch every published Field Report, links resolved.
	 *
	 * @return array{items: array<int,array>, error: string}
	 */
	public static function get_field_reports(): array {
		return self::get_entries(
			[
				'content_type' => 'fieldReport',
				'include'      => 3,
				'limit'        => 100,
				'order'        => '-sys.updatedAt',
			]
		);
	}

	/**
	 * Fetch every published Site Feature.
	 *
	 * include=0: Site Features carry no entry or asset links, so there is nothing
	 * to resolve and no reason to make the CDA assemble an includes block.
	 *
	 * @return array{items: array<int,array>, error: string}
	 */
	public static function get_site_features(): array {
		return self::get_entries(
			[
				'content_type' => 'siteFeature',
				'include'      => 0,
				'limit'        => 50,
				'order'        => 'fields.rank',
			]
		);
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
	 * @param array<string,string|int> $query
	 * @return array{items: array<int,array>, error: string, total: int}
	 */
	public static function get_entries( array $query ): array {
		$settings = AFR_Settings::all();

		if ( ! AFR_Settings::is_configured() ) {
			return [ 'items' => [], 'error' => 'Contentful space ID or delivery token is not configured.', 'total' => 0 ];
		}

		$url = sprintf(
			'%s/spaces/%s/environments/%s/entries',
			self::BASE,
			rawurlencode( $settings['space_id'] ),
			rawurlencode( $settings['environment'] )
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
