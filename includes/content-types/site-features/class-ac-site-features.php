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

class AC_Site_Features extends AC_Option_Content {

	public const OPTION        = 'ac_site_features';
	public const OPTION_SYNCED = 'ac_site_features_synced_at';

	public const PLACEMENT_HOMEPAGE_HERO = 'Homepage hero';

	/**
	 * include=0: Site Features carry no entry or asset links, so there is nothing
	 * to resolve and no reason to make the CDA assemble an includes block.
	 */
	public static function defaults(): array {
		return array_merge(
			parent::defaults(),
			[
				'label'    => 'Site Features',
				'singular' => 'Site Feature',
				'include'  => 0,
				'order'    => 'fields.rank,sys.id',
			]
		);
	}

	protected function map_item( array $raw, array &$warnings ): ?array {
		$headline = trim( (string) ( AC_Contentful::field( $raw, 'headline' ) ?? '' ) );
		if ( $headline === '' ) {
			return null;
		}

		return [
			'id'              => (string) ( $raw['sys']['id'] ?? '' ),
			'placement'       => (string) ( AC_Contentful::field( $raw, 'placement' ) ?? '' ),
			'active'          => (bool) AC_Contentful::field( $raw, 'active', false ),
			'rank'            => (int) ( AC_Contentful::field( $raw, 'rank' ) ?? 99 ),
			'kicker'          => (string) ( AC_Contentful::field( $raw, 'kicker' ) ?? '' ),
			'badge'           => (string) ( AC_Contentful::field( $raw, 'badge' ) ?? '' ),
			'headline'        => $headline,
			'blurb'           => (string) ( AC_Contentful::field( $raw, 'blurb' ) ?? '' ),
			'cta_label'       => (string) ( AC_Contentful::field( $raw, 'ctaLabel' ) ?? '' ),
			'cta_url'         => (string) ( AC_Contentful::field( $raw, 'ctaUrl' ) ?? '' ),
			'aside_label'     => (string) ( AC_Contentful::field( $raw, 'asideLabel' ) ?? '' ),
			'aside_headline'  => (string) ( AC_Contentful::field( $raw, 'asideHeadline' ) ?? '' ),
			'aside_body'      => (string) ( AC_Contentful::field( $raw, 'asideBody' ) ?? '' ),
			'aside_cta_label' => (string) ( AC_Contentful::field( $raw, 'asideCtaLabel' ) ?? '' ),
			'aside_cta_url'   => (string) ( AC_Contentful::field( $raw, 'asideCtaUrl' ) ?? '' ),
		];
	}

	/**
	 * Active features for a placement, best first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_placement( string $placement ): array {
		$matching = array_values(
			array_filter(
				self::stored(),
				static fn ( $f ) => ! empty( $f['active'] )
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
