<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://localhost:8080' !== home_url() ) {
	throw new RuntimeException( 'Disposable site required' );
}
$mode = $args[0] ?? 'setup';
if ( 'disable' === $mode ) {
	delete_option( 'faws_test_fixture' );
	return;
}
if ( 'local' === $mode ) {
	update_option( 'faws_options', array( 'local' => true ) );
	return;
}
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/faws-test-fixture.php', <<<'CODE'
<?php
// Generated only for the disposable development site. Never distribute this fixture.
add_action('wp_enqueue_scripts', static function () {
    if ('http://localhost:8080' === home_url() && get_option('faws_test_fixture') && is_front_page()) {
        wp_enqueue_style('faws-bootstrap-fixture', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', array(), '5.3.3');
        wp_enqueue_script('faws-bootstrap-fixture', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', array(), '5.3.3', array('strategy'=>'defer', 'in_footer'=>true));
    }
});
CODE
);
$state = array();
foreach ( FAWS_Catalog::all() as $id => $item ) {
	$state[ $id ] = array( 'consent' => true );
}
update_option( 'faws_resources', $state );
$transport = static function ( $pre, $args, $url ) {
	$id = FAWS_Catalog::match( $url );
	if ( ! $id ) { return $pre; }
	$item = FAWS_Catalog::all()[ $id ];
	return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'css' === $item['type'] ? 'text/css' : 'application/javascript' ), 'body' => file_get_contents( '/fixtures/bootstrap.' . $item['type'] ) );
};
add_filter( 'pre_http_request', $transport, 10, 3 );
foreach ( array_keys( $state ) as $id ) {
	delete_option( 'faws_attempt_' . $id );
	FAWS_Local::download( $id );
	if ( ! FAWS_Local::verified( $id ) ) { throw new RuntimeException( 'Fixture install failed' ); }
}
remove_filter( 'pre_http_request', $transport );
$state = get_option( 'faws_resources' );
foreach ( $state as &$resource ) { $resource['active'] = true; }
unset( $resource );
update_option( 'faws_resources', $state );
update_option( 'faws_options', array( 'local' => false ) );
update_option( 'faws_test_fixture', true );
WP_CLI::success( 'Pinned fixture files installed; original URLs selected for baseline' );
