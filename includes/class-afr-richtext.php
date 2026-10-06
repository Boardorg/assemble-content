<?php
/**
 * Contentful Rich Text document -> HTML.
 *
 * The Field Report model only permits paragraphs, ordered lists and bold/italic
 * marks, but the full common node set is handled so a loosened validation in
 * Contentful never silently drops content on the floor.
 */

defined( 'ABSPATH' ) || exit;

class AFR_RichText {

	/** Class applied to ordered lists in the current render, if any. */
	private static string $ol_class = '';

	/**
	 * @param array $document Contentful Rich Text document.
	 * @param array $opts     'ol_class' => class to put on ordered lists (for the
	 *                        counter-styled takeaway lists).
	 */
	public static function to_html( $document, array $opts = [] ): string {
		if ( ! is_array( $document ) || ( $document['nodeType'] ?? '' ) !== 'document' ) {
			return '';
		}

		self::$ol_class = (string) ( $opts['ol_class'] ?? '' );
		$html           = self::children( $document );
		self::$ol_class = '';

		return $html;
	}

	/**
	 * The first N paragraphs of a document, as HTML.
	 *
	 * The public version of a report shows an opening excerpt rather than the whole
	 * of Picking Up the Story, so it needs paragraph-level access.
	 */
	public static function first_paragraphs( $document, int $count ): string {
		if ( ! is_array( $document ) || ( $document['nodeType'] ?? '' ) !== 'document' ) {
			return '';
		}

		$html = '';
		$seen = 0;

		foreach ( $document['content'] ?? [] as $node ) {
			if ( ( $node['nodeType'] ?? '' ) !== 'paragraph' ) {
				continue;
			}

			$paragraph = self::node( $node );

			// wrap() drops Contentful's trailing empty paragraph; don't count it.
			if ( trim( $paragraph ) === '' ) {
				continue;
			}

			$html .= $paragraph;

			if ( ++$seen >= $count ) {
				break;
			}
		}

		return $html;
	}

	/** How many non-empty paragraphs a document holds. */
	public static function paragraph_count( $document ): int {
		if ( ! is_array( $document ) || ( $document['nodeType'] ?? '' ) !== 'document' ) {
			return 0;
		}

		$count = 0;
		foreach ( $document['content'] ?? [] as $node ) {
			if ( ( $node['nodeType'] ?? '' ) === 'paragraph' && trim( self::node( $node ) ) !== '' ) {
				$count++;
			}
		}

		return $count;
	}

	/** Plain-text lines of the top-level ordered list, numbering stripped. */
	public static function list_items( $document ): array {
		if ( ! is_array( $document ) || ( $document['nodeType'] ?? '' ) !== 'document' ) {
			return [];
		}

		$items = [];
		foreach ( $document['content'] ?? [] as $node ) {
			if ( ! in_array( $node['nodeType'] ?? '', [ 'ordered-list', 'unordered-list' ], true ) ) {
				continue;
			}

			foreach ( $node['content'] ?? [] as $li ) {
				$text = trim( html_entity_decode( wp_strip_all_tags( self::node( $li ) ), ENT_QUOTES, 'UTF-8' ) );
				if ( $text !== '' ) {
					$items[] = $text;
				}
			}
		}

		return $items;
	}

	/** Flatten a document to plain text — used for excerpts and social copy. */
	public static function to_text( $document ): string {
		$html = self::to_html( $document );

		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	private static function children( array $node ): string {
		$html = '';
		foreach ( $node['content'] ?? [] as $child ) {
			$html .= self::node( $child );
		}

		return $html;
	}

	private static function node( $node ): string {
		if ( ! is_array( $node ) ) {
			return '';
		}

		return match ( $node['nodeType'] ?? '' ) {
			'text'                  => self::text( $node ),
			'paragraph'             => self::wrap( 'p', $node ),
			'heading-1'             => self::wrap( 'h2', $node ), // h1 belongs to the page title
			'heading-2'             => self::wrap( 'h2', $node ),
			'heading-3'             => self::wrap( 'h3', $node ),
			'heading-4'             => self::wrap( 'h4', $node ),
			'heading-5'             => self::wrap( 'h5', $node ),
			'heading-6'             => self::wrap( 'h6', $node ),
			'unordered-list'        => self::wrap( 'ul', $node ),
			'ordered-list'          => self::wrap( 'ol', $node ),
			'list-item'             => self::wrap( 'li', $node ),
			'blockquote'            => self::wrap( 'blockquote', $node ),
			'hr'                    => '<hr />',
			'hyperlink'             => self::hyperlink( $node ),
			'entry-hyperlink'       => self::entry_hyperlink( $node ),
			'asset-hyperlink'       => self::asset_hyperlink( $node ),
			'embedded-asset-block'  => self::embedded_asset( $node ),
			'embedded-entry-block',
			'embedded-entry-inline' => self::children( $node ),
			'table'                 => self::wrap( 'table', $node ),
			'table-row'             => self::wrap( 'tr', $node ),
			'table-cell'            => self::wrap( 'td', $node ),
			'table-header-cell'     => self::wrap( 'th', $node ),
			default                 => self::children( $node ),
		};
	}

	private static function wrap( string $tag, array $node ): string {
		$inner = self::children( $node );

		// Contentful emits an empty trailing paragraph on almost every document.
		if ( $tag === 'p' && trim( $inner ) === '' ) {
			return '';
		}

		$attrs = '';
		if ( $tag === 'ol' && self::$ol_class !== '' ) {
			$attrs = ' class="' . esc_attr( self::$ol_class ) . '"';
		}

		return "<{$tag}{$attrs}>{$inner}</{$tag}>";
	}

	private static function text( array $node ): string {
		$text = esc_html( (string) ( $node['value'] ?? '' ) );

		foreach ( $node['marks'] ?? [] as $mark ) {
			$text = match ( $mark['type'] ?? '' ) {
				'bold'          => "<strong>{$text}</strong>",
				'italic'        => "<em>{$text}</em>",
				'underline'     => "<u>{$text}</u>",
				'code'          => "<code>{$text}</code>",
				'superscript'   => "<sup>{$text}</sup>",
				'subscript'     => "<sub>{$text}</sub>",
				'strikethrough' => "<s>{$text}</s>",
				default         => $text,
			};
		}

		return $text;
	}

	private static function hyperlink( array $node ): string {
		$uri   = esc_url( (string) ( $node['data']['uri'] ?? '' ) );
		$inner = self::children( $node );

		if ( $uri === '' ) {
			return $inner;
		}

		$external = ! str_contains( $uri, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$attrs    = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

		return sprintf( '<a href="%s"%s>%s</a>', $uri, $attrs, $inner );
	}

	private static function entry_hyperlink( array $node ): string {
		$entry = $node['data']['target'] ?? null;
		$slug  = AFR_Contentful::field( is_array( $entry ) ? $entry : null, 'slug' );
		$inner = self::children( $node );

		if ( ! $slug ) {
			return $inner;
		}

		$post = AFR_Sync::find_post_by_slug( (string) $slug );

		return $post
			? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $post ) ), $inner )
			: $inner;
	}

	private static function asset_hyperlink( array $node ): string {
		$url   = AFR_Contentful::asset_url( $node['data']['target'] ?? null );
		$inner = self::children( $node );

		return $url
			? sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), $inner )
			: $inner;
	}

	private static function embedded_asset( array $node ): string {
		$asset = $node['data']['target'] ?? null;
		$url   = AFR_Contentful::asset_url( is_array( $asset ) ? $asset : null );

		if ( $url === '' ) {
			return '';
		}

		$title = (string) ( $asset['fields']['title'] ?? '' );
		$alt   = (string) ( $asset['fields']['description'] ?? $title );

		return sprintf(
			'<figure class="afr-figure"><img src="%s" alt="%s" loading="lazy" />%s</figure>',
			esc_url( $url ),
			esc_attr( $alt ),
			$title ? '<figcaption>' . esc_html( $title ) . '</figcaption>' : ''
		);
	}
}
