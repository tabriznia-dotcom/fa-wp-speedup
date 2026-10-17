<?php
// Dependency-free boundary tests; real WordPress integration lives in integration.php.
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-faws-catalog.php';
$count = 0;
function check( $condition, $name ) {
	global $count;
	++$count;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $name\n" );
		exit( 1 );
	}
}
$catalog = FAWS_Catalog::all();
$url = $catalog['bootstrap-js-5.3.3']['url'];
check( 'bootstrap-js-5.3.3' === FAWS_Catalog::match( $url ), 'exact mapping' );
check( 'bootstrap-js-5.3.3' === FAWS_Catalog::match( $url . '?ver=5.3.3' ), 'WP version query' );
foreach ( array( '?key=secret', '?ver=5.2.0', '?ver=5.3.3&key=x', '#x', '/../../x', '.php' ) as $suffix ) {
	check( null === FAWS_Catalog::match( $url . $suffix ), 'reject dynamic/ambiguous URL' );
}
check( null === FAWS_Catalog::match( 'http://127.0.0.1/a.js' ), 'no private target' );
check( null === FAWS_Catalog::match( str_replace( 'https:', 'http:', $url ) ), 'HTTPS only' );
foreach ( array( 'css', 'js' ) as $type ) {
	$item = $catalog[ 'bootstrap-' . $type . '-5.3.3' ];
	$file = '/fixtures/bootstrap.' . $type;
	$body = file_get_contents( $file );
	$mime = 'css' === $type ? 'text/css' : 'application/javascript';
	check( FAWS_Catalog::valid_body( $body, $mime, $item ), "official $type SRI" );
	check( ! FAWS_Catalog::valid_body( $body . 'x', $mime, $item ), 'tampered body' );
	check( ! FAWS_Catalog::valid_body( $body, 'text/html', $item ), 'HTML MIME rejected' );
	check( ! FAWS_Catalog::valid_body( '<html>error</html>', $mime, $item ), 'HTML payload rejected' );
	check( ! FAWS_Catalog::valid_body( str_repeat( 'x', 1048577 ), $mime, $item ), 'size limit' );
}
echo "PASS $count catalog/security checks\n";
