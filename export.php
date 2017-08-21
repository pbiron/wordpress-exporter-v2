<?php
/**
 * WordPress Export Administration Screen
 *
 * @package WordPress
 * @subpackage Administration
 */

/*
 * we have to muck with $_SERVER['PHP_SELF'] while loading
 * WP bootstrap in order to "pretend" that we're the standard
 * exporter, otherwise /wp-includes/vars.php generates errors.
 * If/when the changes in this plugin get merged with core
 * that won't be necessary.
 */

$save_php_self = $_SERVER['PHP_SELF'];
$_SERVER['PHP_SELF'] = '/wp-admin/export.php';

/** Load WordPress Bootstrap */
require_once( '../../../wp-admin/admin.php' );

$_SERVER['PHP_SELF'] = $save_php_self;

if ( ! current_user_can( 'export' ) )
	wp_die( __( 'Sorry, you are not allowed to export the content of this site.' ) );

global $wxr_exporter_plugin;

$wxr_exporter_plugin->get_ui()->dispatch();
