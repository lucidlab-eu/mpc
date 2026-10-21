<?php
/** Standalone regression: the install ping is opt-in only (also with licensed Pro), and stops at once on opt-out. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'LUCIDLAB_MPC_VERSION', '6.0.390' );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['mpc_options'] = array();
$GLOBALS['mpc_cron']    = array();
$GLOBALS['mpc_posts']   = array();
$GLOBALS['mpc_actions'] = array();
$GLOBALS['mpc_filters'] = array();
$GLOBALS['mpc_ajax']    = false;
$GLOBALS['mpc_http']    = 200;

function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['mpc_actions'][ $hook ][] = $cb; return true; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['mpc_filters'][ $hook ][] = $cb; return true; }
function apply_filters( $hook, $value ) { foreach ( $GLOBALS['mpc_filters'][ $hook ] ?? array() as $cb ) { $value = $cb( $value ); } return $value; }
function do_action( $hook, ...$args ) { foreach ( $GLOBALS['mpc_actions'][ $hook ] ?? array() as $cb ) { $cb( ...$args ); } }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mpc_options'] ) ? $GLOBALS['mpc_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) {
	$exists = array_key_exists( $name, $GLOBALS['mpc_options'] );
	$old    = $exists ? $GLOBALS['mpc_options'][ $name ] : false;
	$GLOBALS['mpc_options'][ $name ] = $value;
	if ( $exists ) { do_action( 'update_option_' . $name, $old, $value, $name ); } else { do_action( 'add_option_' . $name, $name, $value ); }
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['mpc_options'][ $name ] ); do_action( 'deleted_option', $name ); return true; }
function wp_next_scheduled( $hook ) { return $GLOBALS['mpc_cron'][ $hook ]['ts'] ?? false; }
function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['mpc_cron'][ $hook ] = array( 'ts' => $ts, 'recurrence' => $recurrence ); return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['mpc_cron'][ $hook ] ); return 1; }
function wp_doing_ajax() { return $GLOBALS['mpc_ajax']; }
function current_user_can( $cap ) { return true; }
$GLOBALS['mpc_umeta'] = array();
function get_current_user_id() { return 1; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['mpc_umeta'][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['mpc_umeta'][ $key ] = $value; return true; }
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) { if ( array_key_exists( $name, $GLOBALS['mpc_options'] ) ) { return false; } $GLOBALS['mpc_options'][ $name ] = $value; return true; }
function home_url() { return 'https://example.test'; }
function wp_generate_uuid4() { return '0123abcd-4567-4890-abcd-ef0123456789'; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_post( $url, $args ) {
	$GLOBALS['mpc_posts'][] = array( 'url' => $url, 'args' => $args );
	return 0 === $GLOBALS['mpc_http'] ? new WP_Error( 'http', 'Connection refused' ) : array( 'response' => array( 'code' => $GLOBALS['mpc_http'] ) );
}
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
	public function get_error_message() { return $this->message; }
}
class LucidLab_MPC_Logger {
	public static array $lines = array();
	public static function diag( string $area, string $msg, array $context = array(), string $level = 'info', int $every = 0 ): void { self::$lines[] = $level . ' [' . $area . '] ' . $msg; }
}
require __DIR__ . '/../includes/class-lucidlab-mpc-settings.php';
require __DIR__ . '/../includes/class-lucidlab-mpc-site-ping.php';
function mpc_assert( bool $ok, string $what ): void { if ( ! $ok ) { throw new RuntimeException( $what ); } }
function mpc_scheduled(): bool { return false !== wp_next_scheduled( LucidLab_MPC_Site_Ping::HOOK ); }

LucidLab_MPC_Site_Ping::register();

// 1) Fresh Free install: off by default. Admin pages, the weekly hook and a direct send() do nothing.
mpc_assert( 0 === LucidLab_MPC_Settings::defaults()['install_ping'], 'The opt-in must default to off.' );
do_action( 'admin_init' );
do_action( LucidLab_MPC_Site_Ping::HOOK );
mpc_assert( false === LucidLab_MPC_Site_Ping::send(), 'send() went out without opt-in.' );
mpc_assert( 0 === count( $GLOBALS['mpc_posts'] ) && ! mpc_scheduled(), 'Pinged or scheduled without opt-in.' );
mpc_assert( false === get_option( LucidLab_MPC_Site_Ping::INSTALL_ID ), 'An install ID was created without opt-in.' );

// 2) Saving other settings does not ping.
LucidLab_MPC_Settings::update( array( 'jpeg_quality' => 80 ) );
mpc_assert( 0 === count( $GLOBALS['mpc_posts'] ), 'Saving unrelated settings pinged.' );

// 3) Opt in: one ping right away, weekly event, and exactly the documented fields.
LucidLab_MPC_Settings::update( array( 'install_ping' => 1 ) );
mpc_assert( 1 === count( $GLOBALS['mpc_posts'] ), 'Opting in did not ping once.' );
mpc_assert( mpc_scheduled() && 'weekly' === $GLOBALS['mpc_cron'][ LucidLab_MPC_Site_Ping::HOOK ]['recurrence'], 'Weekly event missing after opt-in.' );
$post = $GLOBALS['mpc_posts'][0];
mpc_assert( 'https://lucidlab.eu/wp-json/lucidlab/v1/free/ping' === $post['url'], 'Wrong endpoint.' );
$body = json_decode( $post['args']['body'], true );
mpc_assert( array( 'install_id', 'site_url', 'version', 'plugin' ) === array_keys( $body ), 'Payload fields: ' . implode( ',', array_keys( $body ) ) );
mpc_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $body['install_id'] ), 'install_id is not 32 hex characters (the server requires that).' );
mpc_assert( 'https://example.test' === $body['site_url'] && '6.0.390' === $body['version'] && 'lucidlab-mpc-cleanup' === $body['plugin'], 'site_url, version or plugin wrong.' );
mpc_assert( 0 === $post['args']['redirection'] && $post['args']['timeout'] <= 5, 'Request may follow redirects or block too long.' );

// 4) Admin pages with the same version do not ping again; AJAX never pings.
do_action( 'admin_init' );
$GLOBALS['mpc_ajax'] = true;
do_action( 'admin_init' );
$GLOBALS['mpc_ajax'] = false;
mpc_assert( 1 === count( $GLOBALS['mpc_posts'] ), 'Pinged again without a version change.' );

// 5) Weekly event pings; a version change pings on the next admin page.
do_action( LucidLab_MPC_Site_Ping::HOOK );
mpc_assert( 2 === count( $GLOBALS['mpc_posts'] ), 'Weekly event did not ping.' );
add_filter( 'lucidlab_mpc_site_ping_signature', static fn( $s ) => $s . '|update' );
do_action( 'admin_init' );
mpc_assert( 3 === count( $GLOBALS['mpc_posts'] ), 'Version change did not ping.' );
$GLOBALS['mpc_filters']['lucidlab_mpc_site_ping_signature'] = array();

// 6) Opt out: weekly event removed at once, nothing more is sent, a stale cron event is a no-op.
LucidLab_MPC_Settings::update( array( 'install_ping' => 0 ) );
mpc_assert( ! mpc_scheduled(), 'Opting out did not unschedule at once.' );
mpc_assert( false === get_option( LucidLab_MPC_Site_Ping::LAST ), 'Last ping kept after opt-out.' );
do_action( LucidLab_MPC_Site_Ping::HOOK );
do_action( 'admin_init' );
mpc_assert( 3 === count( $GLOBALS['mpc_posts'] ) && ! mpc_scheduled(), 'Pinged or rescheduled after opt-out.' );

// 7) A failed ping is retried later, not on every admin page.
LucidLab_MPC_Settings::update( array( 'install_ping' => 1 ) );
$GLOBALS['mpc_http'] = 0;
do_action( LucidLab_MPC_Site_Ping::HOOK );
$count = count( $GLOBALS['mpc_posts'] );
do_action( 'admin_init' );
mpc_assert( count( $GLOBALS['mpc_posts'] ) === $count, 'A failed ping was retried on the next page load.' );
$last         = get_option( LucidLab_MPC_Site_Ping::LAST );
$last['time'] = time() - 7200;
$GLOBALS['mpc_options'][ LucidLab_MPC_Site_Ping::LAST ] = $last;
$GLOBALS['mpc_http'] = 200;
do_action( 'admin_init' );
mpc_assert( count( $GLOBALS['mpc_posts'] ) === $count + 1, 'A failed ping was not retried after an hour.' );

// 8) Reset plugin data writes the defaults: that is an opt-out.
update_option( LucidLab_MPC_Settings::OPTION, LucidLab_MPC_Settings::defaults() );
mpc_assert( ! mpc_scheduled(), 'Reset did not unschedule.' );
update_option( LucidLab_MPC_Settings::OPTION, array_merge( LucidLab_MPC_Settings::defaults(), array( 'install_ping' => 1 ) ) );
delete_option( LucidLab_MPC_Settings::OPTION );
mpc_assert( ! mpc_scheduled(), 'Deleting the settings did not unschedule.' );

// 9) Licensed Pro without the Free opt-in: no ping, no weekly event, and the notice shows after 7 days.
//    The filter is the one Pro 6.0.281 adds (LucidLab_MPC_Pro::site_ping_allowed): $allowed || licensed.
$GLOBALS['mpc_licensed'] = true;
add_filter( 'lucidlab_mpc_site_ping_allowed', static fn( $a ) => (bool) $a || $GLOBALS['mpc_licensed'] );
add_filter( 'lucidlab_mpc_site_ping_signature', static fn( $s ) => $s . '|pro:6.0.281' );
$GLOBALS['mpc_umeta'] = array();
$count = count( $GLOBALS['mpc_posts'] );
mpc_assert( ! LucidLab_MPC_Site_Ping::allowed(), 'Licensed Pro allowed the ping without the opt-in.' );
do_action( 'admin_init' );
do_action( 'lucidlab_mpc_pro_license_sync' );
do_action( LucidLab_MPC_Site_Ping::HOOK );
mpc_assert( false === LucidLab_MPC_Site_Ping::send(), 'send() went out for licensed Pro without the opt-in.' );
mpc_assert( count( $GLOBALS['mpc_posts'] ) === $count && ! mpc_scheduled(), 'Licensed Pro pinged or scheduled without the opt-in.' );
$GLOBALS['mpc_options'][ LucidLab_MPC_Site_Ping::INSTALLED_AT ] = time() - DAY_IN_SECONDS;
mpc_assert( ! LucidLab_MPC_Site_Ping::show_notice(), 'Licensed Pro: notice shown before 7 days.' );
$GLOBALS['mpc_options'][ LucidLab_MPC_Site_Ping::INSTALLED_AT ] = time() - 8 * DAY_IN_SECONDS;
mpc_assert( LucidLab_MPC_Site_Ping::show_notice(), 'Licensed Pro: notice not shown after 7 days.' );
// Licensed Pro + "Yes, count this site": pings once and schedules; the notice is gone.
$result = LucidLab_MPC_Site_Ping::set( true );
mpc_assert( $result['enabled'] && count( $GLOBALS['mpc_posts'] ) === $count + 1 && mpc_scheduled(), 'Licensed Pro: opting in did not ping and schedule.' );
mpc_assert( ! LucidLab_MPC_Site_Ping::show_notice(), 'Licensed Pro: notice still shown after opting in.' );
// Licensed Pro + opt-out: stops at once, the license does not keep it on.
LucidLab_MPC_Site_Ping::set( false );
do_action( 'lucidlab_mpc_pro_license_sync' );
do_action( LucidLab_MPC_Site_Ping::HOOK );
mpc_assert( count( $GLOBALS['mpc_posts'] ) === $count + 1 && ! mpc_scheduled(), 'Licensed Pro kept pinging after the opt-out.' );
// Dismissed with licensed Pro: stays hidden.
$GLOBALS['mpc_umeta'] = array();
LucidLab_MPC_Site_Ping::dismiss_notice();
mpc_assert( ! LucidLab_MPC_Site_Ping::show_notice(), 'Licensed Pro: dismissed notice shown again.' );
// The filter can still stop an opted-in site.
add_filter( 'lucidlab_mpc_site_ping_allowed', static fn( $a ) => false );
LucidLab_MPC_Settings::update( array( 'install_ping' => 1 ) );
mpc_assert( count( $GLOBALS['mpc_posts'] ) === $count + 1 && ! mpc_scheduled(), 'The filter could not stop an opted-in site.' );
LucidLab_MPC_Settings::update( array( 'install_ping' => 0 ) );
$GLOBALS['mpc_filters']['lucidlab_mpc_site_ping_allowed']   = array();
$GLOBALS['mpc_filters']['lucidlab_mpc_site_ping_signature'] = array();
$GLOBALS['mpc_umeta'] = array();

// 10) Opt-in notice: hidden for 7 days after install, shown afterwards, gone once answered.
$GLOBALS['mpc_licensed'] = false;
$GLOBALS['mpc_filters']['lucidlab_mpc_site_ping_allowed'] = array();
LucidLab_MPC_Settings::update( array( 'install_ping' => 0 ) );
unset( $GLOBALS['mpc_options'][ LucidLab_MPC_Site_Ping::INSTALLED_AT ] );
LucidLab_MPC_Site_Ping::mark_installed();
mpc_assert( ! LucidLab_MPC_Site_Ping::show_notice(), 'Notice shown before 7 days.' );
$GLOBALS['mpc_options'][ LucidLab_MPC_Site_Ping::INSTALLED_AT ] = time() - 8 * DAY_IN_SECONDS;
LucidLab_MPC_Site_Ping::mark_installed();
mpc_assert( LucidLab_MPC_Site_Ping::show_notice(), 'Notice not shown after 7 days (or mark_installed() reset the time).' );
LucidLab_MPC_Site_Ping::dismiss_notice();
mpc_assert( ! LucidLab_MPC_Site_Ping::show_notice() && 0 === count( array_filter( (array) get_option( LucidLab_MPC_Settings::OPTION ), static fn( $v, $k ) => 'install_ping' === $k && $v, ARRAY_FILTER_USE_BOTH ) ), '"No thanks" did not hide the notice or turned the ping on.' );
$GLOBALS['mpc_umeta'] = array();
$count  = count( $GLOBALS['mpc_posts'] );
$result = LucidLab_MPC_Site_Ping::set( true );
mpc_assert( $result['enabled'] && $result['sent'] && count( $GLOBALS['mpc_posts'] ) === $count + 1 && mpc_scheduled(), '"Yes, count this site" did not ping at once and schedule.' );
mpc_assert( ! LucidLab_MPC_Site_Ping::show_notice(), 'Notice still shown after opting in.' );
$result = LucidLab_MPC_Site_Ping::set( false );
mpc_assert( ! $result['enabled'] && ! mpc_scheduled(), 'set( false ) did not stop.' );

// 11) Deactivation stops it.
$GLOBALS['mpc_licensed'] = true;
do_action( 'admin_init' );
LucidLab_MPC_Site_Ping::stop();
mpc_assert( ! mpc_scheduled(), 'stop() left the weekly event.' );

echo "Install ping is off by default, sends only install_id, site_url, version and plugin after opt-in (notice after 7 days or Options) (now, on version change, weekly), stops at once on opt-out or reset, and is never sent without the opt-in, also with licensed Pro (notice shown after 7 days).\n";
