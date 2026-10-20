<?php
/**
 * Standalone regression: media records that point at ONE file on disk are not copies. Byte Identical
 * counts that file once (no space is freed by merging such records), the merge preview agrees, and the
 * quarantine leaves a file in place while another record still uses it (moving it broke the Keep image
 * and every other record of the group). Visual group lookups stay small for groups of hundreds.
 */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
$base = str_replace( '\\', '/', sys_get_temp_dir() ) . '/lucidlab-mpc-shared-' . bin2hex( random_bytes( 5 ) ); // Paths are compared normalised, as the plugin does (Windows temp dirs use backslashes).
mkdir( "$base/2026/10", 0777, true );
file_put_contents( "$base/2026/10/shared.jpg", str_repeat( 'S', 1000 ) );
file_put_contents( "$base/2026/10/shared-300x300.jpg", str_repeat( 's', 100 ) );
file_put_contents( "$base/2026/10/copy.jpg", str_repeat( 'S', 1000 ) );
$hard = @link( "$base/2026/10/copy.jpg", "$base/2026/10/copy-link.jpg" );

// Records 1-4 use shared.jpg (and its size), 5 is a real copy, 6 a hard link to the copy, 7 has no file.
$GLOBALS['att']     = array(
	1 => '2026/10/shared.jpg',
	2 => '2026/10/shared.jpg',
	3 => '2026/10/shared.jpg',
	4 => '2026/10/shared.jpg',
	5 => '2026/10/copy.jpg',
	6 => $hard ? '2026/10/copy-link.jpg' : '2026/10/copy.jpg',
	7 => '2026/10/gone.jpg',
	8 => '2026/10/shared-300x300.jpg',
);
$GLOBALS['base']    = $base;
$GLOBALS['options'] = array();

function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function get_attached_file( $id ) { return isset( $GLOBALS['att'][ $id ] ) ? $GLOBALS['base'] . '/' . $GLOBALS['att'][ $id ] : false; }
function wp_get_original_image_path( $id ) { return get_attached_file( $id ); }
function wp_get_attachment_metadata( $id ) {
	return '2026/10/shared.jpg' === ( $GLOBALS['att'][ $id ] ?? '' ) ? array( 'sizes' => array( 'medium' => array( 'file' => 'shared-300x300.jpg' ) ) ) : array();
}
function get_post_type( $id ) { return isset( $GLOBALS['att'][ (int) $id ] ) ? 'attachment' : false; }
function _prime_post_caches( $ids ) {}
function get_the_title( $id ) { return 'Title ' . $id; }
function wp_get_attachment_image_url( $id ) { return 'https://example.test/' . $id . '.jpg'; }
function wp_get_attachment_url( $id ) { return 'https://example.test/' . $id . '.jpg'; }
function get_edit_post_link( $id ) { return 'https://example.test/edit/' . $id; }
function size_format( $b ) { return $b . ' B'; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['base'] ); }
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
class Fake_DB {
	public string $postmeta   = 'wp_postmeta';
	public string $posts      = 'wp_posts';
	public string $last_error = '';
	public function esc_like( $s ) { return $s; }
	public function prepare( $sql, ...$a ) { return array( $sql, is_array( $a[0] ?? null ) ? $a[0] : $a ); }
	public function get_col( $q ) {
		[ $sql, $a ] = $q;
		$out = array();
		foreach ( $GLOBALS['att'] as $id => $rel ) {
			if ( str_contains( $sql, 'LIKE' ) ? 0 === strpos( $rel, (string) $a[0] ) : in_array( $rel, $a, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
$GLOBALS['wpdb'] = new Fake_DB();
require __DIR__ . '/../includes/class-lucidlab-mpc-helpers.php';
require __DIR__ . '/../includes/trait-lucidlab-mpc-dupcontent-pro-methods.php';
require __DIR__ . '/../includes/class-lucidlab-mpc-dupcontent.php';
require __DIR__ . '/../includes/class-lucidlab-mpc-quarantine.php';
require __DIR__ . '/../includes/class-lucidlab-mpc-review.php';
require __DIR__ . '/../includes/class-lucidlab-mpc-preview.php';
function mpc_assert( bool $ok, string $what ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $what\n" );
		exit( 1 );
	}
}

// 1. File identity and freed bytes: shared path and hard link count once, a real copy counts.
$path_of = static fn( int $id ): string => (string) get_attached_file( $id );
mpc_assert( LucidLab_MPC_DupContent::file_identity( $path_of( 1 ) ) === LucidLab_MPC_DupContent::file_identity( $path_of( 2 ) ), 'Two records of one file must have one identity.' );
mpc_assert( LucidLab_MPC_DupContent::file_identity( $path_of( 1 ) ) !== LucidLab_MPC_DupContent::file_identity( $path_of( 5 ) ), 'A real copy must have its own identity.' );
mpc_assert( '' === LucidLab_MPC_DupContent::file_identity( $path_of( 7 ) ), 'A missing file has no identity.' );
$freed = LucidLab_MPC_DupContent::freed_bytes( array( 2, 3, 4, 5, 6 ), 1, $path_of );
mpc_assert( 0 === $freed[2]['bytes'] && 1 === $freed[2]['same_as'] && 0 === $freed[4]['bytes'], 'Records sharing the Keep file must free nothing.' );
mpc_assert( 1000 === $freed[5]['bytes'] && 0 === $freed[5]['same_as'], 'A real copy frees its size.' );
if ( $hard ) {
	mpc_assert( 0 === $freed[6]['bytes'] && 5 === $freed[6]['same_as'], 'A hard link to a counted copy frees nothing.' );
}

// 2. Byte Identical groups: 6 records, 2 or 3 files on disk; only the real copy is reclaimable.
$scanner = new class() extends LucidLab_MPC_DupContent {
	public function reference_index( array $ids ): array { return array_fill_keys( $ids, array() ); }
};
$hash   = hash( 'sha256', str_repeat( 'S', 1000 ) );
$result = $scanner->build_groups( array( $hash => array( 'ids' => array( 1, 2, 3, 4, 5, 6 ), 'bytes' => 1000 ) ) );
$group  = $result['groups'][0];
mpc_assert( 6 === $group['count'] && 5 === $result['dupe_count'], 'All six records must still be listed for merging.' );
mpc_assert( 2 === $group['files'] && 1 === $result['file_copies'] && 4 === $result['same_file_records'], 'Files on disk counted wrong: ' . json_encode( array( $group['files'], $result['file_copies'], $result['same_file_records'] ) ) );
mpc_assert( 1000 === $result['reclaim_bytes'], 'Reclaimable must count each file once, got ' . $result['reclaim_bytes'] . ' (was 5000 before).' );
$flags = array_column( $group['members'], 'same_file', 'id' );
mpc_assert( 1 === $flags[2] && 1 === $flags[3] && 1 === $flags[4] && 0 === $flags[5] && 1 === $group['survivor'], 'Members sharing the Keep file must be marked: ' . json_encode( $flags ) );
// Groups that free most space come first, even when a group with more records frees nothing.
$sort = $scanner->build_groups(
	array(
		'a' => array( 'ids' => array( 1, 2, 3, 4 ), 'bytes' => 1000 ),
		'b' => array( 'ids' => array( 5, 8 ), 'bytes' => 100 ),
	)
);
mpc_assert( 'b' === $sort['groups'][0]['hash'] && 0 === $sort['groups'][1]['reclaim'], 'Sort must follow real reclaimable bytes.' );

// 3. Merge preview counts the same way, whatever bytes the browser sends.
$preview = LucidLab_MPC_Preview::from_merge_pairs(
	array(
		array( 'dup' => 2, 'keep' => 1, 'bytes' => 1000 ),
		array( 'dup' => 3, 'keep' => 1, 'bytes' => 1000 ),
		array( 'dup' => 5, 'keep' => 1, 'bytes' => 1000 ),
	)
);
mpc_assert( 1000 === $preview['bytes'] && 3 === $preview['count'], 'Merge preview must free only the real copy, got ' . $preview['bytes'] );

// 4. Quarantine: a file another record still uses stays in place; records leaving in the same batch do not count.
$q      = ( new ReflectionClass( 'LucidLab_MPC_Quarantine' ) )->newInstanceWithoutConstructor();
$files2 = $q->attachment_abs_files( 2 );
$in_use = $q->files_in_use_elsewhere( 2, $files2, array( 2 ) );
mpc_assert( isset( $in_use[ "$base/2026/10/shared.jpg" ], $in_use[ "$base/2026/10/shared-300x300.jpg" ] ), 'Shared main file and size must be reported in use: ' . json_encode( $in_use ) );
mpc_assert( 2 !== reset( $in_use ), 'The record itself does not count as another user.' );
$in_use = $q->files_in_use_elsewhere( 2, $files2, array( 1, 2, 3, 4 ) );
mpc_assert( isset( $in_use[ "$base/2026/10/shared-300x300.jpg" ] ) && 8 === $in_use[ "$base/2026/10/shared-300x300.jpg" ] && ! isset( $in_use[ "$base/2026/10/shared.jpg" ] ), 'With all users of the main file leaving, only the size used by record 8 stays: ' . json_encode( $in_use ) );
$in_use = $q->files_in_use_elsewhere( 5, $q->attachment_abs_files( 5 ), array( 5 ) );
mpc_assert( $hard ? ! isset( $in_use[ "$base/2026/10/copy.jpg" ] ) : isset( $in_use[ "$base/2026/10/copy.jpg" ] ), 'A real copy is used by no other record path.' );
$source = (string) file_get_contents( __DIR__ . '/../includes/class-lucidlab-mpc-quarantine.php' );
mpc_assert( 1 === preg_match( '/files_in_use_elsewhere\( \$id, \$files, \$leaving \).*?isset\( \$in_use\[ \$norm_abs \] \).*?continue;.*?move_file\(/s', $source ), 'quarantine_attachments() must skip files in use before moving.' );

// 5. Visual group lookup: compact option, same answers, old format still read.
$big = range( 1000, 1649 );
LucidLab_MPC_Review::store_visual_groups( array( $big, array( 5, 6 ) ) );
$stored = $GLOBALS['options']['lucidlab_mpc_visual_groups'];
mpc_assert( strlen( serialize( $stored ) ) < 100000, 'Visual group option must not grow with the square of the group size: ' . strlen( serialize( $stored ) ) . ' bytes.' );
mpc_assert( $big === LucidLab_MPC_Review::visual_group_of( 1300 ) && array( 5, 6 ) === LucidLab_MPC_Review::visual_group_of( 6 ) && array() === LucidLab_MPC_Review::visual_group_of( 99 ), 'Visual group lookup wrong.' );
$GLOBALS['options']['lucidlab_mpc_visual_groups'] = array( 7 => array( 7, 8 ), 8 => array( 7, 8 ) );
mpc_assert( array( 7, 8 ) === LucidLab_MPC_Review::visual_group_of( 8 ), 'The previous option format must still be read.' );

array_map( 'unlink', glob( "$base/2026/10/*" ) );
rmdir( "$base/2026/10" );
rmdir( "$base/2026" );
rmdir( $base );
echo 'Shared-file duplicates: records sharing one file count as one file (reclaim, preview, sort), quarantine leaves a file in place while another record uses it, and visual group lookups stay small.' . "\n";
