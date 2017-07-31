<?php

require __DIR__ . '/../wp-includes/class-wxr-exporter.php';

abstract class Exporter_UnitTestCase extends WP_UnitTestCase {
	/**
	 * PHP's DOMDocument.
	 *
	 * @var int
	 */
	const DOMDOCUMENT = 1;

	/**
	 * PHP's XMLReader.
	 *
	 * @var int
	 */
	const XMLREADER = 2;

	/**
	 * PHP's SimpleXML.
	 *
	 * @var int
	 */
	const SIMPLEXML = 4;

	/**
	 * PHP's xml_parser.
	 *
	 * @var int
	 */
	const XML_PARSER = 8;

	/**
	 * All PHP XML parsers.
	 *
	 * @var int
	 */
	const ALL_PARSERS = -1;

	/**
	 * No PHP XML Parsers.
	 *
	 * @var int
	 */
	const NO_PARSERS = 0;

	/**
	 * DOMDocument object to load with XML for tests.
	 *
	 * @var DOMDocument
	 */
	protected $dom;

	/**
	 * DOMXPath object to use for XPath tests.
	 *
	 * @var DOMXPath
	 */
	protected $xpath;

	protected $libxml_use_internal_errors;

	/**
	 * Constructor.
	 *
	 * Turn on libxml "internal" error processing.
	 */
	function __construct() {
		$this->libxml_use_internal_errors = libxml_use_internal_errors( true );
	}

	/**
	 * Destructor.
	 *
	 * Reset libxml "internal" error processing.
	 */
	function __destruct() {
		libxml_use_internal_errors( $this->libxml_use_internal_errors );
	}

	/**
	 * Populate common test content so that we can test both the Export API and the WXR Export
	 * on the same content.
	 */
	protected function populate_test_content() {
		$u1 = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $u1 );
		$u2 = $this->factory->user->create( array( 'role' => 'editor' ) );
		// should not appear in exports
		$u3 = $this->factory->user->create( array( 'role' => 'editor' ) );

		register_taxonomy( 'custom', array( 'post', 'page' ) );

		$c1 = $this->factory->term->create( array( 'taxonomy' => 'category', 'name' => 'C1' ) );
		$c2 = $this->factory->term->create( array( 'taxonomy' => 'category', 'name' => 'C2' ) );
		$c3 = $this->factory->term->create( array( 'taxonomy' => 'category', 'name' => 'C3', 'parent' => $c1 ) );

		$this->factory->post->create( array( 'post_type' => 'post', 'post_author' => $u1,
			'tax_input' => array(
				'category' => array( $c1 ),
				'post_tag' => array( 'T1' ),
				'custom' => array( 'CT1', 'CT3' ),
			) ) );
		$this->factory->post->create( array( 'post_type' => 'post', 'post_author' => $u1,
			'tax_input' => array(
				'category' => array( $c2 ),
				'custom' => array( 'CT2' ),
			) ) );
		$this->factory->post->create( array( 'post_type' => 'post', 'post_author' => $u1,
			'tax_input' => array(
				'category' => array( $c3 ),
				'post_tag' => array( 'T2' ),
			) ) );

		$this->factory->post->create( array( 'post_type' => 'page', 'post_author' => $u2,
			'tax_input' => array(
				'custom' => array( 'CT3', 'CT4' ),
			) ) );

		$this->factory->post->create( array( 'post_type' => 'page', 'post_author' => $u2 ) );
	}

	/**
	 * Test whether XML is wellformed with builtin PHP XML parsers.
	 *
	 * @param string $xml `$xml` is either a filename that contains XML or a string
	 * 					  that contains XML.
	 * @param bool $expected Whether `$xml` is expected to be well-formed.  Default true.
	 * @param int $skip_parsers Bitmap of PHP parsers to skip for this test,
	 * 							in case it is known that one of the PHP parsers
	 * 							is broken with respect to some aspect of the XML-related specs.
	 * 							Accepts one or more of self::DOMDOCUMENT, self::XMLREADER,
	 * 							self::SIMPLEXML, self::XML_PARSER joined with the
	 * 							bitwise OR ('|') operator.
	 */
	protected function is_well_formed( $xml, $expected = true, $skip_parsers = self::NO_PARSERS ) {
		if ( is_readable( $xml ) ) {
			$xml = file_get_contents( $xml );
		}
		elseif ( is_string( $xml ) ) {
			// already have XML instance as a string, so no-op
		}
		else {
			// bail
			$this->assertFalse( true, '$xml must be a readble filename or a string' );
		}

		// compute the parsers to use to test well-formedness
		$parsers = self::ALL_PARSERS ^ $skip_parsers;

		if ( $parsers & self::DOMDOCUMENT ) {
			$this->dom_is_well_formed( $xml, $expected ) ;
		}
		if ( $parsers & self::XMLREADER ) {
			$this->xmlreader_is_well_formed( $xml, $expected ) ;
		}
		if ( $parsers & self::SIMPLEXML ) {
			$this->simplexml_is_well_formed( $xml, $expected ) ;
		}
		if ( $parsers & self::XML_PARSER ) {
			$this->xml_parser_is_well_formed( $xml, $expected ) ;
		}
	}

	/**
	 * Test whether some XML is well-formed using PHP's DOMDocument.
	 *
	 * @param string $xml @see Exporter_UnitTestCase::is_well_formed().
	 * @param bool $expected @see Exporter_UnitTestCase::is_well_formed().
	 */
	protected function dom_is_well_formed( $xml, $expected = true ) {
		libxml_clear_errors();

		$this->dom = new DOMDocument();

		$this->dom->loadXML( $xml );

		$this->assertEquals( $expected, ! $this->libxml_errors() );

		/*
		 * While not part of well-formedness check, per se, it is convenient to
		 * register the namespace prefix=>URI bindings here.
		 */
		$this->register_wxr_namespaces();

		libxml_clear_errors();
	}

	/**
	 * Test whether some XML is well-formed using PHP's XMLReader.
	 *
	 * @param string $xml @see Exporter_UnitTestCase::is_well_formed().
	 * @param bool $expected @see Exporter_UnitTestCase::is_well_formed().
	 */
	protected function xmlreader_is_well_formed( $xml, $expected ) {
		libxml_clear_errors();

		$xmlreader = new XMLReader();

		$xmlreader->XML( $xml );

		while ( $xmlreader->read() ) {}

		$this->assertEquals( $expected, ! $this->libxml_errors() );

		libxml_clear_errors();
	}

	/**
	 * Test whether some XML is well-formed using PHP's SimpleXML.
	 *
	 * @param string $xml @see Exporter_UnitTestCase::is_well_formed().
	 * @param bool $expected @see Exporter_UnitTestCase::is_well_formed().
	 */
	protected function simplexml_is_well_formed( $xml, $expected ) {
		libxml_clear_errors();

		simplexml_load_string( $xml );

		$this->assertEquals( $expected, ! $this->libxml_errors() );

		libxml_clear_errors();
	}

	/**
	 * Test whether some XML is well-formed using PHP's XML_Parser.
	 *
	 * @param string $xml @see Exporter_UnitTestCase::is_well_formed().
	 * @param bool $expected @see Exporter_UnitTestCase::is_well_formed().
	 */
	protected function xml_parser_is_well_formed( $xml, $expected ) {
		$xml_parser = xml_parser_create_ns();

		xml_parse( $xml_parser, $xml, true );

		$success = XML_ERROR_NONE === xml_get_error_code( $xml_parser );

		$this->assertEquals( $expected, $success );
	}

	/**
	 * Register the WXR namespaces.
	 */
	protected function register_wxr_namespaces() {
		if ( ! $this->dom ) {
			return;
		}
		$this->xpath = new DOMXPath( $this->dom );

		$this->xpath->registerNamespace( 'wxr', WXR_Exporter::WXR_NAMESPACE_URI );
		$this->xpath->registerNamespace( 'dc', WXR_Exporter::DUBLIN_CORE_NAMESPACE_URI );
		$this->xpath->registerNamespace( 'content', WXR_Exporter::RSS_CONTENT_NAMESPACE_URI );
	}

	/**
	 * Register extension namespaces.
	 *
	 * Intended for use in test cases that include extension markup.
	 *
	 * @param array $nses Keys are namespace prefixes, values are namepace URIs.
	 */
	protected function register_extension_namespaces( $nses = array() ) {
		foreach ( $nses as $prefix => $uri ) {
			$this->xpath->registerNamespace( $prefix, $uri );
		}
	}

	/**
	 * Evaulate an XPath 1.0 expression against the current DOM.
	 *
	 * @param string $expression An XPath 1.0 expression.
	 * @return bool|DOMNodeList Result of evaluating the expression: a boolean
	 * 							if the expression is a "count()" expression,
	 * 							a DOMNodeList otherwise.
	 */
	protected function xpath_evaluate( $expression ) {
		if ( ! $this->xpath ) {
			return false;
		}

		return $this->xpath->evaluate( $expression );
	}

	/**
	 * Test whether there have been any libxml errors.
	 *
	 * @param array $levels One or more of LIBXML_ERR_FATAL, LIBXML_ERR_ERROR or LIBXML_ERR_WARNING.
	 * @return bool True if libxml_get_errors() returns any errors whose `$level` property
	 * 				is in `$levels`; false otherwise.
	 *
	 * Note: this would have been easier to implement had PHP (or maybe libxml) had defined
	 * the error levels in a "bitmappable" way.
	 */
	protected function libxml_errors( $levels = array( LIBXML_ERR_FATAL, LIBXML_ERR_ERROR ) ) {
		foreach ( libxml_get_errors() as $err ) {
			if ( in_array( $err->level, $levels ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a double-quoted string containing \u{xxxx} escape sequences to UTF-8.
	 *
	 * PHP 7.0.0 introduced the \u{xxxx} escape sequence for Unicdoe codepoints.
	 * This method allows tests to use these escape sequences even when run with
	 * PHP < 7.0.0.
	 *
	 * @param string $str Double-quoted string possibly containing \u{xxxx} escape sequences.
	 * @param string $in_encoding Encoding of the calling script.
	 * @return string
	 *
	 * @todo consider making this a method of WP_XML and use it internally in
	 * WP_XML::parseEQName() and WP_XMLWriter::text().  That is not done at this point
	 * because it should only be called for double-quoted strings and there is no way
	 * to know whether a string parameter was double- or single-quoted in the caller.
	 */
	protected function evaulate_unicode_escape_sequences( $str, $in_encoding = 'UCS-2') {
		if ( version_compare( PHP_VERSION, '7.0.0', '>=' ) ) {
			return $str;
		}

	    return preg_replace_callback('/\\\\u{([0-9a-fA-F]+)}/u', function ( $match ) use ( $in_encoding ) {
	        return mb_convert_encoding( pack( 'H*', $match[1] ), 'UTF-8', $in_encoding );
	    }, $str );
	}
}

?>