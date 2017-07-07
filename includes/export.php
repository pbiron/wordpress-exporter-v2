<?php
/**
 * WordPress Export Administration API
 *
 * @package WordPress
 * @subpackage Administration
 *
 * Rewritten from /wp-admin/includes/export.php to:
 *
 *    1. use XMLWriter (instead of a series of echo's)
 *    2. use the proposed WXR 1.3 markup at https://github.com/pbiron/wxr/1.3-proposed
 *    3. contains hooks that allow plugins to add extension markup to the WXR instance
 *
 * @author pbiron
 */

/*
 * the structure/style of this file is pretty close to the standard /wp-admin/includes/export.php,
 * other than using XMLWriter rather than echo's, a few more "helper" functions, additional hooks for plugins
 * to add markup to the export, and bringing things up to the current PHP Coding Standards.
 *
 * @todo I'm not a big fan of the structure/style, but I've kept it in this form for the initial release
 * to make it easier to compare/constrast the two.  If the general improvements in the functionality
 * get positive feedback, then I'll work on a rewrite of the structure/style, including ideas
 * from https://core.trac.wordpress.org/ticket/22435.
 */

/*
 * @todo create a WP XML API for reading/writing XML instances.  Such an XML API:
 *
 *  1. could be used by not only by the exporter, but also the RSS/Atom feeds;
 *  2. should be streamable (i.e., NOT require the entire instance to be in-memory);
 *  3. can probably be a light-weight wrapper around PHP's XMLReader and XMLWriter classes
 *     (which are streamable, whereas PHP's SimpleXML and DOMDocument classes are not);
 *  4. should manage in-scope XML Namespace bindings (one of the problems with XMLWriter
 *     out-of-the-box is that it does not, @link https://bugs.php.net/bug.php?id=74491)
 *     to avoid serializing redundant/needless namespace decls;
 *  5. should accept EQName's for element and attribute names
 *     (@link https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName);
 *  6. should "intelligently" deal with characters that are outside the range of
 *     characters allowed by the Char production in the XML Spec
 *     (@link https://www.w3.org/TR/xml/#NT-Char).  "intelligently", in this context,
 *     means that I'm not sure just stripping them is the correct thing, but introducing
 *     a WP-specific way of encoding them is also not a good idea (for example, would
 *     other CMS's importing a WXR instance know what to do with them?).  Note that
 *     XMLWriter "unintelligently" deals with the null character ('\\\\0') since it
 *     is just a wrapper around the XMLWriter from libxml which is written in C and,
 *     hence, when a PHP string containing the null character is passed to
 *     XMLWriter::text() it not only strips the null character by it terminates
 *     the string at that point :-(
 *
 * I had started on such an XML API as part of this plugin but then decided to punt
 * on that until getting high-level feedback on the general idea.  Hence, the code
 * in this plugin currently just makes direct calls to XMLWriter methods.
 */

/*
 * @todo for the initial release, we'll let XMLWriter decide what to escape, rather than using CDATA sections
 * for everything like the standard exporter does.  Using CDATA sections for EVERYTHING is overkill and
 * results in instances that are most likey larger than they need to be.  However, it MIGHT be useful
 * for the XML API to selectively use CDATA sections.  For example, post_content, comment_content and
 * meta_value that contain markup (or other characters that XMLWriter over aggressively encodes, such
 * as &quot;) would be smaller using CDATA sections.  Additionally, using CDATA sections in such casees
 * MIGHT be more "human-friendly"...even though the primary purpose of WXR is machine processing.
 * In ad-hoc tests I've done, selectively outputing CDATA sections could result in an additional
 * 10% reduction in file sizes.  It remains to be seen whether that level of savings is worth the
 * trouble.
 */

/*
 * @todo Write more detailed documentation on what plugins are allowed/not allowed to do
 * when hooking into all the actions that allow them to add extension markup.  I think
 * handbook-level of detail is required.
 */

 /*
 * Note: functions and hooks that I've introduced are indicated with "@since x.y.z".
 */

/*
 * There are MANY @todo's sprinkled through out this source.  Some of them are actually
 * "issues" rather than tasks.  Once I release this on GitHub, I'll convert those to true
 * GitHub Issues.  I did them as @todo's because I need some place to store then prior
 * to get this up on GitHub.
 */

/**
 * Version number for the export format.
 *
 * Bump this when something changes that might affect compatibility.
 *
 * @since 2.5.0
 */
define( 'WXR_VERSION', '1.3' );

define( 'RSS_VERSION', '2.0' );

// define constants for the various namespace URIs we use
define( 'WXR_NAMESPACE_URI', 'http://wordpress.org/export/' );
define( 'DUBLIN_CORE_NAMESPACE_URI', 'http://purl.org/dc/elements/1.1/' );
define( 'RSS_CONTENT_NAMESPACE_URI', 'http://purl.org/rss/1.0/modules/content/' );

// define constants for the various namespace prefixes we use
define( 'WXR_PREFIX', 'wxr' );
define( 'DUBLIN_CORE_PREFIX', 'dc' );
define( 'RSS_CONTENT_PREFIX', 'content' );

// @todo decide what the mime-type should be.
// http://www.rssboard.org/rss-profile recommends application/rss+xml
// the standard and redux importers use application/xml
// I think WP should register application/vnd.wordpress.wxr+xml with IANA
// @link https://github.com/humanmade/WordPress-Importer/issues/127
define( 'WXR_MIME_TYPE', 'application/xml' );

// @todo if/when WP registers application/vnd.wordpress.wxr+xml then the export file
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
 * @param array $args {
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
function export_wp( $args ) {
	/**
	 * Output list of authors with posts
	 *
	 * @since 3.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $post_ids Array of post IDs to filter the query by. Optional.
	 */
	function wxr_authors_list( $writer, array $post_ids = null ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			// The standard exporter exports ALL users if $post_ids is empty.
			// I think that's a bug, so we don't export ANY users in this case.
			//
			// @todo open a trac ticket on this issue, even if the other modifications
			// in this plugin do not get picked up by core.
			return;
		}

		$post_ids = array_map( 'absint', $post_ids );
		$and = 'AND ID IN ( ' . implode( ', ', $post_ids ) . ')';

		$authors = array();
		$results = $wpdb->get_results( "SELECT DISTINCT post_author FROM $wpdb->posts WHERE post_status != 'auto-draft' $and" );
		foreach ( (array) $results as $result )
			$authors[] = get_userdata( $result->post_author );

		$authors = array_filter( $authors );

		foreach ( $authors as $author ) {
			wxr_write_user( $writer, $author );
		}
	}

	/**
	 * Output list of metas for a post comment
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_Comment $comment
	 */
	function wxr_comment_metas_list( $writer, $comment ) {
		global $wpdb;

		$c_meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->commentmeta WHERE comment_id = %d", $comment->comment_ID ) );
		foreach ( $c_meta as $meta ) {
			/**
			 * Filters whether to selectively skip comment meta used for WXR exports.
			 *
			 * Returning a truthy value to the filter will skip the current meta
			 * object from being exported.
			 *
			 * @since 4.0.0
			 *
			 * @param bool   $skip     Whether to skip the current comment meta. Default false.
			 * @param string $meta_key Current meta key.
			 * @param object $meta     Current meta object.
			 */
			if ( apply_filters( 'wxr_export_skip_commentmeta', false, $meta->meta_key, $meta ) ) {
				continue;
			}

			wxr_write_meta( $writer, $meta, 'comment' );
		}
	}

	/**
	 * Output list of comments for a post
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_Post $post
	 */
	function wxr_post_comments_list( $writer, $post ) {
		global $wpdb;

		$_comments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_approved <> 'spam'", $post->ID ) );
		$comments = array_map( 'get_comment', $_comments );

		foreach ( $comments as $comment ) {
			wxr_write_comment( $writer, $comment );
		}
	}

	/**
	 * Output list of metas a post
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_Post $post Post object.
	 */
	function wxr_post_metas_list( $writer, $post ) {
		global $wpdb;

		$postmeta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->postmeta WHERE post_id = %d", $post->ID ) );
		foreach ( $postmeta as $meta ) {
			/**
			 * Filters whether to selectively skip post meta used for WXR exports.
			 *
			 * Returning a truthy value to the filter will skip the current meta
			 * object from being exported.
			 *
			 * @since 3.3.0
			 *
			 * @param bool   $skip     Whether to skip the current post meta. Default false.
			 * @param string $meta_key Current meta key.
			 * @param object $meta     Current meta object.
			 */
			if ( apply_filters( 'wxr_export_skip_postmeta', false, $meta->meta_key, $meta ) ) {
				continue;
			}

			wxr_write_meta( $writer, $meta, 'post' );
		}
	}

	/**
	 * Output list of terms associated with a post
	 *
	 * @since 2.3.0
	 * @since x.y.z Function name changed to wxr_post_terms_list().
	 * @since x.y.z Added $post parameter.
	 *
	 * @param XMLWriter $writer
	 * @param WP_Post $post
	 */
	function wxr_post_terms_list( $writer, $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		if ( empty( $taxonomies ) ) {
			return;
		}

		$terms = wp_get_object_terms( $post->ID, $taxonomies );

		foreach ( (array) $terms as $term ) {
			wxr_write_post_term( $writer, $term );
		}
	}

	/**
	 * Output list of posts
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param array $post_ids Post IDs to export.
	 */
	function wxr_posts_list( $writer, $post_ids ) {
		if ( empty( $post_ids ) ) {
			return;
		}

		/**
		 * @global WP_Query $wp_query
		 * @global wpdb $wpdb
		 * @global WP_Post $post
		 */
		global $wp_query, $wpdb, $post;

		// Fake being in the loop.
		$wp_query->in_the_loop = true;

		// Fetch 20 posts at a time rather than loading the entire table into memory.
		while ( $next_posts = array_splice( $post_ids, 0, 20 ) ) {
			$where = 'WHERE ID IN (' . join( ',', $next_posts ) . ')';
			$posts = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} $where" );

			// Begin Loop.
			foreach ( $posts as $post ) {
				wxr_write_post( $writer, $post );
			}

			// @todo convenient place for a flush.  Figure out a good, general flush
			// strategy for large exports
			$writer->flush();
		}
	}

	/**
	 * Output list of terms
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param array $term_ids
	 */
	function wxr_terms_list( $writer, $term_ids ) {
		/**
		 * @global wpdb $wpdb
		 */
		global $wpdb;

		if ( empty( $term_ids ) ) {
			return;
		}

		$_term_ids = array();

		foreach( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( 0 !== $term->parent && ! in_array( $term->parent, $term_ids ) ) {
				// add the term's parent if not already included in $term_ids.
				// this can happen when exporting only posts of a specific post_type
				// where a term in $term_ids has a parent that is only used in
				// posts of a post_type that is not being exported.
				// @todo open a trac ticket to fix that aspect of the standard exporter, even if
				// this rewrite doesn't get merged into core
				$term_ids[] = $term->parent;
			}
		}

		// Put terms in order with no child going before its parent.
		// @todo is this really necessary?  The standard importer doesn't
		// need it because it loads the entire WXR into memory and can find
		// the parent even if it occurs after the child;
		// neither does https://github.com/pbiron/WordPress-Importer because
		// even tho it is a streaming importer it contains logic to deal
		// with parents that occur after children.  But it is part of the
		// standard export so I'm leaving it for now.
		while ( $term_id = array_shift( $term_ids ) ) {
			$term = get_term( $term_id );
			if ( 0 === $term->parent || in_array( $term->parent, $_term_ids ) ) {
				$_term_ids[] = $term_id;
			}
			else {
				$term_ids[] = $term_id;
			}
		}

		// Fetch 20 terms at a time rather than loading the entire table into memory.
		// @todo optimize the chunk size: 20 works well for posts, what's best for terms?
		while ( $next_terms = array_splice( $_term_ids, 0, 20 ) ) {
			$terms = array_map( 'get_term', $next_terms );

			// Begin Loop.
			foreach ( $terms as $term ) {
				if ( 0 !== $term->parent ) {
					// get the parent slug
					$parent = get_term( $term->parent );
					$term->parent = $parent->slug;
				}
				wxr_write_term( $writer, $term );
			}

			// @todo convenient place for a flush.  Figure out a good, general flush
			// strategy for large exports
			$writer->flush();
		}
	}

	/**
	 * Output list of metas term
	 *
	 * @since 4.6.0
	 * @since x.y.z Function name changed to wxr_term_metas_list().
	 *
	 * @param XMLWriter $writer
	 * @param WP_Term $term Term object.
	 */
	function wxr_term_metas_list( $writer, $term ) {
		global $wpdb;

		$termmeta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->termmeta WHERE term_id = %d", $term->term_id ) );

		foreach ( $termmeta as $meta ) {
			/**
			 * Filters whether to selectively skip term meta used for WXR exports.
			 *
			 * Returning a truthy value to the filter will skip the current meta
			 * object from being exported.
			 *
			 * @since 4.6.0
			 *
			 * @param bool   $skip     Whether to skip the current piece of term meta. Default false.
			 * @param string $meta_key Current meta key.
			 * @param object $meta     Current meta object.
			 */
			if ( ! apply_filters( 'wxr_export_skip_termmeta', false, $meta->meta_key, $meta ) ) {
				wxr_write_meta( $writer, $meta, 'term' );
			}
		}
	}

	/**
	 * Output list of metas for a user.
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_User $user User object.
	 */
	function wxr_user_metas_list( $writer, $user ) {
		global $wpdb;

		$usermeta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->usermeta WHERE user_id = %d", $user->ID ) );

		foreach ( $usermeta as $meta ) {
			/**
			 * Filters whether to selectively skip user meta used for WXR exports.
			 *
			 * Returning a truthy value to the filter will skip the current meta
			 * object from being exported.
			 *
			 * @since z.y.z
			 *
			 * @param bool   $skip     Whether to skip the current piece of term meta. Default true.
			 *                         Note that the default here is opposite of the other
			 *                         wxr_export_skip_xxxmeta filters to mimic the
			 *                         behavior of the standard export that user/author meta
			 *                         is not exported at all.
			 * @param string $meta_key Current meta key.
			 * @param object $meta     Current meta object.
			 */
			if ( ! apply_filters( 'wxr_export_skip_usermeta', true, $meta->meta_key, $meta ) ) {
				wxr_write_meta( $writer, $meta, 'user' );
			}
		}
	}

	/**
	 * Write WXR markup for a post comment
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_Comment $comment
	 */
	function wxr_write_comment( $writer, $comment ) {
		$writer->startElementNS( WXR_PREFIX, 'comment', null );

		/**
		 * Allow extension markup to be added to a comment.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param XMLWriter $writer The XMLWriter instance to write to.
		 * @param WP_Comment $c The comment.
		 *
		 * @todo should we also pass the WP_Post object as well?
		 */
		do_action( 'wxr_export_comment', $writer, $comment );

		$writer->writeElementNS( WXR_PREFIX, 'id', null, intval( $comment->comment_ID ) );
		$writer->writeElementNS( WXR_PREFIX, 'author', null, $comment->comment_author );
		$writer->writeElementNS( WXR_PREFIX, 'author_email', null, $comment->comment_author_email );
		$writer->writeElementNS( WXR_PREFIX, 'author_url', null, $comment->comment_author_url );
		$writer->writeElementNS( WXR_PREFIX, 'author_IP', null, $comment->comment_author_IP );
		$writer->writeElementNS( WXR_PREFIX, 'date', null, $comment->comment_date );
		$writer->writeElementNS( WXR_PREFIX, 'date_gmt', null, $comment->comment_date_gmt );
		$writer->writeElementNS( WXR_PREFIX, 'content', null, $comment->comment_content );
		$writer->writeElementNS( WXR_PREFIX, 'approved', null, $comment->comment_approved );
		$writer->writeElementNS( WXR_PREFIX, 'type', null, $comment->comment_type );
		$writer->writeElementNS( WXR_PREFIX, 'parent', null, $comment->comment_parent );
		$writer->writeElementNS( WXR_PREFIX, 'user_id', null, intval( $comment->user_id ) );

		wxr_comment_metas_list( $writer, $comment );

		$writer->endElement();
	}

	/**
	 * Write WXR markup for a meta
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer The XMLWriter to write to.
	 * @param object $meta The meta to write.
	 * @param string $type The type of meta ('post', 'comment', 'user', 'term').
	 */
	function wxr_write_meta( $writer, $meta, $type ) {
		$writer->startElementNS( WXR_PREFIX, 'meta', null );

		/**
		 * Allow plugins to add extension markup to a meta.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param XMLWriter $writer The XMLWriter instance.
		 * @param object $meta {
		 *     @type string $meta_key The meta key.
		 *     @type string $meta_value The meta value.
		 * }
		 * @param string $type The type of meta (e.g. 'post', 'user', 'term', 'comment').
		 */
		do_action( 'wxr_export_meta', $writer, $meta, $type );

		$writer->writeElementNS( WXR_PREFIX, 'key', null, $meta->meta_key );
		$writer->writeElementNS( WXR_PREFIX, 'value', null, $meta->meta_value );

		$writer->endElement();
	}

	/**
	 * Write WXR markup for a post
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_Post $post
	 *
	 * @todo pass $post in.  Need to change calls to get_the_permalink(), etc to
	 * not rely on global $post
	 */
	function wxr_write_post( $writer ) {
		/**
		 * @global WP_Post $post
		 */

		$post = get_post();
		setup_postdata( $post );
		$is_sticky = is_sticky( $post->ID ) ? 1 : 0;

		$writer->startElement( 'item' );

		/**
		 * Allow plugins to add extension markup to an exported post.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param XMLWriter $writer The XMLWriter instance to write to.
		 * @param WP_Post $post The post.
		 *
		 * @todo should we also pass the WP_Post object as well?
		 */
		do_action( 'wxr_export_post', $writer, $post );

		/** This filter is documented in wp-includes/feed.php */
		$title = apply_filters( 'the_title_rss', $post->post_title );
		$writer->writeElement( 'title', $title );
		/** This filter is documented in wp-includes/feed.php */
		$link = esc_url( apply_filters( 'the_permalink_rss', get_permalink() ) );
		$writer->writeElement( 'link', $link );

		$writer->startElement( 'guid' );

		$writer->writeAttribute( 'isPermaLink', 'false' );
		/** This filter is documented in wp-includes/post-template.php */
		$permalink = apply_filters( 'the_guid', get_the_guid (), $post->ID );
		$writer->text( $permalink );

		$writer->endElement();// guid

		/**
		 * Filters the post excerpt used for WXR exports.
		 *
		 * @since 2.6.0
		 *
		 * @param string $post_excerpt Excerpt for the current post.
		 */
		$excerpt = apply_filters( 'the_excerpt_export', $post->post_excerpt );
		$writer->writeElement( 'description', $excerpt );

		$writer->writeElementNS( DUBLIN_CORE_PREFIX, 'creator', null, get_the_author_meta( 'login' ) );

		/**
		 * Filters the post content used for WXR exports.
		 *
		 * @since 2.5.0
		 *
		 * @param string $post_content Content of the current post.
		 */
		$content = apply_filters( 'the_content_export', $post->post_content );
		$writer->writeElementNS( RSS_CONTENT_PREFIX, 'encoded', null, $content );

		$writer->writeElementNS( WXR_PREFIX, 'id', null, intval( $post->ID ) );
		$writer->writeElementNS( WXR_PREFIX, 'date', null, $post->post_date );
		$writer->writeElementNS( WXR_PREFIX, 'date_gmt', null, $post->post_date_gmt );
		$writer->writeElementNS( WXR_PREFIX, 'comment_status', null, $post->comment_status );
		$writer->writeElementNS( WXR_PREFIX, 'ping_status', null, $post->ping_status );
		$writer->writeElementNS( WXR_PREFIX, 'name', null, $post->post_name );
		$writer->writeElementNS( WXR_PREFIX, 'status', null, $post->post_status );
		$writer->writeElementNS( WXR_PREFIX, 'parent', null, $post->post_parent );
		$writer->writeElementNS( WXR_PREFIX, 'menu_order', null, $post->menu_order );
		$writer->writeElementNS( WXR_PREFIX, 'type', null, $post->post_type );
		$writer->writeElementNS( WXR_PREFIX, 'password', null, $post->post_password );
		$writer->writeElementNS( WXR_PREFIX, 'is_sticky', null, intval( $is_sticky ) );

		if ( 'attachment' === $post->post_type ) {
			$writer->writeElementNS( WXR_PREFIX, 'attachment_url', null, wp_get_attachment_url( $post->ID ) );
		}

		wxr_post_terms_list( $writer, $post );
		wxr_post_comments_list( $writer, $post );
		wxr_post_metas_list( $writer, $post );

		$writer->endElement(); // item
	}

	/**
	 * Writer WXR markup for a term assigned to a post
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_Term $term
	 */
	function wxr_write_post_term( $writer, $term ) {
		$writer->startElement( 'category' );

		$writer->writeAttribute( 'domain', $term->taxonomy );
		$writer->writeAttributeNS( WXR_PREFIX, 'slug', null, $term->slug );

		/**
		 * Allow extension markup to be added to a term attached to a post.
		 *
		 * Functions hooked to this action MUST output only attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output any child elements, nor attributes in the empty namespace
		 * nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param XMLWriter $writer The XMLWriter instance to write to.
		 * @param WP_Term $term The term.
		 */
		do_action( 'wxr_export_post_term', $writer, $term );

		$writer->text( $term->name );

		$writer->endElement();
	}

	/**
	 * Write WXR markup for a term
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_Term $term
	 */
	function wxr_write_term( $writer, $term )
	{
		$writer->startElementNS( WXR_PREFIX, 'term', null );

		/**
		 * Allow extension markup to a added to an exported term.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param XMLWriter $writer The XMLWriter instance to write to.
		 * @param WP_Term $term The term.
		 */
		do_action( 'wxr_export_term', $writer, $term );

		$writer->writeElementNS( WXR_PREFIX, 'id', null, intval( $term->term_id ) );
		$writer->writeElementNS( WXR_PREFIX, 'name', null, $term->name );
		$writer->writeElementNS( WXR_PREFIX, 'slug', null, $term->slug );
		$writer->writeElementNS( WXR_PREFIX, 'taxonomy', null, $term->taxonomy );

		if ( $term->parent ) {
			$writer->writeElementNS( WXR_PREFIX, 'parent', null, $term->parent );
		}
		if ( ! empty( $term->description ) ) {
			$writer->writeElementNS( WXR_PREFIX, 'description', null, $term->description );
		}

		wxr_term_metas_list( $writer, $term );

		$writer->endElement();
	}

	/**
	 * Writer WXR markup for a user
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer
	 * @param WP_User $user
	 */
	function wxr_write_user( $writer, $user ) {
		$writer->startElementNS( WXR_PREFIX, 'user', null );

		/**
		 * Allow extension markup to be added to an exported user.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param XMLWriter $writer The XMLWriter instance to write to.
		 * @param WP_User $user The user.
		 */
		do_action( 'wxr_export_user', $writer, $user );

		$writer->writeElementNS( WXR_PREFIX, 'id', null, intval( $user->ID ) );
		$writer->writeElementNS( WXR_PREFIX, 'login', null, $user->user_login );
		$writer->writeElementNS( WXR_PREFIX, 'email', null, $user->user_email );
		$writer->writeElementNS( WXR_PREFIX, 'display_name', null, $user->display_name );
		$writer->writeElementNS( WXR_PREFIX, 'first_name', null, $user->first_name );
		$writer->writeElementNS( WXR_PREFIX, 'last_name', null, $user->last_name );

		wxr_user_metas_list( $writer, $user );

		$writer->endElement();
	}

	/**
	 * Guarantee that a namespace prefix is unique
	 *
	 * @since x.y.z
	 *
	 * @param string $prefix
	 * @param string $namespaceURI
	 * @return string
	 */
	function wxr_unique_prefix( $prefix, $namespaceURI ) {
		static $prefixes = array( WXR_PREFIX, RSS_CONTENT_PREFIX, DUBLIN_CORE_PREFIX );

 		$int = 0;
 		$orig_prefix = $prefix;

		while ( in_array( $prefix, $prefixes ) ) {
 			$prefix = sprintf( "%s%d", $orig_prefix, $int++ );
		}

		$prefixes[] = $prefix;

		/**
		 * Notify plugin that it's preferred prefix has been altered, so that
		 * it can use the altered prefix when it writes extension elements as
		 * part of the export.
		 *
		 * The dynamic portion of the hook is the namespace URI for the prefix.
		 *
		 * @since x.y.z
		 *
		 * @param string $prefix The uniqueified prefix.
		 */
		do_action( "wxr_unique_prefix_{$namespaceURI}", $prefix );

		return $prefix;
	}

	/**
	 *
	 * @since ? This function is in the standard exporter but has no @since tag.
	 *
	 * @param bool   $return_me
	 * @param string $meta_key
	 * @return bool
	 */
	function wxr_filter_postmeta( $return_me, $meta_key ) {
		if ( '_edit_lock' == $meta_key ) {
			$return_me = true;
		}
		return $return_me;
	}
	add_filter( 'wxr_export_skip_postmeta', 'wxr_filter_postmeta', 10, 2 );

	/**
	 * Do not allow 'first_name' and 'last_name' user metas to be output
	 * since they are already output with the <wxr:user/> element.
	 *
	 * @since x.y.z
	 *
	 * @param bool   $return_me
	 * @param string $meta_key
	 * @return bool
	 */
	function wxr_filter_usermeta( $return_me, $meta_key ) {
		if ( in_array( $meta_key, array( 'first_name', 'last_name' ) ) ) {
			$return_me = true;
		}
		return $return_me;
	}
	add_filter( 'wxr_export_skip_usermeta', 'wxr_filter_usermeta', 10, 2 );

	/*
	 * from here down is the actual "meat" of export_wp()
	 */

	/**
	 * @global wpdb    $wpdb WordPress database abstraction object.
	 * @global WP_Post $post Global `$post`.
	 */
	global $wpdb, $post;

	$defaults = array( 'content' => 'all', 'author' => false, 'category' => false,
		'start_date' => false, 'end_date' => false, 'status' => false,
	);
	$args = wp_parse_args( $args, $defaults );

	/**
	 * Fires at the beginning of an export, before any headers are sent.
	 *
	 * @since 2.3.0
	 *
	 * @param array $args An array of export arguments.
	 */
	do_action( 'export_wp', $args );

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

	// @todo verify that all MySQL charset's OTHER than utf16 are subsets
	// of UTF-8 since UTF-8 and UTF-16 are the ONLY encodings that XML
	// processors are required to consume/produce
	$encoding = strtoupper ( get_bloginfo( 'charset' ) ) === 'UTF16' ? 'UTF-16' : 'UTF-8';
	header( 'Content-Description: File Transfer' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	header( 'Content-Type: ' . WXR_MIME_TYPE . '); charset=' . $encoding, true );

	// unlike the standard exporter, when 'all' != $args['content'], we do NOT assume
	// that $args['content'] is a post_type: a plugin could have hooked into 'export_filters'
	// to allow exporting of just taxonomies, users, etc.  In that case, the plugin would also
	// need to hook into the new 'wxr_export_rss_channel_elements' actions to do the actual
	// exporting.
	$where = $post_ids = $ptype = null;
	if ( 'all' != $args['content'] && post_type_exists( $args['content'] ) ) {
		$ptype = get_post_type_object( $args['content'] );
		if ( ! $ptype->can_export )
			$args['content'] = 'post';

		$where = $wpdb->prepare( "{$wpdb->posts}.post_type = %s", $args['content'] );
	} elseif ( 'all' === $args['content'] ) {
		$post_types = get_post_types( array( 'can_export' => true ) );
		$esses = array_fill( 0, count($post_types), '%s' );
		$where = $wpdb->prepare( "{$wpdb->posts}.post_type IN (" . implode( ',', $esses ) . ')', $post_types );
	}

	if ( $args['status'] && ( 'post' == $args['content'] || 'page' == $args['content'] ) ) {
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_status = %s", $args['status'] );
	}
 	elseif ( ! empty( $where ) ) {
 		$where .= " AND {$wpdb->posts}.post_status != 'auto-draft'";
 	}

	$join = '';
	if ( $args['category'] && 'post' == $args['content'] ) {
		if ( $term = term_exists( $args['category'], 'category' ) ) {
			$join = "INNER JOIN {$wpdb->term_relationships} ON ({$wpdb->posts}.ID = {$wpdb->term_relationships}.object_id)";
			$where .= $wpdb->prepare( " AND {$wpdb->term_relationships}.term_taxonomy_id = %d", $term['term_taxonomy_id'] );
		}
	}

	if ( 'post' == $args['content'] || 'page' == $args['content'] || 'attachment' == $args['content'] ) {
		if ( $args['author'] )
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_author = %d", $args['author'] );

		if ( $args['start_date'] )
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_date >= %s", date( 'Y-m-d', strtotime($args['start_date']) ) );

		if ( $args['end_date'] )
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_date < %s", date( 'Y-m-d', strtotime('+1 month', strtotime($args['end_date'])) ) );
	}

	if ( ! empty( $where ) ) {
		// Grab a snapshot of post IDs, just in case it changes during the export.
		$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} $join WHERE $where" );
		// @todo shouldn't post parent's be added to this list if they are not already
		// there?  For example,
	}

	/*
	 * Get the requested terms ready, empty unless posts filtered by category
	 * or all content.
	 */
	$cats = $tags = $terms = array();
	if ( isset( $term ) && $term ) {
		$term_ids = array( $term->term_id );
		unset( $term );
	}
	elseif ( !empty( $ptype ) && ! empty( $post_ids ) ) {
		// when exporting posts from a single post_type, the standard exporter does not
		// export terms attached to those posts (altho it does output the /rss/channel/item/category
		// elements).  I consider that a bug because without this you don't get
		// term parents, descriptions & metas, etc.
		// @todo open a trac ticket to fix that aspect of the standard exporter, even if
		// this rewrite doesn't get merged into core
		$term_ids = get_terms( array( 'fields' => 'ids', 'object_ids' => $post_ids, 'get' => 'all' ) );
		unset( $ptype );
	}
	elseif ( 'all' == $args['content'] ) {
 		// unlike the standard exporter, we also export terms in the 'post_format'
 		// taxonomy (makes it easier on the importer).
 		// We also include 'nav_menu' here so there is no need to have extra logic
 		// elsewhere to output those terms.
		$builtin_taxonomies = array( 'post_tag', 'category', 'post_format', 'nav_menu' );
		$custom_taxonomies = get_taxonomies( array( '_builtin' => false ) );
 		$taxonomies = array_merge( $builtin_taxonomies, $custom_taxonomies );
 		$term_ids = (array) get_terms( $taxonomies, array( 'fields' => 'ids', 'get' => 'all' ) );

 		// @tod while it would be pretty expensive, wouldn't it also be a good idea to
 		// cycle through $post_ids and see if there are any terms assigned to
 		// posts that will be exported that are not included above?  How likely is it
 		// that a plugin/theme allowed someone to assign a term from a builtin tax (other
 		// than post_tag/category/post_format) to posts?

 		unset( $builtin_taxonomies, $custom_taxonomies, $taxonomies );
	}

	// initialize the XMLWriter
	$writer = new XMLWriter();
	$writer->openUri( 'php://output' );
	$writer->setIndent( true );
	$writer->setIndentString( "\t" );

	$writer->startDocument( '1.0', $encoding );

	$writer->startComment();

	$writer->text( '
	This is a WordPress eXtended RSS file generated by WordPress as an export of your site.
	It contains information about your site\'s posts, pages, comments, categories, and other content.
	You may use this file to transfer that content from one site to another.
	This file is not intended to serve as a complete backup of your site.
	To import this information into a WordPress site follow these steps:
	1. Log in to that site as an administrator.
	2. Go to Tools: Import in the WordPress admin panel.
	3. Install the "WordPress" importer from the list.
	4. Activate & Run Importer.
	5. Upload this file using the form provided on that page.
	6. You will first be asked to map the authors in this export file to users
	   on the site. For each author, you may choose to map to an
	   existing user on the site or to create a new user.
	7. WordPress will then import each of the posts, pages, comments, categories, etc.
	   contained in this file into your site.
	');

	$writer->endComment();

	$writer->startElement( 'rss' );

	$writer->writeAttribute( 'version', RSS_VERSION );

	// write namespace decls
	$writer->writeAttribute( 'xmlns:wxr', WXR_NAMESPACE_URI );
	$writer->writeAttribute( 'xmlns:dc', DUBLIN_CORE_NAMESPACE_URI );
	$writer->writeAttribute( 'xmlns:content', RSS_CONTENT_NAMESPACE_URI );

	/**
	 * Plugins that expect to output extension elements.  The importer can use
	 * the plugin's $slug & $url to warn users who perform an import that some
	 * information in the export won't be imported unless these plugins are installed and
	 * activated.
	 *
	 * @since x.y.z
	 *
	 * @todo The $plugins param is actually an array of the hash below.  As far as I know,
	 * there is no convention for this in the PHP Documentation Standards
	 * (https://make.wordpress.org/core/handbook/best-practices/inline-documentation-standards/php/#1-1-parameters-that-are-arrays)

	 * @param array $plugins {
	 *     @type string $prefix Our "preferred" namespace prefix.
	 *     @type string $namespaceURI The namespaceURI for our extension elements/attributes.
	 *     @type string $slug The "file path" for our plugin (i.e., the $plugin parameter to
	 *                        activate_plugin()).  The "new" importer will eventually be able
	 *                        to use this (and `$url`) to inform users peforming an import
	 *                        that unless this plugin is installed/activated, then some information
	 *                        in the WXR instance they are importing will not actually
	 *                        be imported.
	 *    @type string $url The URL from which our plugin can be downloaded if it is not already
	 *                      installed.
	 * }
	 */
	$plugins = apply_filters( 'wxr_export_plugins', array() );

	$plugins_attr = array();
	foreach ( $plugins as $plugin ) {
		if ( ! isset( $plugin['namespaceURI'] ) ||
				in_array( $plugin['namespaceURI'], array( '', WXR_NAMESPACE_URI ) ) ) {
			// plugins are not allowed to use the empty namespace (i.e., RSS's namespace)
			// nor the WXR namespace

			/**
			 * Notify the plugin that it's namespace URI is not allowed
			 *
			 * The dynamic portion of the hook is the plugin's URL
			 *
			 * @since x.y.z
			 */
			do_action( "wxr_export_plugins_illegal_namespaceURI_{$plugin['url']}" );

			continue;
		}

		$plugin['prefix'] = wxr_unique_prefix( $plugin['prefix'], $plugin['namespaceURI'] );

		// write plugin's namespace decl
		$writer->writeAttribute( "xmlns:{$plugin['prefix']}", $plugin['namespaceURI'] );

		// collect the slug/url pairs for use in @wxr:plugins below
		$plugins_attr[] = $plugin['slug'];
		$plugins_attr[] = $plugin['url'];
	}

	if ( ! empty( $plugins_attr ) ) {
		$writer->writeAttributeNS( WXR_PREFIX, 'plugins', null, implode( ' ', $plugins_attr ) );
	}
	$writer->writeAttributeNS( WXR_PREFIX, 'version', null, WXR_VERSION );

	$writer->startElement( 'channel' );

	$writer->writeElement( 'title', get_bloginfo_rss( 'name' ) );
	$writer->writeElement( 'link', get_bloginfo_rss( 'url' ) );
	$writer->writeElement( 'description', get_bloginfo_rss( 'description' ) );
	$writer->writeElement( 'pubDate', date( 'D, d M Y H:i:s +0000' ) );
	$writer->writeElement( 'language', get_bloginfo_rss( 'language' ) );
	$writer->writeElement( 'docs', WXR_NAMESPACE_URI );
	// note: the value of <generator> in this export is different from the
	// standard exporter.  We are using it to identify the parameters (i.e.,
	// export filters) used to generate the export; whereas the standard
	// exporter merely gives the version of WP used on the export site.
	// Also, we write this element directly, rather than via the 'rss_head' action.
	// @todo should we also do_action('rss_head')?  I don't think so, because
	// while WXR is an RSS profile, it is not really an RSS feed so I don't
	// think plugins should expect to be able to add markup to the WXR instance
	// via 'rss_head'.  Rather, they should use the newly defined 'wxr_export_rss_channel'
	// action.
	$writer->writeElement( 'generator', add_query_arg( $args, admin_url ( "/export.php" ) ) );

	if ( is_multisite() ) {
		$writer->writeElementNS( WXR_PREFIX, 'site_url', null, network_home_url() );
	}

	wxr_authors_list( $writer, $post_ids );
	wxr_terms_list( $writer, $term_ids );

	/**
	 * blah, blah, blah
	 *
	 * Functions hooked to this action SHOULD generally output elements
	 * in the their own namespace (which SHOULD be registered with the
	 * wxr_export_plugins action).
	 *
	 * The only time plugins are allowed to output elements/attributes in
	 * the WXR namespace is if they have also hooked into the 'export_filters'
	 * action to produce a completely custom export (e.g., to export ONLY
	 * terms, users, etc).
	 *
	 * If they output elements in the empty namespace then those elements
	 * MUST conform to the RSS 2.0 spec and the RSS Advisory Board's Best Practices
	 * Profile (@link http://www.rssboard.org/rss-profile) AND not be among
	 * those RSS elements that are already output by this exporter.
	 *
	 * @since x.y.z
	 *
	 * @param XMLWriter $writer The XMLWriter instance to write to.
	 * @param array $args The arguments passed to export_wp().
	 */
	do_action( 'wxr_export_rss_channel', $writer, $args );

	wxr_posts_list( $writer, $post_ids );

	$writer->endElement(); // channel
	$writer->endElement(); // rss

	$writer->endDocument();

	exit();
}
