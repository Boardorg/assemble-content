<?php
/**
 * Assemble-only fields per summit, keyed by EP summit code.
 *
 * EP holds the summit facts (title, dates, place, links). These are Assemble's
 * own choices that EP doesn't keep:
 *
 *   - area:          the practice-area key that colours the summit ('' is neutral;
 *                    Life Sciences has no colour ramp);
 *   - area_label:    the eyebrow, in EP's practice areas ("Manufacturing & Safety");
 *   - communities:   Community slugs (the community directory) the summit is
 *                    featured under; ['*'] means every Community in its area;
 *   - blurb:         the one-line card description;
 *   - logo:          a colour logo in the shared image library, relative to
 *                    wp-content/assets/ (for light backgrounds);
 *   - format:        'in-person' or 'virtual';
 *   - cta_label:     the button text, when a placement doesn't set its own;
 *   - ad_headline,
 *     ad_pitch:      copy for event ads;
 *   - image:         an ad or hero image, relative to wp-content/assets/;
 *   - video_url,
 *     video_caption: a short teaser video and its caption.
 *
 * Extras never override an EP fact, and a summit missing from this map still
 * renders: neutral, with every extra empty. To change one without a commit,
 * use the `afr_summit_extras` filter.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assemble's own fields per summit, merged onto EP's facts.
 */
class AFR_Summit_Extras {

	public const DEFAULTS = [
		'area'          => '',
		'area_label'    => '',
		'communities'   => [],
		'blurb'         => '',
		'logo'          => '',
		'format'        => 'in-person',
		'cta_label'     => '',
		'ad_headline'   => '',
		'ad_pitch'      => '',
		'image'         => '',
		'video_url'     => '',
		'video_caption' => '',
	];

	/**
	 * The extras map, keyed by EP summit code.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		$human_capital = [ 'area' => 'human-resources', 'area_label' => __( 'Human Capital', 'assemble-content' ) ];
		$supply_chain  = [ 'area' => 'manufacturing', 'area_label' => __( 'Supply Chain', 'assemble-content' ) ];
		$manufacturing = [ 'area' => 'manufacturing', 'area_label' => __( 'Manufacturing & Safety', 'assemble-content' ) ];
		$marketing     = [ 'area' => 'marketing', 'area_label' => __( 'Marketing & Comms', 'assemble-content' ) ];
		$finance       = [ 'area' => 'finance', 'area_label' => __( 'Finance', 'assemble-content' ) ];
		$life_sciences = [ 'area' => '', 'area_label' => __( 'Life Sciences', 'assemble-content' ) ];

		$extras = [
			'nasces'  => $supply_chain + [
				'communities' => [ 'supply-chain' ],
				'blurb'       => __( 'Supply chain strategy, planning, resilience, logistics, and operations.', 'assemble-content' ),
				'logo'        => 'summits/nasces-wordmark-color.png',
			],
			'napes'   => $supply_chain + [
				'communities' => [ 'supply-chain' ],
				'blurb'       => __( 'Procurement, sourcing, supplier strategy, and value creation.', 'assemble-content' ),
				'logo'        => 'summits/napes-wordmark-color.png',
			],
			'nasrs'   => $supply_chain + [
				'communities' => [ 'esg-csr' ],
				'blurb'       => __( 'Sustainability, responsibility, ESG, and measurable progress.', 'assemble-content' ),
				'logo'        => 'summits/nasrs-wordmark-color.png',
			],
			'fws'     => $manufacturing + [
				'communities' => [ 'manufacturing' ],
				'blurb'       => __( 'Food safety, quality, manufacturing, and operational excellence.', 'assemble-content' ),
				'logo'        => 'summits/fws-wordmark-color.png',
			],
			'names'   => $manufacturing + [
				'communities' => [ 'manufacturing' ],
				'blurb'       => __( 'Manufacturing leadership, operational excellence, automation, and resilience.', 'assemble-content' ),
				'logo'        => 'summits/names-wordmark-color.png',
			],
			'namls'   => $marketing + [
				'communities' => [ '*' ],
				'blurb'       => __( 'Marketing leadership, brand, demand generation, and commercial growth.', 'assemble-content' ),
				'logo'        => 'summits/namls-wordmark-color.png',
			],
			'nafes'   => $finance + [
				'communities' => [],
				'blurb'       => __( 'Finance leadership, planning discipline, and enterprise transformation.', 'assemble-content' ),
				'logo'        => 'summits/nafes-spring-wordmark-color.png',
			],
			'nafesf'  => $finance + [
				'communities' => [],
				'blurb'       => __( 'Planning, transformation, AI adoption, and enterprise performance.', 'assemble-content' ),
				'logo'        => 'summits/nafes-fall-wordmark-color.png',
			],
			'nahres'  => $human_capital + [
				'communities' => [ '*' ],
				'blurb'       => __( 'Talent strategy, organization design, culture, workforce planning, and people leadership.', 'assemble-content' ),
				'logo'        => 'summits/nahres-spring-wordmark-color.png',
			],
			'nahresf' => $human_capital + [
				'communities' => [ '*' ],
				'blurb'       => __( 'People strategy, talent, culture, workforce experience, and capability.', 'assemble-content' ),
				'logo'        => 'summits/nahres-fall-wordmark-color.png',
			],
			'nales'   => $human_capital + [
				'communities' => [ 'learning-development' ],
				'blurb'       => __( 'Enterprise learning, leadership development, and workforce readiness.', 'assemble-content' ),
				'logo'        => 'summits/nales-wordmark-color.png',
			],
			'bmws'    => $life_sciences + [
				'communities' => [],
				'blurb'       => __( 'Biomanufacturing operations, quality, capacity, and next-generation production.', 'assemble-content' ),
				'logo'        => 'summits/bmws-wordmark-color.png',
			],
			'mdws'    => $life_sciences + [
				'communities' => [],
				'blurb'       => __( 'Medical device quality, operations, supply chain, regulatory, and innovation leaders.', 'assemble-content' ),
				'logo'        => 'summits/mdws-wordmark-color.png',
			],
			'pmws'    => $life_sciences + [
				'communities' => [],
				'blurb'       => __( 'Pharma manufacturing, quality, technology transfer, and operational readiness.', 'assemble-content' ),
				'logo'        => 'summits/pmws-wordmark-color.png',
			],
		];

		// Fall editions share their Spring summit's extras (the library has no separate Fall logo for these).
		$extras['namesf'] = $extras['names'];
		$extras['nasrsf'] = $extras['nasrs'];

		/**
		 * Filter the Assemble-only extras per summit code.
		 *
		 * @param array $extras
		 */
		return (array) apply_filters( 'afr_summit_extras', $extras );
	}

	/**
	 * One summit with its extras merged in. `logo` and `image` become absolute
	 * URLs, or ''. EP's facts win: an extra never replaces a key the importer filled.
	 *
	 * @param array      $summit A stored summit.
	 * @param array|null $extras The map from all(), when merging many summits.
	 * @return array
	 */
	public static function merge( array $summit, ?array $extras = null ): array {
		$extras = $extras ?? self::all();
		$extra = array_intersect_key( array_merge( self::DEFAULTS, (array) ( $extras[ $summit['code'] ?? '' ] ?? [] ) ), self::DEFAULTS );

		$extra['communities'] = array_values( array_filter( array_map( 'strval', (array) $extra['communities'] ) ) );
		$extra['format']      = 'virtual' === $extra['format'] ? 'virtual' : 'in-person';
		$extra['logo']        = self::asset_url( (string) $extra['logo'] );
		$extra['image']       = self::asset_url( (string) $extra['image'] );
		$extra['video_url']   = AFR_Summits::https( $extra['video_url'] );

		return $summit + $extra;
	}

	/**
	 * A shared-library file's URL. Sites that serve the library elsewhere change
	 * it with the `afr_summit_asset_url` filter.
	 *
	 * @param string $path Relative to wp-content/assets/.
	 * @return string The URL, or '' for an empty path.
	 */
	public static function asset_url( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		$path = ltrim( $path, '/' );

		/**
		 * Filter the URL of a shared-library file used by a summit.
		 *
		 * @param string $url
		 * @param string $path Relative to wp-content/assets/.
		 */
		return (string) apply_filters( 'afr_summit_asset_url', content_url( 'assets/' . $path ), $path );
	}
}
