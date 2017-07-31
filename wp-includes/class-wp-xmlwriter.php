<?php
/**
 * WP XML Writer API
 *
 * @package XML
 * @subpackage WP XML Writer
 */

/*
 * This goals of this API are:
 *
 * 1. Prevent writing non-well-formed instances.  As is, PHP's XMLWriter class
 * 	does not.  The WP_XMLWriter class is a light-weight wrapper around PHP's
 *  XMLWriter which prevents non-well-formed instances from being written.
 * 2. Manage in-scope namespace declarations.  As is, PHP's XMLWriter class does not.
 * 3. Simplify the writing of namespace-qualified element/attribute names.  PHP's
 *  XMLWriter class has separate methods for writing unqualified and qualified
 *  element/attribute names.
 *
 * @todo It is not possible to programmatically analyze this API and
 * detect whether it is possible to write non-well-formed instances.  Therefore,
 * needs much more testing, especially by people who are not that familiar with
 * what constitutes well-formedness (since those who do understand the finer
 * points of the XML spec are not likely to TRY to do things that would result
 * in non-well-formed intsances).  As far as I know, at this point, the
 * only non-well-formed instances that can be generated are those that
 * contain no elements at all (e.g., `$writer = new WP_XMLWirter(); $writer->open();
 * $writer->endDocument();`).
 *
 * @todo figure out return val strategy.  currently all of the (write|start)xxx() methods
 * return true|false (like XMLWriter does).  It might be better to return
 * WP_Error() for failure...to indicate what the problem was.
 *
 * @todo It is possible to store text in the WP database that contains
 * characters that are not legal in an XML document, and writing that
 * text as is would result in non-well-formed instances.  "intelligently"
 * deal with characters that are outside the range of characters allowed
 * by the Char production in the XML Spec (@link https://www.w3.org/TR/xml/#NT-Char).
 * Currently, if element/attribute content contains illegal characters
 * the entire string is silently ignored. "intelligently", in this context,
 * means that I'm not sure just stripping them is the correct thing, but
 * introducing a WP-specific way of encoding them is also not a good idea
 * (for example, would other CMS's importing a WXR instance know what to do
 * with them?).  Note that XMLWriter "unintelligently" deals with the null
 * character since it is just a wrapper around the XMLWriter from libxml
 * which is written in C and, hence, when a PHP string containing the null
 * character is passed to XMLWriter::text() it not only strips the null
 * character by it terminates the string at that point :-(
 *
 * @todo figure out string encoding strategy.  PHP's XMLWriter class expects all strings to be
 * UTF-8.  So, should callers be required to convert strings (element/attribute names/content/values)
 * to UTF-8 or can we reliably convert then internally?  My initial attempts at converting them internally
 * haven't worked...mostly because I don't understand PHP's string model very well,
 * which suggests we should figure out how to do the conversions interally because
 * I suspect most developers share my miss/lack-of understanding.  Without this, could
 * easily produce non-well-formed instances!
 *
 * @todo PHP's XMLWriter's methods to write various DTD constructs are broken, so we
 * don't include any methods to write DTD stuff.  Should we write our own methods that do
 * so?  I don't think it's worthwhile.
 *
 * @todo write DevHub Handbook documentation for this API (once it stabilizes).
 */

require_once __DIR__ . '/wp-xml.php';

/**
 * A light-weight wrapper around PHP's XMLWriter class that prevents writing
 * non-well-formed XML and simplifies many operations.
 */
class WP_XMLWriter extends WP_XML {
	/**
	 * Write text node as CDATA section if doing so results in a smaller instance.
	 *
	 * @var integer
	 */
	const CDATA_FOR_SIZE = 1;

	/**
	 * Write text node as CDATA section if doing so results in a more "human readable" instance.
	 *
	 * @var integer
	 */
	const CDATA_FOR_READABILITY = 2;

	/**
	 * The PHP XMLWriter to write to.
	 *
	 * @var XMLWriter
	 */
	protected $writer;

	/**
	 * The encoding for the XML instance.
	 *
	 * @var string
	 */
	protected $encoding = 'UTF-8';

	/**
	 * Whether the writer has been opened.
	 *
	 * @var bool
	 */
	protected $opened = false;

	/**
	 * Whether the a writer has been closed.
	 *
	 * @var bool
	 */
	protected $closed = false;

	/**
	 * Whether the XML Declaration has been written.
	 *
	 * @var bool
	 */
	protected $xmlDecl_written = false;

	/**
	 * Whether element content should be written as CDATA sections.
	 *
	 * Value is either false, self::CDATA_FOR_SIZE or self::CDATA_FOR_READABILITY.
	 *
	 * @var bool|int
	 */
	protected $selective_cdata_sections = self::CDATA_FOR_SIZE;

	/**
	 * Whether to generate namespace prefixes when URIQualifiedName is used
	 * and no in-scope namespace declaration for the URI exists.
	 *
	 * Given a call such as WP_XMLWriter::writeElement( 'Q{http://example.com}name', 'content' ),
	 * ff true, will result in something like "<ns0:name xmlns:ns0='http://example.com'>content</ns0:name>"
	 * being written; if false, will result in something like "<name xmlns='http://example.com'>content</name>"
	 * being written.
	 *
	 * @var bool
	 */
	protected $generate_prefixes = true;

	/**
	 * Whether to indent the XML that is written.
	 *
	 * @var bool
	 */
	protected $indent = true;

	/**
	 * String used when indenting the XML that is written.
	 *
	 * @var string
	 */
	protected $indent_string = "\t";

	/**
	 * Whether the writer has written an element start tag but
	 * not yet written it's end tag.
	 *
	 * @var bool
	 */
	protected $in_element = false;

	/**
	 * The current depth in the XML element tree.
	 *
	 * @var int
	 */
	protected $depth = 0;

	/**
	 * Whether the root element has been written.
	 *
	 * @var bool
	 */
	protected $root_written = false;

	/**
	 * The names of attributes on the most recently opened element.
	 *
	 * @var array
	 */
	protected $attributes = array();

	/**
	 * In-scope namespace bindings.
	 *
	 * @var XML_Namespace_Bindings
	 */
	protected $namespaces;

	/**
	 * Elements whose start tags have been written but whose end tags have
	 * not yet been written.
	 *
	 * @var array
	 */
	protected $open_elements = array();

	/**
	 * Constructor.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type bool|int $selective_cdata_sections
	 *     @type bool $generate_prefixes
	 *     @type bool $indent
	 *     @type string $indent_string
	 *     @type string $encoding
	 * }
	 */
	function __construct( $args = array() ) {
		$this->writer = new XMLWriter();
		if ( ! $this->writer ) {
			return new WP_Error( 'wp_xmlwriter.could_not_create', __( 'Could not create XMLWriter' ) );
		}

		$defaults = array(
			'selective_cdata_sections' => self::CDATA_FOR_SIZE,
			'generate_prefixes' => true,
			'indent' => true,
			'indent_string' => "\t",
			'encoding' => 'UTF-8',
		);
		$args = wp_parse_args( $args, $defaults );

		$this->encoding = $args['encoding'];
		$this->selective_cdata_sections = $args['selective_cdata_sections'];
		$this->generate_prefixes = $args['generate_prefixes'];

		$this->namespaces = new XML_Namespace_Bindings();
	}

	/**
	 * Destructor.
	 *
	 * End the document and flush the output.
	 */
	function __destruct() {
		$this->endDocument();
		$this->flush();
	}

	/**
	 * Open an XML document for writing.
	 *
	 * @param string $file The file to write to.  If `$file` is "php://output"
	 * 					   will write to standard out; if `$file` is null,
	 * 					   will write to a string in memory (which can be
	 * 					   retrieved with WP_XMLWriter::flush() or
	 * 					   WP_XMLWriter::outputMemory()).
	 * @return bool True on success, false on error.
	 */
	function openDocument( $file = null ) {
		if ( empty( $file ) ) {
			$success = $this->writer->openMemory();
		}
		else {
			$success = $this->writer->openURI( $file );
		}

		if ( ! $success ) {
			return false;
		}

		$this->reset();
		$this->opened = true;

		$this->setIndent( $this->indent );
		$this->setIndentString( $this->indent_string );

		$this->namespaces->reset();

		return true;
	}

	/**
	 * Write the XML declaration.
	 *
	 * @param string $version The version of XML.  Default '1.0'.
	 * @param string $encoding The encoding of the XML document.  Default 'utf-8'.
	 * @param string $standalone The Standalone document delcaration.  Acccepts 'yes'
	 * 							 and 'no'.  Default 'yes'.
	 * @return bool True on success, false on error.
	 */
	function writeXmlDecl() {
		if ( ! $this->opened ) {
			// prevent XMLWriter from generating an error message
			// note: even if libxml_user_internal_errors( true ), XMLWriter
			// still outputs an error message in this case.
			return false;
		}
		if ( $this->xmlDecl_written ) {
			// prevent outputing a 2nd xmlDecl, which would result
			// in non-well-formed instance because an XML parser would interpret it
			// as a processing instruction, whose targets are not allowed to begin with
			// [xX][mM][lL].
			return false;
		}

		if ( $this->writer->startDocument( '1.0', $this->encoding, 'yes' ) ) {
			$this->xmlDecl_written = true;

			return true;
		}

		return false;
	}

	/**
	 * Write the start tag for an element, including attributes.
	 *
	 * @param string $name The elements name.  Accepts any string matching
	 *					   https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName.
	 * @param array $attributes Attributes to be written on the element.
	 *                          The array keys are attribute names (that
	 *                          match https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName);
	 *                          the array values are the attribute values.
	 *                          If an attribute name begins with `xmlns` then
	 *                          it is written as a namespace declaration.
	 * @return bool|WP_Error True on success, false on error.
	 */
	function startElement( $name, $attributes = array() ) {
		if ( ! $this->opened || $this->closed ) {
			// prevent XMLWriter from generating an error message
			// note: even if libxml_user_internal_errors( true ), XMLWriter
			// still outputs an error message in this case.
			return false;
		}

		if ( $this->root_written ) {
			// prevent elements from being written AFTER the root element has been closed
			// which would result in non-well-formed instances
			return false;
		}

		if ( ! is_array( $attributes ) ) {
			return false;
		}

		if ( ! $this->xmlDecl_written ) {
			$this->writeXmlDecl();
		}

		$nsDecls = $this->extract_nsDecls( $attributes );
		$EQName = $this->expand_EQName( $name, $nsDecls );

		if ( is_wp_error( $EQName ) ) {
			return false;
		}

		if ( $EQName['prefix'] && ! $EQName['uri'] ) {
			// prefix is not currently bound to a URI
			return false;
		}

		if ( $EQName['add-ns-decl'] ) {
			$nsDecls[$EQName['prefix']] = $EQName['uri'];
			$attributes["xmlns:{$EQName['prefix']}"] = $EQName['uri'];
		}

		$this->namespaces->bindNamespaces( $nsDecls );

		if ( ! $EQName['uri'] || null === $EQName['prefix'] ) {
			$success = $this->writer->startElement ( $EQName['local-name'] );
		}
		elseif ( $EQName['prefix'] ) {
			$success = $this->writer->startElementNS( $EQName['prefix'], $EQName['local-name'], null );
		}
		else {
			$success = $this->writer->startElementNS( null, $EQName['local-name'], $EQName['uri'] );
		}

		if ( ! $success ) {
			return false;
		}

		$this->open_elements[] = $name;

		// write attributes, preventing the same attribute name
		// from being written more than once, which would result in a non-well-formed instance
		$attr_written = array();
		foreach ( (array) $attributes as $name => $value ) {
			if ( ! in_array( $name, $attr_written ) ) {
				if ( $EQName['uri'] && ! $EQName['prefix'] && 'xmlns' === $name ) {
					continue;
				}
				if ( ! $this->writeAttribute( $name, $value ) ) {
					return false;
				}
				$attr_written[] = $name;
			}
		}
		$this->attributes = $attr_written;

		unset( $attr_written );

//		$this->in_element = true;
//		$this->depth++;

		return true;
	}

	/**
	 * Write an attribute.
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool True on success, false on error.
	 */
	function writeAttribute( $name, $value ) {
		if ( ! $this->opened ) {
			// prevent XMLWriter from generating an error message
			// note: even if libxml_user_internal_errors( true ), XMLWriter
			// still outputs an error message in this case.
			return false;
		}

		if ( 0 === count( $this->open_elements ) ) {
			return false;
		}

		if ( ! self::isLegalAttribute( $name, $value ) ) {
			return false;
		}

		if ( in_array( $name, $this->attributes ) ) {
			return false;
		}

		$EQName = $this->expand_EQName( $name );
		if ( is_wp_error( $EQName ) ) {
			return false;
		}

		if ( 'xmlns' === $EQName['prefix'] ) {
			$success = $this->writer->writeAttribute( $name, $value );
		}
		elseif ( ! $EQName['uri'] ) {
			$success = $this->writer->writeAttribute( $EQName['local-name'], $value );
		}
		elseif ( $EQName['prefix'] ) {
			$success = $this->writer->writeAttributeNS( $EQName['prefix'], $EQName['local-name'], null, $value );
		}
		else {
			$success = $this->writer->writeAttributeNS( null, $EQName['local-name'], $EQName['uri'], $value );
		}

		return $success;
	}

	/**
	 * Write an element, including attributes and text content.
	 *
	 * @param string $name The elements name.  Accepts any string matching
	 *					   https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName.
	 * @param string $content The text content for the element.
	 * @param array $attributes Attributes to be written on the element.
	 *                          The array keys are attribute names (that
	 *                          match https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName);
	 *                          the array values are the attribute values.
	 *                          If an attribute name begins with `xmlns` then
	 *                          it is written as a namespace declaration.
	 * @return bool True on success, false on error.
	 */
	function writeElement( $name, $content = '', $attributes = array() ) {
		if ( ! $this->startElement( $name, $attributes ) ) {
			return false;
		}

		$success = $this->text( $content );

		if ( ! $this->endElement() ) {
			return false;
		}

		return $success;

	}

	/**
	 * Write text.
	 *
	 * @param string $content The text content to be written.
	 * @return bool True on success, false on error.
	 *
	 * @todo as of now, we require the caller to ensure string is UTF-8
	 * @todo figure out if XMLWriter expects EVERYTHING (including
	 * element/attribute names) to be in utf-8 or is it just text content.
	 */
	function text( $content ) {
		if ( ! $this->opened || $this->closed ) {
			// prevent XMLWriter from generating an error message
			// note: even if libxml_user_internal_errors( true ), XMLWriter
			// still outputs an error message in this case.
//			return false;
		}
//		if ( ! $this->in_element ) {
		if ( empty( $this->open_elements ) ) {
			return false;
		}

		if ( $this->containsIllegalChars( $content ) ) {
// 			 @todo figure out strategy for dealing with chars outside
// 			 the range of @link https://www.w3.org/TR/REC-xml/#NT-Char.
// 			 as of now, we skip the entire string.
			return false;
		}

		if ( $this->shouldCDATA( $content ) ) {
			$success = $this->writer->writeCData( $content );
		}
		else {
			$success = $this->writer->text( $content );
		}

		return $success;
	}

	/**
	 * Write the end tag for the current element.
	 *
	 * Will also end any other open elements.
	 */
	function endElement() {
		if ( ! $this->opened || $this->closed ) {
			// prevent XMLWriter from generating an error message
			// note: even if libxml_user_internal_errors( true ), XMLWriter
			// still outputs an error message in this case.
			return false;
		}

//		if ( ! $this->in_element ) {
		if ( 0 === count( $this->open_elements ) ) {
			return false;
		}

		if ( $this->writer->endElement() ) {
			$this->namespaces->unbindNamespaces();

//			$this->in_element = false;
			$this->attributes = array();

			array_shift( $this->open_elements );
//			$this->depth--;
//			if ( 0 === $this->depth ) {
			if ( 0 === count( $this->open_elements ) ) {
				// prevent elements from being written AFTER the root element has been closed
				// which would result in non-well-formed instances
				$this->root_written = true;
			}

			return true;
		}

		return false;
	}

	/**
	 * Write a comment.
	 *
	 * @param string $content
	 * @return bool True on success, false on error.
	 */
	function writeComment( $content ) {
		if ( ! $this->opened ) {
			// prevent XMLWriter from generating an error message
			// note: even if libxml_user_internal_errors( true ), XMLWriter
			// still outputs an error message in this case.
			return false;
		}

		if ( ! $this->xmlDecl_written ) {
			$this->writeXmlDecl();
		}

		return $this->writer->writeComment( $content );
	}

	/**
	 * Write a processing instruction.
	 *
	 * @param string $target
	 * @param string $content
	 * @return bool True on success, false on error.
	 */
	function writePI( $target, $content ) {
		if ( ! $this->opened || ! $this->xmlDecl_written /* || $this->closed */) {
			// prevent XMLWriter from generating an error message
			// note: even if libxml_user_internal_errors( true ), XMLWriter
			// still outputs an error message in this case.
			return false;
		}

		if ( ! self::isName( $target ) || preg_match ( '/^[xX][mM][lL]$/', $target ) ) {
			// $target is illegal
			// @link https://www.w3.org/TR/REC-xml/#NT-PITarget
			return false;
		}

		if ( false !== strpos( $content, '?>' ) ) {
			// $content is illegal
			// @link https://www.w3.org/TR/REC-xml/#NT-PI
			return false;
		}

		return $this->writer->writePI( $target, $content );
	}

	/**
	 * Write closing tags for all open elements and flush the buffer.
	 *
	 * @return int|string If writer opened with a filename, the number of bytes
	 * 					  written (which may be 0);  If writer opened for memory
	 * 					  buffer, the current contents of the memory buffer.
	 */
	function endDocument() {
		$success = $this->writer->endDocument();
		if ( $success ) {
			$this->closed = true;
		}

		return $this->flush();
	}

	/**
	 * Flush current buffer.
	 *
	 * @param bool $empty Whether to empty the memory buffer.  Default true.  Has no
	 * 					  effect if the writer has not been opened for memory buffer.
	 * @return int|string If writer opened with a filename, the number of bytes
	 * 					  written (which may be 0);  If writer opened for memory
	 * 					  buffer, the current contents of the memory buffer.
	 */
	function flush( $empty = true ) {
		return $this->writer->flush( $empty );
	}

	/**
	 * Set whether to indent the output.
	 *
	 * May be called multiple times during the life of a WP_XMLWriter, to turn
	 * indenting on/off for specific parts of the output.
	 *
	 * @param bool $indent True to indent, false to not indent.  Default true.
	 * @return bool True on success, false on error (including if called
	 * 				before WP_XMLWriter::open() has been called).
	 */
	function setIndent( $indent = true ) {
		if ( ! $this->opened ) {
			// prevent XMLWriter from outputing an error message
			return false;
		}

		$this->indent = $indent;

		return $this->writer->setIndent( $indent );
	}

	/**
	 * Set the string to be used for indenting.
	 *
	 * Only has an effect if WP_XMLWriter::setIndent( true ) has previously
	 * been called.
	 *
	 * If called before WP_XMLWirter::openURI() or WP_XMLWriter::openMemory(), then
	 * false will be returned.
	 *
	 * @param string $indentString The string to use for indenting.  Default "\t".
	 * @return bool True on success, false on error.
	 */
	function setIndentString( $indentString = "\t" ) {
		if ( ! $this->opened ) {
			// prevent XMLWriter from outputing an error message
			return false;
		}
		if ( ! self::isWhiteSpace( $indentString ) ) {
			// any string that does not consist only of XML whitespace
			// would result in a non-well-formed instance.
			return false;
		}

		$this->indent_string = $indentString;

		return $this->writer->setIndentString( $indentString );
	}

	/**
	 * Should a given string be written in a CDATA section?
	 *
	 * Determined based on whether the result would be smaller with a CDATA section
	 * and not based on "readability" for humans.
	 *
	 * @param string $content
	 * @return bool
	 */
	protected function shouldCDATA( $content ) {
		switch ( $this->selective_cdata_sections ) {
			case self::CDATA_FOR_SIZE:
				// compute whether the string would be smaller if encoded
				// as a CDATA section
				$double_quote = substr_count( $content , '"');// &quot;
				$lt = substr_count( $content , '<');// &lt;
				$gt = substr_count( $content , '>');// &gt;
				$amp = substr_count( $content , '&');// &amp;

				$return = ( $double_quote * 5 ) + ( $lt * 3 ) + ( $gt * 3 ) + ( $amp * 4 ) > 12;

				break;
			case self::CDATA_FOR_READABILITY:
				// return true if the string contains any characters
				// that need to be encoded as character entities
				$return = strpbrk( $content, '<>"&' );

			case false:
			default:
				$return = false;
		}

		return $return;
	}

	/**
	 * Reset the state of the writer.
	 */
	protected function reset() {
		$this->xmlDecl_written = false;
		$this->root_written = false;
		$this->closed = false;
		$this->opened = false;
		$this->depth = 0;
		$this->open_elements = array();
		$this->namespaces->reset();
	}

	/**
	 * Expand an EQName.
	 *
	 * @link https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName

	 * @param string $name
	 * @return array {
	 *     @type string|false $uri
	 *     @type string|false|null $prefix
	 *     @type string $local-name
	 *     @type bool $add-ns-decl
	 * }
	 */
	protected function expand_EQName( $name, $nsDecls = array() ) {
		$EQName = parent::parseEQName( $name );
		if ( is_wp_error( $EQName ) ) {
			return $EQName;
		}

		// temporarily bind namespaces
		$this->namespaces->bindNamespaces( $nsDecls );

		if ( $EQName['prefix'] && ! $EQName['uri'] ) {
 			// @link https://www.w3.org/TR/REC-xml-names/#NT-PrefixedName
			$EQName['uri'] = $this->namespaces->get_uri( $EQName['prefix'] );
			$EQName['add-ns-decl'] = false;
		}
		elseif ( ! $EQName['uri'] && ! $EQName['prefix'] ) {
			// @link https://www.w3.org/TR/REC-xml-names/#NT-UnprefixedName
			$EQName['add-ns-decl'] = false;
		}
		elseif ( ! $EQName['prefix'] ) {
			// @link https://www.w3.org/TR/xpath-30/#prod-xpath30-URIQualifiedName
			$add_ns_decl = false;
			$EQName['prefix'] = $this->namespaces->get_prefix( $EQName['uri'] );
			if ( false === $EQName['prefix']  && $this->generate_prefixes ) {
				$EQName['prefix'] = $this->namespaces->generate_unique_prefix( $EQName['uri'] );
				$add_ns_decl = true;
			}
			$EQName['add-ns-decl'] = $add_ns_decl;
		}

		// unbind temporary namespaces
		$this->namespaces->unbindNamespaces();

		return $EQName;
	}

	/**
	 * Extract namespace declarations from a list of attributes.
	 *
	 *
	 * @param array $attributes Key is attribute name, value is attribute value
	 * @return array
	 */
	static protected function extract_nsDecls( $attributes ) {
		$nsDecls = array();
		foreach ( $attributes as $name => $value ) {
			if ( ! self::isLegalAttribute( $name, $value ) ) {
				continue;
			}

			if ( 'xmlns' === $name ) {
				$nsDecls[null] = $value;
			}
			else {
				$EQName = self::parseEQName( $name );
				if ( 'xmlns' === $EQName['prefix'] ) {
					if ( 'xmlns' === $EQName['local-name'] ) {
						// @link https://www.w3.org/TR/REC-xml-names/#xmlReserved
						continue;
					}
				$nsDecls[$EQName['local-name']] = $value;
				}
			}
		}

		return $nsDecls;
	}
}
