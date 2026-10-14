<?php
/**
 * The date a synced piece is filed under.
 *
 * In order: the editor's `publishDate`, the discussion date in the source line,
 * then the first publish time (the CDA's `sys.createdAt`). A date with no time
 * is local midnight on that day; a timestamp is converted from its own offset to
 * the site's timezone. A date in the future is clamped to now: Contentful's own
 * scheduling decides when a piece goes live, never WordPress.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Post_Date {

	/**
	 * `post_date` and `post_date_gmt` for wp_insert_post().
	 *
	 * @return array{post_date:string,post_date_gmt:string}
	 */
	public static function for_entry( array $entry ): array {
		$gmt = self::gmt( $entry );
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( '' === $gmt || $gmt > $now ) {
			$gmt = $now;
		}

		return [
			'post_date'     => get_date_from_gmt( $gmt ),
			'post_date_gmt' => $gmt,
		];
	}

	/**
	 * The site-local date and time as a timestamp, so gmdate() formats the day a
	 * reader on this site expects. 0 when unknown.
	 */
	public static function timestamp( array $entry ): int {
		$gmt = self::gmt( $entry );

		return '' === $gmt ? 0 : (int) strtotime( get_date_from_gmt( $gmt ) . ' UTC' );
	}

	/** The chosen date as GMT 'Y-m-d H:i:s', or ''. */
	private static function gmt( array $entry ): string {
		$publish = trim( (string) ( AFR_Contentful::field( $entry, 'publishDate' ) ?? '' ) );
		if ( '' !== $publish ) {
			$gmt = self::to_gmt( $publish );
			if ( '' !== $gmt ) {
				return $gmt;
			}
		}

		$source = (string) ( AFR_Contentful::field( $entry, 'sourceLine' ) ?? '' );
		if ( preg_match( '/\b([A-Z][a-z]+ \d{1,2},? \d{4})\b/', $source, $m ) ) {
			$ts = strtotime( $m[1] . ' UTC' );
			if ( $ts ) {
				return get_gmt_from_date( gmdate( 'Y-m-d', $ts ) . ' 00:00:00' );
			}
		}

		return self::to_gmt( (string) ( $entry['sys']['createdAt'] ?? '' ) );
	}

	/** A Contentful date or datetime as GMT 'Y-m-d H:i:s', or ''. */
	private static function to_gmt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Date only: midnight in the site's timezone.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return get_gmt_from_date( $value . ' 00:00:00' );
		}

		// With a time but no offset, Contentful means the site's local time.
		if ( ! preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
			return get_gmt_from_date( str_replace( 'T', ' ', $value ) );
		}

		$ts = strtotime( $value );

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
}
