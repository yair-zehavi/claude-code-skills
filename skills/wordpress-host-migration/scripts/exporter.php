<?php
/*
 * One-shot WordPress migration exporter for hosts with FTP only (no SSH, exec, tar or mysqldump).
 * Upload to the old site's docroot under a random filename, pull from the new server, delete after use.
 * Streams, never writes to disk. Requires header X-Mig-Token (32+ chars); anything else gets a bare 404.
 *   ?m=info                                 environment + DB summary (JSON)
 *   ?m=list[&since=TS]                      manifest: path \t size \t mtime per file
 *   ?m=files[&since=TS][&parts=N&part=K]    GNU tar stream of the docroot
 *   ?m=db                                   SQL dump stream from a consistent snapshot
 *   ?m=selfdelete                           removes this file
 * After EXPIRES (epoch) the first request of any kind deletes the file.
 *
 * Before upload, replace:
 *   __TOKEN_SHA256__  sha256 hex of the token (the token itself stays on the new server)
 *   __EXPIRES__       epoch shortly after the planned cutover
 */
const TOKEN_SHA256 = '__TOKEN_SHA256__';
const EXPIRES      = __EXPIRES__;

if ( time() > EXPIRES ) {
	@unlink( __FILE__ );
	http_response_code( 404 );
	exit;
}
// The length floor refuses a missing or short token even if TOKEN_SHA256 was built from an empty string.
$mig_token = (string) ( $_SERVER['HTTP_X_MIG_TOKEN'] ?? '' );
if ( strlen( $mig_token ) < 32 || ! hash_equals( TOKEN_SHA256, hash( 'sha256', $mig_token ) ) ) {
	http_response_code( 404 );
	exit;
}
if ( ( $_GET['m'] ?? '' ) === 'selfdelete' ) {
	echo @unlink( __FILE__ ) ? 'deleted' : 'unlink failed';
	exit;
}

@set_time_limit( 0 );
@ini_set( 'zlib.output_compression', '0' );
@ini_set( 'memory_limit', '512M' );
while ( ob_get_level() ) {
	ob_end_clean();
}
header( 'X-Accel-Buffering: no' );
header( 'Cache-Control: no-store' );

$root = __DIR__;
$self = basename( __FILE__ );

function mig_excluded( $rel, $self ) {
	// Add any temporary files of your own (debug logs, test plugins) here.
	static $prefixes = array(
		'wp-content/cache/',
		'wp-content/upgrade/',
		'wp-content/upgrade-temp-backup/',
		'.tmb/',
	);
	if ( $rel === $self ) {
		return true;
	}
	foreach ( $prefixes as $p ) {
		if ( strncmp( $rel, $p, strlen( $p ) ) === 0 ) {
			return true;
		}
	}
	return false;
}

function mig_walk( $root, $self, $since ) {
	$out = array();
	$it  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $it as $f ) {
		$rel = substr( $f->getPathname(), strlen( $root ) + 1 );
		if ( $f->isLink() ) {
			continue;
		}
		$isdir = $f->isDir();
		if ( mig_excluded( $isdir ? $rel . '/' : $rel, $self ) ) {
			continue;
		}
		if ( $since && ( $isdir || $f->getMTime() < $since ) ) {
			continue;
		}
		$out[] = array( $rel, $isdir, $isdir ? 0 : $f->getSize(), $f->getMTime(), $f->getPerms() & 0777 );
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a[0], $b[0] ); } );
	return $out;
}

function mig_db_conf( $root ) {
	$src = file_get_contents( $root . '/wp-config.php' );
	$c   = array();
	foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET' ) as $k ) {
		if ( preg_match( "/define\\(\\s*['\"]" . $k . "['\"]\\s*,\\s*(['\"])(.*?)\\1\\s*\\)/s", $src, $m ) ) {
			$c[ $k ] = stripcslashes( $m[2] );
		}
	}
	$c['prefix'] = preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $m ) ? $m[1] : 'wp_';
	return $c;
}

function mig_db( $c ) {
	$host = $c['DB_HOST'];
	$port = null;
	$sock = null;
	if ( strpos( $host, ':' ) !== false ) {
		list( $host, $rest ) = explode( ':', $host, 2 );
		if ( is_numeric( $rest ) ) {
			$port = (int) $rest;
		} else {
			$sock = $rest;
		}
	}
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	$db = new mysqli( $host, $c['DB_USER'], $c['DB_PASSWORD'], $c['DB_NAME'], $port ?? 3306, $sock );
	$db->set_charset( 'utf8mb4' );
	return $db;
}

function mig_tar_header( $name, $size, $mtime, $mode, $type ) {
	$h  = str_pad( substr( $name, 0, 99 ), 100, "\0" );
	$h .= sprintf( "%07o\0", $mode );
	$h .= sprintf( "%07o\0", 0 );
	$h .= sprintf( "%07o\0", 0 );
	$h .= sprintf( "%011o\0", $size );
	$h .= sprintf( "%011o\0", $mtime );
	$h .= '        ';
	$h .= $type;
	$h .= str_repeat( "\0", 100 );
	$h .= "ustar  \0";
	$h .= str_repeat( "\0", 32 ) . str_repeat( "\0", 32 );
	$h .= str_repeat( "\0", 8 ) . str_repeat( "\0", 8 );
	$h .= str_repeat( "\0", 155 );
	$h  = str_pad( $h, 512, "\0" );
	$sum = 0;
	for ( $i = 0; $i < 512; $i++ ) {
		$sum += ord( $h[ $i ] );
	}
	return substr_replace( $h, sprintf( "%06o\0 ", $sum ), 148, 8 );
}

function mig_tar_entry_header( $name, $size, $mtime, $mode, $type ) {
	$out = '';
	if ( strlen( $name ) > 99 ) {
		$long = $name . "\0";
		$out .= mig_tar_header( '././@LongLink', strlen( $long ), 0, 0, 'L' );
		$out .= str_pad( $long, (int) ceil( strlen( $long ) / 512 ) * 512, "\0" );
	}
	return $out . mig_tar_header( $name, $size, $mtime, $mode, $type );
}

$mode  = $_GET['m'] ?? 'info';
$since = isset( $_GET['since'] ) ? (int) $_GET['since'] : 0;

if ( $mode === 'info' ) {
	header( 'Content-Type: application/json' );
	$c    = mig_db_conf( $root );
	$info = array(
		'php'      => PHP_VERSION,
		'sapi'     => PHP_SAPI,
		'disabled' => ini_get( 'disable_functions' ),
		'max_exec' => ini_get( 'max_execution_time' ),
		'mysqli'   => class_exists( 'mysqli' ),
		'root'     => $root,
		'prefix'   => $c['prefix'],
		'db_host'  => $c['DB_HOST'] ?? null,
		'charset'  => $c['DB_CHARSET'] ?? null,
		'server'   => $_SERVER['SERVER_SOFTWARE'] ?? '',
		'time'     => time(),
	);
	$db            = mig_db( $c );
	$info['mysql'] = $db->server_info;
	$tables        = array();
	$r             = $db->query( 'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY bytes DESC' );
	while ( $row = $r->fetch_assoc() ) {
		$tables[] = $row;
	}
	$info['tables'] = $tables;
	echo json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	exit;
}

if ( $mode === 'list' ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
	foreach ( mig_walk( $root, $self, $since ) as $e ) {
		if ( ! $e[1] ) {
			echo $e[0], "\t", $e[2], "\t", $e[3], "\n";
		}
	}
	exit;
}

if ( $mode === 'files' ) {
	header( 'Content-Type: application/x-tar' );
	$entries = mig_walk( $root, $self, $since );
	$parts   = max( 1, (int) ( $_GET['parts'] ?? 1 ) );
	$part    = (int) ( $_GET['part'] ?? 0 );
	if ( $parts > 1 ) {
		$total = array_sum( array_column( $entries, 2 ) );
		$acc   = 0;
		$keep  = array();
		foreach ( $entries as $e ) {
			$bucket = min( $parts - 1, (int) floor( $acc * $parts / max( 1, $total ) ) );
			$acc   += $e[2];
			if ( $bucket === $part ) {
				$keep[] = $e;
			}
		}
		$entries = $keep;
	}
	foreach ( $entries as $e ) {
		list( $rel, $isdir, $size, $mtime, $perm ) = $e;
		if ( $isdir ) {
			echo mig_tar_entry_header( $rel . '/', 0, $mtime, $perm ?: 0755, '5' );
			continue;
		}
		$fh = @fopen( $root . '/' . $rel, 'rb' );
		if ( ! $fh ) {
			continue;
		}
		echo mig_tar_entry_header( $rel, $size, $mtime, $perm ?: 0644, '0' );
		$sent = 0;
		while ( $sent < $size && ! feof( $fh ) ) {
			$buf = fread( $fh, min( 1048576, $size - $sent ) );
			if ( $buf === false || $buf === '' ) {
				break;
			}
			echo $buf;
			$sent += strlen( $buf );
		}
		fclose( $fh );
		if ( $sent < $size ) {
			echo str_repeat( "\0", $size - $sent );
		}
		$pad = ( 512 - ( $size % 512 ) ) % 512;
		if ( $pad ) {
			echo str_repeat( "\0", $pad );
		}
		flush();
	}
	echo str_repeat( "\0", 1024 );
	exit;
}

if ( $mode === 'db' ) {
	header( 'Content-Type: application/sql; charset=utf-8' );
	$db = mig_db( mig_db_conf( $root ) );
	$db->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
	$db->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
	echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n";
	$db->query( "SET time_zone='+00:00'" );
	$tr     = $db->query( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
	$tables = array();
	while ( $row = $tr->fetch_row() ) {
		$tables[] = $row[0];
	}
	foreach ( $tables as $t ) {
		$q      = '`' . str_replace( '`', '``', $t ) . '`';
		$create = $db->query( "SHOW CREATE TABLE $q" )->fetch_row()[1];
		echo "DROP TABLE IF EXISTS $q;\n", $create, ";\n";
		$res    = $db->query( "SELECT * FROM $q", MYSQLI_USE_RESULT );
		$fields = $res->fetch_fields();
		$bin    = array();
		foreach ( $fields as $i => $f ) {
			$bin[ $i ] = ( $f->charsetnr === 63 && in_array( $f->type, array( MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_BIT ), true ) );
		}
		$buf = '';
		$n   = 0;
		while ( $row = $res->fetch_row() ) {
			$vals = array();
			foreach ( $row as $i => $v ) {
				if ( $v === null ) {
					$vals[] = 'NULL';
				} elseif ( $bin[ $i ] ) {
					$vals[] = $v === '' ? "''" : '0x' . bin2hex( $v );
				} else {
					$vals[] = "'" . $db->real_escape_string( $v ) . "'";
				}
			}
			$buf .= ( $n ? ",\n(" : "INSERT INTO $q VALUES\n(" ) . implode( ',', $vals ) . ')';
			$n++;
			if ( strlen( $buf ) > 1000000 ) {
				echo $buf, ";\n";
				flush();
				$buf = '';
				$n   = 0;
			}
		}
		if ( $n ) {
			echo $buf, ";\n";
		}
		$res->free();
		echo "\n";
		flush();
	}
	$db->query( 'COMMIT' );
	echo "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- mig-dump-complete\n";
	exit;
}

http_response_code( 400 );
