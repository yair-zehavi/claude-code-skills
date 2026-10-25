<?php
// Merge the source host's wp-config into the new host's one without printing secrets.
// Keeps the new host's DB credentials and Redis/object-cache config; takes the 8 salts,
// table prefix, WPLANG and memory limits from the source. Aborts if any salt is missing.
// Usage: php merge-wp-config.php <new-host-wp-config> <source-wp-config> <out-wp-config> <out-salt-file>
// The salt file layout (salts in a separate file included by wp-config.php) matches Cloudways;
// adapt the last two writes if your host keeps salts inline.
list( , $cw, $src, $out_cfg, $out_salt ) = $argv;
$c = file_get_contents( $cw );
$s = file_get_contents( $src );

preg_match_all( "/^\s*define\(\s*['\"](AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)['\"].*?\);\s*$/m", $s, $m );
if ( count( array_unique( $m[1] ) ) !== 8 ) {
	fwrite( STDERR, 'salts found: ' . count( $m[1] ) . "\n" );
	exit( 1 );
}
preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $s, $p ) || exit( "no table_prefix in source\n" );

$extra = '';
foreach ( array( 'WPLANG', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT' ) as $k ) {
	if ( preg_match( "/^\s*define\(\s*['\"]" . $k . "['\"].*?\);\s*$/m", $s, $d ) ) {
		$extra .= trim( $d[0] ) . "\n";
		$c = preg_replace( "/^\s*define\(\s*['\"]" . $k . "['\"].*?\);\s*\n/m", '', $c );
	}
}
$c = preg_replace( '/\$table_prefix\s*=\s*[\'"][^\'"]*[\'"];/', "\$table_prefix  = '{$p[1]}';\n" . rtrim( $extra ), $c, 1, $n );
if ( $n !== 1 ) {
	exit( "table_prefix replace failed\n" );
}
file_put_contents( $out_salt, "<?php\n" . implode( "\n", array_map( 'trim', $m[0] ) ) . "\n" );
file_put_contents( $out_cfg, $c );
echo "config ok: prefix {$p[1]}, carried: " . str_replace( "\n", ' ', trim( preg_replace( '/,.*$/m', '', $extra ) ) ) . "\n";
