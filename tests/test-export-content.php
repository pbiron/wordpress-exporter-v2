<?php

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../wp-includes/class-wp-export-content.php';

/**
 * @group export
 * @group export-content
 */
class Export_Content_Tests extends Exporter_UnitTestCase {
	function setUp() {
		parent::setUp();

		global $wpdb;

		_delete_all_data();

		$this->populate_test_content();
	}

	function test_all_content() {
 		$export_content = new WP_Export_Content( array( 'content' => 'all' ) );

 		// gather all the users, terms & posts
		$users = array();
		foreach ( $export_content->users() as $user ) {
			$users[] = $user;
		}
		$terms = array();
		foreach ( $export_content->terms() as $term ) {
			$terms[] = $term;
		}
		$posts = array();
		foreach ( $export_content->posts() as $post ) {
			$posts[] = $post;
		}

		// count users
		$this->assertEquals( 2, count( $export_content->user_ids() ) );
		$this->assertEquals( 2, count( $users ) );

 		// check that all post authors are included among the exported users
 		foreach ( $posts as $post ) {
 			$this->assertContains( $post->post_author, wp_list_pluck( $users, 'user_login' ) );
 		}

		// count terms
		$this->assertEquals( 10, count( $export_content->term_ids() ) );
		$this->assertEquals( 10, count( $terms ) );

		$counts = $this->count_terms( $terms );

		$this->assertEquals( 4, $counts['num_categories'] );
		$this->assertEquals( 2, $counts['num_tags'] );
		$this->assertEquals( 4, $counts['num_custom_terms'] );

		// count posts/pages
		$this->assertEquals( 5, count( $export_content->post_ids() ) );
		$this->assertEquals( 5, count( $posts ) );

		$counts = $this->count_post_types( $posts );

		$this->assertEquals( 3, $counts['num_posts'] );
		$this->assertEquals( 2, $counts['num_pages'] );

 		//count terms on posts/pages
		$counts = $this->count_terms_on_items( $posts );

 		$this->assertEquals( 1, $counts['num_items_c1'] );
 		$this->assertEquals( 1, $counts['num_items_c2'] );
 		$this->assertEquals( 1, $counts['num_items_c3'] );
 		$this->assertEquals( 1, $counts['num_items_t1'] );
 		$this->assertEquals( 1, $counts['num_items_t2'] );
 		$this->assertEquals( 1, $counts['num_items_ct1'] );
 		$this->assertEquals( 1, $counts['num_items_ct2'] );
 		$this->assertEquals( 2, $counts['num_items_ct3'] );
 		$this->assertEquals( 1, $counts['num_items_no_cat'] );
 		$this->assertEquals( 4, $counts['num_items_with_terms'] );
 		$this->assertEquals( 1, $counts['num_posts_ct3'] );
 		$this->assertEquals( 1, $counts['num_pages_ct3'] );

 		// check that all post terms are included among the exported terms
		foreach ( $posts as $post ) {
			foreach ( $post->terms as $term ) {
				$this->assertContains( $term->term_id, $export_content->term_ids() );
				$this->assertContains( $term->term_id, wp_list_pluck( $terms, 'term_id' ) );
			}
		}
	}

	function test_post_type_post() {
 		$export_content = new WP_Export_Content( array( 'post_type' => 'post' ) );

	 	// gather all the users, terms & posts
		$users = array();
		foreach ( $export_content->users() as $user ) {
			$users[] = $user;
		}
		$terms = array();
		foreach ( $export_content->terms() as $term ) {
			$terms[] = $term;
		}
		$posts = array();
		foreach ( $export_content->posts() as $post ) {
			$posts[] = $post;
		}

		// count users
		$this->assertEquals( 1, count( $export_content->user_ids() ) );
		$this->assertEquals( 1, count( $users ) );

		// check that all post authors are included among the exported users
 		foreach ( $posts as $post ) {
 			$this->assertContains( $post->post_author, wp_list_pluck( $users, 'user_login' ) );
 		}

		// count terms
		$this->assertEquals( 8, count( $export_content->term_ids() ) );
		$this->assertEquals( 8, count( $terms ) );

		$counts = $this->count_terms( $terms );

		$this->assertEquals( 3, $counts['num_categories'] );
		$this->assertEquals( 2, $counts['num_tags'] );
		$this->assertEquals( 3, $counts['num_custom_terms'] );

		// count posts
		$this->assertEquals( 3, count( $export_content->post_ids() ) );
		$this->assertEquals( 3, count( $posts ) );

		$counts = $this->count_post_types( $posts );

		$this->assertEquals( 3, $counts['num_posts'] );
		$this->assertEquals( 0, $counts['num_pages'] );

 		//count terms on posts
		$counts = $this->count_terms_on_items( $posts );

 		$this->assertEquals( 1, $counts['num_items_c1'] );
 		$this->assertEquals( 1, $counts['num_items_c2'] );
 		$this->assertEquals( 1, $counts['num_items_c3'] );
 		$this->assertEquals( 1, $counts['num_items_t1'] );
 		$this->assertEquals( 1, $counts['num_items_t2'] );
 		$this->assertEquals( 1, $counts['num_items_ct1'] );
 		$this->assertEquals( 1, $counts['num_items_ct2'] );
 		$this->assertEquals( 1, $counts['num_items_ct3'] );
 		$this->assertEquals( 0, $counts['num_items_no_cat'] );
 		$this->assertEquals( 3, $counts['num_items_with_terms'] );
 		$this->assertEquals( 1, $counts['num_posts_ct3'] );
 		$this->assertEquals( 0, $counts['num_pages_ct3'] );

		// check that all post terms are included among the exported terms
		foreach ( $posts as $post ) {
			foreach ( $post->terms as $term ) {
				$this->assertContains( $term->term_id, $export_content->term_ids() );
				$this->assertContains( $term->term_id, wp_list_pluck( $terms, 'term_id' ) );
			}
		}
	}

	function test_post_type_page() {
 		$export_content = new WP_Export_Content( array( 'post_type' => 'page' ) );

	 	// gather all the users, terms & posts
		$users = array();
		foreach ( $export_content->users() as $user ) {
			$users[] = $user;
		}
		$terms = array();
		foreach ( $export_content->terms() as $term ) {
			$terms[] = $term;
		}
		$posts = array();
		foreach ( $export_content->posts() as $post ) {
			$posts[] = $post;
		}

		// count users
		$this->assertEquals( 1, count( $export_content->user_ids() ) );
		$this->assertEquals( 1, count( $users ) );

		// check that all post authors are included among the exported users
 		foreach ( $posts as $post ) {
 			$this->assertContains( $post->post_author, wp_list_pluck( $users, 'user_login' ) );
 		}

		// count terms
		$this->assertEquals( 2, count( $export_content->term_ids() ) );
		$this->assertEquals( 2, count( $terms ) );

		$counts = $this->count_terms( $terms );

		$this->assertEquals( 0, $counts['num_categories'] );
		$this->assertEquals( 0, $counts['num_tags'] );
		$this->assertEquals( 2, $counts['num_custom_terms'] );

		// count posts
		$this->assertEquals( 2, count( $export_content->post_ids() ) );
		$this->assertEquals( 2, count( $posts ) );

		$counts = $this->count_post_types( $posts );

		$this->assertEquals( 0, $counts['num_posts'] );
		$this->assertEquals( 2, $counts['num_pages'] );

 		//count terms on pages
		$counts = $this->count_terms_on_items( $posts );

 		$this->assertEquals( 0, $counts['num_items_c1'] );
 		$this->assertEquals( 0, $counts['num_items_c2'] );
 		$this->assertEquals( 0, $counts['num_items_c3'] );
 		$this->assertEquals( 0, $counts['num_items_t1'] );
 		$this->assertEquals( 0, $counts['num_items_t2'] );
 		$this->assertEquals( 0, $counts['num_items_ct1'] );
 		$this->assertEquals( 0, $counts['num_items_ct2'] );
 		$this->assertEquals( 1, $counts['num_items_ct3'] );
 		$this->assertEquals( 1, $counts['num_items_no_cat'] );
 		$this->assertEquals( 1, $counts['num_items_with_terms'] );
 		$this->assertEquals( 0, $counts['num_posts_ct3'] );
 		$this->assertEquals( 1, $counts['num_pages_ct3'] );

		// check that all post terms are included among the exported terms
		foreach ( $posts as $post ) {
			foreach ( $post->terms as $term ) {
				$this->assertContains( $term->term_id, $export_content->term_ids() );
				$this->assertContains( $term->term_id, wp_list_pluck( $terms, 'term_id' ) );
			}
		}
	}


	function test_posts_with_c3() {
 		$export_content = new WP_Export_Content( array( 'post_type' => 'post', 'taxonomy' => array( 'category' => 'C3' ) ) );

		// gather all the users, terms & posts
		$users = array();
		foreach ( $export_content->users() as $user ) {
			$users[] = $user;
		}
		$terms = array();
		foreach ( $export_content->terms() as $term ) {
			$terms[] = $term;
		}
		$posts = array();
		foreach ( $export_content->posts() as $post ) {
			$posts[] = $post;
		}

		// count users
		$this->assertEquals( 1, count( $export_content->user_ids() ) );
		$this->assertEquals( 1, count( $users ) );

		// check that all post authors are included among the exported users
	 	foreach ( $posts as $post ) {
 			$this->assertContains( $post->post_author, wp_list_pluck( $users, 'user_login' ) );
 		}

		// count terms
		$counts = $this->count_terms( $terms );

		$this->assertEquals( 2, $counts['num_categories'] );
		$this->assertEquals( 1, $counts['num_tags'] );
		$this->assertEquals( 0, $counts['num_custom_terms'] );

		// count posts
		$counts = $this->count_post_types( $posts );

		$this->assertEquals( 1, $counts['num_posts'] );
		$this->assertEquals( 0, $counts['num_pages'] );

		//count terms on posts
		$counts = $this->count_terms_on_items( $posts );

 		$this->assertEquals( 0, $counts['num_items_c1'] );
 		$this->assertEquals( 0, $counts['num_items_c2'] );
 		$this->assertEquals( 1, $counts['num_items_c3'] );
 		$this->assertEquals( 0, $counts['num_items_t1'] );
 		$this->assertEquals( 1, $counts['num_items_t2'] );
 		$this->assertEquals( 0, $counts['num_items_ct1'] );
 		$this->assertEquals( 0, $counts['num_items_ct2'] );
 		$this->assertEquals( 0, $counts['num_items_ct3'] );
 		$this->assertEquals( 0, $counts['num_items_no_cat'] );
 		$this->assertEquals( 1, $counts['num_items_with_terms'] );
 		$this->assertEquals( 0, $counts['num_posts_ct3'] );
 		$this->assertEquals( 0, $counts['num_pages_ct3'] );

		// check that all post terms are included among the exported terms
		foreach ( $posts as $post ) {
			foreach ( $post->terms as $term ) {
				$this->assertContains( $term->term_id, $export_content->term_ids() );
				$this->assertContains( $term->term_id, wp_list_pluck( $terms, 'term_id' ) );
			}
		}
	}

	function test_custom_export_filter() {
		add_filter( 'export_term_ids', function( $term_ids, $filters ) {
			if ( 'taxonomies' === $filters['content'] && ! empty( $filters['taxonomies'] ) ) {
				$term_ids = get_terms( array( 'taxonomy' => $filters['taxonomies'], 'fields' => 'ids' ) );
			}

			return $term_ids;
		}, 10, 2 );

 		$export_content = new WP_Export_Content( array( 'content' => 'taxonomies', 'taxonomies' => 'post_tag' ) );

	 	// gather all the users, terms & posts
		$users = array();
		foreach ( $export_content->users() as $user ) {
			$users[] = $user;
		}
		$terms = array();
		foreach ( $export_content->terms() as $term ) {
			$terms[] = $term;
		}
		$posts = array();
		foreach ( $export_content->posts() as $post ) {
			$posts[] = $post;
		}

		// count users
		$this->assertEquals( 0, count( $export_content->user_ids() ) );
		$this->assertEquals( 0, count( $users ) );

		// count terms
		$this->assertEquals( 2, count( $export_content->term_ids() ) );
		$this->assertEquals( 2, count( $terms ) );

		$counts = $this->count_terms( $terms );

		$this->assertEquals( 0, $counts['num_categories'] );
		$this->assertEquals( 2, $counts['num_tags'] );
		$this->assertEquals( 0, $counts['num_custom_terms'] );

		// count posts
		$this->assertEquals( 0, count( $export_content->post_ids() ) );
		$this->assertEquals( 0, count( $posts ) );
	}

	protected function count_terms( $terms ) {
		$num_categories = $num_tags = $num_custom_terms = 0;

		foreach ( $terms as $term ) {
			switch( $term->taxonomy ) {
				case 'category':
					$num_categories++;

					break;
				case 'post_tag':
					$num_tags++;

					break;
				case 'custom':
					$num_custom_terms++;
			}
		}

		return compact( 'num_categories', 'num_tags', 'num_custom_terms' );
	}

	protected function count_post_types( $posts ) {
		$num_posts = $num_pages = 0;
		foreach ( $posts as $post ) {
			switch ( $post->post_type ) {
				case 'post':
					$num_posts++;

					break;
				case 'page':
					$num_pages++;
			}
		}

		return compact( 'num_posts', 'num_pages' );
	}

	protected function count_terms_on_items( $posts ) {
	 	$num_items_c1 = $num_items_c2 = $num_items_c3 = 0;
 		$num_items_t1 = $num_items_t2 = 0;
 		$num_items_ct1 = $num_items_ct2 = $num_items_ct3 = 0;
 		$num_items_no_cat = $num_items_with_terms = 0;
 		$num_posts_ct3 = $num_pages_ct3 = 0;

		foreach ( $posts as $post ) {
			if ( empty( $post->terms ) ) {
				$num_items_no_cat++;
			}
			else {
				$num_items_with_terms++;
			}

			foreach ( $post->terms as $term ) {
				switch ( $term->taxonomy ) {
					case 'category':
						switch ( $term->slug ) {
							case 'c1':
								$num_items_c1++;

								break;
	 						case 'c2':
	 							$num_items_c2++;

	 							break;
	 						case 'c3':
	 							$num_items_c3++;

	 							break;
	 					}

	 					break;
					case 'post_tag':
						switch ( $term->slug ) {
							case 't1':
								$num_items_t1++;

								break;
	 						case 't2':
	 							$num_items_t2++;

	 							break;
	 					}

	 					break;
					case 'custom':
						switch ( $term->slug ) {
							case 'ct1':
								$num_items_ct1++;

								break;
	 						case 'ct2':
	 							$num_items_ct2++;

	 							break;
	 						case 'ct3':
	 							$num_items_ct3++;

	 							if ( 'post' === $post->post_type ) {
	 								$num_posts_ct3++;
	 							}
	 							elseif ( 'page' === $post->post_type ) {
	 								$num_pages_ct3++;
	 							}

	 							break;
						}

	 					break;
				}// switch ( $term->taxonomy )
			}// foreach ( $post->terms )
		}

		return compact( 'num_items_c1', 'num_items_c2', 'num_items_c3',
			'num_items_t1', 'num_items_t2',
			'num_items_ct1', 'num_items_ct2', 'num_items_ct3',
			'num_items_no_cat', 'num_items_with_terms',
			'num_posts_ct3', 'num_pages_ct3' );
	}
}