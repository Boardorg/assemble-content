<?php
/**
 * Site Features — the non-report promotional slots on the website.
 *
 * A Site Feature has no WordPress post behind it. It is small, there are very
 * few of them, and nothing needs to query them by taxonomy or date, so they are
 * cached whole in one option rather than mapped onto a post type. The webhook
 * refreshes that option the same way it re-syncs a report.
 *
 * The option is the *only* copy on the site: if it is empty, the placement
 * simply renders nothing. Nothing here ever falls back to hardcoded marketing
 * copy, because silently serving stale promotional text is worse than an
 * absent card.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Features {

	public const OPTION       = 'afr_site_features';
	public const OPTION_SYNCED = 'afr_site_features_synced_at';

	public const PLACEMENT_HOMEPAGE_HERO = 'Homepage hero';

	/**
	 * Re-fetch every published Site Feature from the delivery API.
	 *
	 * @return array{count:int,error:string}
	 */
	public static function sync(): array {
		$fetch = AFR_Contentful::get_site_features();

		if ( $fetch['error'] !== '' ) {
			return [ 'count' => 0, 'error' => $fetch['error'] ];
		}

		$features = [];
		foreach ( $fetch['items'] as $entry ) {
			$headline = trim( (string) ( AFR_Contentful::field( $entry, 'headline' ) ?? '' ) );
			if ( $headline === '' ) {
				continue;
			}

			$features[] = [
				'id'             => (string) ( $entry['sys']['id'] ?? '' ),
				'placement'      => (string) ( AFR_Contentful::field( $entry, 'placement' ) ?? '' ),
				'active'         => (bool) AFR_Contentful::field( $entry, 'active', false ),
				'rank'           => (int) ( AFR_Contentful::field( $entry, 'rank' ) ?? 99 ),
				'kicker'         => (string) ( AFR_Contentful::field( $entry, 'kicker' ) ?? '' ),
				'badge'          => (string) ( AFR_Contentful::field( $entry, 'badge' ) ?? '' ),
				'headline'       => $headline,
				'blurb'          => (string) ( AFR_Contentful::field( $entry, 'blurb' ) ?? '' ),
				'cta_label'      => (string) ( AFR_Contentful::field( $entry, 'ctaLabel' ) ?? '' ),
				'cta_url'        => (string) ( AFR_Contentful::field( $entry, 'ctaUrl' ) ?? '' ),
				'aside_label'    => (string) ( AFR_Contentful::field( $entry, 'asideLabel' ) ?? '' ),
				'aside_headline' => (string) ( AFR_Contentful::field( $entry, 'asideHeadline' ) ?? '' ),
				'aside_body'     => (string) ( AFR_Contentful::field( $entry, 'asideBody' ) ?? '' ),
				'aside_cta_label' => (string) ( AFR_Contentful::field( $entry, 'asideCtaLabel' ) ?? '' ),
				'aside_cta_url'  => (string) ( AFR_Contentful::field( $entry, 'asideCtaUrl' ) ?? '' ),
			];
		}

		update_option( self::OPTION, $features, false );
		update_option( self::OPTION_SYNCED, current_time( 'mysql' ), false );

		return [ 'count' => count( $features ), 'error' => '' ];
	}

	/**
	 * Active features for a placement, best first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_placement( string $placement ): array {
		$all = (array) get_option( self::OPTION, [] );

		$matching = array_values(
			array_filter(
				$all,
				static fn ( $f ) => is_array( $f )
					&& ! empty( $f['active'] )
					&& ( $f['placement'] ?? '' ) === $placement
			)
		);

		usort( $matching, static fn ( $a, $b ) => ( $a['rank'] ?? 99 ) <=> ( $b['rank'] ?? 99 ) );

		return $matching;
	}

	/** The single homepage hero feature, or null when none is active. */
	public static function homepage_hero(): ?array {
		$features = self::for_placement( self::PLACEMENT_HOMEPAGE_HERO );

		return $features[0] ?? null;
	}

	/**
	 * Resolve a stored CTA URL for output.
	 *
	 * Editors may enter a site-relative path, which is friendlier than pasting a
	 * beta hostname that would then be wrong in production.
	 */
	public static function url( string $stored ): string {
		$stored = trim( $stored );

		if ( $stored === '' ) {
			return '';
		}

		if ( str_starts_with( $stored, '/' ) ) {
			return home_url( $stored );
		}

		return $stored;
	}
}
