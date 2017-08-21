<?php

require_once dirname( __FILE__ ) . '/class-logger-serversentevents.php';
require_once dirname( __FILE__ ) . '/wp-includes/class-wxr-exporter.php';

class WXR_Export_UI {
	protected $exporter;

	// @todo decide what the mime-type should be.
// http://www.rssboard.org/rss-profile recommends application/rss+xml
// the standard and redux importers use application/xml
// I think .org should register application/vnd.wordpress.wxr+xml with IANA
// @link https://github.com/humanmade/WordPress-Importer/issues/127
	const WXR_MIME_TYPE = 'application/xml';

// @todo if/when .org registers application/vnd.wordpress.wxr+xml then the export file
// extension should change to '.wxr'
	const WXR_FILE_EXTENSION = '.xml';

	function __construct() {
		add_action( 'wp_ajax_wxr-export', array( $this, 'stream_export' ) );
	}

	/**
	 * Get the URL for the exporter.
	 *
	 * @param int $step Go to step rather than start.
	 */
	protected function get_url( $step = 0 ) {
		$path = plugins_url( 'export.php', __FILE__);
		if ( $step ) {
			$path = add_query_arg( 'step', (int) $step, $path );
		}

		return $path;
	}

	protected function display_error( WP_Error $err, $step = 0 ) {
		$this->render_header();

		echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-exporter' ) . '</strong><br />';
		echo $err->get_error_message();
		echo '</p>';
		printf(
			'<p><a class="button" href="%s">Try Again</a></p>',
			esc_url( $this->get_url( $step ) )
		);

		$this->render_footer();
	}

	/**
	 * Handle load event for the exporter.
	 */
	public function on_load() {
		// Skip outputting the header on our export page, so we can handle it.
		$_GET['noheader'] = true;
	}

	/**
	 * Render the export page.
	 */
	public function dispatch() {
		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
			case 0:
				$this->display_intro_step();
				break;
			case 1:
				check_admin_referer( 'export' );
				$this->display_export_step();
				break;
			case 2:
				check_admin_referer( 'export-download' );
				$this->download();
				break;
		}
	}

	function download() {
		$file = get_transient( '_wxr_export_file' );
		if ( empty( $file ) ) {
			return;
		}

		$sitename = sanitize_key( get_bloginfo( 'name' ) );
		if ( ! empty( $sitename ) ) {
			$sitename .= '.';
		}
		$date = date( 'Y-m-d' );
		$wp_filename = "{$sitename}wordpress.{$date}" . self::WXR_FILE_EXTENSION;

		/**
		 * Filters the export filename.
		 *
		 * @since 4.4.0
		 *
		 * @param string $wp_filename The name of the file for download.
		 * @param string $sitename    The site name.
		 * @param string $date        Today's date, formatted.
		 */
		$filename = apply_filters( 'export_wp_filename', $wp_filename, $sitename, $date );

	 	header( 'Content-Description: File Transfer' );
	 	header( 'Content-Disposition: attachment; filename=' . $filename );
	 	header( 'Content-Type: ' . self::WXR_MIME_TYPE . '; charset=' . get_transient( '_wxr_export_encoding' ), true );

	 	echo file_get_contents( $file );

	 	delete_transient( '_wxr_export_file' );
	 	delete_transient( '_wxr_export_encoding' );

	 	unlink( $file );
	}

	/**
	 * Render the exporter header.
	 */
	protected function render_header() {
		require dirname( __FILE__ ) . '/templates/header.php';
	}

	/**
	 * Render the exporter footer.
	 */
	protected function render_footer() {
		require dirname( __FILE__ ) . '/templates/footer.php';
	}

	/**
	 * Display introductory text and file upload form
	 */
	protected function display_intro_step() {
		require dirname( __FILE__ ) . '/templates/intro.php';
	}

	/**
	 * Display the actual export step.
	 */
	protected function display_export_step() {
		// Time to run the export!
		set_time_limit( 0 );

		require dirname( __FILE__ ) . '/templates/export.php';
	}

	/**
	 * Run an export, and send an event-stream response.
	 *
	 * Streams logs and success messages to the browser to allow live status
	 * and updates.
	 */
	function stream_export() {
		// Turn off PHP output compression
		$previous = error_reporting( error_reporting() ^ E_WARNING );
		ini_set( 'output_buffering', 'off' );
		ini_set( 'zlib.output_compression', false );
		error_reporting( $previous );

		if ( $GLOBALS['is_nginx'] ) {
			// Setting this header instructs Nginx to disable fastcgi_buffering
			// and disable gzip for this request.
			header( 'X-Accel-Buffering: no' );
			header( 'Content-Encoding: none' );
		}

		// Start the event stream.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );

		if ( get_transient( '_wxr_export_started' ) ) {
 			// Tell the browser to stop reconnecting.
 			status_header( 204 );
 			exit;
		}
		else {
			set_transient( '_wxr_export_started', true );
		}

		// 2KB padding for IE
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";

		// Time to run the export!
		set_time_limit( 0 );

		// Ensure we're not buffered.
		wp_ob_end_flush_all();
		flush();

 		$exporter = self::get_exporter( $_REQUEST['filters'] );

		// Keep track of our progress
		add_action( 'wxr_exporter.wrote.post', array( $this, 'exported_post' ), 10, 2 );
		add_action( 'wxr_exporter.wrote.user', array( $this, 'exported_user' ) );
		add_action( 'wxr_exporter.wrote.comment', array( $this, 'exported_comment' ) );
		add_action( 'wxr_exporter.wrote.term', array( $this, 'exported_term' ) );
		add_action( 'wxr_exporter.wrote.link', array( $this, 'exported_link' ) );

		// Clean up some memory
		unset( $settings );

		// Flush once more.
		flush();

		$file = tempnam( sys_get_temp_dir(), 'WXR' );
		set_transient( '_wxr_export_file', $file );
 		$err = $exporter->export( $file );
 		set_transient( '_wxr_export_encoding', $exporter->get_encoding() );

		// Remove the settings to stop future reconnects.
		delete_transient( '_wxr_export_started' );

		// Let the browser know we're done.
		$complete = array(
			'action' => 'complete',
			'error' => false,
		);
		if ( is_wp_error( $err ) ) {
			$complete['error'] = $err->get_error_message();
		}

		$this->emit_sse_message( $complete );
		exit;
	}

	/**
	 * Get the exporter instance.
	 *
	 * @return WXR_Exporter
	 */
	static function get_exporter( $filters ) {
		$exporter = new WXR_Exporter( $filters );
		$logger = new WP_Exporter_Logger_ServerSentEvents();
		$exporter->set_logger( $logger );

		return $exporter;
	}

// 	/**
// 	 * Get options for the exporter.
// 	 *
// 	 * @return array Options to pass to WXR_Exporter::__construct
// 	 */
// 	protected function get_export_options() {
// 		$options = array(
// 			'fetch_attachments' => $this->fetch_attachments,
// 			'default_author'    => get_current_user_id(),
// 		);

// 		/**
// 		 * Filter the exporter options used in the admin UI.
// 		 *
// 		 * @param array $options Options to pass to WXR_Exporter::__construct
// 		 */
// 		return apply_filters( 'wxr_exporter.admin.export_options', $options );
// 	}

	/**
	 * Emit a Server-Sent Events message.
	 *
	 * @param mixed $data Data to be JSON-encoded and sent in the message.
	 */
	protected function emit_sse_message( $data ) {
		echo "event: message\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";

		// Extra padding.
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";

		flush();
	}

	/**
	 * Send message when a post has been exported.
	 *
	 * @param int $id Post ID.
	 * @param array $data Post data saved to the DB.
	 */
	public function exported_post( $id, $data ) {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => ( $data['post_type'] === 'attachment' ) ? 'media' : 'posts',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a post is marked as already exported.
	 *
	 * @param array $data Post data saved to the DB.
	 */
	public function already_exported_post( $data ) {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => ( $data['post_type'] === 'attachment' ) ? 'media' : 'posts',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a comment has been exported.
	 */
	public function exported_comment() {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => 'comments',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a term has been exported.
	 */
	public function exported_term() {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => 'terms',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a user has been exported.
	 */
	public function exported_user() {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => 'users',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a link has been exported.
	 */
	public function exported_link() {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => 'links',
			'delta'  => 1,
		));
	}

	static function build_filters() {
		$filters = array();

		if ( ! isset( $_REQUEST['content'] ) || 'all' == $_REQUEST['content'] ) {
			$filters['content'] = 'all';
		}
//		else {
//		}
		else {
			$filters['content'] = $_REQUEST['content'];
			//		if ( 'all' !== $filters['content'] ) {
			$post_type_obj = get_post_type_object( $_REQUEST['content'] );
			if ( ! empty( $post_type_obj ) ) {
				$filters['post_type'] = $post_type_obj->name;

				foreach ( $_REQUEST as $name => $value ) {
					if ( 0 === strpos( $name, "{$filters['post_type']}_taxonomy_" ) ) {
						$filters['taxonomy'][preg_replace( "/{$filters['post_type']}_taxonomy_/", '', $name )] = $value;
					}
				}
			}
			elseif ( 'links' === $_REQUEST['content'] ) {
				$filters['content'] = 'links';
				if ( isset( $_REQUEST['links_taxonomy_link_category'] ) ) {
					$filters['taxonomy']['link_category'] = $_REQUEST['links_taxonomy_link_category'];
				}
				if ( isset( $_REQUEST['link_relationship'] ) ) {
					$filters['link_relationship'] = $_REQUEST['link_relationship'];
				}
			}
		}

		if ( isset( $_REQUEST['author'] ) ) {
			$filters['author'] = intval( $_REQUEST['author'] );
		}
		if ( isset( $_REQUEST['start_date'] ) && "0" !== $_REQUEST['start_date'] ) {
			$filters['start_date'] = $_REQUEST['start_date'];
		}
		if ( isset( $_REQUEST['end_date'] ) && "0" !== $_REQUEST['end_date'] ) {
			$filters['end_date'] = $_REQUEST['end_date'];
		}
		if ( ! empty( $filters['start_date'] ) ) {
			$filters['start_date'] = date( 'Y-m-d', strtotime( $filters['start_date'] ) );
		}
		if ( ! empty( $filters['end_date'] ) ) {
			$filters['end_date'] = date( 'Y-m-d', strtotime( '+1 month', strtotime( $filters['end_date'] ) ) );
		}

		if ( isset( $_REQUEST['status'] ) ) {
			$filters['status'] = $_REQUEST['status'];
		}

		$filters = array_filter( $filters );
		foreach ( $filters as $name => &$filter ) {
			if ( is_array( $filter ) ) {
				$filter = array_filter( $filter );
				if ( empty( $filter ) ) {
					unset( $filters[$name] );
				}
			}
		}

		/**
		 * Filters the export args.
		 *
		 * @since 3.5.0
		 *
		 * @param array $args The arguments to send to the exporter.
		 */
		$filters = apply_filters( 'export_args', $filters );

		return $filters;
	}

	static function post_type_export_filter( $post_type ) {
		global $wpdb;
		$post_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare ( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) ) );
		if ( empty( $post_ids ) ) {
			return;
		}
		$post_type_obj = get_post_type_object( $post_type );
	 ?>
	 		<fieldset>
				<p><label><input type="radio" name="content" value="<?php echo $post_type ?>" /> <?php echo esc_html( $post_type_obj->label ); ?></label></p>
				<ul id="<?php echo $post_type ?>-filters" class="export-filters" style="display: none;">
					<?php
					foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) :
						$dropdown =  wp_dropdown_categories( array(
							'taxonomy' => $tax->name,
							'object_ids' => $post_ids,
							'name' => "{$post_type}_taxonomy_{$tax->name}",
							'orderby' => 'name',
							'show_option_all' => __('All'),
							'hide_empty' => true,
							'hide_if_empty' => true,
							'echo' => false ) );
						if ( empty( $dropdown ) ) {
							continue;
							}
					 ?>
					<li>
						<label><span class="label-responsive"><?php echo esc_html( $tax->label )  . ':' ; ?></span>
						<?php echo $dropdown; ?>
						</label>
					</li>
					<?php endforeach; ?>
					<li>
						<label><span class="label-responsive"><?php _e( 'Authors:' ); ?></span>
						<?php
						$authors = $wpdb->get_col( $wpdb->prepare ( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );
						wp_dropdown_users( array(
							'include' => $authors,
							'name' => 'author',
							'multi' => true,
							'show_option_all' => __( 'All' ),
							'show' => 'display_name_with_login',
						) ); ?>
						</label>
					</li>
					<li>
						<fieldset>
							<legend class="screen-reader-text"><?php _e( 'Date range:' ); ?></legend>
							<label for="<?php echo $post_type; ?>-start-date" class="label-responsive"><?php _e( 'Start date:' ); ?></label>
							<select name="start_date" id="<?php echo $post_type; ?>-start-date">
								<option value="0"><?php _e( '&mdash; Select &mdash;' ); ?></option>
								<?php self::export_date_options( $post_type ); ?>
							</select>
							<label for="<?php echo $post_type; ?>-end-date" class="label-responsive"><?php _e( 'End date:' ); ?></label>
							<select name="end_date" id="<?php echo $post_type; ?>-end-date">
								<option value="0"><?php _e( '&mdash; Select &mdash;' ); ?></option>
								<?php self::export_date_options( $post_type ); ?>
							</select>
						</fieldset>
					</li>
					<?php
						$post_stati = self::get_used_post_stati( $post_type );
						if ( ! empty ( $post_stati ) ) :
					?>
					<li>
						<label for="<?php echo $post_type; ?>-status" class="label-responsive"><?php _e( 'Status:' ); ?></label>
						<select name="status" id="<?php echo $post_type; ?>-status">
							<option value="0"><?php _e( 'All' ); ?></option>
							<?php
							foreach ( $post_stati as $status ) : ?>
							<option value="<?php echo esc_attr( $status->name ); ?>"><?php echo esc_html( $status->label ); ?></option>
							<?php endforeach; ?>
						</select>
					</li>
						<?php endif; ?>
				</ul>
			</fieldset>
	<?php
	}

	/**
	 * Create the date options fields for exporting a given post type.
	 *
	 * @global wpdb      $wpdb      WordPress database abstraction object.
	 * @global WP_Locale $wp_locale Date and Time Locale object.
	 *
	 * @since 3.1.0
	 *
	 * @param string $post_type The post type. Default 'post'.
	 */
	static function export_date_options( $post_type = 'post' ) {
		global $wpdb, $wp_locale;

		$months = $wpdb->get_results( $wpdb->prepare( "
			SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
			FROM $wpdb->posts
			WHERE post_type = %s AND post_status != 'auto-draft'
			ORDER BY post_date DESC
		", $post_type ) );

		$month_count = count( $months );
		if ( !$month_count || ( 1 == $month_count && 0 == $months[0]->month ) )
			return;

		foreach ( $months as $date ) {
			if ( 0 == $date->year )
				continue;

			$month = zeroise( $date->month, 2 );
			echo '<option value="' . $date->year . '-' . $month . '">' . $wp_locale->get_month( $month ) . ' ' . $date->year . '</option>';
		}
	}

	static function get_used_post_stati( $post_type = 'post' ) {
		global $wpdb, $wp_post_statuses;

		$post_stati = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_status FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) ) as $status ) {
			if ( ! $wp_post_statuses[$status]->internal ) {
				$post_stati[] = $wp_post_statuses[$status];
			}
		}

		return $post_stati;
	}

	/**
	 * Display JavaScript on the page.
	 *
	 * @since 3.5.0
	 */
	static function export_add_js () {
 ?>
	<script type="text/javascript">
		jQuery(document).ready(function($){
	 		var form = $('#export-filters'),
	 			filters = form.find('.export-filters');
			// start with no controls active
	 		filters.find('input, select').attr('disabled', 'disabled');
	 		form.find('input:radio').change(function() {
				filters.slideUp('fast');
				// make sure only the controls for the current filter are active
	 	 		filters.find('input, select').attr('disabled', 'disabled');
				$(this).closest('fieldset').find( 'input, select' ).removeAttr( 'disabled' );
				// show the controls for the current filter
				$('#' + $(this).val() + '-filters').slideDown();
	 		});
		});
	</script>
<?php
	}
}
