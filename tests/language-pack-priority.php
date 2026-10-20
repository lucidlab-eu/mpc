<?php
/** Bundled Spanish is a fallback; a WordPress.org pack wins when present. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'LUCIDLAB_MPC_DIR', dirname( __DIR__ ) . '/' );
$root = sys_get_temp_dir() . '/lucidlab-mpc-i18n-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/plugins', 0700, true );
define( 'WP_LANG_DIR', $root );
file_put_contents( $root . '/plugins/lucidlab-mpc-cleanup-es_ES.mo', 'test' );
file_put_contents( $root . '/plugins/lucidlab-mpc-cleanup-de_DE.mo', 'test' );
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function unload_textdomain( $domain ) { return true; }
function load_textdomain( $domain, $path, $locale = null ) { $GLOBALS['loaded_path'] = $path; return true; }
function is_textdomain_loaded( $domain ) { return false; }
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-i18n.php';
try {
	if ( 'es_ES' !== LucidLab_MPC_I18n::pack_locale( 'es_ES' ) || 'de_DE' !== LucidLab_MPC_I18n::pack_locale( 'de_DE' ) ) {
		throw new RuntimeException( 'Local or WordPress.org locale was not discovered.' );
	}
	$method = new ReflectionMethod( LucidLab_MPC_I18n::class, 'load_domain' );
	$method->invoke( null, 'lucidlab-mpc-cleanup', 'es_ES', 'es_ES', LUCIDLAB_MPC_DIR . 'languages/' );
	if ( $root . '/plugins/lucidlab-mpc-cleanup-es_ES.mo' !== $GLOBALS['loaded_path'] ) {
		throw new RuntimeException( 'Bundled Spanish displaced the WordPress.org pack.' );
	}
	$method->invoke( null, 'lucidlab-mpc-cleanup', 'de_DE', 'de_DE', LUCIDLAB_MPC_DIR . 'languages/' );
	if ( $root . '/plugins/lucidlab-mpc-cleanup-de_DE.mo' !== $GLOBALS['loaded_path'] ) {
		throw new RuntimeException( 'German WordPress.org pack did not load.' );
	}
	unlink( $root . '/plugins/lucidlab-mpc-cleanup-es_ES.mo' );
	$method->invoke( null, 'lucidlab-mpc-cleanup', 'es_ES', 'es_ES', LUCIDLAB_MPC_DIR . 'languages/' );
	if ( LUCIDLAB_MPC_DIR . 'languages/lucidlab-mpc-cleanup-es_ES.mo' !== $GLOBALS['loaded_path'] ) {
		throw new RuntimeException( 'Bundled Spanish fallback did not load.' );
	}
	echo "Language pack priority passed\n";
} finally {
	foreach ( glob( $root . '/plugins/*.mo' ) ?: array() as $file ) { unlink( $file ); }
	rmdir( $root . '/plugins' );
	rmdir( $root );
}
