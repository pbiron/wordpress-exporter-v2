<?php

require_once dirname( __FILE__ ) . '/base.php';
require_once __DIR__ . '/../wp-includes/class-wxr-exporter.php';

/**
 * @group export
 * @group wxr-exporter
 * @group xml
 *
 * Note: we can NOT use any of the phpunit XML-related assertions because
 * they are HORRIBLY broken.  For example:
 *
 * assertXmlStringEqualsXmlString( '<root xmlns="urn:foo"/>', '<foo:root xmlns:foo="urn:foo"/>' );
 *
 * fails even tho their XML Infoset's are equal (and will be treated identically
 * by a namespace-aware parser)!
 */
class WXR_Exporter_Tests extends Exporter_UnitTestCase {
	protected $file;

	function setUp() {
		parent::setUp();

		global $wpdb;

		_delete_all_data();

		@unlink( $this->file );

		$this->populate_test_content();
	}

	function tearDown() {
		libxml_clear_errors();

		@unlink( $this->file );

		parent::tearDown();
	}

	function test_export_to_file() {
		$exporter = $this->get_exporter( array( 'content' => 'all' ) );
		$this->file = dirname( __FILE__ ) . '/data/all_content.xml';

		$exporter->export ( $this->file );

		$this->is_well_formed( $this->file );
	}


	function test_export_to_stdout() {
		$exporter = $this->get_exporter( array( 'content' => 'all' ) );

		ob_start();
		$exporter->export ( 'php://output' );
		$wxr = ob_get_clean();

		$this->is_well_formed( $wxr );
	}

	function test_all_content() {
 		$exporter = $this->get_exporter( array( 'content' => 'all' ) );
		$wxr = $exporter->export ();

		$this->is_well_formed( $wxr );

		// count users
		$num_users = $this->xpath_evaluate( 'count(/rss/channel/wxr:user)' );
		$this->assertEquals( 2, $num_users );

		// check that all post authors are included among the exported users
		foreach ( $this->xpath_evaluate( '/rss/channel/item/dc:creator' ) as $login ) {
			$num_users = $this->xpath_evaluate( "count(/rss/channel/wxr:user[wxr:login = '{$login->nodeValue}'])");
			$this->assertEquals( 1, $num_users );
		}

		// count terms
		$num_categories = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "category"])' );
		$this->assertEquals( 4, $num_categories );
		$num_tags = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "post_tag"])' );
		$this->assertEquals( 2, $num_tags );
		$num_custom_terms = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "custom"])' );
		$this->assertEquals( 4, $num_custom_terms );

		// count posts/pages
		$num_items = $this->xpath_evaluate( 'count(/rss/channel/item)' );
		$this->assertEquals( 5, $num_items );
		$num_posts = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "post"])' );
		$this->assertEquals( 3, $num_posts );
		$num_pages = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "page"])' );
		$this->assertEquals( 2, $num_pages );

		//count terms on posts/pages
		$num_posts_c1 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c1"])' );
		$this->assertEquals( 1, $num_posts_c1 );
		$num_posts_c2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c2"])' );
		$this->assertEquals( 1, $num_posts_c2 );
		$num_posts_c3 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c3"])' );
		$this->assertEquals( 1, $num_posts_c3 );
		$num_posts_t1 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "post_tag" and @wxr:slug = "t1"])' );
		$this->assertEquals( 1, $num_posts_t1 );
		$num_posts_t2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "post_tag" and @wxr:slug = "t2"])' );
		$this->assertEquals( 1, $num_posts_t2 );
		$num_posts_ct1 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct1"])' );
		$this->assertEquals( 1, $num_posts_ct1 );
		$num_posts_ct2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct2"])' );
		$this->assertEquals( 1, $num_posts_ct2 );
		$num_posts_ct3 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct3"])' );
		$this->assertEquals( 2, $num_posts_ct3 );
		$num_items_no_cat = $this->xpath_evaluate( 'count(/rss/channel/item[not( category )])' );
		$this->assertEquals( 1, $num_items_no_cat );
		$posts_with_terms = $this->xpath_evaluate( 'count(/rss/channel/item[category])');
		$this->assertEquals( 4, $posts_with_terms );
		$num_posts_ct3 = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "post"]/category[@domain = "custom" and @wxr:slug = "ct3"])' );
		$this->assertEquals( 1, $num_posts_ct3 );
		$num_pages_ct3 = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "page"]/category[@domain = "custom" and @wxr:slug = "ct3"])' );
		$this->assertEquals( 1, $num_pages_ct3 );

		// check that all post terms are included among the exported terms
		foreach ( $this->xpath_evaluate( '/rss/channel/item/category' ) as $term ) {
			$tax = $term->getAttributeNode( 'domain' )->nodeValue;
			$slug = $term->getAttributeNodeNS( WXR_Exporter::WXR_NAMESPACE_URI, 'slug' )->nodeValue;
			$num_terms = $this->xpath_evaluate( "count(/rss/channel/wxr:term[wxr:taxonomy = '$tax' and wxr:slug = '$slug'])");
			$this->assertEquals( 1, $num_terms );
		}
	}

	function test_post_type_post() {
 		$exporter = $this->get_exporter( array( 'post_type' => 'post' ) );
		$wxr = $exporter->export ();

		$this->is_well_formed( $wxr );

		// count users
		$num_users = $this->xpath_evaluate( 'count(/rss/channel/wxr:user)' );
		$this->assertEquals( 1, $num_users );

		// check that all post authors are included among the exported users
		foreach ( $this->xpath_evaluate( '/rss/channel/item/dc:creator' ) as $login ) {
			$num_users = $this->xpath_evaluate( "count(/rss/channel/wxr:user[wxr:login = '{$login->nodeValue}'])");
			$this->assertEquals( 1, $num_users );
		}

		// count terms
		$num_categories = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "category"])' );
		$this->assertEquals( 3, $num_categories );
		$num_tags = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "post_tag"])' );
		$this->assertEquals( 2, $num_tags );
		$num_custom_terms = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "custom"])' );
		$this->assertEquals( 3, $num_custom_terms );

		// count posts
		$num_items = $this->xpath_evaluate( 'count(/rss/channel/item)' );
		$this->assertEquals( 3, $num_items );
		$num_posts = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "post"])' );
		$this->assertEquals( 3, $num_posts );
		$num_pages = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "page"])' );
		$this->assertEquals( 0, $num_pages );

		//count terms on posts
		$num_posts_c1 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c1"])' );
		$this->assertEquals( 1, $num_posts_c1 );
		$num_posts_c2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c2"])' );
		$this->assertEquals( 1, $num_posts_c2 );
		$num_posts_c3 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c3"])' );
		$this->assertEquals( 1, $num_posts_c3 );
		$num_posts_t1 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "post_tag" and @wxr:slug = "t1"])' );
		$this->assertEquals( 1, $num_posts_t1 );
		$num_posts_t2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "post_tag" and @wxr:slug = "t2"])' );
		$this->assertEquals( 1, $num_posts_t2 );
		$num_posts_ct1 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct1"])' );
		$this->assertEquals( 1, $num_posts_ct1 );
		$num_posts_ct2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct2"])' );
		$this->assertEquals( 1, $num_posts_ct2 );
		$num_posts_ct3 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct3"])' );
		$this->assertEquals( 1, $num_posts_ct3 );
		$num_items_no_cat = $this->xpath_evaluate( 'count(/rss/channel/item[not( category )])' );
		$this->assertEquals( 0, $num_items_no_cat );
		$posts_with_terms = $this->xpath_evaluate( 'count(/rss/channel/item[category])');
		$this->assertEquals( 3, $posts_with_terms );

		// check that all post terms are included among the exported terms
		foreach ( $this->xpath_evaluate( '/rss/channel/item/category' ) as $term ) {
			$tax = $term->getAttributeNode( 'domain' )->nodeValue;
			$slug = $term->getAttributeNodeNS( WXR_Exporter::WXR_NAMESPACE_URI, 'slug' )->nodeValue;
			$num_terms = $this->xpath_evaluate( "count(/rss/channel/wxr:term[wxr:taxonomy = '$tax' and wxr:slug = '$slug'])");
			$this->assertEquals( 1, $num_terms );
		}
	}

	function test_post_type_page() {
 		$exporter = $this->get_exporter( array( 'post_type' => 'page' ) );
		$wxr = $exporter->export ();

		$this->is_well_formed( $wxr );

		// count users
		$num_users = $this->xpath_evaluate( 'count(/rss/channel/wxr:user)' );
		$this->assertEquals( 1, $num_users );

		// check that all post authors are included among the exported users
		foreach ( $this->xpath_evaluate( '/rss/channel/item/dc:creator' ) as $login ) {
			$num_users = $this->xpath_evaluate( "count(/rss/channel/wxr:user[wxr:login = '{$login->nodeValue}'])");
			$this->assertEquals( 1, $num_users );
		}

		// count terms
		$num_categories = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "category"])' );
		$this->assertEquals( 0, $num_categories );
		$num_tags = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "post_tag"])' );
		$this->assertEquals( 0, $num_tags );
		$num_custom_terms = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "custom"])' );
		$this->assertEquals( 2, $num_custom_terms );

		// count pages
		$num_items = $this->xpath_evaluate( 'count(/rss/channel/item)' );
		$this->assertEquals( 2, $num_items );
		$num_posts = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "post"])' );
		$this->assertEquals( 0, $num_posts );
		$num_pages = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "page"])' );
		$this->assertEquals( 2, $num_pages );

		//count terms on pages
		$num_posts_ct2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct2"])' );
		$this->assertEquals( 0, $num_posts_ct2 );
		$num_posts_ct3 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "custom" and @wxr:slug = "ct3"])' );
		$this->assertEquals( 1, $num_posts_ct3 );
		$num_items_no_cat = $this->xpath_evaluate( 'count(/rss/channel/item[not( category )])' );
		$this->assertEquals( 1, $num_items_no_cat );
		$posts_with_terms = $this->xpath_evaluate( 'count(/rss/channel/item[category])');
		$this->assertEquals( 1, $posts_with_terms );

		// check that all post terms are included among the exported terms
		foreach ( $this->xpath_evaluate( '/rss/channel/item/category' ) as $term ) {
			$tax = $term->getAttributeNode( 'domain' )->nodeValue;
			$slug = $term->getAttributeNodeNS( WXR_Exporter::WXR_NAMESPACE_URI, 'slug' )->nodeValue;
			$num_terms = $this->xpath_evaluate( "count(/rss/channel/wxr:term[wxr:taxonomy = '$tax' and wxr:slug = '$slug'])");
			$this->assertEquals( 1, $num_terms );
		}
	}


	function test_posts_with_c3() {
 		$exporter = $this->get_exporter( array( 'post_type' => 'post', 'taxonomy' => array( 'category' => 'C3' ) ) );
		$wxr = $exporter->export ();

		$this->is_well_formed( $wxr );

		// count users
		$num_users = $this->xpath_evaluate( 'count(/rss/channel/wxr:user)' );
		$this->assertEquals( 1, $num_users );

		// check that all post authors are included among the exported users
		foreach ( $this->xpath_evaluate( '/rss/channel/item/dc:creator' ) as $login ) {
			$num_users = $this->xpath_evaluate( "count(/rss/channel/wxr:user[wxr:login = '{$login->nodeValue}'])");
			$this->assertEquals( 1, $num_users );
		}

		// count terms
		$num_categories = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "category"])' );
		$this->assertEquals( 2, $num_categories );
		$num_tags = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "post_tag"])' );
		$this->assertEquals( 1, $num_tags );
		$num_custom_terms = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "custom"])' );
		$this->assertEquals( 0, $num_custom_terms );

		// count posts
		$num_items = $this->xpath_evaluate( 'count(/rss/channel/item)' );
		$this->assertEquals( 1, $num_items );
		$num_posts = $this->xpath_evaluate( 'count(/rss/channel/item[wxr:type = "post"])' );
		$this->assertEquals( 1, $num_posts );

		//count terms on posts
		$num_posts_c3 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c3"])' );
		$this->assertEquals( 1, $num_posts_c3 );
		$num_posts_c2 = $this->xpath_evaluate( 'count(/rss/channel/item/category[@domain = "category" and @wxr:slug = "c2"])' );
		$this->assertEquals( 0, $num_posts_c2 );

		// check that all post terms are included among the exported terms
		foreach ( $this->xpath_evaluate( '/rss/channel/item/category' ) as $term ) {
			$tax = $term->getAttributeNode( 'domain' )->nodeValue;
			$slug = $term->getAttributeNodeNS( WXR_Exporter::WXR_NAMESPACE_URI, 'slug' )->nodeValue;
			$num_terms = $this->xpath_evaluate( "count(/rss/channel/wxr:term[wxr:taxonomy = '$tax' and wxr:slug = '$slug'])");
			$this->assertEquals( 1, $num_terms );
		}
	}

	function test_extension_markup() {
		add_filter( 'wxr_export_extension_namespaces', function( $ext ) {
			$plugins[] = array(
				'prefix' => 'example',
				'namespace-uri' => 'urn:example',
				);

			return $plugins;
		} );
		add_filter( 'wxr_export_extension_markup', function( ) {
			$plugins[] = array(
				'namespace-uri' => 'urn:example',
				'plugin-name' => 'Test',
				'plugin-slug' => 'test/plugin.php',
				'plugin-uri' => 'http://example.org/test'
			);

			return $plugins;
		} );
		add_action( 'wxr_export_post', function( $writer, $post ) {
			$writer->startElement( 'Q{urn:example}custom_table_row' );
			$writer->writeElement( 'Q{urn:example}post_id', $post->ID );
			$writer->endElement();
		}, 10, 2 );

		$exporter = $this->get_exporter( array( 'content' => 'all' ) );
		$wxr = $exporter->export ();

		$this->is_well_formed( $wxr );

		$this->register_extension_namespaces( array( 'test' => 'urn:example' ) );

		$num_posts = $this->xpath_evaluate( 'count(/rss/channel/item)' );
		$this->assertEquals( 5, $num_posts );

		$num_posts_with_extension_markup = $this->xpath_evaluate( 'count(/rss/channel/item[test:custom_table_row])' );
		$this->assertEquals( 5, $num_posts_with_extension_markup );

		$ext_markup_pi = $this->xpath_evaluate( 'count(//processing-instruction("WXR_Importer"))' );
		$this->assertEquals( 1, $ext_markup_pi );

		$ext_markup_pi = $this->xpath_evaluate( '//processing-instruction("WXR_Importer")' );
		$ext_markup_pi = $ext_markup_pi->item( 0 );
		$this->assertEquals( "namespace-uri='urn:example' plugin-name='Test' plugin-slug='test/plugin.php' plugin-uri='http://example.org/test' ", $ext_markup_pi->nodeValue );
	}

	function test_custom_export_filter() {
		add_filter( 'export_term_ids', function( $term_ids, $filters ) {
			if ( 'taxonomies' === $filters['content'] && ! empty( $filters['taxonomies'] ) ) {
				$term_ids = get_terms( array( 'taxonomy' => $filters['taxonomies'], 'fields' => 'ids' ) );
			}

			return $term_ids;
		}, 10, 2 );

		$exporter = $this->get_exporter( array( 'content' => 'taxonomies', 'taxonomies' => 'post_tag' ) );
		$wxr = $exporter->export ();

		$this->is_well_formed( $wxr );

		// count users
		$num_users = $this->xpath_evaluate( 'count(/rss/channel/wxr:user)' );
		$this->assertEquals( 0, $num_users );

		// count terms
		$num_terms = $this->xpath_evaluate( 'count(/rss/channel/wxr:term)' );
		$this->assertEquals( 2, $num_terms );

		$num_terms = $this->xpath_evaluate( 'count(/rss/channel/wxr:term[wxr:taxonomy = "post_tag"])' );
		$this->assertEquals( 2, $num_terms );

		// count posts
		$num_items = $this->xpath_evaluate( 'count(/rss/channel/item)' );
		$this->assertEquals( 0, $num_items );
	}
}