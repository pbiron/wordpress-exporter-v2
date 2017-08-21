<?php
/**
 * Footer template for the WXR Exporter UI.
 */
?>

	<?php do_action( 'wxr_exporter.ui.footer' ) ?>

</div>

<?php
require_once( ABSPATH . 'wp-admin/admin-footer.php' );

// Don't load the admin footer, as it's loaded for us later.
