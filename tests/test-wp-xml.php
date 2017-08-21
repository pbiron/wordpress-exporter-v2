<?php

require_once dirname( __FILE__ ) . '/../wp-includes/wp-xml.php';

/**
 * @group xml
 * @group wp-xml
 *
 * Test basic XML non-terminal checking and namespace binding operatings
 */
class WP_XML_Tests extends Exporter_UnitTestCase {
	function test_illegal_chars() {
		$success = WP_XML::containsIllegalChars( 'this is a test' );
		$this->assertFalse( $success );

		$success = WP_XML::containsIllegalChars( $this->evaulate_unicode_escape_sequences( "this \u{fffd} is a test" ) );
		$this->assertFalse( $success );

		$success = WP_XML::containsIllegalChars( "this \0 is a test" );
		$this->assertTrue( $success );

		$success = WP_XML::containsIllegalChars( $this->evaulate_unicode_escape_sequences( "this \u{ffff} is a test" ) );
		$this->assertTrue( $success );
	}

	function test_whitespace() {
		$success = WP_XML::isWhiteSpace( '   ' );
		$this->assertTrue( $success );

		$success = WP_XML::isWhiteSpace( " \t " );
		$this->assertTrue( $success );

		$success = WP_XML::isWhiteSpace( " \t\n\r " );
		$this->assertTrue( $success );

		$success = WP_XML::isWhiteSpace( 'this is a test' );
		$this->assertFalse( $success );

		$success = WP_XML::isWhiteSpace( '' );
		$this->assertFalse( $success );
	}

	function test_legal_attributes() {
		$success = WP_XML::isLegalAttribute( 'foo', 'bar' );
		$this->assertTrue( $success );

		$success = WP_XML::isLegalAttribute( $this->evaulate_unicode_escape_sequences( "foo\u{00ed}" ), 'bar' );
		$this->assertTrue( $success );

		$success = WP_XML::isLegalAttribute( 'xml:id', 'bar' );
		$this->assertTrue( $success );

		$success = WP_XML::isLegalAttribute( 'xmlns', 'urn:foo' );
		$this->assertTrue( $success );

		$success = WP_XML::isLegalAttribute( 'xmlns:xml', WP_XML::XML_NAMESPACE_URI );
		$this->assertTrue( $success );

		$success = WP_XML::isLegalAttribute( 'xmlns:prefix', 'urn:foo' );
		$this->assertTrue( $success );

		$success = WP_XML::isLegalAttribute( 'xmlns:xml', 'urn:foo' );
		$this->assertFalse( $success );

		$success = WP_XML::isLegalAttribute( 'xmlns:xmlns', 'urn:foo' );
		$this->assertFalse( $success );

		$success = WP_XML::isLegalAttribute( 'this is a test', 'bar' );
		$this->assertFalse( $success );

		$success = WP_XML::isLegalAttribute( 'xml:id', 'this is a test' );
		$this->assertFalse( $success );
	}

	function test_NCNames() {
		$success = WP_XML::isNCName( 'localName' );
		$this->assertTrue( $success );

		$success = WP_XML::isNCName( 'prefix:localName' );
		$this->assertFalse( $success );

		$success = WP_XML::isNCName( $this->evaulate_unicode_escape_sequences( "\u{d7e1}\u{fffd}" ) );
		$this->assertTrue( $success );

		$success = WP_XML::isNCName( 'this is a test' );
		$this->assertFalse( $success );

		$success = WP_XML::isNCName( 'prefix::localName' );
		$this->assertFalse( $success );
	}

	function test_QNames() {
		// @todo add more tests, including non-ascii localnames
		$success = WP_XML::isQName( 'localName' );
		$this->assertTrue( $success );

		$success = WP_XML::isQName( 'prefix:localName' );
		$this->assertTrue( $success );

		$success = WP_XML::isQName( $this->evaulate_unicode_escape_sequences( "\u{39e9}\u{d12}:\u{d7e1}\u{fffd}" ) );
		$this->assertTrue( $success );

		$success = WP_XML::isQName( 'this is a test' );
		$this->assertFalse( $success );

		$success = WP_XML::isQName( 'prefix::localName' );
		$this->assertFalse( $success );
	}

	function test_EQNames() {
		// @todo add more tests, including non-ascii localnames
		$success = WP_XML::isEQName( 'Q{urn:foo}localName' );
		$this->assertTrue( $success );

		$success = WP_XML::isEQName( 'prefix:localName' );
		$this->assertTrue( $success );

		$success = WP_XML::isEQName( $this->evaulate_unicode_escape_sequences( "\u{39e9}\u{d12}:\u{d7e1}\u{fffd}" ) );
		$this->assertTrue( $success );

		$success = WP_XML::isEQName( 'localName' );
		$this->assertTrue( $success );

		$success = WP_XML::isEQName( 'Q{urn:f}oo}localName' );
		$this->assertFalse( $success );

		$success = WP_XML::isEQName( '{urn:foo}localName' );
		$this->assertFalse( $success );

		$success = WP_XML::isEQName( 'prefix::localName' );
		$this->assertFalse( $success );
	}

	function test_namespace_bindings() {
		$namespaces = new XML_Namespace_Bindings();

		// eqivalent to something like <localName xmlns:foo='urn:foo' xmlns:bar='urn:bar' xmlns='urn:default'>
		$namespaces->bindNamespaces( array( 'foo' => 'urn:foo', 'bar' => 'urn:bar', null => 'urn:default' ) );
		// eqivalent to something like <baz:localName xmlns:baz='urn:baz' xmlns:biff='urn:biff'>
		$namespaces->bindNamespaces( array( 'baz' => 'urn:baz', 'biff' => 'urn:biff' ) );

		$prefix = $namespaces->get_prefix( 'urn:foo' );
		$this->assertEquals( $prefix, 'foo' );

		$prefix = $namespaces->get_prefix( 'urn:bar' );
		$this->assertEquals( $prefix, 'bar' );

		$prefix = $namespaces->get_prefix( 'urn:default' );
		$this->assertEquals( $prefix, null );

		$uri = $namespaces->get_uri( 'foo' );
		$this->assertEquals( $uri, 'urn:foo' );

		$uri = $namespaces->get_uri( 'bar' );
		$this->assertEquals( $uri, 'urn:bar' );

		$uri = $namespaces->get_uri( null );
		$this->assertEquals( $uri, 'urn:default' );

		$prefix = $namespaces->get_prefix( 'urn:baz' );
		$this->assertEquals( $prefix, 'baz' );

		$prefix = $namespaces->get_prefix( 'urn:biff' );
		$this->assertEquals( $prefix, 'biff' );

		$uri = $namespaces->get_uri( 'baz' );
		$this->assertEquals( $uri, 'urn:baz' );

		$uri = $namespaces->get_uri( 'biff' );
		$this->assertEquals( $uri, 'urn:biff' );

		// equivalent to WP_XMLWriter::writeElement('Q{urn:unbound}localName', 'content')
		// given the above namespace bindings
		$unique_prefix = $namespaces->generate_unique_prefix();
		$bound_prefixes = array( 'foo', 'bar', 'baz', 'biff' );
		$this->assertTrue( ! in_array( $unique_prefix, $bound_prefixes ) );
		$bound_prefixes[] = $unique_prefix;
		$namespaces->bindNamespaces( array( $unique_prefix => 'urn:barf' ) );

		$unique_prefix = $namespaces->generate_unique_prefix();
		$this->assertTrue( ! in_array( $unique_prefix, $bound_prefixes ) );
		$namespaces->unbindNamespaces();

		// equivalent to </baz:localName>
		$namespaces->unbindNamespaces();

		$prefix = $namespaces->get_prefix( 'urn:foo' );
		$this->assertEquals( $prefix, 'foo' );

		$prefix = $namespaces->get_prefix( 'urn:bar' );
		$this->assertEquals( $prefix, 'bar' );

		$uri = $namespaces->get_uri( 'foo' );
		$this->assertEquals( $uri, 'urn:foo' );

		$uri = $namespaces->get_uri( 'bar' );
		$this->assertEquals( $uri, 'urn:bar' );

		$prefix = $namespaces->get_prefix( 'urn:baz' );
		$this->assertFalse( $prefix );

		$prefix = $namespaces->get_prefix( 'urn:biff' );
		$this->assertFalse( $prefix );

		$uri = $namespaces->get_uri( 'baz' );
		$this->assertFalse( $uri );

		$uri = $namespaces->get_uri( 'biff' );
		$this->assertFalse( $uri );
	}
}