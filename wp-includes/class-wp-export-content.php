<?php
/**
 * WP Export API
 *
 * @package Export
 */

require_once dirname( __FILE__ ) . '/../wp-includes/iterators.php';

/**
 * Represents a set of posts and other site data to be exported.
 *
 * An immutable object, which gathers all data needed for the export.
 *
 * Based on ideas in @link https://core.trac.wordpress.org/attachment/ticket/22435/export.5.diff
 * but with some significant changes both in what is exported and in how
 * the content to be exported is computed.
 *
 * @todo The names of the "wxr_export_skip_(term|post)meta" filters that are
 * in the standard exporter don't really fit with being used in this class
 * (since this is agnostic with respect to the format the serialized export
 * takes).  But, since many plugins already use those names I'm leaving them
 * as is for now.  Seek advise on whether it would be too disruptive to
 * change them to format agnostic names.
 */
class WP_Export_Content {
	/**
	 * Size of chunks to get from database.
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 100;

	/**
	 * User IDs to export.
	 *
	 * @var array
	 */
	private $user_ids = array();

	/**
	 * Term IDs to export.
	 *
	 * @var array
	 */
	private $term_ids = array();

	/**
	 * Link IDs to export.
	 *
	 * @var array
	 */
	private $link_ids = array();

	/**
	 * Post IDs to export.
	 *
	 * @var array
	 */
	private $post_ids = array();

	/**
	 * Media IDs to export.
	 *
	 * @var array
	 */
	private $media_ids = array();

	/**
	 * Comment IDs to export.
	 *
	 * @var array
	 */
	private $comment_ids = array();

	/**
	 * Export filters.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * WHERE clauses for computing Post IDs.
	 *
	 * @var array
	 */
	private $wheres = array();

	/**
	 * JOIN clauses for computing Post IDs.
	 *
	 * @var array
	 */
	private $joins = array();

	/**
	 * Constructor
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
	 *
	 * @todo The @param hash above is copied unchanged from the export_wp() in the standard
	 * exporter, which does not reflect the functionality of this class.  Refresh it to
	 * reflect what this class actually uses.
	 */
	function __construct( $filters = array() ) {
		$default_filters = array(
			'content' => null,
			'post_ids' => null,
			'post_type' => null,
			'status' => null,
			'author' => null,
			'start_date' => null,
			'end_date' => null,
			'taxonomy' => null,
		);
		$this->filters = wp_parse_args( $filters, $default_filters );

		$this->post_ids = $this->calculate_post_ids();
		$this->media_ids = $this->calculate_media_ids();
		$this->comment_ids = $this->calculate_comment_ids();
		$this->link_ids = $this->calculate_link_ids();
		$this->user_ids = $this->calculate_user_ids();
		$this->term_ids = $this->calculate_term_ids();
	}

	function get_counts() {
		return (object) array(
			'post_count' => count( $this->post_ids ),
			'media_count' => count( $this->media_ids ),
			'term_count' => count( $this->term_ids ),
			'user_count' => count( $this->user_ids ),
			'link_count' => count( $this->link_ids ),
			'comment_count' => count( $this->comment_ids ),
		);
	}

	/**
	 * Get the IDs of the users to export.
	 *
	 * @return array
	 */
	function user_ids() {
		return $this->user_ids;
	}

	/**
	 * Get the IDs of the terms to export.
	 *
	 * @return array
	 */
	function term_ids() {
		return $this->term_ids;
	}

	/**
	 * Get the IDs of the links to export.
	 *
	 * @return array
	 */
	function link_ids() {
		return $this->link_ids;
	}

	/**
	 * Get the IDs of the posts to export.
	 *
	 * @return array
	 */
	function post_ids() {
		return $this->post_ids;
	}

	function media_ids() {
		return $this->media_ids;
	}

	/**
	 * Get the site metadata.
	 *
	 * @return array {
	 *     @type $string The site name.
	 *     @type $url The site URL.
	 *     @type $language The site language.
	 *     @type $descript The site description.
	 *     @type $string The date/time of the export.
	 *     @type $site_url If multisite then network_home_url(), otherwise ''.
	 *     @type $generator The ???.
	 * }
	 */
	function site_metadata() {
		$metadata = array(
			'name' => $this->bloginfo_rss( 'name' ),
			'url' => $this->bloginfo_rss( 'url' ),
			'language' => $this->bloginfo_rss( 'language' ),
			'description' => $this->bloginfo_rss( 'description' ),
			'pubDate' => date( 'D, d M Y H:i:s +0000' ),
			'site_url' => is_multisite()? network_home_url() : '',
			// note: the value of <generator> in this export is different from the
			// standard exporter.  We are using it to identify the parameters (i.e.,
			// export filters) used to generate the export; whereas the standard
			// exporter merely gives the version of WP used on the export site.
			// Also, we write this element directly, rather than via the 'rss_head' action.
			'generator' => add_query_arg( $this->filters, admin_url ( "/export.php" ) ),
			'wp_version' => get_bloginfo_rss( 'version' ),
		);

		return $metadata;
	}

	/**
	 * Iterator over the users to export.
	 *
	 * @return WP_Map_Iterator
	 */
	function users() {
		$iterator = new WP_IDs_Iterator( $this->user_ids, 'get_userdata', self::CHUNK_SIZE );
		if ( is_wp_error( $iterator ) ) {
			return array();
		}

		return new WP_Map_Iterator( $iterator, array( $this, 'exportify_user' ) );
	}

	/**
	 * Iterator over the terms to export.
	 *
	 * @return WP_Map_Iterator
	 */
	function terms() {
		$iterator = new WP_IDs_Iterator( $this->term_ids, 'get_term', self::CHUNK_SIZE );
		if ( is_wp_error( $iterator ) ) {
			return array();
		}

		return new WP_Map_Iterator( $iterator, array( $this, 'exportify_term' ) );
	}

	/**
	 * Iterator over the links to export.
	 *
	 * @return WP_Map_Iterator
	 */
	function links() {
		global $wpdb;
		$iterator = new WP_IDs_Iterator( $this->link_ids, 'get_bookmark', self::CHUNK_SIZE );
		if ( is_wp_error( $iterator ) ) {
			return array();
		}

		return new WP_Map_Iterator( $iterator, array( $this, 'exportify_link' ) );
	}

	/**
	 * Iterator over the posts to export.
	 *
	 * @return WP_Map_Iterator
	 */
	function posts() {
		$iterator = new WP_IDs_Iterator( $this->post_ids, 'get_post', self::CHUNK_SIZE );
		if ( is_wp_error( $iterator ) ) {
			return array();
		}

		return new WP_Map_Iterator( $iterator, array( $this, 'exportify_post' ) );
	}

	/**
	 * Iterator over the media to export.
	 *
	 * @return WP_Map_Iterator
	 */
	function media() {
		$iterator = new WP_IDs_Iterator( $this->media_ids, 'get_post', self::CHUNK_SIZE );
		if ( is_wp_error( $iterator ) ) {
			return array();
		}

		return new WP_Map_Iterator( $iterator, array( $this, 'exportify_post' ) );
	}

	/**
	 * Augment a WP_User object for export.
	 *
	 * Adds user meta.
	 *
	 * @param WP_User $user
	 * @return WP_User
	 */
	function exportify_user( $user ) {
		global $wpdb;

		$metas = array();

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
				$metas[] = $meta;
			}
		}

		$user->meta = $metas;

		return $user;
	}

	/**
	 * Augment a WP_Term object for export.
	 *
	 * Replaces parent ID with parent slug and adds term meta.
	 *
	 * @param WP_Term $term
	 * @return WP_Term
	 */
	function exportify_term( $term ) {
		global $wpdb;

		$term->meta = array();

		if ( 0 !== $term->parent ) {
			$parent = get_term( $term->parent );
			$term->parent = $parent->slug;
		}

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
				$term->meta[] = $meta;
			}
		}

		return $term;
	}

	/**
	 * Augment a link for export.
	 *
	 * Replaces owner ID with login and adds link categories.
	 *
	 * @param object $link
	 * @return object
	 */
	function exportify_link( $link ) {
		$owner = $this->find_user_from_any_object( $link->link_owner );
		if ( ! $owner || is_wp_error( $owner ) ) {
			// @todo what should we do with this error?
		}
		else {
			$link->link_owner = $owner->user_login;
		}

		$cats = array();
		foreach ( $link->link_category as $cat ) {
			$cats[] = get_term( $cat, 'link_category' );
		}

		$link->link_category = $cats;

		return $link;
	}

	/**
	 * Augment a WP_Post object for export.
	 *
	 * @todo describe the augmentation.
	 *
	 * @param WP_Post $post
	 * @return WP_Post
	 */
	function exportify_post( $post ) {
		/**
		 * @global WP_Query $wp_query.
		 */
		global $wp_query;

		$wp_query->in_the_loop = true;

		$previous_global_post = isset( $GLOBALS['post'] )? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post;

		setup_postdata( $post );

		$post->post_title = apply_filters( 'the_title_rss', $post->post_title );
		/** This filter is documented in wp-includes/feed.php */
		$post->permalink = esc_url( apply_filters( 'the_permalink_rss', get_permalink( $post->ID ) ) );
		$post->post_author = get_the_author_meta( 'login' );
		$post->post_content = apply_filters( 'the_content_export', $post->post_content );
		$post->post_excerpt = apply_filters( 'the_excerpt_export', $post->post_excerpt );
		$post->is_sticky = is_sticky( $post->ID ) ? 1 : 0;
		$post->terms = self::get_terms_for_post( $post );
		$post->meta = self::get_meta_for_post( $post );
		$post->comments = self::get_comments_for_post( $post );

		$GLOBALS['post'] = $previous_global_post;

		return $post;
	}

	/**
	 * Get the encoding for the export.
	 *
	 * @return string
	 */
	function get_encoding() {
		// @todo verify that all MySQL charset's OTHER than utf16 are subsets
		// of UTF-8 since UTF-8 and UTF-16 are the ONLY encodings that XML
		// processors are required to consume/produce
		return strtoupper ( get_bloginfo( 'charset' ) ) === 'UTF16' ? 'UTF-16' : 'UTF-8';

	}

	/**
	 * Get the IDs of the users to export.
	 *
	 * @return array User IDs to export.
	 */
	private function calculate_user_ids() {
		global $wpdb;

		$post_author_ids = $comment_user_ids = $link_owner_ids = array();

		if ( ! empty( $this->post_ids ) ) {
			$IDs = $this->build_IN_condition( 'ID', $this->post_ids, '%d' );
			$post_author_ids = $wpdb->get_col( "SELECT DISTINCT post_author FROM $wpdb->posts WHERE $IDs AND post_status != 'auto-draft'" );

			/*
			 * @todo open trac ticket to include comment user_id & link_owner users to standard exporter
			 */
			$IDs = $this->build_IN_condition( 'comment_post_ID', $this->post_ids, '%d' );
			$comment_user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM $wpdb->comments WHERE $IDs" );
			$comment_user_ids = array_filter( $comment_user_ids );
		}

		if ( ! empty( $this->link_ids ) ) {
			$IDs = $this->build_IN_condition( 'link_id', $this->link_ids, '%d' );
			$link_owner_ids = $wpdb->get_col( "SELECT DISTINCT link_owner FROM $wpdb->links WHERE $IDs" );
		}

		// because of bugs elsewhere in core, some of the user_ids calculated above
		// may no longer exist as users, so strip those out.
		$user_ids = array_merge( $post_author_ids, $comment_user_ids, $link_owner_ids );
		if ( ! empty( $user_ids ) ) {
			$IDs = $this->build_IN_condition( 'ID', $user_ids );
			$user_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->users WHERE $IDs" );
		}

		/**
		 * Filter the user IDs to be exported.
		 *
		 * @param array $user_ids The user IDs to be exported.
		 * @param array $filters ???
		 */
		$user_ids = array_map( 'intval', apply_filters( 'export_user_ids', $user_ids, $this->filters ) );

		return $user_ids;
	}

	/**
	 * Get the IDs of the terms to export.
	 *
	 * @return array Terms IDs to export.
	 */
	private function calculate_term_ids() {
		global $wpdb;
		$term_ids = array();

		if ( 'all' === $this->filters['content'] ) {
			$taxonomies = get_taxonomies();

	 		$term_ids = array_map( 'intval', $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms}" ) );
		}
		else {
			// note: the reason we can't just do simple get_terms( 'object_ids' => ... ) calls here
			// is because $wpdb->term_relationships.object_id isn't scoped to an "object type", hence,
			// terms attached to a link with link_id = X would be returned when true === in_array( $this->post_ids, X ),
			// an vice versa
			// @todo: see if there's a trac ticket related to object_id that we can reference here to explain the need
			if ( ! empty( $this->post_ids ) ) {
				// @todo: find a more efficient way of doing this!!!
				foreach ( $this->post_ids as $post_id ) {
					$post = get_post( $post_id );
					foreach ( get_object_taxonomies( $post ) as $tax ) {
						$term_ids = array_merge( $term_ids,
							wp_list_pluck( wp_get_object_terms( $post_id, $tax ), 'term_id' ) );
					}
				}
			}
			if ( ! empty( $this->link_ids ) ) {
				$term_ids = array_merge( $term_ids,
					wp_list_pluck( wp_get_object_terms( $this->link_ids, 'link_category' ), 'term_id' ) );
			}
		}

		/**
		 * Filter the term IDs to be exported.
		 *
		 * @param array $term_ids The term IDs to be exported.
		 * @param array $filters ???
		 */
		$term_ids = array_map( 'intval', apply_filters( 'export_term_ids', $term_ids, $this->filters ) );

		$term_ids = $this->add_term_parents( $term_ids );

		// try to ensure parents appear before any of their descendents
		// not strictly necessary, but makes it a little easier on the
		// importer
		$term_ids = $this->topologically_sort_term_ids( $term_ids );

		return $term_ids;
	}

	/**
	 * Get the IDs of the links to export.
	 *
	 * @return array Link IDs to export.
	 */
	private function calculate_link_ids() {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		$link_ids = array();
		if ( in_array( $this->filters['content'], array( 'all', 'links' ) ) ) {
			$wheres = array();
			$join = '';
			if ( isset( $this->filters['link_relationship'] ) ) {
				$wheres[] = $wpdb->prepare( 'link_rel LIKE %s', '%' . $this->filters['link_relationship'] . '%' );
			}
			if ( isset( $this->filters['taxonomy']['link_category'] ) ) {
				$join = "INNER JOIN {$wpdb->term_relationships} AS tr ON (l.link_id = tr.object_id)";
				$wheres[] = $wpdb->prepare( 'tr.term_taxonomy_id = %d', $this->filters['taxonomy']['link_category'] );
			}
			$where = implode( ' AND ', array_filter( $wheres ) );
			if ( ! empty( $where ) ) {
				$where = "WHERE $where";
			}

			$link_ids = $wpdb->get_col( "SELECT link_id FROM {$wpdb->links} as l $join $where" );
		}

		/**
		 * Filter the link IDs to be exported.
		 *
		 * @param array $link_ids The link IDs to be exported.
		 * @param array $filters ???
		 */
		$link_ids = array_map( 'intval', apply_filters( 'export_link_ids', $link_ids, $this->filters ) );

		return $link_ids;
	}

	/**
	 * Get the IDs of the posts to export.
	 *
	 * @return array Post IDs to export.
	 */
	private function calculate_post_ids() {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		if ( is_array( $this->filters['post_ids'] ) ) {
			return $this->filters['post_ids'];
		}

		$this->post_type_where();
		$this->status_where();
		$this->author_where();
		$this->date_where();
		$this->terms_where();

		$where = implode( ' AND ', array_filter( $this->wheres ) );
		if ( $where ) {
			$where = "WHERE $where";
		}

		$join = implode( ' ', array_filter( $this->joins ) );

		$post_ids = $wpdb->get_col( "SELECT DISTINCT ID FROM {$wpdb->posts} AS p $join $where" );

		/**
		 * Filter the post IDs to be exported.
		 *
		 * @param array $post_ids The post IDs to be exported.
		 * @param array $filters ???
		 */
		$post_ids = array_map( 'intval', apply_filters( 'export_post_ids', $post_ids, $this->filters ) );

		// try to ensure parents appear before any of their children
		// not strictly necessary, but makes it a little easier on the
		// importer
		$post_ids = $this->topologically_sort_post_ids( $post_ids );

		return $post_ids;
	}

	private function calculate_media_ids() {
		global $wpdb;

		if ( 'attachment' === $this->filters['post_type'] || 'all' === $this->filters['content'] ) {
			$media_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		}
		else {
			$media_ids = $this->attachments_for_specific_post_types( $this->post_ids );
		}

		/**
		 * Filter the media IDs to be exported.
		 *
		 * @param array $media_ids The post IDs to be exported.
		 * @param array $filters ???
		 */
		$media_ids = array_map( 'intval', apply_filters( 'export_media_ids', $media_ids, $this->filters ) );

		return $media_ids;
	}

	private function calculate_comment_ids() {
		global $wpdb;

		if ( empty( $this->post_ids ) && empty( $this->media_ids ) ) {
			return array();
		}

		$in = $this->build_IN_condition( 'comment_post_id', array_merge( $this->post_ids, $this->media_ids ) );
		$comment_ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE $in AND comment_approved <> 'spam'" );

		/**
		 * Filter the comment IDs to be exported.
		 *
		 * @param array $comment_ids The comment IDs to be exported.
		 * @param array $filters ???
		 */
		$comment_ids = array_map( 'intval', apply_filters( 'export_comment_ids', $comment_ids, $this->filters ) );

		return $comment_ids;
	}

	/**
	 *
	 */
	private function post_type_where() {
		if ( ! ( 'all' === $this->filters['content'] || $this->filters['post_type'] ) ) {
			$this->wheres[] = 'p.post_type IS NULL';

			return;
		}

		$post_types_filters = array( 'can_export' => true );

		if ( $this->filters['post_type'] ) {
			$post_types_filters = array_merge( $post_types_filters, array( 'name' => $this->filters['post_type'] ) );
		}

		$post_types = get_post_types( $post_types_filters );
		unset( $post_types['attachment'] );
		if ( ! $post_types ) {
			$this->wheres[] = 'p.post_type IS NULL';

			return;
		}

		$this->wheres[] = $this->build_IN_condition( 'p.post_type', $post_types );
	}

	/**
	 *
	 */
	private function status_where() {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		if ( ! $this->filters['status'] ) {
			$this->wheres[] = "p.post_status != 'auto-draft'";

			return;
		}

		$this->wheres[] = $wpdb->prepare( 'p.post_status = %s', $this->filters['status'] );
	}

	/**
	 *
	 */
	private function author_where() {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		$user = $this->find_user_from_any_object( $this->filters['author'] );
		if ( ! $user || is_wp_error( $user ) ) {
			return;
		}

		$this->wheres[] = $wpdb->prepare( 'p.post_author = %d', $user->ID );
	}

	/**
	 *
	 */
	private function date_where() {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		if ( isset( $this->filters['start_date'] ) ) {
			$timestamp = strtotime( $this->filters['start_date'] );
			if ( $timestamp ) {
				$this->wheres[] = $wpdb->prepare( 'p.post_date >= %s', date( 'Y-m-d 00:00:00', $timestamp ) );
			}
		}

		if ( isset( $this->filters['end_date'] ) ) {
			$timestamp = strtotime( $this->filters['end_date'] );
			if ( $timestamp ) {
				$this->wheres[] = $wpdb->prepare( 'p.post_date < %s', date( 'Y-m-d 00:00:00', $timestamp ) );
			}
		}
	}

	/**
	 *
	 */
	private function terms_where() {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		$terms = $this->find_terms_from_any_object( $this->filters['taxonomy'] );
		if ( empty( $terms ) ) {
			return;
		}

		$this->joins[] = "INNER JOIN {$wpdb->term_relationships} AS tr ON (p.ID = tr.object_id)";
		$this->wheres[] = $this->build_IN_condition( 'tr.term_taxonomy_id',
			wp_list_pluck ( $terms, 'term_taxonomy_id' ), '%d' );
	}

	/**
	 * Get attachments.
	 *
	 * @param array $post_ids
	 * @return array|array
	 */
	private function attachments_for_specific_post_types( $post_ids ) {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

// 		if ( ! $this->filters['post_type'] ) {
// 			return array();
// 		}

		$attachment_ids = array();
		while ( $batch_of_post_ids = array_splice( $post_ids, 0, self::CHUNK_SIZE ) ) {
			$post_parent_condition = $this->build_IN_condition( 'post_parent', $batch_of_post_ids, '%d' );
			$attachment_ids = array_merge( $attachment_ids,
				(array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND $post_parent_condition" ) );
//				(array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" ) );
		}

		return array_map( 'intval', $attachment_ids );
	}

	/**
	 * Get a user.
	 *
	 * @param int|string|WP_User $user
	 * @return WP_User|false WP_User object on success, false on failure.
	 */
	private static function find_user_from_any_object( $user ) {
		if ( is_numeric( $user ) ) {
			return get_user_by( 'id', $user );
		}
		elseif ( is_string( $user ) ) {
			return get_user_by( 'login', $user );
		}
		elseif ( isset( $user->ID ) ) {
			return get_user_by( 'id', $user->ID );
		}

		return false;
	}

	/**
	 * Get terms from specific taxonomies.
	 *
	 * @param array $taxonomies Keys are taxonomy names, values are term_ids.
	 * @return array WP_Term on success, null or false on error.
	 */
	private static function find_terms_from_any_object( $taxonomies ) {
		$terms = array();

		foreach ( (array) $taxonomies as $tax => $term ) {
			if ( is_numeric( $term ) ) {
				$terms[] = get_term( $term, $tax );
			}
			elseif ( is_string( $term ) ) {
				$term = term_exists( $term, $tax );
				if ( isset( $term['term_id'] ) ) {
					$terms[] = get_term( $term['term_id'], $tax );
				}
			}
			elseif ( isset( $term->term_id ) ) {
				$terms[] = get_term( $term->term_id, $tax );
			}
		}

		return $terms;
	}

	/**
	 * Add term parents if not already included in $term_ids.
	 *
	 * @param array $term_ids
	 * @return array
	 */
	private static function add_term_parents( $term_ids ) {
		// @todo open a trac ticket to fix that aspect of the standard exporter, even if
		// this rewrite doesn't get merged into core
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( 0 !== $term->parent && ! in_array( $term->parent, $term_ids ) ) {
				$term_ids[] = $term->parent;
			}
		}

		return $term_ids;
	}

	/**
	 * Sort an array of term IDs so that parents occur before their children.
	 *
	 * Core tries to prevent cycles (e.g., A->parent == B && B->parent == A)
	 * with wp_check_term_hierarchy_for_loops(), but there is nothing preventing
	 * plugins from directly writing to $wpdb->term_taxonomy and creating them.
	 * If cycles exist, then the result will not be a true topological sort.
	 *
	 * @param array $term_ids
	 * @return array
	 */
	private static function topologically_sort_term_ids( $term_ids ) {
		$sorted = $visited = array();
		while ( $term_id = array_shift( $term_ids ) ) {
			$term = get_term( $term_id );
			if ( 0 === $term->parent || in_array( $term->parent, $sorted ) ||
					in_array( $term_id, $visited ) ) {
				$sorted[] = $term_id;
			}
			elseif ( ! in_array( $term_id, $visited ) ) {
				$term_ids[] = $term_id;
			}
			$visited[] = $term_id;
		}

		return $sorted;
	}

	/**
	 * Sort an array of post IDs so that parents occur before their children
	 *
	 * Core tries to prevent cycles (e.g., A->parent == B && B->parent == A)
	 * with wp_check_post_hierarchy_for_loops(), but there is nothing preventing
	 * plugins from directly writing to $wpdb->posts and creating them.
	 * If cycles exist, then the result will not be a true topological sort.
	 *
	 * @param array $post_ids
	 * @return array
	 */
	private static function topologically_sort_post_ids( $post_ids ) {
		$sorted = $visited = array();
		while ( $post_id = array_shift( $post_ids ) ) {
			$post = get_post( $post_id );
			if ( 0 === $post->post_parent || in_array( $post->post_parent, $sorted ) ||
					in_array( $post_id, $visited ) ) {
				$sorted[] = $post_id;
			}
			elseif ( ! in_array( $post_id, $visited ) ) {
				$post_ids[] = $post_id;
			}
			$visited[] = $post_id;
		}

		return $sorted;
	}

	/**
	 * Get the terms for a post.
	 *
	 * @param WP_Post $post
	 * @return array|WP_Error Array of WP_Term objects on success, WP_Error on error.
	 */
	private static function get_terms_for_post( $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type );

		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = wp_get_object_terms( $post->ID, $taxonomies );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Get meta for a specific post.
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	private static function get_meta_for_post( $post ) {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		$meta_for_export = array();
		$meta_from_db = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->postmeta WHERE post_id = %d", $post->ID ) );
		foreach ( $meta_from_db as $meta ) {
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

			if ( '_edit_last' === $meta->meta_key ) {
				$meta->meta_value = $post->post_author;
			}
			$meta_for_export[] = $meta;
		}

		return $meta_for_export;
	}

	/**
	 * Get the comments and comment meta for a specific post.
	 *
	 * Excludes comments with spam status.
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	private static function get_comments_for_post( $post ) {
		/**
		 * @global wpdb $wpdb.
		 */
		global $wpdb;

		$comments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_approved <> 'spam'", $post->ID ) );
		foreach( $comments as $comment ) {
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->commentmeta WHERE comment_id = %d", $comment->comment_ID ) );

			$meta = $meta? $meta : array();
			$comment->meta = $meta;
		}

		return $comments;
	}

	/**
	 * Get bloginfo.
	 *
	 * @param string $section
	 * @return string
	 */
	private static function bloginfo_rss( $section ) {
		return apply_filters( 'bloginfo_rss', get_bloginfo_rss( $section ), $section );
	}

	/**
	 * Builds a SQL condition in the form "post_id IN (1, 2, 3, 4)"
	 *
	 * @param string $column_name The name of the table column from the IN condition
	 * @param array $values Array of values in which the column value should be
	 * @param string $format Optional printf format specifier for the elements of the array. Defaults to %s.
	 * @return string The IN condition, with escaped values. If there are no values, the return value is an empty string.
	 *
	 * Note: the patch in trac 22435 has this in wpdb but it doesn't belong there
	 * 		 @link https://core.trac.wordpress.org/ticket/22435#comment:30
	 */
	private static function build_IN_condition( $column_name, $values, $format = '%s' ) {
		global $wpdb;

		if ( !is_array( $values ) || empty( $values ) ) {
			return '';
		}

		$formats = implode( ', ', array_fill( 0, count( $values ), $format ) );

		return $wpdb->prepare( "$column_name IN ($formats)", $values );
	}
}
