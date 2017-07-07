# WordPress Exporter Redux
Proposed rewrite of the standard WordPress exporter.

## Description

This proposed rewrite of the standard WordPress exporter:

1. Exports [WXR 1.3-proposed][] instances.
1. Uses a real streaming XML serializer (instead of echo statements) to generate the XML.
	Ultimately, I'd like to define a full-blown XML API.  See the `@todo` at the start of
	./includes/export.php for some ideas on that XML API.
1. Provides hooks that allow plugins to add extension markup to the generated WXR
	instance, e.g., rows from custom tables that are associated with various content
	included in the output.  
1. Provides additional hooks that allow plugins that hook into the 'export filters' action
	that exists in the standard exporter to do actually something useful with the
	the 'export filters' they have added.
1. Fixes what I consider to be a few bugs in the standard exporter that are unrelated to
	any of the above.  For example, when exporting posts of a single post type the
	standard exporter exports the terms associated with the posts in the `/rss/channel/item/category`
	element, but does not export the "full" terms.  Thus, hierarchical relationship between
	those terms are not represented (and cannot be produced by the importer), nor are term metas
	and term descriptions.  In this version, the "full" terms are also exported in that case.

The above goals are completely independant of one another.  For example, if folks like the [WXR 1.3-proposed][]
changes but hate the rest of my changes/fixes then those WXR changes can be encorporated
into the standard exporter without any of the other changes.

### Companion Plugins ###

Generating exports with this plugin wouldn't be very useful if you couldn't also import them
(which the [WordPress Importer][] is unable to do).  [WordPress Importer Redux][] to the rescue!
[WordPress Importer Redux][] not only imports [WXR 1.3-proposed] instances, but also
older WXR 1.0, 1.1 and 1.2 instances.

Want to experiment with the hooks mentions above?  A "demo" plugin exists that demonstrates
their use.  That plugin is available at [WordPress Exporter Redux Extension][].

[WordPress Importer]: https://wordpress.org/plugins/wordpress-importer/
[WordPress Importer Redux]: https://github.com/pbiron/WordPress-Importer
[WordPress Exporter Redux Extension]: https://github.com/pbiron/WordPress-Exporter-extension
[WXR 1.3-proposed]: https://github.com/pbiron/wxr/1.3-proposed
[XML Infoset]: http://www.w3.org/TR/xml-infoset/
[XML Schema 1.1]: https://www.w3.org/TR/xmlschema11-1
[XML Schema 1.0]: https://www.w3.org/TR/xmlschema-1
[GitHub Updater]: https://github.com/afragen/github-updater

## How do I use it?

1. Install the plugin directly from GitHub. ([Download as a ZIP.](https://github.com/pbiron/WordPress-Importer/archive/master.zip))
2. Activate the plugin
3. Head to Tools
4. Select "Export (v2)"
5. Follow the on-screen instructions.

## Change Log

### 0.2

* Fixed bug causing $term_ids to be unset when $post_ids is empty
* Fix base/@href for Network admin screens
* Added [GitHub Updater][] plugin header info

### 0.1

* Init commit

## To Do's ##

1. Add unit tests
   1. The problem is that:
      1. what we really want to test is the [XML Infoset][] constructed from
   		the generated WXR instances and **NOT** the serialized XML (since the serialized form
   		could change without affecting what is "seen" by the importer, e.g., namespace prefixes,
   		whitespace, etc)...and there is no easy way in PHP to get at the [XML Infoset][].
   	   1. We also do **NOT** want to test the exporter by checking the results of re-importing,
   	   	since those kinds of tests could fail because of bugs in the importer.
   1. At the very least, we could test that the generated WXR instances are well-formed.
   1. Unit testing for validity would be nice, but the XML Schema for [WXR 1.3-proposed][]
   		requires an [XML Schema 1.1][] processor.  All of the XML processors in a vanilla PHP install
   		that are able to validate XML instances only handle [XML Schema 1.0][] schemas...and
   		[XML Schema 1.0][] is not expressive enough to capture the rules of RSS (nor the
   		WXR extensibility rules in [WXR 1.3-proposed][]), so validating
   		with a 1.0 schema would be useless, or worse.
   1. we could also do simple XPath expressions that count the number of various elements
   	in the generated XML.  This would mimic those parts of the current unit tests for import
   	that contain things like `$user_count = count_users(); $this->assertEquals( 3, $user_count['total_users'] );`
1. Incorporate the ideas for a generic [Export API](https://core.trac.wordpress.org/ticket/22435).
1. Ultimately, I'd like to define a full-blown XML API.  See the `@todo` at the start of
	./includes/export.php for some ideas on that XML API.  An XML API could be used by not
	only the exporter/importer, but also the RSS/Atom feeds and might be generally useful
	to plugins that need to read/write XML for other reasons.
   1. Any XML API:
      1. Should be streamable (i.e., NOT require the entire instance to be in-memory,
      	whether reading or writing);
	  1. Can probably be a light-weight wrapper around PHP's XMLReader and XMLWriter classes
	  	(which are streamable, whereas PHP's SimpleXML and DOMDocument classes are not);
	  1. Should manage in-scope XML Namespace bindings (one of the problems with XMLWriter
	  	out-of-the-box is that it does not, see
	  	[XMLWriter adds to many namespace definitions](https://bugs.php.net/bug.php?id=74491))
	  	to avoid serializing redundant/needless namespace decls;
	  1. Should accept [EQName](https://www.w3.org/TR/xpath-30/#prod-xpath30-EQName)'s
	  	for element and attribute names
	  1. Should "intelligently" deal with characters that are outside the range of
	  	characters allowed by the [Char](https://www.w3.org/TR/xml/#NT-Char) production
	  	in the XML Spec.  "intelligently", in this context,
	  	means that I'm not sure just stripping them is the correct thing to do, but introducing
	  	a WP-specific way of encoding them is also not a good idea (for example, would
	  	other CMS's importing a WXR instance know what to do with them?).
1. Generalize the `wxr_export_plugins` hook.  For example, it should be easier for plugins that
	register exportable custom post_types (eCPTs) to get their info into `/rss/@wxr:plugins`.
	When exporting **all** content, posts from eCPTs are included in the generated WXR and the
	plugins that register eCPTs but have no need to add extension markup shouldn't have to
	hook into `wxr_export_plugins` to get their info into `/rss/@wxr:plugins` so that the
	importer can notify users performing an import that some of the posts might not be
	importer if the plugins that registered their eCPTs are not installed/activated.
   1. perhaps by adding	additional params to
   	[register_post_type()](https://developer.wordpress.org/reference/functions/register_post_type/).

## How can I help?

The best way to help with the exporter right now is to **try exporting and see what breaks**. Compare the old exporter to the new one, and find any inconsistent behaviour.

There is a [general feedback thread](https://github.com/pbiron/WordPress-Exporter/issues/1) so you can let us know how it goes. If the exporter works perfectly, let us know. If something doesn't import the way you think it should, you can file a new issue, or leave a comment to check whether it's intentional first. :)

Have comments/suggestions about the markup changes in [WXR 1.3-proposed]?  Head on over there and open an issue.
