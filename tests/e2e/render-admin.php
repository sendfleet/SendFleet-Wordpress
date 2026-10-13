<?php
// phpcs:ignoreFile -- Test script: renders every admin screen and fails on PHP notices.
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
set_error_handler( static function ( $no, $str, $file, $line ) { echo "PHP ERROR [$no] $str in $file:$line\n"; return true; } );
foreach ( array( 'render_overview' => array(), 'render_log' => array(), 'render_settings' => array(), 'render_log ' => array( 'tab' => 'suppressions' ) ) as $method => $get ) {
	$_GET = $get;
	set_current_screen( 'dashboard' );
	ob_start();
	call_user_func( array( 'SendFleet\\Admin\\Admin', trim( $method ) ) );
	$html = ob_get_clean();
	echo trim( $method ), ' ', strlen( $html ), " bytes\n";
}
$row = SendFleet\Log::recent( 1 );
if ( $row ) {
	$_GET = array( 'view' => $row[0]['id'] );
	ob_start();
	SendFleet\Admin\Admin::render_log();
	$html = ob_get_clean();
	echo 'detail ', strlen( $html ), " bytes\n";
}
// Not connected state.
SendFleet\Settings::update( array( 'api_key' => '' ) );
$_GET = array();
ob_start();
SendFleet\Admin\Admin::render_overview();
$html = ob_get_clean();
echo 'overview (not connected) ', strlen( $html ), " bytes\n";
