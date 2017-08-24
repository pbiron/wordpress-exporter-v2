# WordPress Exporter Redux
Proposed rewrite of the standard WordPress exporter.

## Description

This proposed rewrite of the standard WordPress exporter:

1. Exports [WXR 1.3-proposed][] instances.
1. Uses proposed Export & XML APIs.
   1. The Export API is a modified version of that proposed in [Export API][].
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
1. Incorporates the UI/UX from [WordPress Importer Redux][].

The above goals are completely independant of one another.  For example, if folks like the [WXR 1.3-proposed][]
changes but hate the rest of my changes/fixes then those WXR changes can be encorporated
into the standard exporter without any of the other changes.

### Companion Plugins ###

Generating exports with this plugin wouldn't be very useful if you couldn't also import them
(which the [WordPress Importer][] is unable to do).  [WordPress Importer Redux][] to the rescue!
[WordPress Importer Redux][] not only imports [WXR 1.3-proposed] instances, but also
older WXR 1.0, 1.1 and 1.2 instances.

Want to experiment with the hooks mentions above?

* A "demo" plugin exists that demonstrates the use of most of them.  That plugin is available at
	[WordPress Exporter Redux Extension][].
* A more realistic "demo" plugin exists that demonstrates exporting and importing custom tables.  That plugin is available at
	[P2P Export/Import][].

[WordPress Importer Redux]: https://github.com/pbiron/wordpress-importer-v2
[WordPress Importer]: https://wordpress.org/plugins/wordpress-importer/
[WordPress Exporter Redux Extension]: https://github.com/pbiron/wordpress-exporter-v2-extension
[P2P Export/Import]: https://github.com/pbiron/p2p-export-import
[WXR 1.3-proposed]: https://github.com/pbiron/wxr/1.3-proposed
[XML Infoset]: http://www.w3.org/TR/xml-infoset/
[XML Schema 1.1]: https://www.w3.org/TR/xmlschema11-1
[XML Schema 1.0]: https://www.w3.org/TR/xmlschema-1
[GitHub Updater]: https://github.com/afragen/github-updater
[Trac Ticket 39237]: https://core.trac.wordpress.org/ticket/39237#comment:8
[Export API]: https://core.trac.wordpress.org/attachment/ticket/22435/export.5.diff

## How do I use it?

1. Install the plugin
   1. Directly from GitHub. ([Download as a ZIP.](https://github.com/pbiron/WordPress-Importer/archive/master.zip))
   1. Via [GitHub Updater][]
2. Activate the plugin
3. Head to Tools
4. Select "Export (v2)"
5. Follow the on-screen instructions.

## Change Log

### 0.4.1

* Limit terms to those whose taxonomy is registered
* Improved log message for exported posts

### 0.4

* Incorporated UI/UX from [WordPress Importer Redux][]
* More extensive export filters
* Compatibility with PHP 5.2

### 0.3

* Added Export API
* Added XML API
* Rewrote to use the Export & XML APIs

### 0.2

* Fixed bug causing $term_ids to be unset when $post_ids is empty
* Fix base/@href for Network admin screens
* Added [GitHub Updater][] plugin header info

### 0.1

* Init commit

## To Do's ##

1. Add more unit tests
1. XML API
	1. Should "intelligently" deal with characters that are outside the range of
	   characters allowed by the [Char](https://www.w3.org/TR/xml/#NT-Char) production
	   in the XML Spec.  "intelligently", in this context,
	   means that I'm not sure just stripping them is the correct thing to do, but introducing
	   a WP-specific way of encoding them is also not a good idea (for example, would
	   other CMS's importing a WXR instance know what to do with them?).
	2. Add XML 1.1 non-terminal checking (once it is verify that all of PHP's XML parsers
	   can correctly handle XML 1.1 instances.

## How can I help?

The best way to help with the exporter right now is to **try exporting and see what breaks**. Compare the old exporter to the new one, and find any inconsistent behaviour.

There is a [general feedback thread](https://github.com/pbiron/wordpress-exporter-v2/issues/1) so you can let us know how it goes. If the exporter works perfectly, let us know. If something doesn't import the way you think it should, you can file a new issue, or leave a comment to check whether it's intentional first. :)

Have comments/suggestions about the markup changes in [WXR 1.3-proposed]?  Head on over there and open an issue.
