<?php
/** Run only with dev/wp.sh eval-file tests/integration.php on the disposable site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://localhost:8080' !== home_url() ) {
	throw new RuntimeException( 'Disposable development site required' );
}
global $count;
$count = 0;
function faws_check( $condition, $label ) {
	global $count;
	++$count;
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
}
$id = 'bootstrap-js-5.3.3';
$item = FAWS_Catalog::all()[ $id ];
$saved_options = get_option( 'faws_options', array() );
$saved_resources = get_option( 'faws_resources', array() );
try {
	wp_set_current_user( 0 );
	$_GET = array();
	$_COOKIE = array();
	update_option( 'faws_options', array( 'local' => true ) );
	update_option( 'faws_resources', array( $id => array( 'consent' => true ) ) );
	$fixture_transport = static function ( $pre, $args, $url ) use ( $item ) {
		if ( $url !== $item['url'] ) { return $pre; }
		faws_check( true === $args['sslverify'] && true === $args['reject_unsafe_urls'] && 0 === $args['redirection'], 'safe transport parameters' );
		return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/javascript' ), 'body' => file_get_contents( '/fixtures/bootstrap.js' ) );
	};
	add_filter( 'pre_http_request', $fixture_transport, 10, 3 );
	delete_option( 'faws_attempt_' . $id );
	$consent_before = get_option( 'faws_resources' );
	FAWS_Local::download( $id );
	remove_filter( 'pre_http_request', $fixture_transport );
	$result = get_option( 'faws_resources' );
	faws_check( $consent_before === $result, 'worker never writes administrator consent state' );
	$download = get_option( 'faws_download_' . $id );
	faws_check( 'verified' === ( $download['status'] ?? '' ), 'fixture-backed download: ' . wp_json_encode( $result ) );
	faws_check( FAWS_Local::verified( $id ), 'disk SRI verified' );
	wp_enqueue_script( 'faws-fixture', $item['url'], array(), '5.3.3', array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_add_inline_script( 'faws-fixture', 'window.fawsBefore=true;', 'before' );
	wp_localize_script( 'faws-fixture', 'fawsData', array( 'test' => 'yes' ) );
	$registered = wp_scripts()->registered['faws-fixture'];
	$before = serialize( $registered );
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'faws-fixture' ), 'download consent does not activate' );
	$result[ $id ]['active'] = true;
	update_option( 'faws_resources', $result );
	list( $file, $url ) = FAWS_Local::path( $id );
	faws_check( $url === apply_filters( 'script_loader_src', $item['url'] . '?ver=5.3.3', 'faws-fixture' ), 'WP loader uses local source' );
	faws_check( $before === serialize( $registered ), 'enqueue metadata untouched' );
	ob_start();
	wp_scripts()->do_items( array( 'faws-fixture' ), 1 );
	$tag = ob_get_clean();
	faws_check( false !== strpos( $tag, $url ) && false !== strpos( $tag, 'defer' ) && false !== strpos( $tag, 'fawsBefore' ) && false !== strpos( $tag, 'fawsData' ), 'rendered tag retains defer, inline and localization' );
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'checkout-payment' ), 'sensitive handle excluded' );
	wp_enqueue_script( 'theme-bootstrap', '/local/bootstrap.js' );
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'faws-fixture' ), 'duplicate library excluded' );
	wp_dequeue_script( 'theme-bootstrap' );
	$_COOKIE['wp_woocommerce_session_test'] = 'not-persisted';
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'faws-fixture' ), 'Woo session excluded' );
	$_COOKIE = array();
	$_GET['wc-ajax'] = 'checkout';
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'faws-fixture' ), 'query sensitive request excluded' );
	$_GET = array();
	wp_set_current_user( 1 );
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'faws-fixture' ), 'logged in excluded' );
	wp_set_current_user( 0 );
	$original = file_get_contents( $file );
	file_put_contents( $file, 'tampered' );
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'faws-fixture' ), 'tampered file rolls back' );
	file_put_contents( $file, $original );
	update_option( 'faws_attempt_' . $id, time() - 7200 );
	$calls = 0;
	$reject = static function () use ( &$calls ) { ++$calls; return new WP_Error( 'simulated_timeout', 'test' ); };
	add_filter( 'pre_http_request', $reject );
	FAWS_Local::download( $id );
	faws_check( FAWS_Local::verified( $id ), 'failed refresh retains verified old file' );
	FAWS_Local::download( $id );
	faws_check( 1 === $calls, 'cooldown prevents repeated HTTP requests' );
	remove_filter( 'pre_http_request', $reject );
	FAWS_Local::remove( $id );
	faws_check( $item['url'] === FAWS_Plugin::source( $item['url'], 'faws-fixture' ), 'missing file original fallback' );
	update_option( 'faws_resources', array() );
	$reject = static function () { throw new RuntimeException( 'Unapproved download performed HTTP' ); };
	add_filter( 'pre_http_request', $reject );
	FAWS_Local::download( $id );
	remove_filter( 'pre_http_request', $reject );
	faws_check( ! FAWS_Local::verified( $id ), 'unapproved download blocked' );
	update_option( 'faws_resources', array( $id => array( 'consent' => true ) ) );
	delete_option( 'faws_attempt_' . $id );
	$revoke = static function ( $pre, $args, $url ) use ( $fixture_transport, $id ) {
		update_option( 'faws_resources', array( $id => array( 'consent' => false ) ) );
		return $fixture_transport( $pre, $args, $url );
	};
	add_filter( 'pre_http_request', $revoke, 10, 3 );
	FAWS_Local::download( $id );
	remove_filter( 'pre_http_request', $revoke );
	faws_check( empty( get_option( 'faws_resources' )[ $id ]['consent'] ) && ! FAWS_Local::verified( $id ), 'revocation during download stays revoked and removes installed file' );
	WP_CLI::success( "$count WordPress integration checks passed (fixture HTTP transport; live safe-HTTP DNS unverified)" );
} finally {
	FAWS_Local::remove( $id );
	update_option( 'faws_options', $saved_options );
	update_option( 'faws_resources', $saved_resources );
}
