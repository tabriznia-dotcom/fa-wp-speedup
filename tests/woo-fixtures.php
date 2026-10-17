<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://localhost:8080' !== home_url() ) {
	throw new RuntimeException( 'Disposable site required' );
}
WC_Install::create_pages();
update_option( 'woocommerce_currency', 'IRR' );
update_option( 'woocommerce_default_country', 'IR' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Test COD', 'enable_for_virtual' => 'yes', 'enable_for_methods' => array() ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
foreach ( array( 'cart', 'checkout', 'myaccount' ) as $name ) {
	wp_update_post( array( 'ID' => wc_get_page_id( $name ), 'post_content' => '[woocommerce_' . ( 'myaccount' === $name ? 'my_account' : $name ) . ']' ) );
}
$product_id = wc_get_product_id_by_sku( 'faws-simple' );
$product = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();
$product->set_name( 'FAWS محصول آزمایشی' );
$product->set_sku( 'faws-simple' );
$product->set_status( 'publish' );
$product->set_regular_price( '100000' );
$product->set_virtual( true );
$product->save();
$variable_id = wc_get_product_id_by_sku( 'faws-variable' );
$variable = $variable_id ? wc_get_product( $variable_id ) : new WC_Product_Variable();
$variable->set_name( 'FAWS محصول متغیر' );
$variable->set_sku( 'faws-variable' );
$variable->set_status( 'publish' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'Small', 'Large' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable->save();
$children = $variable->get_children();
$variation = $children ? wc_get_product( $children[0] ) : new WC_Product_Variation();
$variation->set_parent_id( $variable->get_id() );
$variation->set_attributes( array( 'size' => 'Small' ) );
$variation->set_regular_price( '200000' );
$variation->set_virtual( true );
$variation->set_status( 'publish' );
$variation->save();
WC_Product_Variable::sync( $variable->get_id() );
update_option( 'faws_options', array( 'local' => true, 'diagnostics' => true ) );
WP_CLI::line( wp_json_encode( array(
	'simple' => $product->get_id(), 'variable' => $variable->get_id(), 'variation' => $variation->get_id(),
	'cart' => wc_get_cart_url(), 'checkout' => wc_get_checkout_url(), 'account' => wc_get_page_permalink( 'myaccount' ),
	'shop' => wc_get_page_permalink( 'shop' ), 'simple_url' => get_permalink( $product->get_id() ), 'variable_url' => get_permalink( $variable->get_id() ),
) ) );
