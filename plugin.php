<?php
/*
 * Plugin Name: WordPress Exporter v2
 * Description: Proposed changes to WP Core's standard exporter
 * Version: 0.3
 * Author: Paul V. Biron/Sparrow Hawk Computing
 * Author URI: http://sparrowhawkcomputing.com
 * Plugin URI: https://github.com/pbiron/wordpress-exporter-v2
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/pbiron/wordpress-exporter-v2
 */

/**
 * WordPress Export Administration Screen
 *
 * @package WordPress
 * @subpackage Administration
 */

/*
 * This plugin represents some proposed changes to the standard exporter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

require dirname( __FILE__ ) . '/class-logger-serversentevents.php';
require dirname( __FILE__ ) . '/class-wxr-exporter-ui.php';

class WXR_Exporter_Plugin {
	/**
	 * Our UI.
	 *
	 * @var WXR_Export_UI
	 */
	protected $ui;

	/**
	 * Constructor.
	 *
	 * Hook into actions.
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_item' ) );
		add_action( 'admin_head', array( __CLASS__, 'add_html_base' ) );
	}

	/**
	 * Get our UI.
	 *
	 * @return WXR_Export_UI
	 */
	function get_ui() {
		return $this->ui;
	}

	function admin_init() {
		$this->ui = new WXR_Export_UI();
		add_action( 'wp_ajax_wxr-export', array( $this->ui, 'stream_export' ) );
	}

	/**
	 * Add our menu item to the tools.php menu.
	 */
	static function add_menu_item() {
		/**
		 * @global array $submenu
		 */
		global $submenu;

		/*
		 * cheating by not using add_submenu_page() so that
		 * we can mimic how the standard exporter works.
		 *
		 * Kids, don't try this at home!
		 *
		 * Unfortunately, because of how _wp_menu_output() detects which
		 * menu sub_item is "current", doing things this way means that our
		 * menu item never gets the 'current' @class.  But I'm not going to
		 * worry about that.
		 */

		/*
		 * Note that this exporter can run along side the standard exporter so that you
		 * can export from both and compare/contrast the results.
		 */
		$submenu['tools.php'][max( array_keys( $submenu['tools.php'] )) + 1] = array(
			'Export (v2)',
			'export',
			plugins_url( 'export.php', __FILE__),
		);
	}

	/**
	 * Add an HTML <base> to <head> so that the rest of the admin
	 * menus work correctly.
	 *
	 * This is necessary because we are cheating in how we add our menu item above.
	 */
	static function add_html_base() {
		$export = 'export.php';
		$here = '/' . PLUGINDIR . '/' . basename ( dirname( __FILE__ ) ) . "/{$export}";
		if ( $here !== remove_query_arg( 'step', $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		if ( is_network_admin() ) {
			$href = network_admin_url( $export );
		}
		else {
			$href = admin_url( $export );
		}

		$href = esc_attr( $href );

		echo "<base href='$href' />\n";
	}
}

// set a global to the instantiation of ourself, so that we can access it in export.php
global $wxr_exporter_plugin;
$wxr_exporter_plugin = new WXR_Exporter_Plugin();