<?php
/**
 * WP XML API
 *
 * @package XML
 */

/**
 * XML 1.0 specification non-terminal checking and other utility methods.
 *
 * @todo Write XML 1.1 non-terminal regexes and allow WP_XML::isNCName(), etc
 * to conidtionally use the 1.1 non-terminals.  Before bothering to do that,
 * verify that all of the PHP XML parsers correctly handle (or report appropriate
 * errors) when XML 1.1 is used.
 */
class WP_XML {
	/**
	 * Regular expression that matches a single XML Char.
	 *
	 * @var string
	 *
	 * @link https://www.w3.org/TR/REC-xml/#NT-Char
	 */
	const Chars = '/^[\\x9\\xA\\xD\\x{0020}-\\x{D7FF}\\x{E000}-\\x{FFFD}\\x{010000}-\\x{10FFFF}]+$/u';

	/**
	 * Regular expression that matches XML white space.
	 *
	 * @var string
	 *
	 * @link https://www.w3.org/TR/REC-xml/#NT-S
	 */
	const WhiteSpace = "/^([ \t\n\r]+)$/";

	/**
	 * Regular expression that matches an XML Name.
	 *
	 * @var string
	 *
	 * @link https://www.w3.org/TR/REC-xml/#NT-Name
	 * @link https://stackoverflow.com/a/15188815
	 *
	 * @todo if/when WP specifies PHP 5.6.0 as the minimum version,
	 * we won't have to duplicate <NameChar> in the rest of these constants, etc
	 * because PHP 5.6.0 allows constants to be initialized with expressions.
	 */
	const Name = '/
		(?(DEFINE)
		    (?<NameStartChar> [:A-Z_a-z\\xC0-\\xD6\\xD8-\\xF6\\xF8-\\x{2FF}\\x{370}-\\x{37D}\\x{37F}-\\x{1FFF}\\x{200C}-\\x{200D}\\x{2070}-\\x{218F}\\x{2C00}-\\x{2FEF}\\x{3001}-\\x{D7FF}\\x{F900}-\\x{FDCF}\\x{FDF0}-\\x{FFFD}\\x{10000}-\\x{EFFFF}])
		    (?<NameChar>      (?&NameStartChar) | [.\\-0-9\\xB7\\x{0300}-\\x{036F}\\x{203F}-\\x{2040}])
		    (?<Name>          (?&NameStartChar) (?&NameChar)*)
		)
		^(?&Name)$
		/ux';

	/**
	 * Regular expression that matches an XML NCName.
	 *
	 * @var string
	 *
	 * @link https://www.w3.org/TR/REC-xml-names/#NT-NCName
	 */
	const NCName = '/
		(?(DEFINE)
		    (?<NameStartCharMinusColon> [A-Z_a-z\\xC0-\\xD6\\xD8-\\xF6\\xF8-\\x{2FF}\\x{370}-\\x{37D}\\x{37F}-\\x{1FFF}\\x{200C}-\\x{200D}\\x{2070}-\\x{218F}\\x{2C00}-\\x{2FEF}\\x{3001}-\\x{D7FF}\\x{F900}-\\x{FDCF}\\x{FDF0}-\\x{FFFD}\\x{10000}-\\x{EFFFF}])
		    (?<NameChar>      (?&NameStartCharMinusColon) | [.\\-0-9\\xB7\\x{0300}-\\x{036F}\\x{203F}-\\x{2040}])
		    (?<NCName>         (?&NameStartCharMinusColon) (?&NameChar)*)
		)
		^(?&NCName)$
		/ux';

	/**
	 * Regular expression that matches an XML PrefixedName.
	 *
	 * When matched with preg_match( WP_XML::PrefixedName, $str, $matches ),
	 * the prefix will be in $matches[4] and the local-name will be in $matches[5].
	 *
	 * @var string
	 *
	 * @link https://www.w3.org/TR/REC-xml-names/#NT-PrefixedName
	 */
	const PrefixedName = '/
		(?(DEFINE)
		    (?<NameStartCharMinusColon> [A-Z_a-z\\xC0-\\xD6\\xD8-\\xF6\\xF8-\\x{2FF}\\x{370}-\\x{37D}\\x{37F}-\\x{1FFF}\\x{200C}-\\x{200D}\\x{2070}-\\x{218F}\\x{2C00}-\\x{2FEF}\\x{3001}-\\x{D7FF}\\x{F900}-\\x{FDCF}\\x{FDF0}-\\x{FFFD}\\x{10000}-\\x{EFFFF}])
		    (?<NameChar>      (?&NameStartCharMinusColon) | [.\\-0-9\\xB7\\x{0300}-\\x{036F}\\x{203F}-\\x{2040}])
		    (?<NCName>         (?&NameStartCharMinusColon) (?&NameChar)*)
		)
		^((?&NCName)):((?&NCName))$
		/ux';

	/**
	 * Regular expression that matches an XML URIQualifiedName
	 *
	 * When matched with preg_match( WP_XML::URIQualifiedName, $str, $matches ),
	 * the uri will be in $matches[4] and the local-name will be in $matches[5].
	 *
	 * @var string
	 *
	 * @link https://www.w3.org/TR/xpath-30/#prod-xpath30-URIQualifiedName
	 */
	const URIQualifiedName	 = '/
		(?(DEFINE)
		    (?<NameStartCharMinusColon> [A-Z_a-z\\xC0-\\xD6\\xD8-\\xF6\\xF8-\\x{2FF}\\x{370}-\\x{37D}\\x{37F}-\\x{1FFF}\\x{200C}-\\x{200D}\\x{2070}-\\x{218F}\\x{2C00}-\\x{2FEF}\\x{3001}-\\x{D7FF}\\x{F900}-\\x{FDCF}\\x{FDF0}-\\x{FFFD}\\x{10000}-\\x{EFFFF}])
		    (?<NameChar>      (?&NameStartCharMinusColon) | [.\\-0-9\\xB7\\x{0300}-\\x{036F}\\x{203F}-\\x{2040}])
		    (?<NCName>         (?&NameStartCharMinusColon) (?&NameChar)*)
		)
		^Q{([^{}]*)}((?&NCName))$
		/ux';

	/**
	 * The namespace URI for the prefix "xml"
	 *
	 * @link https://www.w3.org/TR/REC-xml-names/#xmlReserved
	 *
	 * @var string
	 */
	const XML_NAMESPACE_URI = 'http://www.w3.org/XML/1998/namespace';

	/**
	 * The namespace URI for the prefix "xmlns"
	 *
	 * @link https://www.w3.org/TR/REC-xml-names/#xmlReserved
	 *
	 * @var string
	 */
	const XML_NAMESPACES_NAMESPACE_URI = 'http://www.w3.org/2000/xmlns/';

	/**
	 * Does a string consist solely of one or more XML Space characters?
	 *
	 * @link https://www.w3.org/TR/REC-xml/#NT-S
	 *
	 * @param string $str
	 * @return bool
	 */
	static function isWhiteSpace( $str ) {
		return preg_match( self::WhiteSpace, $str ) > 0;
	}

	/**
	 * Is a string a legal XML Name?
	 *
	 * @link https://www.w3.org/TR/REC-xml/#NT-Name
	 *
	 * @param string $str
	 * @return bool True for success, false for failure.
	 */
	static function isName( $str ) {
		return preg_match( self::Name, $str ) > 0;
	}

	/**
	 * Is a string a legal XML NCName?
	 *
	 * @link https://www.w3.org/TR/REC-xml-names/#NT-NCName
	 *
	 * @param string $str
	 * @return bool True for success, false for failure.
	 */
	static function isNCName( $str ) {
		return preg_match( self::NCName, $str ) > 0;
	}

	/**
	 * Is a string a legal XML PrefixedName?
	 *
	 * @link https://www.w3.org/TR/REC-xml-names/#NT-PrefixedName
	 *
	 * @param string $str
	 * @return bool True for success, false for failure.
	 */
	static function isPrefixedName( $str ) {
		return preg_match( self::PrefixedName, $str ) > 0;
	}

	/**
	 * Is a string a legal XML QName?
	 *
	 * @link https://www.w3.org/TR/REC-xml-names/#NT-QName
	 *
	 * @param string $str
	 * @return bool True for success, false for failure.
	 */
	static function isQName( $str ) {
		return preg_match( self::PrefixedName, $str ) > 0 || self::isNCName( $str );
	}

	/**
	 * Is a string a legal XML URIQualifiedName?
	 *
	 * @link https://www.w3.org/TR/xpath-30/#doc-xpath30-URIQualifiedName
	 *
	 * @param string $str
	 * @return bool True for success, false for failure.
	 */
	static function isURIQualifiedName( $str ) {
		return preg_match( self::URIQualifiedName, $str ) > 0;
	}

	/**
	 * Is a string a legal XML EQName?
	 *
	 * @link https://www.w3.org/TR/xpath-30/#doc-xpath30-EQName
	 *
	 * @param string $str
	 * @return bool True for success, false for failure.
	 */
	static function isEQName( $str ) {
		return self::isURIQualifiedName( $str ) || self::isQName( $str );
	}

	/**
	 * Does a string contain characters that are illegal in XML?
	 *
	 * @link https://www.w3.org/TR/REC-xml/#NT-Char
	 *
	 * @param string $str
	 * @return bool True for if $str contains illegal chars, false otherwise.
	 */
	static function containsIllegalChars( $str ) {
		return preg_match( self::Chars, $str ) === 0 ;
	}

	/**
	 * Parse an EQName into it's consituent parts.
	 *
	 * @link https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName
	 *
	 * @param string $name
	 * @return array {
	 *     @type string|bool $namespace-uri The namespace URI.
	 *     @type string|bool $prefix The namespace prefix.
	 *     @type stirng|bool $local-name The local-name.
	 * }
	 */
	static function parseEQName( $name ) {
		if ( preg_match( self::URIQualifiedName	, $name, $matches ) > 0 ) {
			// @link https://www.w3.org/TR/xpath-30/#prod-xpath30-URIQualifiedName
			$uri = $matches[4];
			$prefix = false;
			if ( self::XML_NAMESPACES_NAMESPACE_URI === $uri ) {
				$prefix = 'xmlns';
			}
			return array( 'uri' => $uri, 'prefix' => $prefix, 'local-name' => $matches[5] );
		}
		elseif ( preg_match( self::PrefixedName, $name, $matches ) > 0 ) {
			// @link https://www.w3.org/TR/REC-xml-names/#NT-PrefixedName
			$uri = false;
			$prefix = $matches[4];
			if ( 'xmlns' === $prefix ) {
				// @link https://www.w3.org/TR/REC-xml-names/#xmlReserved
				$uri = self::XML_NAMESPACES_NAMESPACE_URI;
			}
			elseif ( 'xml' === $prefix ) {
				// @link https://www.w3.org/TR/REC-xml-names/#xmlReserved
				$uri = self::XML_NAMESPACE_URI;
			}
			return array( 'uri' => $uri, 'prefix' => $prefix, 'local-name' => $matches[5] );
		}
		elseif ( self::isNCName( $name ) ) {
			// @link https://www.w3.org/TR/REC-xml-names/#NT-UnprefixedName
			return array( 'uri' => false, 'prefix' => false, 'local-name' => $name );
		}
		else {
			// NOT an EQName
			return new WP_Error( 'wp_xml.not_legal_EQName', __( 'Not a legal EQName' ) );
		}
	}

	/**
	 * Is a name/value pair a legal XML attribute?
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool
	 */
	static function isLegalAttribute( $name, $value ) {
		$EQName = self::parseEQName( $name );
		if ( is_wp_error( $EQName ) ) {
			return false;
		}

		if ( 'xmlns' === $EQName['prefix'] &&
				( 'xmlns' === $EQName['local-name'] ||
					( 'xml' === $EQName['local-name'] && self::XML_NAMESPACE_URI !== $value ) ) ) {
			// @link https://www.w3.org/TR/REC-xml-names/#xmlReserved
			return false;
		}

		if ( 'xml' === $EQName['prefix'] && 'space' === $EQName['local-name'] &&
				! in_array( $value, array( 'default', 'preserve' ) ) ) {
			// @link https://www.w3.org/TR/REC-xml/#sec-white-space
			// many XML parsers do not treat this as an error (because the XML
			// spec is ambiguous on the matter: while it explicitly says it is
			// an error, it also says that parser can ignore that error :-(
			// however, PHP's XMLReader reports instances with a value other than
			// "default" or "preserve" as non-well-formed and PHP's DOMDocument
			// is as abiguous as the XML spec on the matter (@link https://bugs.php.net/bug.php?id=74988),
			// so we will forbid it.
			return false;
		}

		if ( 'xml' === $EQName['prefix'] && 'id' === $EQName['local-name'] &&
				! self::isNCName( $value ) ) {
			// @link https://www.w3.org/TR/xml-id/#processing
			// note: we do NOT enforce the constraint that @xml:id values
			// are unique...that is up to the caller.
			return false;
		}

		return ! self::containsIllegalChars( $value );
	}
}

/**
 * A single XML Namespace, consisting of a prefix and a URI.
 */
class XML_Namespace {
	/**
	 * Namespace prefix.
	 *
	 * @var string
	 */
	protected $prefix = null ;

	/**
	 * Namespace URI.
	 *
	 * @var string
	 */
	protected $uri = null ;

	/**
	 * Constructor
	 *
	 * @param string $prefix
	 * @param string $uri
	 */
	function __construct( $prefix, $uri ) {
		$this->prefix = $prefix;
		$this->uri = $uri;
	}

	/**
	 * Get the prefix for this namespace
	 *
	 * @return string
	 */
	function get_prefix() {
		return $this->prefix;
	}

	/**
	 * Get the URI for this namespace.
	 *
	 * @return string
	 */
	function get_uri() {
		return $this->uri;
	}
}

/**
 * XML Namespace bindings.
 *
 * Implemented as a stack, each item of which is an array of namespace delcarations
 * on a single XML element. The item on the top of the stack is for the current XML element,
 * the next item is for the parent of the current XML element, etc.
 */
class XML_Namespace_Bindings {
	/**
	 * In-scope namespaces.
	 *
	 * @var XML_Namespace[]
	 */
	protected $contexts = array();

	/**
	 * Bind namespaces for the current context.
	 *
	 * @param array $nsDecls {
	 *     @type string $prefix The namespace prefix.
	 *     @type string $uri The namespace URI.
	 * }
	 */
	function bindNamespaces( $nsDecls ) {
		$ns = array() ;
		foreach ( (array) $nsDecls as $prefix => $uri ) {
			if ( empty( $prefix ) ) {
				$prefix = null;
			}
			if ( $prefix !== $this->get_prefix( $uri ) ) {
				$ns[] = new XML_Namespace( $prefix, $uri );
			}
		}

		array_unshift( $this->contexts, array( $ns) );
	}

	/**
	 * Unbind namespaces for the current context.
	 */
	function unbindNamespaces() {
		array_shift( $this->contexts );
	}

	/**
	 * Unbind all namespaces in all contexts.
	 */
	function reset() {
		$this->contexts = array();
	}

	/**
	 * Get the prefix bound to a given URI in the in-scope namespaces.
	 *
	 * @param string $uri Namespace URI to get prefix for.
	 * @return string|false Namespace prefix for `$uri`, or false if `$uri`
	 * 						is not bound.
	 */
	function get_prefix ( $uri ) {
		foreach ( $this->contexts as $context ) {
			foreach ( $context as $namespaces ) {
				foreach ( $namespaces as $namespace ) {
					if ( $namespace->get_uri() === $uri ) {
						return $namespace->get_prefix();
					}
				}
			}
		}

		return false;
	}

	/**
	 * Get the URI that a given prefix is bound to in the in-scope namespaces.
	 *
	 * @param string $prefix Namespace prefix to get URI for.
	 * @return string|false Namespace URI for `$prefix`, or false if `$prefix`
	 * 						is not bound.
	 */
	function get_uri ( $prefix ) {
		foreach ( $this->contexts as $context ) {
			foreach ( $context as $namespaces ) {
				foreach ( $namespaces as $namespace ) {
					if ( $namespace->get_prefix() === $prefix ) {
						return $namespace->get_uri();
					}
				}
			}
		}

		return false;
	}

	/**
	 * Generate a prefix for a namespace URI that is unique given the current bindings.
	 *
	 * @return string Unique namespace prefix.
	 */
	function generate_unique_prefix() {
		$num = 0;
		$prefix = 'ns';

		while ( $this->get_uri( sprintf( "{$prefix}%d", $num ) ) ) {
			$num++;
		}
		$prefix = sprintf( "{$prefix}%d", $num );

		return $prefix;
	}
}