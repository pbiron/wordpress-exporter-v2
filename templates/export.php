<?php
/**
 * Page for the actual export step.
 */

/*
 * Delete transients that may have been left over from a recent run.
 */
delete_transient( '_wxr_export_started' );
delete_transient( '_wxr_export_file' );
delete_transient( '_wxr_export_encoding' );

$filters = self::build_filters();

$exporter = $this->get_exporter( $filters );

$data = $exporter->get_counts();

$args = array(
	'action' => 'wxr-export',
	'filters'     => $filters,
);
$url = add_query_arg( urlencode_deep( $args ), admin_url( 'admin-ajax.php' ) );

$dataTables_language = plugins_url( '/assets/jquery.dataTables/languages/' . get_locale() . '.json', dirname( __FILE__ ) );
$script_data = array(
	'count' => array(
		'posts' => $data->post_count,
		'media' => $data->media_count,
		'users' => $data->user_count,
		'comments' => $data->comment_count,
		'terms' => $data->term_count,
		'links' => $data->link_count,
	),
	'url' => $url,
	'strings' => array(
		// status messages
		'complete' => __( 'Export complete!', 'wordpress-exporter' ),
		'downloaded' => __( 'Export file downloaded.', 'wordpress-exporter' ),
	),
	'dataTables_language' => $dataTables_language,
);

// neither IE10-11 nor Edge understand EventSource, so enqueue a polyfill
wp_enqueue_script( 'eventsource-polyfill', plugins_url( 'assets/eventsource-polyfill.js', dirname( __FILE__ ) ), array(), '20160909', true );

// DataTables allows the log messages to sorted/paginated
$suffix = defined ('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min' ;
wp_enqueue_script( 'jquery.dataTables', plugins_url( "assets/jquery.dataTables/jquery.dataTables$suffix.js", dirname( __FILE__ ) ), array( 'jquery' ), '20160909', true );
wp_enqueue_style( 'jquery.dataTables', plugins_url( 'assets/jquery.dataTables/jquery.dataTables.css', dirname( __FILE__ ) ), array(), '20160909' );

$url = plugins_url( 'assets/export.js',  dirname( __FILE__ ) );
wp_enqueue_script( 'wxr-exporter-export', $url, array( 'jquery' ), '20160909', true );
wp_localize_script( 'wxr-exporter-export', 'wxrExportData', $script_data );

wp_enqueue_style( 'wxr-exporter-export', plugins_url( 'assets/export.css', dirname( __FILE__ ) ), array(), '20160909' );

$this->render_header();
?>
<div class="welcome-panel">
	<div class="welcome-panel-content">
		<h2><?php esc_html_e( sprintf( 'Step %d: Exporting', $_REQUEST['step'] ), 'wordpress-exporter' ) ?></h2>
		<div id="export-status-message" class="notice notice-info"><?php esc_html_e( 'Now exporting.', 'wordpress-exporter' ) ?></div>

		<table class="export-status">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Export Summary', 'wordpress-exporter' ) ?></th>
					<th><?php esc_html_e( 'Completed', 'wordpress-exporter' ) ?></th>
					<th><?php esc_html_e( 'Progress', 'wordpress-exporter' ) ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>
						<span class="dashicons dashicons-admin-post"></span>
						<?php
						echo esc_html( sprintf(
							_n( '%d post (including CPTs)', '%d posts (including CPTs)', $data->post_count, 'wordpress-exporter' ),
							$data->post_count
						));
						?>
					</td>
					<td>
						<span id="completed-posts" class="completed">0/0</span>
					</td>
					<td>
						<progress id="progressbar-posts" max="100" value="0"></progress>
						<span id="progress-posts" class="progress">0%</span>
					</td>
				</tr>
				<tr>
					<td>
						<span class="dashicons dashicons-admin-media"></span>
						<?php
						echo esc_html( sprintf(
							_n( '%d media item', '%d media items', $data->media_count, 'wordpress-exporter' ),
							$data->media_count
						));
						?>
					</td>
					<td>
						<span id="completed-media" class="completed">0/0</span>
					</td>
					<td>
						<progress id="progressbar-media" max="100" value="0"></progress>
						<span id="progress-media" class="progress">0%</span>
					</td>
				</tr>

				<tr>
					<td>
						<span class="dashicons dashicons-admin-users"></span>
						<?php
						echo esc_html( sprintf(
							_n( '%d user', '%d users', $data->user_count, 'wordpress-exporter' ),
							$data->user_count
						));
						?>
					</td>
					<td>
						<span id="completed-users" class="completed">0/0</span>
					</td>
					<td>
						<progress id="progressbar-users" max="100" value="0"></progress>
						<span id="progress-users" class="progress">0%</span>
					</td>
				</tr>

				<tr>
					<td>
						<span class="dashicons dashicons-admin-comments"></span>
						<?php
						echo esc_html( sprintf(
							_n( '%d comment', '%d comments', $data->comment_count, 'wordpress-exporter' ),
							$data->comment_count
						));
						?>
					</td>
					<td>
						<span id="completed-comments" class="completed">0/0</span>
					</td>
					<td>
						<progress id="progressbar-comments" max="100" value="0"></progress>
						<span id="progress-comments" class="progress">0%</span>
					</td>
				</tr>

				<tr>
					<td>
						<span class="dashicons dashicons-category"></span>
						<?php
						echo esc_html( sprintf(
							_n( '%d term', '%d terms', $data->term_count, 'wordpress-exporter' ),
							$data->term_count
						));
						?>
					</td>
					<td>
						<span id="completed-terms" class="completed">0/0</span>
					</td>
					<td>
						<progress id="progressbar-terms" max="100" value="0"></progress>
						<span id="progress-terms" class="progress">0%</span>
					</td>
				</tr>
				<tr>
					<td>
						<span class="dashicons dashicons-admin-links"></span>
						<?php
						echo esc_html( sprintf(
							_n( '%d link', '%d links', $data->link_count, 'wordpress-exporter' ),
							$data->link_count
						));
						?>
					</td>
					<td>
						<span id="completed-links" class="completed">0/0</span>
					</td>
					<td>
						<progress id="progressbar-links" max="100" value="0"></progress>
						<span id="progress-links" class="progress">0%</span>
					</td>
				</tr>
			</tbody>
		</table>

		<div class="export-status-indicator">
			<div class="progress">
				<progress id="progressbar-total" max="100" value="0"></progress>
			</div>
			<div class="status">
				<span id="completed-total" class="completed">0/0</span>
				<span id="progress-total" class="progress">0%</span>
			</div>
		</div>
	</div>
</div>

<form method='POST' action='<?php echo $this->get_url( 2 ) ?>' id='download'>
	<?php wp_nonce_field( 'export-download' ); ?>
	<?php submit_button( __('Download Export File') ); ?>
</form>

<table id="export-log" class="widefat">
	<thead>
		<tr>
			<th class='level'><?php esc_html_e( 'Level', 'wordpress-exporter' ) ?></th>
			<!-- th class='type'><?php esc_html_e( 'Type', 'wordpress-exporter' ) ?></th-->
			<th><?php esc_html_e( 'Message', 'wordpress-exporter' ) ?></th>
		</tr>
	</thead>
	<tbody>
	</tbody>
</table>
<?php

$this->render_footer();
