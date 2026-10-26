<?php
/**
 * A content type cached whole in one option rather than as posts.
 *
 * For small lists that nothing queries by taxonomy or date: the community
 * directory, Site Features, summits. A sync fetches the whole list, maps each
 * item, checks the result looks sound, and replaces the option. A failed or
 * suspect fetch replaces nothing: the last good copy stays, and the type's status
 * option says what went wrong.
 *
 * A subclass sets OPTION and implements map_item(); it can override fetch(),
 * validate(), store() and stored() when its source or option shape differs.
 */

defined( 'ABSPATH' ) || exit;

abstract class AC_Option_Content extends AC_Content_Type {

	/** The option holding the list. Each subclass sets its own. */
	public const OPTION = '';

	/**
	 * Config keys an option type adds to AC_Content_Type::base_defaults(), used by
	 * the default Contentful fetch:
	 *
	 * - include   (int)    Contentful link depth to resolve.
	 * - page_size (int)    Items per page.
	 * - order     (string) Order for the full fetch.
	 */
	public static function base_defaults(): array {
		return array_merge(
			parent::base_defaults(),
			[
				'include'   => 0,
				'page_size' => 100,
				'order'     => 'sys.id',
			]
		);
	}

	public function is_valid(): bool {
		return parent::is_valid() && static::OPTION !== '';
	}

	/**
	 * One fetched item in the stored shape, or null to leave it out. Add to
	 * $warnings to say why an item was left out.
	 *
	 * @param string[] $warnings
	 */
	abstract protected function map_item( array $raw, array &$warnings ): ?array;

	/** The key an item is stored under, or '' for a plain list. */
	protected function item_key( array $item ): string {
		return '';
	}

	/** The query the source's fetch_all() gets for this type. */
	public function query(): array {
		return [
			'content_type' => $this->key,
			'include'      => (int) $this->config['include'],
			'page_size'    => (int) $this->config['page_size'],
			'order'        => (string) $this->config['order'],
		];
	}

	/**
	 * Every published item.
	 *
	 * @return array{items: array<int,array>, error: string, total: int, complete: bool, not_modified: bool, meta: array}
	 */
	protected function fetch( bool $dry_run ): array {
		return $this->source()->fetch_all( $this->query() );
	}

	/**
	 * Why a freshly mapped list must not replace the stored one, or '' when it may.
	 *
	 * @param array $items  The mapped list.
	 * @param array $before The stored list.
	 * @param array $fetch  The fetch result.
	 */
	protected function validate( array $items, array $before, array $fetch ): string {
		if ( $fetch['error'] !== '' ) {
			return $fetch['error'];
		}

		return $fetch['complete'] ? '' : 'incomplete fetch';
	}

	/**
	 * Replace the stored list.
	 *
	 * @param array $items The mapped list.
	 * @param array $fetch The fetch result.
	 */
	protected function store( array $items, array $fetch ): void {
		update_option( static::OPTION, $items, false );
		update_option( static::OPTION . '_synced_at', current_time( 'mysql' ), false );
	}

	/** The source confirmed nothing changed: note that the stored copy was checked. */
	protected function touch(): void {
		update_option( static::OPTION . '_synced_at', current_time( 'mysql' ), false );
	}

	/** The stored list, as it was last replaced. */
	public static function stored(): array {
		return array_filter( (array) get_option( static::OPTION, [] ), 'is_array' );
	}

	/** When the stored list was last replaced or confirmed, site time, or ''. */
	public static function synced_at(): string {
		return (string) get_option( static::OPTION . '_synced_at', '' );
	}

	/**
	 * The outcome of the last sync that wasn't a dry run.
	 *
	 * @return array{at?:string,ok?:bool,error?:string,warnings?:string[]}
	 */
	public static function last_status(): array {
		return (array) get_option( static::OPTION . '_status', [] );
	}

	/**
	 * Fetch, map, check and store the list.
	 *
	 * $force has no effect: an option type always replaces its whole list.
	 *
	 * @return array{ok:bool,stored:bool,unchanged:bool,count:int,items:array,warnings:string[],error:string}
	 */
	public function sync( bool $force = false, bool $dry_run = false ): array {
		$result = [ 'ok' => false, 'stored' => false, 'unchanged' => false, 'count' => 0, 'items' => [], 'warnings' => [], 'error' => '' ];
		$before = static::stored();
		$fetch  = $this->fetch( $dry_run );

		if ( $fetch['not_modified'] && $before ) {
			if ( ! $dry_run ) {
				$this->touch();
			}

			$result = array_merge( $result, [ 'ok' => true, 'unchanged' => true, 'count' => count( $before ), 'items' => $before ] );
		} else {
			$items = [];
			foreach ( $fetch['items'] as $raw ) {
				$item = is_array( $raw ) ? $this->map_item( $raw, $result['warnings'] ) : null;
				if ( $item === null ) {
					continue;
				}

				$key = $this->item_key( $item );
				if ( $key !== '' ) {
					$items[ $key ] = $item;
				} else {
					$items[] = $item;
				}
			}

			$result['error'] = $this->validate( $items, $before, $fetch );

			if ( $result['error'] === '' ) {
				$result = array_merge( $result, [ 'ok' => true, 'count' => count( $items ), 'items' => $items ] );

				if ( ! $dry_run ) {
					$this->store( $items, $fetch );
					$result['stored'] = true;
				}
			}
		}

		if ( ! $dry_run ) {
			$this->record( $result );
		}

		return $result;
	}

	/** Keep the outcome for the sync report and admin notices. */
	protected function record( array $result ): void {
		update_option(
			static::OPTION . '_status',
			[
				'at'       => current_time( 'mysql' ),
				'ok'       => $result['ok'],
				'error'    => $result['error'],
				'warnings' => array_slice( $result['warnings'], 0, 25 ),
			],
			false
		);
	}

	public function summary(): string {
		$status  = static::last_status();
		$summary = sprintf( '%d stored, last synced %s', count( static::stored() ), static::synced_at() ?: 'never' );

		if ( isset( $status['ok'] ) && ! $status['ok'] ) {
			$summary .= sprintf( ' — the sync on %s failed: %s', $status['at'] ?? '?', $status['error'] ?? '' );
		}

		return $summary;
	}
}
