<?php
/**
 * Syncs Contentful changes in the background.
 *
 * The webhook only asks for a run. A run reads everything published, unpublished
 * or deleted since the last run from the Sync API, queues it, and works through
 * the queue: a synced entry is re-synced on its own, and anything else (a person,
 * an asset, a community) re-syncs only the synced entries that link to it. A
 * burst of publishes is handled in one pass, and a nightly run picks up anything
 * a missed or failed webhook delivery left behind.
 */

defined( 'ABSPATH' ) || exit;

class AC_Contentful_Changes {

	/** Cron hook for a run asked for by the webhook, or a run handing over to the next. */
	public const RUN = 'ac_contentful_changes';

	/** Cron hook for the nightly run. */
	public const SWEEP = 'ac_contentful_changes_sweep';

	/** Option holding the sync token, the queue and the last run's outcome. */
	public const STATE = 'ac_contentful_changes';

	private const LOCK     = 'ac_contentful_changes_lock';
	private const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/** Seconds between a webhook and its run, so the Delivery API has the change. */
	private const DELAY = 10;

	/** Seconds of work per cron run before handing the rest to the next run. */
	private const BUDGET = 40;

	/** Link hops followed from a changed item: asset, person, talk. */
	private const MAX_HOPS = 3;

	/** Fetch attempts for one queued item before it is dropped. */
	private const MAX_TRIES = 3;

	/** Items synced in this run, so each is synced once however many changes touch it. */
	private static array $done = [];

	public static function init(): void {
		add_action( self::RUN, [ self::class, 'cron' ] );
		add_action( self::SWEEP, [ self::class, 'cron' ] );
		add_action( 'init', [ self::class, 'schedule_sweep' ] );
	}

	/** Daily, at 3am site time. */
	public static function schedule_sweep(): void {
		if ( wp_next_scheduled( self::SWEEP ) && 'daily' !== wp_get_schedule( self::SWEEP ) ) {
			wp_clear_scheduled_hook( self::SWEEP );
		}

		if ( AC_Settings::is_configured() && ! wp_next_scheduled( self::SWEEP ) ) {
			$at = new DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );
			wp_schedule_event( $at->getTimestamp(), 'daily', self::SWEEP );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::RUN );
		wp_clear_scheduled_hook( self::SWEEP );
	}

	/** Ask for a run shortly. A run already waiting covers this request too. */
	public static function request(): bool {
		return self::schedule_run( time() + self::DELAY );
	}

	private static function schedule_run( int $at ): bool {
		$next = wp_next_scheduled( self::RUN );

		if ( $next && $next <= $at ) {
			return true;
		}

		if ( $next ) {
			wp_unschedule_event( $next, self::RUN );
		}

		return true === wp_schedule_single_event( $at, self::RUN );
	}

	public static function cron(): void {
		self::run( self::BUDGET );
	}

	/**
	 * Read and apply the changes since the last run.
	 *
	 * @param int $budget Seconds of work before handing over to the next run; 0 runs until done.
	 * @return array{fetched:int,processed:int,synced:int,errors:int,queued:int,messages:string[]}
	 */
	public static function run( int $budget = 0 ): array {
		$report = [ 'fetched' => 0, 'processed' => 0, 'synced' => 0, 'errors' => 0, 'queued' => 0, 'messages' => [] ];

		if ( ! AC_Settings::is_configured() ) {
			$report['messages'][] = 'Contentful is not configured';

			return $report;
		}

		if ( ! self::lock() ) {
			self::schedule_run( time() + MINUTE_IN_SECONDS );
			$report['messages'][] = 'another run is in progress; trying again in a minute';

			return $report;
		}

		self::$done = [];
		$deadline   = $budget > 0 ? time() + $budget : PHP_INT_MAX;

		try {
			$state  = self::state();
			$pulled = self::pull( $state, $deadline, $report );
			$retry  = self::work( $state, $deadline, $report );

			$state['pending']     = array_merge( $state['pending'], $retry );
			$report['queued']     = count( $state['pending'] );
			$state['last_run']    = current_time( 'mysql' );
			$state['last_report'] = array_merge( $report, [ 'messages' => array_slice( $report['messages'], -25 ) ] );
			self::save( $state );

			// Out of time with work left: carry on now. Failures wait a minute; an unreadable Sync API, five.
			$unfinished = 'more' === $pulled
				|| count( $state['pending'] ) > count( $retry )
				|| ( $state['full'] && ! isset( self::$done['full'] ) );

			if ( $unfinished ) {
				self::schedule_run( time() );
			} elseif ( $retry ) {
				self::schedule_run( time() + MINUTE_IN_SECONDS );
			} elseif ( 'error' === $pulled ) {
				self::schedule_run( time() + 5 * MINUTE_IN_SECONDS );
			}
		} finally {
			self::unlock();
		}

		return $report;
	}

	/**
	 * Queue every change since the stored token.
	 *
	 * @return string 'done' when the queue is up to date, 'more' when time ran out, 'error' when the read failed.
	 */
	private static function pull( array &$state, int $deadline, array &$report ): string {
		while ( time() < $deadline ) {
			$page = AC_Contentful::sync_page( $state['token'] );

			if ( $page['expired'] ) {
				$report['messages'][] = 'the sync token was rejected; starting over with a full sync';
				$state                = array_merge( self::blank_state(), [ 'pending' => $state['pending'] ] );
				continue;
			}

			if ( $page['error'] !== '' ) {
				$report['errors']++;
				$report['messages'][] = 'could not read changes: ' . $page['error'];

				return 'error';
			}

			// The first pass lists everything there is: nothing to queue, just a full sync once it ends.
			if ( $state['ready'] ) {
				foreach ( $page['items'] as $item ) {
					self::enqueue( $state, $item );
				}
			}

			$report['fetched'] += count( $page['items'] );
			$state['token']     = $page['token'];

			if ( $page['done'] && ! $state['ready'] ) {
				$state['ready'] = true;
				$state['full']  = true;
			}

			self::save( $state );

			if ( $page['done'] ) {
				return 'done';
			}
		}

		return 'more';
	}

	private static function enqueue( array &$state, array $item ): void {
		$kind = (string) ( $item['sys']['type'] ?? '' );
		$id   = (string) ( $item['sys']['id'] ?? '' );

		if ( $id === '' || ! in_array( $kind, [ 'Entry', 'Asset', 'DeletedEntry', 'DeletedAsset' ], true ) ) {
			return;
		}

		// The latest change to an item replaces any earlier one still queued.
		$key = ( str_ends_with( $kind, 'Asset' ) ? 'asset:' : 'entry:' ) . $id;
		unset( $state['pending'][ $key ] );

		$state['pending'][ $key ] = [
			'id'    => $id,
			'kind'  => $kind,
			'type'  => (string) ( $item['sys']['contentType']['sys']['id'] ?? '' ),
			'tries' => 0,
		];
	}

	/**
	 * Apply queued changes until the queue is empty or the deadline passes.
	 *
	 * @return array Items whose fetch failed, to try again on a later run.
	 */
	private static function work( array &$state, int $deadline, array &$report ): array {
		$retry = [];

		while ( $state['pending'] && time() < $deadline ) {
			$key  = (string) array_key_first( $state['pending'] );
			$item = $state['pending'][ $key ];
			unset( $state['pending'][ $key ] );

			if ( ! self::apply( $item, $state, $report ) && ++$item['tries'] < self::MAX_TRIES ) {
				$retry[ $key ] = $item;
			}

			$report['processed']++;
			if ( 0 === $report['processed'] % 25 ) {
				self::save( array_merge( $state, [ 'pending' => array_merge( $state['pending'], $retry ) ] ) );
			}
		}

		if ( ! $state['pending'] && $state['full'] && time() < $deadline ) {
			self::$done['full'] = true;
			$result               = AC_Sync::sync_all( true, 'contentful changes: full sync' );
			$state['full']        = $result['errors'] > 0;
			$report['synced']    += $result['created'] + $result['updated'] + $result['drafted'];
			$report['errors']    += $result['errors'];
			$report['messages'][] = sprintf( 'full sync: created %d, updated %d, drafted %d, errors %d', $result['created'], $result['updated'], $result['drafted'], $result['errors'] );
		}

		return $retry;
	}

	/** Apply one change. Returns false when it should be tried again. */
	private static function apply( array $item, array &$state, array &$report ): bool {
		if ( $item['kind'] === 'DeletedAsset' ) {
			// Nothing links to a deleted asset any more, so there is no way to find who used it.
			$state['full'] = true;

			return true;
		}

		$is_entry = $item['kind'] === 'Entry' || $item['kind'] === 'DeletedEntry';
		$type     = $is_entry ? self::synced_type( $item['type'] ) : null;

		if ( $type instanceof AC_Post_Type_Content || ( $item['kind'] === 'DeletedEntry' && AC_Sync::find_post_by_entry_id( $item['id'] ) ) ) {
			return self::sync_entry( $item['id'], $report );
		}

		if ( $type instanceof AC_Option_Content ) {
			self::sync_option( $type, $report );
		}

		if ( $item['kind'] === 'DeletedEntry' ) {
			// Links to a deleted entry can't be looked up either; only worth a full sync if synced content may use it.
			if ( $item['type'] === '' || in_array( $item['type'], AC_Types::dependency_types(), true ) ) {
				$state['full'] = true;
			}

			return true;
		}

		return self::sync_linked( $is_entry ? 'entry' : 'asset', $item['id'], $report );
	}

	/** Re-sync the synced entries that link to an item, following links through anything that isn't synced. */
	private static function sync_linked( string $link, string $id, array &$report ): bool {
		$queue = [ [ $link, $id, 1 ] ];
		$seen  = [ $id => true ];
		$ok    = true;

		while ( $queue ) {
			[ $link, $id, $hop ] = array_shift( $queue );
			$found               = AC_Contentful::linking_entries( $link, $id );

			if ( $found['error'] !== '' ) {
				$report['errors']++;
				$report['messages'][] = sprintf( 'could not find entries linking to %s: %s', $id, $found['error'] );
				$ok                   = false;
				continue;
			}

			foreach ( $found['entries'] as $entry ) {
				if ( isset( $seen[ $entry['id'] ] ) ) {
					continue;
				}
				$seen[ $entry['id'] ] = true;
				$type                 = self::synced_type( $entry['type'] );

				if ( $type instanceof AC_Post_Type_Content ) {
					$ok = self::sync_entry( $entry['id'], $report ) && $ok;
					continue;
				}

				if ( $type instanceof AC_Option_Content ) {
					self::sync_option( $type, $report );
				}

				if ( $hop < self::MAX_HOPS ) {
					$queue[] = [ 'entry', $entry['id'], $hop + 1 ];
				}
			}
		}

		return $ok;
	}

	private static function sync_entry( string $entry_id, array &$report ): bool {
		if ( isset( self::$done[ 'entry:' . $entry_id ] ) ) {
			return true;
		}
		self::$done[ 'entry:' . $entry_id ] = true;

		$result            = AC_Sync::sync_one( $entry_id, true, 'contentful changes' );
		$report['synced'] += $result['created'] + $result['updated'] + $result['drafted'];

		if ( $result['errors'] > 0 ) {
			$report['errors']++;
			$report['messages'] = array_merge( $report['messages'], $result['messages'] );
			unset( self::$done[ 'entry:' . $entry_id ] );

			return false;
		}

		return true;
	}

	private static function sync_option( AC_Option_Content $type, array &$report ): void {
		if ( isset( self::$done[ 'option:' . $type->key() ] ) ) {
			return;
		}
		self::$done[ 'option:' . $type->key() ] = true;

		$outcome = $type->sync();
		if ( $outcome['error'] !== '' ) {
			$report['errors']++;
			$report['messages'][] = strtolower( $type->label() ) . ': ' . $outcome['error'];
		} elseif ( ! $outcome['unchanged'] ) {
			$report['synced']++;
		}
	}

	/** The registered type for a Contentful content type, if this site syncs it from Contentful. */
	private static function synced_type( string $content_type ): ?AC_Content_Type {
		$type = $content_type !== '' ? AC_Types::type( $content_type ) : null;

		return $type && $type->config( 'source' ) === 'contentful' ? $type : null;
	}

	// ------------------------------------------------------------------- state

	/**
	 * The stored state, reset when the space or environment has changed.
	 *
	 * @return array{scope:string,token:string,ready:bool,full:bool,pending:array,last_run:string,last_report:array}
	 */
	public static function state(): array {
		$state = array_merge( self::blank_state(), (array) get_option( self::STATE, [] ) );

		if ( $state['scope'] !== self::scope() ) {
			$state = self::blank_state();
		}

		return $state;
	}

	private static function blank_state(): array {
		return [
			'scope'       => self::scope(),
			'token'       => '',
			'ready'       => false,
			'full'        => false,
			'pending'     => [],
			'last_run'    => '',
			'last_report' => [],
		];
	}

	private static function save( array $state ): void {
		update_option( self::STATE, $state, false );
	}

	/** A sync token is only good for the space and environment it came from. */
	private static function scope(): string {
		$settings = AC_Settings::all();

		return $settings['space_id'] . '/' . $settings['environment'];
	}

	/** One line for `wp assemble-content status`. */
	public static function summary(): string {
		$state = self::state();

		if ( ! $state['ready'] ) {
			return $state['token'] !== '' ? 'starting: reading the first full list of changes' : 'not started yet';
		}

		$last = $state['last_report'];

		return sprintf(
			'last run %s — %d synced, %d errors; %d queued%s',
			$state['last_run'] ?: 'never',
			(int) ( $last['synced'] ?? 0 ),
			(int) ( $last['errors'] ?? 0 ),
			count( $state['pending'] ),
			$state['full'] ? '; full sync due' : ''
		);
	}

	/** Drop the token and queue, so the next run starts over with a full sync. */
	public static function reset(): void {
		delete_option( self::STATE );
	}

	// -------------------------------------------------------------------- lock

	private static function lock(): bool {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( self::LOCK, time(), 'ac_changes', self::LOCK_TTL );
		}

		if ( get_transient( self::LOCK ) ) {
			return false;
		}

		return set_transient( self::LOCK, time(), self::LOCK_TTL );
	}

	private static function unlock(): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( self::LOCK, 'ac_changes' );
		} else {
			delete_transient( self::LOCK );
		}
	}
}
