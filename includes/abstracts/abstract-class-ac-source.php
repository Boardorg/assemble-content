<?php
/**
 * Where synced content comes from: a read-only API the plugin copies into WordPress.
 *
 * A source knows how to fetch and how to identify what it fetched. It never
 * decides where content is stored or whether it belongs on this site; that is the
 * content type's job (AC_Content_Type). Sources are registered in AC_Types and
 * looked up by ID, so a content type names its source in its config.
 *
 * Every fetch reports failure in `error` rather than throwing, and a caller must
 * never treat what is missing from a failed or incomplete fetch as unpublished.
 */

defined( 'ABSPATH' ) || exit;

abstract class AC_Source {

	/** The ID content types use to name this source, e.g. 'contentful'. */
	abstract public function id(): string;

	/** A name for admin screens and log messages, e.g. 'Contentful'. */
	abstract public function label(): string;

	/** Whether this site has what the source needs (credentials, a URL). */
	abstract public function is_configured(): bool;

	/**
	 * Fetch every item a query names.
	 *
	 * `complete` is true only when the whole set arrived. `not_modified` is true
	 * when the source confirmed nothing changed since the copy the query refers to
	 * (an ETag match); `items` is then empty and the stored copy stands.
	 *
	 * @param array $query Source-specific query.
	 * @return array{items: array<int,array>, error: string, total: int, complete: bool, not_modified: bool, meta: array}
	 */
	abstract public function fetch_all( array $query ): array;

	/**
	 * Fetch one item by ID. Only `item: null` with `error: ''` means the item is
	 * gone; anything with an error says nothing about the item.
	 *
	 * @param string $id    The item's ID at the source.
	 * @param array  $query Source-specific options.
	 * @return array{item: ?array, error: string}
	 */
	public function fetch_one( string $id, array $query = [] ): array {
		return [
			'item'  => null,
			'error' => sprintf( '%s cannot fetch single items.', $this->label() ),
		];
	}

	/**
	 * What the sync engine needs to know about an item: its ID, its revision and
	 * when it last changed (together, whether a stored copy is current), and the
	 * source's name for its type ('' when the source has no types).
	 *
	 * @return array{id: string, revision: string, updated_at: string, type: string}
	 */
	abstract public function describe( array $item ): array;

	/** A fetch result that carries only an error. */
	protected function failed( string $error ): array {
		return [
			'items'        => [],
			'error'        => $error,
			'total'        => 0,
			'complete'     => false,
			'not_modified' => false,
			'meta'         => [],
		];
	}
}
