<?php
/*
 * Plugin Name: WordPress Exporter v2
 * Description: Proposed changes to WP Core's standard exporter
 * Version: 0.2
 * Author: pbiron
 * Author URI: http://sparrowhawkcomputing.com
 * Plugin URI: https://github.com/pbiron/WordPress-Exporter
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/pbiron/WordPress-Exporter
 */

/**
 * WordPress Export Administration Screen
 *
 * @package WordPress
 * @subpackage Administration
 */

/*
 * This plugin represents some proposed changes to the standard exporter.
 *
 * It does NOT fix every problem that I see with the standard exporter
 * (it is only version 0.1., after all :-).
 *
 * Among the improvements are:
 *
 * 1. Outputs what I consider an improved version of the WXR markup
 * 	  a. the proposed changes to the WXR markup are documented in an
 *       XML Schema at https://github.com/pbiron/wxr/1.3-proposed
 *    b. among other things, the proposed changes to WXR include:
 *       A. making it conform to both http://www.rssboard.org/rss-specification and
 *          http://www.rssboard.org/rss-profile, which the XML output
 *          by the standard exporter does not
 *       B. make it easier to write an importer that is truely XML Namespace-aware,
 *          by having a single namespaceURI regardless of the version of WXR
 *       C. make it easier to write a streaming importer, by having /rss/@wxr:version
 *          instead of /rss/channel/wp:wxr_version.  That is, the importer can
 *          determine if it is able to handle the WXR instance after examining only
 *          the root element.
 *       D. "rationalizes" the markup for terms (e.g., no need for separate markup
 *          for terms in the 'post_tag' and 'category' taxonomies, as well as
 *          using /rss/channel/item/description element instead of excerpt:encoded)
 *       E. Allows for smaller WXR instances, by not having redundant leading strings
 *          in many element names, e.g., wp:term/wp:term_id becomes wxr:term/wxr:id.
 *          The size improvements this produces become significant the larger the
 *          WXR instance is.
 *       F. Dispite what may appear to be "drastic" (and certainly, backwards incompatible)
 *          changes to the WXR markup, there is a very simple transform from WXR 1.0, 1.1 and 1.2
 *          instances into my proposed WXR 1.3.  That transform is included in my
 *          fork of https://github.com/humanmade/WordPress-Importer.
 *
 * 2. Uses a real streaming XML serializer (instead of echo statements) to generate the XML
 *    a. Ultimately, I'd like to define a full-blown XML API
 *    b. see the @todo at the start of ./includes/export.php for some ideas on
 *       that XML API
 *
 * 3. Provides hooks that allow plugins to add extension markup to the generated WXR
 *    instance, e.g., rows from custom tables that are associated with various content
 *    included in the output
 *    a. these hooks are demonstrated in a "demo" plugin at
 *       https://github.com/pbiron/WordPress-Exporter-extension
 *
 * 4. Provides additional hooks that allow plugins that hook into the 'export filters' action
 *    that exists in the standard exporter to do actually something useful with the
 *    the 'export filters' they have added
 *    a. these hooks are demonstrated in a "demo" plugin at
 *       https://github.com/pbiron/WordPress-Exporter-extension
 *
 * 5. Fixes what I consider to be a few bugs in the standard exporter that are unrelated to
 *    any of the above.  For example, when exporting posts of a single post type the
 *    standard exporter exports the terms associated with the posts in the /rss/channel/item/category
 *    element, but does not export the "full" terms.  Thus, hierarchical relationship between
 *    those terms are not represented (and cannot be produced by the importer), nor are term metas
 *    and term descriptions.  In this version, the "full" terms are also exported in that case.
 *
 * The above goals are completely independant of one another.  For example, if folks like the proposed
 * WXR 1.3 changes but hate the rest of my changes/fixes then those WXR changes can be encorporated
 * into the standard exporter without any of the other changes.
 *
 * As I was nearing the release of this plugin, I was made aware of https://core.trac.wordpress.org/ticket/22435.
 * I haven't had the time to thoroughly explore the differences/commonalities between the two approaches,
 * but I will shortly.  The one thing that stands out however, is the building up and tearing down of
 * multiple DOMDocuments using Oxymel during the course of an export seems to be very inefficient,
 * and that building an XML API around XMLWriter is a more efficent approach.
 *
 * The code in this plugin represents the minimal changes to the standard exporter code that
 * I could think of that implements the above goals (and a few not mentioned above).
 * I think the existing exporter code is a mess.  But I wanted to keep this code as close as
 * possible to the existing code for it's initial release.  In future releases I will surely
 * incorporate code/ideas from https://core.trac.wordpress.org/ticket/22435.
 */

/*
 * @todo add unit tests...once I figure out what to test.  That is, the tests should
 * check that the XML Infoset of a generated WXR instance is what is expected, and should
 * NOT compare the serialized XML nor the results of reimporting the WXR (since any difference
 * from what is expected in that case could be a problem with the importer).
 *
 * At the very least, we could unit test for well-formedness.  Unit testing for validity
 * would be nice, but the XML Schema at https://github.com/pbiron/wxr/1.3-proposed requires
 * an XML Schema 1.1 processor and all of the XML processors in a vanilla PHP install only
 * handle XML Schema 1.0 schemas (XML Schema 1.0 is not expressive enough to capture the
 * rules of RSS, so validating with a 1.0 schema would be useless, or worse).
 */

/**
 * Add our menu item to the tools.php menu.
 */
add_action( 'admin_menu', function () {
	/**
	 * @global array $submenu
	 */
	global $submenu;

	/*
	 * cheating by not using add_submenu_page() so that
	 * we can mimic how the standard exporter works.
	 *
	 * Kids, don't try this at home!
	 *
	 * Unfortunately, because of how _wp_menu_output() detects which
	 * menu sub_item is "current", doing things this way means that our
	 * menu item never gets the 'current' @class.  But I'm not going to
	 * worry about that.
	 */

	/*
	 * Note that this exporter can run along side the standard exporter so that you
	 * can export from both and compare/contrast the results.
	 */
	$submenu['tools.php'][max( array_keys( $submenu['tools.php'] )) + 1] = array(
		'Export (v2)',
		'export',
		plugins_url( 'export.php', __FILE__),
	);
});

add_action( 'admin_head', function () {
	/*
	 * add an HTML <base> to <head> so that the rest of the admin
	 * menus work correctly...this is necessary because we are cheating
	 * in how we add our menu item above.
	 */
	if ( is_network_admin() ) {
		$href = network_admin_url();
	}
	else {
		$href = admin_url();
	}

	$href = esc_attr( $href );

	echo "<base href='$href'/>\n";
});

/**
 * remove various filters that core hooks into, whose purpose
 * is to ensure that the standard exporter produces well-formed
 * XML via echo statements.  Since we are producing output
 * with XMLWriter (which automatically encodes things like '&') these
 * filters result in "double-encoding" (e.g., '&' in a post title
 * becomes '&amp;amp;').
 */
add_action( 'admin_init', function () {
	/*
	 * We are leaving all the calls to apply_filters() in
	 * ./includes/export.php on the small chance that plugins have
	 * hooked into them to do something OTHER THAN encoding XML entities,
	 * etc.
	 *
	 * @todo verify that we don't need to remove any other filters
	 */
	remove_filter( 'the_title_rss', 'strip_tags' );
	remove_filter( 'the_title_rss', 'ent2ncr',  8 );
	remove_filter( 'the_title_rss', 'esc_html' );
});