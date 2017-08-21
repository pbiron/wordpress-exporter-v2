<?php
/**
 * Intro screen (step 0).
 */

add_action( 'admin_head', array( 'WXR_Export_UI', 'export_add_js' ) );

get_current_screen()->add_help_tab( array(
	'id'      => 'overview',
	'title'   => __('Overview'),
	'content' => '<p>' . __('You can export a file of your site&#8217;s content in order to import it into another installation or platform. The export file will be an XML file format called WXR. Posts, pages, comments, custom fields, categories, and tags can be included. You can choose for the WXR file to include only certain posts or pages by setting the dropdown filters to limit the export by category, author, date range by month, or publishing status.') . '</p>' .
		'<p>' . __('Once generated, your WXR file can be imported by another WordPress site or by another blogging platform able to access this format.') . '</p>',
) );

get_current_screen()->set_help_sidebar(
	'<p><strong>' . __('For more information:') . '</strong></p>' .
	'<p>' . __('<a href="https://codex.wordpress.org/Tools_Export_Screen">Documentation on Export</a>') . '</p>' .
	'<p>' . __('<a href="https://wordpress.org/support/">Support Forums</a>') . '</p>'
);

$this->render_header();

?>
	<h1><?php echo _e( 'Export (v2)' ); ?></h1>

	<p><?php _e('When you click the button below WordPress will create an XML file for you to save to your computer.'); ?></p>
	<p><?php _e('This format, which we call WordPress eXtended RSS or WXR, will contain your posts, pages, comments, custom fields, categories, and tags.'); ?></p>
	<p><?php _e('Once you&#8217;ve saved the download file, you can use the Import function in another WordPress installation to import the content from this site.'); ?></p>

	<h2><?php _e( 'Choose what to export' ); ?></h2>
	<form method="POST" id="export-filters" action="<?php echo esc_url( $this->get_url( 1 ) ) ?>">
		<?php wp_nonce_field( 'export' ); ?>
		<input type="hidden" name="download1" value="true" />
		<fieldset>
			<legend class="screen-reader-text"><?php _e( 'Content to export' ); ?></legend>
			<fieldset>
				<p><label><input type="radio" name="content" value="all" checked="checked" aria-describedby="all-content-desc" /> <?php _e( 'All content' ); ?></label></p>
				<p class="description" id="all-content-desc"><?php _e( 'This will contain all of your posts, pages, comments, custom fields, terms, navigation menus, and custom posts.' ); ?></p>
			</fieldset>
			<?php
				// output UI for filtering all exportable post types
				foreach ( get_post_types ( array( 'can_export' => true ) ) as $post_type ) {
					self::post_type_export_filter( $post_type );
				}

				// output UI for filtering links
				global $wpdb;

				$link_ids = $wpdb->get_col( "SELECT link_id FROM {$wpdb->links}" );
				if ( ! empty( $link_ids ) ) {
					$dropdown =  wp_dropdown_categories( array(
						'taxonomy' => 'link_category',
						'object_ids' => $link_ids,
						'name' => "links_taxonomy_link_category",
						'orderby' => 'name',
						'show_option_all' => __('All'),
						'hide_empty' => true,
						'hide_if_empty' => true,
						'echo' => false ) );
					$tax = get_taxonomy( 'link_category' );

					$link_rels = array();
					foreach ( $wpdb->get_col( "SELECT link_rel FROM {$wpdb->links}" ) as $_link_rel ) {
						foreach ( explode( ' ', $_link_rel ) as $link_rel ) {
							$link_rels[] = $link_rel;
						}
					}
					$link_rels = array_unique( $link_rels );
					sort( $link_rels );
					?>
	 		<fieldset>
				<p><label><input type="radio" name="content" value="links" /> <?php echo _e( 'Links' ); ?></label></p>
				<ul id="links-filters" class="export-filters" style="display: none;">
					<li>
						<label><span class="label-responsive"><?php echo esc_html( $tax->label )  . ':' ; ?></span>
						<?php echo $dropdown; ?>
						</label>
					</li>
					<li>
						<label><span class="label-responsive"><?php echo esc_html( __( 'Link Relationships' ) )  . ':' ; ?></span>
						<select name="link_relationship">
							<option value="0"><?php _e( 'All '); ?></option>
						<?php foreach ( $link_rels as $link_rel ) : ?>
							<option><?php echo $link_rel; ?></option>
						<?php endforeach; ?>
						</select>
						</label>
					</li>
				</ul>
			</fieldset>
			<?php
				}

/**
 * Fires at the end of the export filters form.
 *
 * @since 3.5.0
 */
do_action( 'export_filters' );
 ?>
		</fieldset>
<?php
submit_button( __('Start Export') );

 ?>
	</form>
<?php

$this->render_footer();

