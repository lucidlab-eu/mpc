<?php
/**
 * Lucid Lab Media & Products Cleanup: index class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Persistent media index shared by scanners, one table row per image attachment.
 * Changed attachments are flagged dirty so incremental scans can revisit them.
 */
class LucidLab_MPC_Index {
	const OPTION     = 'lucidlab_mpc_media_index';
	const GENERATION = 'lucidlab_mpc_media_index_generation';
	const READY      = 'lucidlab_mpc_media_index_ready';
	const DIRTY      = 'lucidlab_mpc_media_index_dirty';
	const DB_VERSION = '2';

	/** Columns every reader and writer of the table relies on. */
	const COLUMNS   = array( 'id', 'title', 'mime', 'file', 'mtime', 'dirty', 'removed' );
	const DB_OPTION = 'lucidlab_mpc_media_index_db';
	const DIRTY_MAX = 5000;

	/** Background build cursor (last attachment ID written), present only while an automatic build runs. */
	const AUTOBUILD = 'lucidlab_mpc_media_index_autobuild';

	/** WP-Cron hook that builds the index in batches after activation or an upgrade. */
	const AUTOBUILD_HOOK = 'lucidlab_mpc_index_autobuild';

	/** Time the running build step started; another step waits until it is over a minute old. */
	const AUTOBUILD_LOCK  = 'lucidlab_mpc_media_index_autobuild_lock';
	const AUTOBUILD_RETRY = 'lucidlab_mpc_media_index_autobuild_retry';

	/** Seconds one build step may use when it runs inside the plugin's own admin page load. */
	const INLINE_BUDGET = 5;

	/** Plugin version whose activation/upgrade check already ran. */
	const SEEN_VERSION = 'lucidlab_mpc_index_seen_version';

	/** Last "indexed:library" mismatch that started a rebuild; the same mismatch does not start another. */
	const VERIFY_MISMATCH = 'lucidlab_mpc_media_index_mismatch';

	/**
	 * Memoised result of has_rows_or_no_images(); reset whenever the table changes in bulk.
	 *
	 * @var bool|null
	 */
	private static ?bool $rows_memo = null;

	/**
	 * Bulk.
	 *
	 * @var array{remove:array<int,true>,upsert:array<int,true>,dirty:array<int,true>}|null
	 */
	private static ?array $bulk = null;

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lucidlab_mpc_media_index';
	}

	/**
	 * Create the table and move a former option-based index into it.
	 *
	 * @return bool
	 */
	public static function install(): bool {
		global $wpdb;
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return true;
		}
		require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL,
  title text NOT NULL,
  mime varchar(100) NOT NULL DEFAULT '',
  file varchar(255) NOT NULL DEFAULT '',
  mtime int(10) unsigned NOT NULL DEFAULT 0,
  dirty tinyint(1) unsigned NOT NULL DEFAULT 0,
  removed tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY dirty (dirty)
) " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			LucidLab_MPC_Logger::diag( 'media-index', 'Index table could not be created.', array( 'db_error' => $wpdb->last_error ), 'error' );
			return false;
		}
		// A table left by an older build can lack columns. dbDelta adds them; if it could not, stop here
		// instead of stamping the version, because every REPLACE and COUNT would fail silently.
		$missing = self::missing_columns();
		if ( $missing ) {
			LucidLab_MPC_Logger::diag(
				'media-index',
				'Index table is missing columns after the schema update.',
				array(
					'missing'  => implode( ',', $missing ),
					'db_error' => $wpdb->last_error,
				),
				'error'
			);
			return false;
		}
		self::$rows_memo = null;
		// A table created just now is empty: a leftover Ready flag must not claim otherwise.
		if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT 1 FROM %i LIMIT 1) t', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			delete_option( self::READY );
		}
		$legacy = get_option( self::OPTION );
		if ( is_array( $legacy ) && isset( $legacy['items'] ) && is_array( $legacy['items'] ) ) {
			if ( ! isset( $legacy['generation'] ) || (string) get_option( self::GENERATION, 0 ) === (string) $legacy['generation'] ) {
				$rows = array();
				foreach ( $legacy['items'] as $id => $item ) {
					$rows[ (int) $id ] = is_array( $item ) ? $item : array();
				}
				self::write_rows( $rows, 0 );
				update_option(
					self::OPTION,
					array(
						'built'      => (int) ( $legacy['built'] ?? time() ),
						'generation' => get_option( self::GENERATION, 0 ),
					),
					false
				);
				update_option( self::READY, 1, false );
			} else {
				delete_option( self::OPTION );
				delete_option( self::READY );
			}
		}
		$dirty = LucidLab_MPC_Helpers::int_ids( get_option( self::DIRTY, array() ) );
		if ( $dirty ) {
			self::flag_dirty( $dirty );
		}
		delete_option( self::DIRTY );
		update_option( self::DB_OPTION, self::DB_VERSION, true );
		// A build that ran over the old table moved its cursor on although its writes failed, so resuming it
		// would index only the images added after that point (lucidlab.eu: 5 of 26). Start it again from ID 0.
		$resume = get_option( self::AUTOBUILD, false );
		if ( false !== $resume && (int) $resume > 0 ) {
			update_option( self::AUTOBUILD, 0, false );
			LucidLab_MPC_Logger::diag( 'media-index', 'Schema updated during a build; the build restarts from the first image.', array( 'was_at_id' => (int) $resume ), 'warning' );
		}
		return true;
	}

	/**
	 * Required columns that the table does not have.
	 *
	 * @return string[]
	 */
	public static function missing_columns(): array {
		global $wpdb;
		$have = array_map( 'strtolower', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', self::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		return array_values( array_diff( self::COLUMNS, $have ) );
	}

	/**
	 * Forget the schema stamp so the next install() runs dbDelta again.
	 *
	 * @param string $why Why.
	 */
	private static function schema_failed( string $why ): void {
		global $wpdb;
		LucidLab_MPC_Logger::diag( 'media-index-schema', $why, array( 'db_error' => $wpdb->last_error ), 'error', MINUTE_IN_SECONDS );
		delete_option( self::DB_OPTION );
		delete_option( self::READY );
		self::$rows_memo = null;
	}

	/**
	 * REPLACE rows in chunks.
	 *
	 * @param array<int,array> $rows  Id => payload.
	 * @param int              $dirty Dirty.
	 *
	 * @return bool False when the database rejected a write.
	 */
	private static function write_rows( array $rows, int $dirty ): bool {
		global $wpdb;
		$table = self::table();
		foreach ( array_chunk( $rows, 500, true ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $id => $item ) {
				$values[] = $wpdb->prepare( '(%d,%s,%s,%s,%d,%d,0)', (int) $id, (string) ( $item['title'] ?? '' ), (string) ( $item['mime'] ?? '' ), mb_substr( (string) ( $item['file'] ?? '' ), 0, 255 ), (int) ( $item['mtime'] ?? 0 ), $dirty );
			}
			if ( $values && false === $wpdb->query( "REPLACE INTO {$table} (id,title,mime,file,mtime,dirty,removed) VALUES " . implode( ',', $values ) ) ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- each VALUES tuple is already passed through $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				return false;
			}
		}
		return true;
	}

	/**
	 * Flag dirty.
	 *
	 * @param int[] $ids Ids.
	 */
	private static function flag_dirty( array $ids ): void {
		global $wpdb;
		$table = self::table();
		foreach ( array_chunk( LucidLab_MPC_Helpers::int_ids( $ids ), 500 ) as $chunk ) {
			$values = implode( ',', array_map( static fn( $id ) => '(' . (int) $id . ",'',1,1)", $chunk ) );
			// Unknown IDs get a removed placeholder so the change is still reported once.
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (id,title,dirty,removed) VALUES {$values} ON DUPLICATE KEY UPDATE dirty=1", $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $values holds only (int)-cast IDs and literals; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
	}

	/**
	 * Flag removed.
	 *
	 * @param int[] $ids Ids.
	 */
	private static function flag_removed( array $ids ): void {
		global $wpdb;
		$ids = LucidLab_MPC_Helpers::int_ids( $ids );
		if ( ! $ids ) {
			return;
		}
		$table = self::table();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$values = implode( ',', array_map( static fn( $id ) => '(' . (int) $id . ",'',1,1)", $chunk ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (id,title,dirty,removed) VALUES {$values} ON DUPLICATE KEY UPDATE dirty=1, removed=1", $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $values holds only (int)-cast IDs and literals; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
	}

	/**
	 * Begin bulk.
	 */
	public static function begin_bulk(): void {
		if ( null === self::$bulk ) {
			self::$bulk = array(
				'remove' => array(),
				'upsert' => array(),
				'dirty'  => array(),
			);
		}
	}

	/**
	 * End bulk.
	 */
	public static function end_bulk(): void {
		if ( null === self::$bulk ) {
			return;
		}
		$ops        = self::$bulk;
		self::$bulk = null;
		if ( ! self::install() ) {
			return;
		}
		$rows = array();
		$gone = array_map( 'intval', array_keys( $ops['remove'] ) );
		foreach ( array_map( 'intval', array_keys( $ops['upsert'] ) ) as $id ) {
			$payload = self::item_payload( $id );
			if ( $payload ) {
				$rows[ $id ] = $payload;
			} else {
				$gone[] = $id;
			}
		}
		self::write_rows( $rows, 1 );
		self::flag_removed( $gone );
		$extra = array_diff( LucidLab_MPC_Helpers::int_ids( array_keys( $ops['dirty'] ) ), array_keys( $rows ), $gone );
		if ( $extra ) {
			self::flag_dirty( $extra );
		}
	}

	/**
	 * Index summary: built, generation, count, last_id. Empty when the index is not built.
	 *
	 * @return array{built?:int,generation?:mixed,count?:int,last_id?:int}
	 */
	public static function get(): array {
		global $wpdb;
		if ( ! self::is_ready() || ! self::install() ) {
			return array();
		}
		$meta = get_option( self::OPTION, array() );
		$meta = is_array( $meta ) ? $meta : array();
		if ( isset( $meta['generation'] ) && (string) get_option( self::GENERATION, 0 ) !== (string) $meta['generation'] ) {
			return array();
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, MAX(id) AS last_id FROM %i WHERE removed=0', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		return array(
			'built'      => (int) ( $meta['built'] ?? 0 ),
			'generation' => $meta['generation'] ?? get_option( self::GENERATION, 0 ),
			'count'      => (int) ( $row->n ?? 0 ),
			'last_id'    => (int) ( $row->last_id ?? 0 ),
		);
	}

	/**
	 * Whether ready.
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		if ( 1 !== (int) get_option( self::READY, 0 ) || false !== get_option( self::AUTOBUILD, false ) ) {
			return false;
		}
		// The flag alone said Ready while the index was stale or empty. Check what scanners will actually read.
		$meta = get_option( self::OPTION, array() );
		if ( is_array( $meta ) && isset( $meta['generation'] ) && (string) get_option( self::GENERATION, 0 ) !== (string) $meta['generation'] ) {
			return false;
		}
		if ( self::has_rows_or_no_images() ) {
			return true;
		}
		// Ready flag over an empty table: the last build wrote nothing. Rebuild, and recheck the schema first.
		if ( self::missing_columns() ) {
			self::schema_failed( 'Index table schema is out of date; it will be updated before the next build.' );
		} else {
			delete_option( self::READY );
		}
		return false;
	}

	/**
	 * True when the index table has rows, or the library has no images to index.
	 *
	 * @return bool
	 */
	private static function has_rows_or_no_images(): bool {
		global $wpdb;
		if ( null !== self::$rows_memo ) {
			return self::$rows_memo;
		}
		if ( ! self::install() ) {
			self::$rows_memo = false;
			return false;
		}
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT 1 FROM %i WHERE removed=0 LIMIT 1) t', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( '' !== $wpdb->last_error ) {
			self::schema_failed( 'Reading the index table failed.' );
			self::$rows_memo = false;
			return false;
		}
		if ( $rows > 0 ) {
			self::$rows_memo = true;
			return true;
		}
		$images          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s LIMIT 1) t", $wpdb->esc_like( 'image/' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		self::$rows_memo = 0 === $images;
		return self::$rows_memo;
	}

	/**
	 * Attachment IDs whose title or filename contains $search, newest first; null when not built.
	 *
	 * @param string $search Search.
	 *
	 * @return int[]|null
	 */
	public static function search( string $search ): ?array {
		global $wpdb;
		if ( ! self::get() ) {
			return null;
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE removed=0 AND (title LIKE %s OR file LIKE %s) ORDER BY id DESC', self::table(), $like, $like ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Item payload.
	 *
	 * @param int $id Id.
	 *
	 * @return array|null
	 */
	public static function item_payload( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
			return null;
		}
		$file = get_attached_file( $id );
		return array(
			'title' => (string) $post->post_title,
			'mime'  => (string) $post->post_mime_type,
			'file'  => $file ? basename( $file ) : '',
			'mtime' => ( $file && is_file( $file ) ) ? (int) filemtime( $file ) : 0,
		);
	}

	/**
	 * Rebuild.
	 *
	 * @return array
	 */
	public static function rebuild(): array {
		global $wpdb;
		if ( ! self::install() ) {
			return array( 'count' => 0 );
		}
		$table = self::table();
		self::stop_autobuild();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		self::$rows_memo = null;
		$last            = 0;
		$count           = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_title,post_mime_type FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s AND ID>%d ORDER BY ID LIMIT 1000", $wpdb->esc_like( 'image/' ) . '%', $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( ! $rows ) {
				break;
			}
			$ids = array_map( static fn( $r ) => (int) $r['ID'], $rows );
			update_meta_cache( 'post', $ids );
			$items = array();
			foreach ( $rows as $row ) {
				$id           = (int) $row['ID'];
				$file         = get_attached_file( $id );
				$items[ $id ] = array(
					'title' => (string) $row['post_title'],
					'mime'  => (string) $row['post_mime_type'],
					'file'  => $file ? basename( $file ) : '',
					'mtime' => ( $file && is_file( $file ) ) ? (int) filemtime( $file ) : 0,
				);
				$last         = $id;
			}
			if ( ! self::write_rows( $items, 0 ) ) {
				self::schema_failed( 'Writing the index failed during a manual rebuild.' );
				return array( 'count' => 0 );
			}
			$count    += count( $items );
			$rows_seen = count( $rows );
		} while ( 1000 === $rows_seen );
		update_option(
			self::OPTION,
			array(
				'built'      => time(),
				'generation' => get_option( self::GENERATION, 0 ),
			),
			false
		);
		update_option( self::READY, 1, false );
		return array(
			'built'   => time(),
			'count'   => $count,
			'last_id' => $last,
		);
	}

	/**
	 * Upsert.
	 *
	 * @param int $id Id.
	 */
	public static function upsert( int $id ): void {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return;
		}
		if ( null !== self::$bulk ) {
			unset( self::$bulk['remove'][ $id ] );
			self::$bulk['upsert'][ $id ] = true;
			return;
		}
		if ( ! self::install() ) {
			return;
		}
		$payload = self::item_payload( $id );
		if ( ! $payload ) {
			self::flag_removed( array( $id ) );
			return;
		}
		self::write_rows( array( $id => $payload ), 1 );
	}

	/**
	 * Remove.
	 *
	 * @param int $id Id.
	 */
	public static function remove( int $id ): void {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return;
		}
		if ( null !== self::$bulk ) {
			unset( self::$bulk['upsert'][ $id ] );
			self::$bulk['remove'][ $id ] = true;
			return;
		}
		if ( self::install() ) {
			self::flag_removed( array( $id ) );
		}
	}

	/**
	 * Mark dirty.
	 *
	 * @param int $id Id.
	 */
	public static function mark_dirty( int $id ): void {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return;
		}
		if ( null !== self::$bulk ) {
			self::$bulk['dirty'][ $id ] = true;
			return;
		}
		if ( self::install() ) {
			self::flag_dirty( array( $id ) );
		}
	}

	/**
	 * Dirty ids.
	 *
	 * @return int[] Changed attachment IDs, newest changes last.
	 */
	public static function dirty_ids(): array {
		global $wpdb;
		if ( ! self::install() ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE dirty=1 ORDER BY id LIMIT %d', self::table(), self::DIRTY_MAX ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Take dirty.
	 *
	 * @return array
	 */
	public static function take_dirty(): array {
		global $wpdb;
		$dirty = self::dirty_ids();
		if ( $dirty ) {
			$table = self::table();
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE removed=1', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET dirty=0 WHERE dirty=1', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
		return $dirty;
	}

	/**
	 * Last id.
	 *
	 * @return int
	 */
	public static function last_id(): int {
		$index = self::get();
		return (int) ( $index['last_id'] ?? 0 );
	}

	/**
	 * Admin requests: keep the index usable. Starts a build when the index is not ready, re-queues a build
	 * whose cron event was lost, and runs one short step inline on the plugin's own page so the index also
	 * fills when WP-Cron never fires (DISABLE_WP_CRON without a server cron, or a blocked loopback).
	 */
	public static function maybe_schedule_build(): void {
		if ( false !== get_option( self::AUTOBUILD, false ) ) {
			self::ensure_scheduled();
			self::maybe_inline_step();
			return;
		}
		if ( self::is_ready() ) {
			// A Ready index was trusted as is, so a build that finished short of the library (lucidlab.eu:
			// 5 of 26 images, built by 6.0.383) stayed short after every later upgrade. Compare it with the
			// library after each version change and on every load of the plugin page (two COUNT queries).
			$new_version = get_option( self::SEEN_VERSION ) !== LUCIDLAB_MPC_VERSION;
			if ( $new_version ) {
				update_option( self::SEEN_VERSION, LUCIDLAB_MPC_VERSION, false );
			}
			if ( ( $new_version || self::on_plugin_page() ) && ! self::matches_library( $new_version ? 'version-change' : 'plugin-page' ) ) {
				self::start_autobuild();
				self::maybe_inline_step();
			}
			return;
		}
		if ( ! self::install() ) {
			return;
		}
		update_option( self::SEEN_VERSION, LUCIDLAB_MPC_VERSION, false );
		self::start_autobuild();
		self::maybe_inline_step();
	}

	/**
	 * Whether a Ready index holds one row per image in the library. A mismatch asks for one rebuild;
	 * when the same mismatch is still there after that rebuild it is logged and accepted, so a library
	 * the build cannot fully read does not rebuild on every page load.
	 *
	 * @param string $trigger What asked for the check (version-change or plugin-page), for the log.
	 *
	 * @return bool False when the index should be rebuilt.
	 */
	public static function matches_library( string $trigger ): bool {
		global $wpdb;
		$indexed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE removed=0', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( '' !== $wpdb->last_error ) {
			return true; // is_ready() handles an unreadable table.
		}
		$library = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s", $wpdb->esc_like( 'image/' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( $indexed === $library ) {
			delete_option( self::VERIFY_MISMATCH );
			return true;
		}
		$key     = $indexed . ':' . $library;
		$context = array(
			'indexed'        => $indexed,
			'library_images' => $library,
			'trigger'        => $trigger,
		);
		if ( get_option( self::VERIFY_MISMATCH ) === $key ) {
			LucidLab_MPC_Logger::diag( 'media-index', 'Ready index still differs from the library after a rebuild; keeping it.', $context, 'warning', HOUR_IN_SECONDS );
			return true;
		}
		update_option( self::VERIFY_MISMATCH, $key, false );
		LucidLab_MPC_Logger::diag( 'media-index', 'Ready index does not match the library; rebuilding.', $context, 'warning' );
		return false;
	}

	/**
	 * Queue the next cron step unless one is already queued.
	 */
	private static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::AUTOBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::AUTOBUILD_HOOK );
		}
	}

	/**
	 * Whether this is a normal page load of the plugin's own admin screen by an administrator.
	 *
	 * @return bool
	 */
	private static function on_plugin_page(): bool {
		if ( ! is_admin() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( wp_doing_ajax() ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		return 'lucidlab-mpc-cleanup' === $page;
	}

	/**
	 * Whether this is one of the plugin's own AJAX polls (job status, scans) by an administrator.
	 *
	 * @return bool
	 */
	private static function on_plugin_ajax(): bool {
		if ( ! wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; the handler verifies its nonce.
		return 'lucidlab_mpc_op' === $action;
	}

	/**
	 * Run one time-limited build step during the plugin's own page load (5 s) or its AJAX polls (2 s),
	 * so a large library keeps building while the plugin is open even when WP-Cron never runs.
	 */
	private static function maybe_inline_step(): void {
		if ( self::on_plugin_page() ) {
			self::autobuild_step( self::INLINE_BUDGET );
		} elseif ( self::on_plugin_ajax() ) {
			self::autobuild_step( 2 );
		}
	}

	/**
	 * Progress of a running automatic build, or null when none runs.
	 *
	 * @return array{indexed:int,total:int}|null
	 */
	public static function build_progress(): ?array {
		global $wpdb;
		if ( false === get_option( self::AUTOBUILD, false ) ) {
			return null;
		}
		return array(
			'indexed' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			'total'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s", $wpdb->esc_like( 'image/' ) . '%' ) ),
		);
	}

	/**
	 * Start a background build from attachment ID 0.
	 */
	public static function start_autobuild(): void {
		global $wpdb;
		if ( ! self::install() ) {
			return;
		}
		delete_option( self::READY );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		self::$rows_memo = null;
		update_option( self::AUTOBUILD, 0, false );
		delete_option( self::AUTOBUILD_RETRY );
		self::ensure_scheduled();
		LucidLab_MPC_Logger::diag(
			'media-index',
			'Automatic build started.',
			array(
				'version'       => LUCIDLAB_MPC_VERSION,
				'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			)
		);
	}

	/**
	 * Stop a background build (deactivation, or a manual rebuild took over).
	 */
	public static function stop_autobuild(): void {
		delete_option( self::AUTOBUILD );
		delete_option( self::AUTOBUILD_LOCK );
		wp_clear_scheduled_hook( self::AUTOBUILD_HOOK );
	}

	/**
	 * One step of the background build: batches of 500 within the time budget, then reschedule.
	 * Runs from WP-Cron (15 s) or inline on the plugin page (5 s); a lock keeps the two from overlapping.
	 *
	 * @param int|string $budget Seconds to spend. WP-Cron passes no argument.
	 */
	public static function autobuild_step( $budget = 15 ): void {
		global $wpdb;
		if ( false === get_option( self::AUTOBUILD, false ) || ! self::install() ) {
			return;
		}
		// Read the cursor after install(): a schema update resets it to 0.
		$last = get_option( self::AUTOBUILD, false );
		if ( ! add_option( self::AUTOBUILD_LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::AUTOBUILD_LOCK, 0 ) < MINUTE_IN_SECONDS ) {
				return;
			}
			LucidLab_MPC_Logger::diag( 'media-index', 'Took over a build step lock older than a minute; the previous step did not finish.', array(), 'warning' );
			update_option( self::AUTOBUILD_LOCK, time(), false );
		}
		// do_action() may hand over '' when an event has no arguments; that means the cron default.
		$budget  = (int) $budget > 0 ? min( 25, (int) $budget ) : 15;
		$last    = (int) $last;
		$from    = $last;
		$start   = microtime( true );
		$done    = false;
		$written = 0;
		while ( microtime( true ) - $start < $budget ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_title,post_mime_type FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s AND ID>%d ORDER BY ID LIMIT 500", $wpdb->esc_like( 'image/' ) . '%', $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( ! $rows ) {
				$done = true;
				break;
			}
			update_meta_cache( 'post', array_map( static fn( $r ) => (int) $r['ID'], $rows ) );
			$items = array();
			foreach ( $rows as $row ) {
				$id           = (int) $row['ID'];
				$file         = get_attached_file( $id );
				$items[ $id ] = array(
					'title' => (string) $row['post_title'],
					'mime'  => (string) $row['post_mime_type'],
					'file'  => $file ? basename( $file ) : '',
					'mtime' => ( $file && is_file( $file ) ) ? (int) filemtime( $file ) : 0,
				);
				$last         = $id;
			}
			if ( ! self::write_rows( $items, 0 ) ) {
				self::schema_failed( 'Writing the index failed; the table schema will be updated and the build restarts.' );
				delete_option( self::AUTOBUILD_LOCK );
				return;
			}
			$written += count( $items );
			if ( count( $rows ) < 500 ) {
				$done = true;
				break;
			}
		}
		self::$rows_memo = null;
		delete_option( self::AUTOBUILD_LOCK );
		LucidLab_MPC_Logger::diag(
			'media-index',
			$done ? 'Build finished.' : 'Build step.',
			array(
				'driver'  => wp_doing_cron() ? 'cron' : ( wp_doing_ajax() ? 'ajax' : 'page' ),
				'from_id' => $from,
				'to_id'   => $last,
				'written' => $written,
				'seconds' => round( microtime( true ) - $start, 2 ),
			)
		);
		if ( ! $done ) {
			update_option( self::AUTOBUILD, $last, false );
			self::ensure_scheduled();
			return;
		}
		// Check the result against the library before calling it Ready: a build resumed from a stale cursor
		// used to finish with only the newest images. Restart once from ID 0 when rows are missing.
		$indexed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE removed=0', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$library = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s", $wpdb->esc_like( 'image/' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		LucidLab_MPC_Logger::diag(
			'media-index',
			'Build checked against the library.',
			array(
				'indexed'        => $indexed,
				'library_images' => $library,
			),
			$indexed < $library ? 'warning' : 'info'
		);
		if ( $indexed < $library && ! get_option( self::AUTOBUILD_RETRY ) ) {
			update_option( self::AUTOBUILD_RETRY, 1, false );
			update_option( self::AUTOBUILD, 0, false );
			self::ensure_scheduled();
			return;
		}
		delete_option( self::AUTOBUILD_RETRY );
		if ( $indexed === $library ) {
			delete_option( self::VERIFY_MISMATCH );
		}
		update_option(
			self::OPTION,
			array(
				'built'      => time(),
				'generation' => get_option( self::GENERATION, 0 ),
			),
			false
		);
		update_option( self::READY, 1, false );
		delete_option( self::AUTOBUILD );
	}

	/**
	 * Invalidate.
	 */
	public static function invalidate(): void {
		global $wpdb;
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, false );
		delete_option( self::OPTION );
		delete_option( self::READY );
		delete_option( self::DIRTY );
		self::$rows_memo = null;
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is the plugin prefix plus lucidlab_mpc_media_index; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
	}
}
