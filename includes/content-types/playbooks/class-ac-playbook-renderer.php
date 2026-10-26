<?php
/**
 * A playbook's view model: what a theme may render for the current visitor.
 *
 * The gate works as it does for Field Reports. AC_Audience::view_for() picks the
 * view, and VIEW_SECTIONS below lists everything that view may contain. A theme
 * renders only what view_model() hands it, so it can't leak a chapter the view
 * doesn't allow. There is no council or delegate variant for playbooks: those
 * audiences get the standard view.
 *
 * The body is one Rich Text document. Each Heading 2 starts a chapter (a TOC
 * entry with a stable anchor); a Heading 2 with a colon ("Days 0–30: Diagnose
 * and Orient") is a numbered phase. Inside a chapter, a Heading 3 is a subsection,
 * and an all-italic paragraph straight after it is that subsection's subtitle.
 * Embedded Playbook Callout entries come out as `callout` blocks for the theme to
 * map onto its own template parts (feed change #6, for playbooks).
 */

defined( 'ABSPATH' ) || exit;

class AC_Playbook_Renderer {

	public const CALLOUT_TYPE = 'playbookCallout';

	/**
	 * The gate: which sections each view may have. Everything a visitor can be
	 * shown is listed here, and nowhere else.
	 *
	 * Every view gets the playbook's identity (title, community, topics, dek,
	 * edition, image, the people credited). The body is members only. The public
	 * view gets the first chapter and the join prompt; the denied view gets the gate.
	 */
	private const VIEW_SECTIONS = [
		AC_Audience::VIEW_STANDARD => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'image', 'people', 'toc', 'chapters', 'community', 'author' ],
		AC_Audience::VIEW_PUBLIC   => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'image', 'people', 'intro', 'join' ],
		AC_Audience::VIEW_DENIED   => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'image', 'people', 'gate' ],
	];

	/**
	 * Sections a site may add to the public view of a playbook ticked Public,
	 * through `ac_public_full_text` (default off), as for Field Reports.
	 */
	private const PUBLIC_FULL_SECTIONS = [ 'toc', 'chapters', 'community', 'author' ];

	/** Callout kinds, as Contentful names them, and the keys the view model uses. */
	private const CALLOUT_KINDS = [
		'Stat'       => 'stat',
		'Checklist'  => 'checklist',
		'Checkpoint' => 'checkpoint',
		'Note'       => 'note',
		'Table'      => 'table',
		'Layers'     => 'layers',
	];

	/**
	 * Everything a theme needs to render one playbook for the current visitor, and
	 * nothing the visitor may not see.
	 *
	 * - `view`: standard, public or denied (council and delegate read as standard).
	 * - `sections`: only the sections VIEW_SECTIONS allows for that view.
	 * - `words`: word count of the whole body, for a reading time, in every view.
	 * - `full_text`: true when the site opted in (`ac_public_full_text`) to give the
	 *   public view of a Public playbook its body as well.
	 * - `notices`: the admin preview switcher and the bypass banner, as HTML.
	 *
	 * Section shapes:
	 *   headline  string
	 *   kicker    { name, short_name, slug, practice_area, logo: asset|null, logo_url }
	 *   topics    [ { name, slug } ]
	 *   dek       string
	 *   edition   string, e.g. "2026 Edition"
	 *   image     asset (featuredImage)
	 *   people    { author: person|null, editor: person|null } (no bios)
	 *   toc       [ { id, title, kicker, rest, kind: intro|phase, number } ]
	 *   chapters  [ chapter ]
	 *   intro     chapter (public: the first chapter only)
	 *   community { name, boilerplate }
	 *   author    person, with bio
	 *   join      { url, community, bypass: button|null }
	 *   gate      { sign_in_url, bypass: button|null }
	 *
	 *   chapter = { id, title, kicker, rest, kind: intro|phase, number, blocks: [ block ] }
	 *   block   = { type: lead, html }                 opening paragraph, before any subsection
	 *           | { type: html, html }                 paragraphs, lists, quotes
	 *           | { type: subhead, id, title, subtitle }
	 *           | { type: callout, kind, label, tag, stat, text, items: [string], rows: [ { label, value } ], columns: [string] }
	 *   person  = { name, role, organization, bio, image: asset|null, image_url }
	 *
	 * @return array{view:string,post_id:int,sections:array<string,mixed>,words:int,full_text:bool,notices:array{preview:string,bypass:string}}
	 */
	public static function view_model( int $post_id ): array {
		$entry = AC_Post_Type_Content::data( $post_id );

		if ( ! $entry ) {
			return [
				'view'             => '',
				'post_id'          => $post_id,
				'sections'         => [],
				'words'            => 0,
				'full_text'        => false,
				'functional_areas' => [],
				'places'           => [],
				'notices'          => [ 'preview' => '', 'bypass' => '' ],
			];
		}

		$model = self::view_model_for( $entry, AC_Audience::view_for( $post_id ), $post_id );

		/** Documented on AC_Field_Report_Renderer::view_model(): the same filter covers playbooks. */
		if ( AC_Audience::VIEW_PUBLIC === $model['view'] && apply_filters( 'ac_public_full_text', false, $post_id ) ) {
			foreach ( self::PUBLIC_FULL_SECTIONS as $key ) {
				$value = self::section( $key, $entry );
				if ( $value !== null && $value !== '' && $value !== [] ) {
					$model['sections'][ $key ] = $value;
				}
			}
			unset( $model['sections']['intro'] );
			$model['full_text'] = true;
		}

		$model['notices'] = [
			'preview' => AC_Audience::preview_links( $post_id ),
			'bypass'  => AC_Bypass::banner(),
		];

		return $model;
	}

	/**
	 * The view model for an entry and a view chosen by the caller (tests, draft
	 * preview). Only use it where access has already been settled.
	 */
	public static function view_model_for( array $entry, string $view, int $post_id = 0 ): array {
		$view = self::normalise_view( $view );

		return [
			'view'             => $view,
			'post_id'          => $post_id,
			'sections'         => self::sections( $entry, $view ),
			'words'            => self::words( self::chapters( $entry ) ),
			'full_text'        => false,
			'functional_areas' => AC_Field_Report_Renderer::functional_areas_data( $entry ),
			'places'           => AC_Placement::places( $entry ),
			'notices'          => [ 'preview' => '', 'bypass' => '' ],
		];
	}

	/** Council and delegate audiences read playbooks in full; unknown views are denied. */
	public static function normalise_view( string $view ): string {
		if ( in_array( $view, [ AC_Audience::VIEW_COUNCIL, AC_Audience::VIEW_DELEGATE ], true ) ) {
			return AC_Audience::VIEW_STANDARD;
		}

		return isset( self::VIEW_SECTIONS[ $view ] ) ? $view : AC_Audience::VIEW_DENIED;
	}

	/** Section keys a view may show. */
	public static function view_sections( string $view ): array {
		return self::VIEW_SECTIONS[ self::normalise_view( $view ) ];
	}

	/** The sections a view may show. Nothing outside view_sections( $view ) is built. */
	public static function sections( array $entry, string $view ): array {
		$sections = [];

		foreach ( self::view_sections( $view ) as $key ) {
			$value = self::section( $key, $entry );

			if ( $value !== null && $value !== '' && $value !== [] ) {
				$sections[ $key ] = $value;
			}
		}

		return $sections;
	}

	/** One section's data, or null. */
	private static function section( string $key, array $entry ) {
		return match ( $key ) {
			'headline'  => trim( (string) ( AC_Contentful::field( $entry, 'title' ) ?? '' ) ),
			'kicker'    => AC_Field_Report_Renderer::community_data( $entry, 'community' ),
			'topics'    => AC_Query::entry_topics( $entry ),
			'dek'       => self::dek( $entry ),
			'edition'   => trim( (string) ( AC_Contentful::field( $entry, 'edition' ) ?? '' ) ),
			'image'     => self::featured_image( $entry ),
			'people'    => self::people( $entry ),
			'toc'       => self::toc( self::chapters( $entry ) ),
			'chapters'  => self::chapters( $entry ),
			'intro'     => self::chapters( $entry )[0] ?? null,
			'community' => AC_Field_Report_Renderer::community_about( $entry, 'community' ),
			'author'    => AC_Field_Report_Renderer::person( AC_Contentful::field( $entry, 'author' ) ),
			'join'      => AC_Engagement::join_data( $entry ),
			'gate'      => AC_Field_Report_Renderer::gate_data(),
			default     => null,
		};
	}

	// ------------------------------------------------------------ section data

	/** The dek, else the first 40 words of the body. Also the post excerpt. */
	public static function dek( array $entry ): string {
		$dek = trim( (string) ( AC_Contentful::field( $entry, 'dek' ) ?? '' ) );

		return $dek !== '' ? $dek : wp_trim_words( AC_RichText::to_text( AC_Contentful::field( $entry, 'body' ) ), 40 );
	}

	private static function featured_image( array $entry ): ?array {
		$asset = AC_Contentful::field( $entry, 'featuredImage' );

		return is_array( $asset ) && AC_Contentful::asset_url( $asset ) !== '' ? $asset : null;
	}

	/** Written by / Edited by, without bios (the author's bio is its own section). */
	private static function people( array $entry ): ?array {
		$people = [];

		foreach ( [ 'author', 'editor' ] as $field ) {
			$person = AC_Field_Report_Renderer::person( AC_Contentful::field( $entry, $field ) );
			if ( $person ) {
				unset( $person['bio'] );
				$people[ $field ] = $person;
			}
		}

		return $people ? $people + [ 'author' => null, 'editor' => null ] : null;
	}

	/** @return array<int,array> The TOC: one row per titled chapter. */
	private static function toc( array $chapters ): array {
		$toc = [];

		foreach ( $chapters as $chapter ) {
			if ( $chapter['title'] === '' ) {
				continue;
			}

			$toc[] = array_intersect_key( $chapter, array_flip( [ 'id', 'title', 'kicker', 'rest', 'kind', 'number' ] ) );
		}

		return $toc;
	}

	/**
	 * Split the body into chapters at each Heading 2.
	 *
	 * @return array<int,array>
	 */
	public static function chapters( array $entry ): array {
		$document = AC_Contentful::field( $entry, 'body' );

		if ( ! is_array( $document ) || ( $document['nodeType'] ?? '' ) !== 'document' ) {
			return [];
		}

		$chapters = [];
		$ids      = [];
		$phase    = 0;
		$current  = null;
		$nodes    = array_values( (array) ( $document['content'] ?? [] ) );

		for ( $i = 0, $n = count( $nodes ); $i < $n; $i++ ) {
			$node = $nodes[ $i ];
			$type = (string) ( $node['nodeType'] ?? '' );

			if ( $type === 'heading-2' ) {
				if ( $current ) {
					$chapters[] = $current;
				}

				$title = self::plain( $node );
				$colon = mb_strpos( $title, ':' );
				$is_phase = $colon !== false && $colon > 0;

				$current = [
					'id'     => self::unique_id( $title, $ids ),
					'title'  => $title,
					'kicker' => $is_phase ? trim( mb_substr( $title, 0, $colon ) ) : '',
					'rest'   => $is_phase ? trim( mb_substr( $title, $colon + 1 ) ) : $title,
					'kind'   => $is_phase ? 'phase' : 'intro',
					'number' => $is_phase ? ++$phase : 0,
					'blocks' => [],
				];
				continue;
			}

			// Anything before the first chapter heading becomes an untitled opening chapter.
			if ( ! $current ) {
				$current = [ 'id' => '', 'title' => '', 'kicker' => '', 'rest' => '', 'kind' => 'intro', 'number' => 0, 'blocks' => [] ];
			}

			if ( $type === 'heading-3' ) {
				$subtitle = '';
				if ( isset( $nodes[ $i + 1 ] ) && self::is_italic_paragraph( $nodes[ $i + 1 ] ) ) {
					$subtitle = self::plain( $nodes[ $i + 1 ] );
					$i++;
				}

				$title               = self::plain( $node );
				$current['blocks'][] = [ 'type' => 'subhead', 'id' => self::unique_id( $title, $ids ), 'title' => $title, 'subtitle' => $subtitle ];
				continue;
			}

			if ( $type === 'embedded-entry-block' ) {
				$callout = self::callout( $node['data']['target'] ?? null );
				if ( $callout ) {
					$current['blocks'][] = $callout;
				}
				continue;
			}

			$html = AC_RichText::to_html( [ 'nodeType' => 'document', 'data' => [], 'content' => [ $node ] ] );
			if ( trim( $html ) === '' ) {
				continue;
			}

			// The chapter's opening paragraph, before any subsection or callout, is its lead.
			if ( $type === 'paragraph' && ! $current['blocks'] ) {
				$current['blocks'][] = [ 'type' => 'lead', 'html' => AC_RichText::to_html( [ 'nodeType' => 'document', 'data' => [], 'content' => $node['content'] ?? [] ] ) ];
				continue;
			}

			$last = count( $current['blocks'] ) - 1;
			if ( $last >= 0 && $current['blocks'][ $last ]['type'] === 'html' ) {
				$current['blocks'][ $last ]['html'] .= $html;
			} else {
				$current['blocks'][] = [ 'type' => 'html', 'html' => $html ];
			}
		}

		if ( $current ) {
			$chapters[] = $current;
		}

		return $chapters;
	}

	/** A resolved Playbook Callout entry as a block, or null. */
	private static function callout( $target ): ?array {
		if ( ! is_array( $target ) || ( $target['sys']['contentType']['sys']['id'] ?? '' ) !== self::CALLOUT_TYPE ) {
			return null;
		}

		$kind = self::CALLOUT_KINDS[ (string) ( AC_Contentful::field( $target, 'kind' ) ?? '' ) ] ?? '';
		if ( $kind === '' ) {
			return null;
		}

		$label = trim( (string) ( AC_Contentful::field( $target, 'label' ) ?? '' ) );
		$items = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) AC_Contentful::field( $target, 'items', [] ) ) ) ) );

		$rows    = [];
		$columns = [];
		if ( in_array( $kind, [ 'table', 'layers' ], true ) ) {
			foreach ( $items as $item ) {
				$rows[] = self::split_pair( $item );
			}
			$items = [];
		}
		if ( $kind === 'table' && $label !== '' ) {
			$pair    = self::split_pair( $label );
			$columns = array_values( array_filter( [ $pair['label'], $pair['value'] ] ) );
			$label   = '';
		}

		$callout = [
			'type'    => 'callout',
			'kind'    => $kind,
			'label'   => $label,
			'tag'     => trim( (string) ( AC_Contentful::field( $target, 'tag' ) ?? '' ) ),
			'stat'    => trim( (string) ( AC_Contentful::field( $target, 'stat' ) ?? '' ) ),
			'text'    => trim( (string) ( AC_Contentful::field( $target, 'text' ) ?? '' ) ),
			'items'   => $items,
			'rows'    => $rows,
			'columns' => $columns,
		];

		return ( $callout['stat'] === '' && $callout['text'] === '' && ! $items && ! $rows ) ? null : $callout;
	}

	/** "Label: Value", split at the first colon. */
	private static function split_pair( string $text ): array {
		$at = mb_strpos( $text, ':' );

		return $at === false
			? [ 'label' => trim( $text ), 'value' => '' ]
			: [ 'label' => trim( mb_substr( $text, 0, $at ) ), 'value' => trim( mb_substr( $text, $at + 1 ) ) ];
	}

	/** A paragraph whose every non-blank text run is italic. */
	private static function is_italic_paragraph( $node ): bool {
		if ( ! is_array( $node ) || ( $node['nodeType'] ?? '' ) !== 'paragraph' ) {
			return false;
		}

		$seen = false;
		foreach ( (array) ( $node['content'] ?? [] ) as $child ) {
			if ( ( $child['nodeType'] ?? '' ) !== 'text' ) {
				return false;
			}
			if ( trim( (string) ( $child['value'] ?? '' ) ) === '' ) {
				continue;
			}
			if ( ! in_array( 'italic', array_column( (array) ( $child['marks'] ?? [] ), 'type' ), true ) ) {
				return false;
			}
			$seen = true;
		}

		return $seen;
	}

	private static function plain( array $node ): string {
		return AC_RichText::to_text( [ 'nodeType' => 'document', 'data' => [], 'content' => [ $node ] ] );
	}

	/** A heading's anchor: its slug, de-duplicated within the playbook. */
	private static function unique_id( string $title, array &$ids ): string {
		$base = sanitize_title( $title ) ?: 'section';
		$id   = $base;
		$n    = 2;

		while ( isset( $ids[ $id ] ) ) {
			$id = $base . '-' . $n++;
		}

		$ids[ $id ] = true;

		return $id;
	}

	/** Words in every chapter, callouts included. */
	private static function words( array $chapters ): int {
		$text = [];

		foreach ( $chapters as $chapter ) {
			$text[] = $chapter['title'];
			foreach ( $chapter['blocks'] as $block ) {
				$text[] = match ( $block['type'] ) {
					'lead', 'html' => $block['html'],
					'subhead'      => $block['title'] . ' ' . $block['subtitle'],
					'callout'      => implode( ' ', array_merge( [ $block['label'], $block['stat'], $block['text'] ], $block['items'], array_merge( ...array_map( 'array_values', $block['rows'] ?: [ [] ] ) ) ) ),
					default        => '',
				};
			}
		}

		$plain = html_entity_decode( wp_strip_all_tags( implode( ' ', $text ) ), ENT_QUOTES, 'UTF-8' );

		return count( preg_split( '/\s+/u', trim( $plain ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
	}

	// ----------------------------------------------------------------- storage

	/**
	 * Plain HTML of the whole playbook for the stored post_content (search, Yoast).
	 * Never served: every display goes through view_model().
	 */
	public static function render_document( array $entry ): string {
		$html = '';

		foreach ( self::chapters( $entry ) as $chapter ) {
			if ( $chapter['title'] !== '' ) {
				$html .= '<h2>' . esc_html( $chapter['title'] ) . '</h2>';
			}

			foreach ( $chapter['blocks'] as $block ) {
				$html .= match ( $block['type'] ) {
					'lead'    => '<p>' . $block['html'] . '</p>',
					'html'    => $block['html'],
					'subhead' => '<h3>' . esc_html( $block['title'] ) . '</h3>' . ( $block['subtitle'] !== '' ? '<p>' . esc_html( $block['subtitle'] ) . '</p>' : '' ),
					'callout' => self::callout_text( $block ),
					default   => '',
				};
			}
		}

		return $html;
	}

	private static function callout_text( array $block ): string {
		$lines = array_filter( array_merge(
			[ $block['label'], trim( $block['stat'] . ' ' . $block['text'] ) ],
			$block['items'],
			array_map( static fn( $row ) => trim( $row['label'] . ': ' . $row['value'], ': ' ), $block['rows'] )
		) );

		return $lines ? '<p>' . implode( '<br />', array_map( 'esc_html', $lines ) ) . '</p>' : '';
	}
}
