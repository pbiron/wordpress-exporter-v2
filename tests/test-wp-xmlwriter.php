<?php

require_once __DIR__ . '/../wp-includes/class-wp-xmlwriter.php';

/**
 * @group xml
 * @group wp-xmlwriter
 *
 * Test writing various XML instances with WP_XMLWriter.
 */
class WP_XMLWriter_Tests extends Exporter_UnitTestCase {
	protected $file;

	function __construct() {
		$this->file = __DIR__ . '/data/write.xml';
	}

	function setUp() {
		parent::setUp();

		 @unlink( $this->file );
	}

	function tearDown() {
		libxml_clear_errors();

		@unlink( $this->file );

		parent::tearDown();
	}

	function test_well_formedness() {
		$writer = new WP_XMLWriter();

		$writer->openDocument();
		$writer->writeElement( 'foo:bar', 'content', array( 'xmlns:foo' => 'urn:foo' ) );
		$xml = $writer->endDocument();
		$this->is_well_formed( $xml );

		$writer->openDocument();
		$writer->writeElement( 'Q{urn:foo}bar', 'content' );
		$xml = $writer->endDocument();
		$this->is_well_formed( $xml );

		$writer->openDocument();
		$writer->startElement( 'foo:bar', array( 'xmlns:foo' => 'urn:foo' ) );
		$writer->writeElement( 'foo:baz' );
		$xml = $writer->endDocument();
		$this->is_well_formed( $xml );

		$writer->openDocument();
		$writer->startElement( 'foo:bar', array( 'xmlns:foo' => 'urn:foo' ) );
		$writer->writeElement( 'Q{urn:foo}baz' );
		$xml = $writer->endDocument();
		$this->is_well_formed( $xml );
	}

	/**
	 * This test writes instances that would be non-well-formed
	 * if WP_XMLWriter didn't prevent them.
	 *
	 * If one were to do any of the following with vanilla XMLWriter they would
	 * result in non-well-formed instances.
	 */
	function test_non_well_formedness() {
		$wp_xmlwriter = new WP_XMLWriter();
		$vanilla_xmlwriter = new XMLWriter();

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->writeElement( 'foo:bar', 'content' );// this should be ignored, because prefix "foo" isn't bound
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml, false );// not well-formed because no root element,
											 // this should be the only case that WP_XMLWriter
											 // creates a non-wellformed instance

		// this one is the mirror of the previous one but using vanilla XMLWriter
		// the only difference is in WHY it is not well-formed
		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->writeElement( 'foo:bar', 'content' );// this should be ignored, because prefix "foo" isn't bound
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		$this->is_well_formed( $xml, false );// not well-formed because prefix "foo" is not bound

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'Q{urn:foo}bar' );
		$wp_xmlwriter->writeElement( 'foo', 'content' );
		$wp_xmlwriter->writeAttribute( 'foo', 'bar' );// this should be ignored, because a child element has already been written
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->writeElement( 'foo', 'content' );
		$wp_xmlwriter->endElement();
		$wp_xmlwriter->writeElement( 'afterRoot' );// this should be ignored because the root element has already been closed
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		// this one is the mirror of the previous one but using vanilla XMLWriter
		// to show that XMLWriter does, in fact, produce a non-well-formed instance
		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startDocument();
		$vanilla_xmlwriter->startElement( 'root' );
		$vanilla_xmlwriter->writeElement( 'foo', 'content' );
		$vanilla_xmlwriter->endElement();
		$vanilla_xmlwriter->writeElement( 'afterRoot' );// this will result in a non-well-formed instance
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		$this->is_well_formed( $xml, false );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->text('test');// this should be ignored because text content can't appear before the root element
		$wp_xmlwriter->writeElement( 'root' );
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		// this one is the mirror of the previous one but using vanilla XMLWriter
		// to show that XMLWriter does, in fact, produce a non-well-formed instance
		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startDocument();
		$vanilla_xmlwriter->text( 'test' );
		$vanilla_xmlwriter->startElement( 'root' );
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		$this->is_well_formed( $xml, false );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->writeElement( 'this is a test', 'content' );// this should be ignored because it isn't a legal element name
		$wp_xmlwriter->endElement();
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		// this one is the mirror of the previous one but using vanilla XMLWriter
		// to show that while XMLWriter does ignore the illegal element name,
		// it generates error output (which can't be captured by libxml_use_internal_errors( true )).
		// That error output causes phpunit to throw an expection, so we trap that here.
		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startDocument();
		$vanilla_xmlwriter->startElement( 'root' );
		try {
			$vanilla_xmlwriter->writeElement( 'this is a test', 'content' );
		}
		catch ( Exception $exception ) {}
		$vanilla_xmlwriter->endElement();
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		$this->is_well_formed( $xml );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->writeAttribute( 'this is a test', 'value' );// this should be ignored because it isn't a legal
															   // attribute name
		$wp_xmlwriter->endElement();
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		// this one is the mirror of the previous one but using vanilla XMLWriter
		// to show that XMLWriter does ignore the illegal attribute name,
		// it generates error output (which can't be captured by libxml_use_internal_errors( true )).
		// That error output causes phpunit to throw an expection, so we trap that here.
		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startDocument();
		$vanilla_xmlwriter->startElement( 'root' );
		try {
			$vanilla_xmlwriter->writeAttribute( 'this is a test', 'value' );
		}
		catch ( Exception $exception ) {}
		$vanilla_xmlwriter->endElement();
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		$this->is_well_formed( $xml );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->writeElement( 'root', 'content' );
		$wp_xmlwriter->writePI( 'test', 'content' );
		$wp_xmlwriter->writePI( 'xml', 'content' );// this should be ignored because 'xml' is an illegal PI target
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->writeElement( 'root', 'content' );
		$wp_xmlwriter->writeXmlDecl();// this should be ignored because the default xmlDecl will already have been written
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->startElement( 'foo', 'content' ); // this should be ignored because 'content' isn't an array of attributes
		$wp_xmlwriter->writeElement( 'bar' );
		$wp_xmlwriter->endElement();// </foo>, will actually close root since <foo> wasn't written
		$wp_xmlwriter->endElement();// will be ignored because </root> has already been closed
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->writeAttribute( 'xmlns:xml', 'urn:illegal' );// this should be ignored, because the prefix "xml"
															  // can't be bound to anything other than
															  // http://www.w3.org/XML/1998/namespace
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startDocument();
		$vanilla_xmlwriter->startElement( 'root' );
		$vanilla_xmlwriter->writeAttribute( 'xmlns:xml', 'urn:illegal' );
		$vanilla_xmlwriter->endElement();
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		$this->is_well_formed( $xml, false );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->writeAttribute( 'xmlns:xmlns', 'urn:illegal' );// this should be ignored, because the prefix "xmlns"
																	  // can't be bound to anything
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );

		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startDocument();
		$vanilla_xmlwriter->startElement( 'root' );
		$vanilla_xmlwriter->writeAttribute( 'xmlns:xmlns', 'urn:illegal' );
		$vanilla_xmlwriter->endElement();
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		$this->is_well_formed( $xml, false );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->writeAttribute( 'xml:space', 'foo' );// this should be ignored because 'foo' isn't a legal value for @xml:space
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );
		$attr = $this->xpath_evaluate( 'root/@xml:space' );
		$this->assertEquals( 0, $attr->length );

		// this one is the mirror of the previous one but using vanilla XMLWriter
		// to show that XMLWriter does, in fact, produce a non-well-formed instance
		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startElement( 'root' );
		$vanilla_xmlwriter->writeAttribute( 'xml:space', 'foo' );// this will result in a non-well-formed instance
																 // because 'foo' isn't a legal value for @xml:space
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		// DOMDocument and xml_parser are both broken with respect to illegal values of
		// @xml:space, so skip them for this test
		$this->is_well_formed( $xml );

		$wp_xmlwriter->openDocument();
		$wp_xmlwriter->startElement( 'root' );
		$wp_xmlwriter->writeAttribute( 'xml:id', 'this is a test' );// this should be ignored because 'this is a test'
																	// isn't a legal value for @xml:id
		$xml = $wp_xmlwriter->endDocument();
		$this->is_well_formed( $xml );
		$attr = $this->xpath_evaluate( 'root/@xml:id' );
		$this->assertEquals( 0, $attr->length );

		// this one is the mirror of the previous one but using vanilla XMLWriter
		// to show that XMLWriter does, in fact, produce a non-well-formed instance
		$vanilla_xmlwriter->openMemory();
		$vanilla_xmlwriter->startElement( 'root' );
		$vanilla_xmlwriter->writeAttribute( 'xml:id', 'this is a test' );// this should be ignored because 'this is a test'
															 			 // isn't a legal value for @xml:id
		$vanilla_xmlwriter->endDocument();
		$xml = $vanilla_xmlwriter->outputMemory();
		// xml_parser is broken with respect to illegal values of
		// @xml:id, so skip it for this test
		$this->is_well_formed( $xml, false, self::XML_PARSER );
	}

	function test_write_to_file() {
		$writer = new WP_XMLWriter();
		$writer->openDocument( $this->file );

		$writer->writeElement( 'root' );
		$writer->endDocument();
		$this->assertTrue( is_file( $this->file ) );
		$this->is_well_formed( $this->file );
	}

	function test_write_to_stdout() {
		$writer = new WP_XMLWriter();
		$writer->openDocument( 'php://output' );

		ob_start();
		$writer->writeElement( 'root' );
		$writer->endDocument();
		$xml = ob_get_clean();
		$this->is_well_formed( $xml );
	}
}