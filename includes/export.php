<?php
/**
 * WordPress Export Administration API
 *
 * @package WordPress
 * @subpackage Administration
 */

require __DIR__ . '/../wp-includes/class-wxr-exporter.php';

/*
 * @todo Write more detailed documentation on what plugins are allowed/not allowed to do
 * when hooking into all the actions that allow them to add extension markup.  I think
 * handbook-level of detail is required.
 */

// @todo decide what the mime-type should be.
// http://www.rssboard.org/rss-profile recommends application/rss+xml
// the standard and redux importers use application/xml
// I think .org should register application/vnd.wordpress.wxr+xml with IANA
// @link https://github.com/humanmade/WordPress-Importer/issues/127
define( 'WXR_MIME_TYPE', 'application/xml' );

// @todo if/when .org registers application/vnd.wordpress.wxr+xml then the export file
// extension should change to '.wxr'
define( 'WXR_FILE_EXTENSION', '.xml' );

/**
 * Generates the WXR export file for download.
 *
 * Default behavior is to export all content, however, note that post content will only
 * be exported for post types with the `can_export` argument enabled. Any posts with the
 * 'auto-draft' status will be skipped.
 *
 * @since 2.1.0
 *
 * @global wpdb    $wpdb WordPress database abstraction object.
 * @global WP_Post $post Global `$post`.
 *
 * @param array $filters {
 *     Optional. Arguments for generating the WXR export file for download. Default empty array.
 *
 *     @type string $content        Type of content to export. If set, only the post content of this post type
 *                                  will be exported. Accepts 'all', 'post', 'page', 'attachment', or a defined
 *                                  custom post. If an invalid custom post type is supplied, every post type for
 *                                  which `$can_export` is enabled will be exported instead. If a valid custom post
 *                                  type is supplied but `can_export` is disabled, then 'posts' will be exported
 *                                  instead. When 'all' is supplied, only post types with `can_export` enabled will
 *                                  be exported. Default 'all'.
 *     @type string $author         Author to export content for. Only used when `$content` is 'post', 'page', or
 *                                  'attachment'. Accepts false (all) or a specific author ID. Default false (all).
 *     @type string $category       Category (slug) to export content for. Used only when `$content` is 'post'. If
 *                                  set, only post content assigned to `$category` will be exported. Accepts false
 *                                  or a specific category slug. Default is false (all categories).
 *     @type string $start_date     Start date to export content from. Expected date format is 'Y-m-d'. Used only
 *                                  when `$content` is 'post', 'page' or 'attachment'. Default false (since the
 *                                  beginning of time).
 *     @type string $end_date       End date to export content to. Expected date format is 'Y-m-d'. Used only when
 *                                  `$content` is 'post', 'page' or 'attachment'. Default false (latest publish date).
 *     @type string $status         Post status to export posts for. Used only when `$content` is 'post' or 'page'.
 *                                  Accepts false (all statuses except 'auto-draft'), or a specific status, i.e.
 *                                  'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', or
 *                                  'trash'. Default false (all statuses except 'auto-draft').
 * }
 */
function export_wp( $filters ) {
	/**
	 * Fires at the beginning of an export, before any headers are sent.
	 *
	 * @since 2.3.0
	 *
	 * @param array $filters An array of export $filters.
	 */
	do_action( 'export_wp', $filters );

	$exporter = new WXR_Exporter( $filters );

	$sitename = sanitize_key( get_bloginfo( 'name' ) );
	if ( ! empty( $sitename ) ) {
		$sitename .= '.';
	}
	$date = date( 'Y-m-d' );
	$wp_filename = $sitename . 'wordpress.' . $date . WXR_FILE_EXTENSION;
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
	header( 'Content-Type: ' . WXR_MIME_TYPE . '); charset=' . $exporter->get_encoding(), true );

	$exporter->export( 'php://output' );

	exit();
}
