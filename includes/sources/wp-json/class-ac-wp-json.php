<?php
/**
 * A read-only client for another site's public WordPress REST (wp-json) API.
 *
 * The source for summits (Executive Platforms' summit API). Requests are
 * conditional when the caller passes the stored ETag, so an unchanged resource
 * comes back as a 304 with no body. Called only from cron and WP-CLI, never while
 * a page renders.
 */

defined( 'ABSPATH' ) || exit;

class AC_WP_JSON extends AC_Source {

	public const TIMEOUT = 20;

	public function id(): string {
		return 'wp-json';
	}

	public function label(): string {
		return 'WordPress REST API';
	}

	/** Public APIs need no credentials. */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * A list from one URL.
	 *
	 * @param array $query url (required), etag (send it to get a 304 when unchanged),
	 *                     list_key (where the list sits in the body; '' when the body is the list).
	 */
	public function fetch_all( array $query ): array {
		$url      = (string) ( $query['url'] ?? '' );
		$list_key = (string) ( $query['list_key'] ?? '' );

		if ( $url === '' ) {
			return $this->failed( 'No URL given.' );
		}

		$response = self::get( $url, (string) ( $query['etag'] ?? '' ) );
		$meta     = [ 'status' => $response['status'], 'etag' => $response['etag'], 'body' => $response['body'] ];

		if ( $response['error'] !== '' ) {
			return array_merge( $this->failed( $response['error'] ), [ 'meta' => $meta ] );
		}

		if ( $response['status'] === 304 ) {
			return [ 'items' => [], 'error' => '', 'total' => 0, 'complete' => false, 'not_modified' => true, 'meta' => $meta ];
		}

		$body = $response['body'];
		$list = $list_key === '' ? $body : ( is_array( $body ) ? ( $body[ $list_key ] ?? null ) : null );

		if ( $response['status'] !== 200 || ! is_array( $list ) ) {
			return array_merge( $this->failed( sprintf( '%s answered %d without a list.', $url, $response['status'] ) ), [ 'meta' => $meta ] );
		}

		$items = array_values( array_filter( $list, 'is_array' ) );

		return [
			'items'        => $items,
			'error'        => '',
			'total'        => count( $items ),
			'complete'     => true,
			'not_modified' => false,
			'meta'         => $meta,
		];
	}

	/** One object from `<url>/<id>`. A 404 means the item is gone. */
	public function fetch_one( string $id, array $query = [] ): array {
		$url = (string) ( $query['url'] ?? '' );

		if ( $url === '' ) {
			return [ 'item' => null, 'error' => 'No URL given.' ];
		}

		$response = self::get( trailingslashit( $url ) . rawurlencode( $id ) );

		if ( $response['error'] !== '' ) {
			return [ 'item' => null, 'error' => $response['error'] ];
		}

		if ( $response['status'] === 404 ) {
			return [ 'item' => null, 'error' => '' ];
		}

		if ( $response['status'] !== 200 || ! is_array( $response['body'] ) ) {
			return [ 'item' => null, 'error' => sprintf( '%s answered %d.', $url, $response['status'] ) ];
		}

		return [ 'item' => $response['body'], 'error' => '' ];
	}

	/** Core REST objects carry `id` and `modified`; other APIs map their own fields. */
	public function describe( array $item ): array {
		return [
			'id'         => (string) ( $item['id'] ?? '' ),
			'revision'   => '',
			'updated_at' => (string) ( $item['modified_gmt'] ?? ( $item['modified'] ?? '' ) ),
			'type'       => (string) ( $item['type'] ?? '' ),
		];
	}

	/**
	 * A GET for JSON, conditional when an ETag is given.
	 *
	 * @param string $url  The URL.
	 * @param string $etag The stored ETag, or '' for an unconditional request.
	 * @return array{status:int,body:mixed,etag:string,error:string} `error` is set only when the
	 *                                                               request itself failed.
	 */
	public static function get( string $url, string $etag = '' ): array {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Cron and CLI only; summit editions can take longer than vip_safe_wp_remote_get allows.
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'AssembleContent/' . AC_VERSION . ' (+' . home_url( '/' ) . ')',
				'headers'    => array_filter(
					[
						'Accept'        => 'application/json',
						'If-None-Match' => $etag,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'status' => 0, 'body' => null, 'etag' => '', 'error' => $response->get_error_message() ];
		}

		return [
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
			'etag'   => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'error'  => '',
		];
	}
}
