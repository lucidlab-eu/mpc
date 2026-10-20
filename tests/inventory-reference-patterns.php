<?php
/** Standalone regression test: php tests/inventory-reference-patterns.php */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );
function __( string $message, string $domain = '' ): string { return '[translated regex would fail]'; }
function esc_html__( string $message, string $domain = '' ): string { return '[translated regex would fail]'; }
require __DIR__ . '/../includes/class-lucidlab-mpc-inventory.php';

$method = new ReflectionMethod( LucidLab_MPC_Inventory::class, 'has_upload_reference' );
$cases = array(
    array( '<img src="/wp-content/uploads/2026/door.jpg">', false, true ),
    array( '<a href="/wp-content/uploads/2026/guide.pdf">', true, true ),
    array( '<img src="/wp-content/uploads/2026/door.jpg">', true, false ),
    array( '<img src="/other/door.jpg">', false, false ),
);
foreach ( $cases as $case ) {
    if ( $method->invoke( null, $case[0], $case[1] ) !== $case[2] ) {
        throw new RuntimeException( 'Upload reference pattern returned the wrong result.' );
    }
}
echo "Image/PDF reference patterns remain valid with translated UI strings.\n";
