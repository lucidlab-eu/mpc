<?php
/**
 * Lucid Lab Media & Products Cleanup: logger class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight logger (opt-in). Stored in an option, capped.
 */
class LucidLab_MPC_Logger {

	const OPTION = 'lucidlab_mpc_log';
	const MAX    = 500;

	/**
	 * Enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return ! empty( LucidLab_MPC_Settings::get( 'logging' ) );
	}

	/**
	 * Log.
	 *
	 * @param string $msg   Msg.
	 * @param string $level Level.
	 * @param bool   $force Write even when logging is turned off (diagnostics and fatal errors).
	 */
	public static function log( string $msg, string $level = 'info', bool $force = false ): void {
		if ( ! $force && ! self::enabled() ) {
			return;
		}
		$l   = get_option( self::OPTION );
		$l   = is_array( $l ) ? $l : array();
		$l[] = array(
			't'   => gmdate( 'Y-m-d H:i:s' ),
			'lvl' => $level,
			'm'   => $msg,
		);
		if ( count( $l ) > self::MAX ) {
			$l = array_slice( $l, - self::MAX );
		}
		update_option( self::OPTION, $l, false );
	}

	/**
	 * Info.
	 *
	 * @param string $msg     Msg.
	 * @param array  $context Context.
	 */
	public static function info( string $msg, array $context = array() ): void {
		self::log( self::compose_message( $msg, $context ), 'info' );
	}

	/**
	 * Warning.
	 *
	 * @param string $msg     Msg.
	 * @param array  $context Context.
	 */
	public static function warning( string $msg, array $context = array() ): void {
		self::log( self::compose_message( $msg, $context ), 'warning' );
	}

	/**
	 * Error.
	 *
	 * @param string $msg     Msg.
	 * @param array  $context Context.
	 */
	public static function error( string $msg, array $context = array() ): void {
		self::log( self::compose_message( $msg, $context ), 'error' );
	}

	/**
	 * Compose message.
	 *
	 * @param string $msg     Msg.
	 * @param array  $context Context.
	 *
	 * @return string
	 */
	private static function compose_message( string $msg, array $context ): string {
		if ( empty( $context ) ) {
			return $msg;
		}
		$json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? $msg : $msg . ' ' . $json;
	}

	/**
	 * Get.
	 *
	 * @return array
	 */
	public static function get(): array {
		$l = get_option( self::OPTION );
		if ( ! is_array( $l ) ) {
			return array();
		}
		// Stored timestamps are UTC. Convert for both the on-screen log and
		// CSV export without rewriting historical entries.
		foreach ( $l as &$line ) {
			if ( ! is_array( $line ) || empty( $line['t'] ) ) {
				continue;
			}
			$timestamp = strtotime( (string) $line['t'] . ' UTC' );
			if ( $timestamp ) {
				$line['t'] = wp_date( 'Y-m-d H:i:s', $timestamp );
			}
			if ( isset( $line['m'] ) && is_string( $line['m'] ) ) {
				// Older entries stored scanner keys in the readable scan heading.
				// Normalize only that heading; preserve the rest of the diagnostic message.
				$line['m'] = preg_replace_callback(
					'/\bScan (orphans|uploads|sizes|fs-sizes|missing|shared|dupcontent|visual|duplicates|webpstats|crawl|dbcleaner):/i',
					static fn( $matches ) => 'Scan ' . LucidLab_MPC_Helpers::scan_tab_label( strtolower( $matches[1] ) ) . ':',
					$line['m']
				);
				if ( 1 === preg_match( '/^Scan .+: done\.$/i', $line['m'] ) ) {
					$line['m'] = preg_replace( '/done\.$/i', __( 'finished.', 'lucidlab-mpc-cleanup' ), $line['m'] );
				}
			}
		}
		unset( $line );
		return $l;
	}

	/**
	 * Structured diagnostic line for job internals (index build, reference index, visual compare).
	 * Written even when logging is off, so a stalled job leaves a trail on the Logs page.
	 * $every throttles repeated lines of one area to one per that many seconds; 0 always writes.
	 *
	 * @param string $area    Area, e.g. "media-index".
	 * @param string $msg     Message.
	 * @param array  $context Context values.
	 * @param string $level   Level.
	 * @param int    $every   Minimum seconds between lines of this area.
	 */
	public static function diag( string $area, string $msg, array $context = array(), string $level = 'info', int $every = 0 ): void {
		if ( $every > 0 ) {
			$gate = 'lucidlab_mpc_diag_' . md5( $area );
			if ( get_transient( $gate ) ) {
				return;
			}
			set_transient( $gate, 1, $every );
		}
		$context['mem'] = size_format( memory_get_usage( true ) );
		self::log( '[' . $area . '] ' . self::compose_message( $msg, $context ), $level, true );
	}

	/**
	 * What the running request is doing, for the fatal-error handler.
	 *
	 * @var array{context:string,option:string,start:float}|null
	 */
	private static ?array $watch = null;

	/**
	 * Memory released at shutdown so the handler can still run after an out-of-memory fatal.
	 *
	 * @var string|null
	 */
	private static ?string $reserve = null;

	/**
	 * Record PHP fatal errors (timeout, memory, uncaught) that happen while $context runs.
	 * When $job_option names a saved job, its last_error gets the fatal text, so the job card shows why it stopped.
	 *
	 * @param string $context    What runs, e.g. "scan orphans".
	 * @param string $job_option Option that stores the job, or ''.
	 */
	public static function watch_fatal( string $context, string $job_option = '' ): void {
		static $registered = false;
		self::$watch       = array(
			'context' => $context,
			'option'  => $job_option,
			'start'   => microtime( true ),
		);
		if ( ! $registered ) {
			$registered    = true;
			self::$reserve = str_repeat( ' ', 256 * 1024 );
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}
	}

	/**
	 * The watched work finished normally.
	 */
	public static function unwatch(): void {
		self::$watch = null;
	}

	/**
	 * Fatal error details for a watched request, or null.
	 *
	 * @param array|null $error error_get_last() value.
	 *
	 * @return array|null
	 */
	public static function fatal_details( ?array $error ): ?array {
		if ( null === self::$watch || ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return null;
		}
		return array(
			'context' => self::$watch['context'],
			'message' => (string) ( $error['message'] ?? '' ),
			'file'    => str_replace( ABSPATH, '', (string) ( $error['file'] ?? '' ) ),
			'line'    => (int) ( $error['line'] ?? 0 ),
			'memory'  => size_format( memory_get_peak_usage( true ) ),
			'limit'   => (string) ini_get( 'memory_limit' ),
			'seconds' => round( microtime( true ) - self::$watch['start'], 1 ),
			'time'    => time(),
		);
	}

	/**
	 * Shutdown handler: store a fatal error in the Logs and in the watched job.
	 */
	public static function on_shutdown(): void {
		self::$reserve = null;
		$fatal         = self::fatal_details( error_get_last() );
		if ( null === $fatal ) {
			return;
		}
		$text        = sprintf( 'PHP fatal error during %1$s: %2$s in %3$s:%4$d (peak memory %5$s of %6$s, %7$s s)', $fatal['context'], $fatal['message'], $fatal['file'], $fatal['line'], $fatal['memory'], $fatal['limit'], $fatal['seconds'] );
		$option      = (string) self::$watch['option'];
		self::$watch = null;
		try {
			if ( '' !== $option ) {
				wp_cache_delete( $option, 'options' );
				$job = get_option( $option );
				if ( is_array( $job ) ) {
					$job['last_error'] = $text;
					$job['fatal']      = $fatal;
					update_option( $option, $job, false );
				}
			}
			self::log( $text, 'error', true );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Clear.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
