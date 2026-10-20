<?php
/** Standalone regression: a scan only uses a reference index whose every stage finished, and never one from an older build format. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
$GLOBALS['o'] = array();
$GLOBALS['t'] = array();
function __( $s ) { return $s; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['o'] ) ? $GLOBALS['o'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['o'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['o'][ $n ] ); return true; }
function get_transient( $n ) { return $GLOBALS['t'][ $n ] ?? false; }
function set_transient( $n, $v, $e = 0 ) { $GLOBALS['t'][ $n ] = $v; return true; }
function delete_transient( $n ) { unset( $GLOBALS['t'][ $n ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
class LucidLab_MPC_Logger {
	public static array $lines = array();
	public static function diag( string $area, string $msg, array $c = array(), string $level = 'info', int $every = 0 ): void { self::$lines[] = "$level [$area] $msg"; }
}
class LucidLab_MPC_Settings { public static function get( $k ) { return 100; } }
class LucidLab_MPC_Work {
	public static array $files = array();
	public static function exists( $f ) { return isset( self::$files[ $f ] ); }
	public static function copy( $a, $b ) { if ( ! isset( self::$files[ $a ] ) ) { return false; } self::$files[ $b ] = self::$files[ $a ]; return true; }
	public static function delete( ...$f ) { foreach ( $f as $x ) { unset( self::$files[ $x ] ); } }
	public static function path( $f ) { self::$files[ $f ] = self::$files[ $f ] ?? array(); return sys_get_temp_dir() . '/mpc-ref-test-' . md5( $f ); }
	public static function append( $f, array $rows ) { foreach ( $rows as $r ) { self::$files[ $f ][] = $r; } return true; }
	public static function count_lines( $f ) { return count( self::$files[ $f ] ?? array() ); }
	public static function remember( $f, callable $fn ) { return $fn(); }
	public static function each( $f, callable $fn ) { foreach ( self::$files[ $f ] ?? array() as $r ) { $fn( $r ); } }
}
// Each stage references one file, so the finished index shows which stages it really contains.
class LucidLab_MPC_Orphans {
	const HEAVY_OPTION = 'lucidlab_mpc_ref_heavy_settings';
	public ?float $index_deadline = null;
	public bool $batch_open = false;
	public int $last_fetch_limit = 0;
	public static array $reads = array();
	private function one( string $stage, array &$bn ): int { self::$reads[] = $stage; $bn[ $stage . '.jpg' ] = 1; return 0; }
	public function collect_static_part( string &$part, int &$c, array &$ids, array &$bn ): int { $part = 'done'; return $this->one( 'static', $bn ); }
	public function terms_step( array &$ids, array &$bn, int &$c, ?array &$t = null ): int { return $this->one( 'terms', $bn ); }
	public function posts_step( array &$ids, array &$bn, int &$c, ?array &$t = null ): int { return $this->one( 'posts', $bn ); }
	public function meta_step( array &$ids, array &$bn, int &$c, ?array &$t = null ): int { return $this->one( 'meta', $bn ); }
	public function options_step( array &$bn, int &$c, ?array &$t = null ): int { return $this->one( 'options', $bn ); }
}
class Fake_DB {
	public string $prefix = 'wp_', $term_taxonomy = 'tt', $posts = 'p', $postmeta = 'pm', $options = 'o';
	public function prepare( $s, ...$a ) { return $s; }
	public function get_var( $q ) { return str_contains( $q, 'GET_LOCK' ) || str_contains( $q, 'RELEASE_LOCK' ) ? 1 : 10; }
}
$GLOBALS['wpdb'] = new Fake_DB();
require __DIR__ . '/../includes/class-lucidlab-mpc-reference-index.php';
function mpc_assert( bool $ok, string $what ): void { if ( ! $ok ) { throw new RuntimeException( $what ); } }
$ri = 'LucidLab_MPC_Reference_Index';

// 1) nummersschilderen.nl: an older build was left half-way (its options stage kept timing out). The Orphan
// scan resumed it, and the result went straight to "checking images". A build from an older format is not resumed.
update_option( $ri::BUILD, array( 'schema' => $ri::SCHEMA - 1, 'file' => $ri::BUILD_FILE, 'phase' => 'options', 'cursor' => 9000, 'generation' => '0' ) );
LucidLab_MPC_Work::$files[ $ri::BUILD_FILE ] = array( array( 'i' => array(), 'b' => array( 'old.jpg' ) ) );
$scan = $ri::begin( 'orphan-ref.jsonl' );
mpc_assert( 'join' === $scan['phase'], 'Scan did not join a build.' );
mpc_assert( 'static' === get_option( $ri::BUILD )['phase'], 'A build from an older format was resumed instead of restarted.' );
$guard = 0;
while ( 'done' !== $scan['phase'] && $guard++ < 50 ) {
	mpc_assert( ! LucidLab_MPC_Work::exists( 'orphan-ref.jsonl' ), 'The scan received a reference index before the build finished.' );
	$ri::pump( $scan, 0.5 );
}
mpc_assert( 'done' === $scan['phase'], 'Build never finished.' );
mpc_assert( array( 'static', 'terms', 'posts', 'meta', 'options' ) === LucidLab_MPC_Orphans::$reads, 'Stages read: ' . implode( ',', LucidLab_MPC_Orphans::$reads ) );
$bn = $ri::load( 'orphan-ref.jsonl' )['bn'];
foreach ( $ri::PHASES as $stage ) {
	mpc_assert( isset( $bn[ $stage . '.jpg' ] ), "Index is missing the $stage stage." );
}
mpc_assert( ! isset( $bn['old.jpg'] ), 'Rows from the older build leaked into the new index.' );

// 2) A build marked done but without every stage on record is never handed to a scan.
LucidLab_MPC_Orphans::$reads = array();
$GLOBALS['t'] = array();
update_option( $ri::BUILD, array( 'schema' => $ri::SCHEMA, 'file' => $ri::BUILD_FILE, 'phase' => 'done', 'cursor' => 0, 'generation' => '0', 'completed' => array( 'static', 'terms', 'posts' ) ) );
LucidLab_MPC_Work::delete( 'orphan-ref.jsonl', $ri::SHARED );
mpc_assert( ! $ri::complete( get_option( $ri::BUILD ) ), 'An incomplete build counts as complete.' );
$scan = $ri::begin( 'orphan-ref.jsonl' );
mpc_assert( 'join' === $scan['phase'] && ! LucidLab_MPC_Work::exists( 'orphan-ref.jsonl' ), 'An incomplete build was handed to the scan.' );
while ( 'done' !== $scan['phase'] && $guard++ < 100 ) {
	$ri::pump( $scan, 0.5 );
}
mpc_assert( in_array( 'meta', LucidLab_MPC_Orphans::$reads, true ) && in_array( 'options', LucidLab_MPC_Orphans::$reads, true ), 'Missing stages were not read.' );
mpc_assert( $ri::complete( get_option( $ri::BUILD ) ), 'Rebuilt index is not complete.' );

echo "Scans wait for a reference index with every stage finished and never resume an older build format.\n";
