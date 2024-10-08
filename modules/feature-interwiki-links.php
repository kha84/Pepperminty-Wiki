<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

register_module([
	"name" => "Interwiki links",
	"version" => "0.1.2",
	"author" => "Starbeamrainbowlabs",
	"description" => "Adds interwiki link support. Set the interwiki_index_location setting at an index file to activate support.",
	"id" => "feature-interwiki-links",
	"code" => function() {
		global $env, $settings, $paths;
		if(!empty($settings->interwiki_index_location)) {
			// Generate the interwiki index cache file if it doesn't exist already
			// NOTE: If you want to update the cache file, just delete it & it'll get regenerated automagically :-)
			if(!file_exists($paths->interwiki_index))
				interwiki_index_update();
			else
				$env->interwiki_index = json_decode(file_get_contents($paths->interwiki_index));
		}
		
		$doc_help = "<p>$settings->sitename doesn't currently support interwiki links, but if you'd like it to, please contact ".htmlentities($settings->admindetails_name)." ($settings->sitename's administrator) through their contact details at the bottom of every page and point them at <a href='https://starbeamrainbowlabs.com/labs/peppermint/_docpress/06.5-Interwiki-Links.html'>the documentation on how to set it up</a>. It's really easy, and they can always <a href='https://github.com/sbrl/Pepperminty-Wiki/issues/new'>open an issue</a> if they get stuck :-)</p>\n";
		if(!empty($env->interwiki_index)) {
			$doc_help = <<<HELP_BLOCK
<p>$settings->sitename supports inter-wiki links. Such a link sends the user elsewhere on the internet. By prefixing a page name with a prefix, the convenience of the internal link syntax described above can be exploited to send users elsewhere without having to type out full urls! Here are few examples (note that these prefixes are only examples, and probably aren't available on $settings->sitename - check the list below for supported prefixes):</p>

<pre><code>[[another_wiki:Apples]]
[[trees:Apple Trees]]
[[history:The Great Rainforest|rainforest]]
[[any prefix here:page name|Display text]]
</code></pre>

<p>Note that unlike normal internal links, the page name is case-sensitive and can't be case-corrected automatically. The wikis supported by $settings->sitename are as follows:</p>

{supported_interwikis}

<p>This list can be edited by $settings->admindetails_name, $settings->sitename's administrator. Documentation on how to do that is <a href='https://starbeamrainbowlabs.com/labs/peppermint/__nightdocs/06.5-Interwiki-Links.html'>available here</a>.</p>
HELP_BLOCK;

			$doc_help_insert = "<table><tr><th>Name</th><th>Prefix</th>\n";
			foreach($env->interwiki_index as $interwiki_def)
				$doc_help_insert .= "<tr><td>".htmlentities($interwiki_def->name)."</td><td><code>$interwiki_def->prefix</code></td></tr>\n";
			$doc_help_insert .= "</table>";
			
			$doc_help = str_replace("{supported_interwikis}", $doc_help_insert, $doc_help);
		}
		
		add_help_section("22-interwiki-links", "Interwiki Links", $doc_help);
	}
]);

/**
 * Updates the interwiki index cache file.
 * If the interwiki_index_location isn't defined, then this function will do
 * nothing.
 */
function interwiki_index_update() {
	global $env, $settings, $paths;
	
	if(empty($settings->interwiki_index_location))
		return;
	
	$env->interwiki_index = new stdClass();
	$interwiki_csv_handle = fopen($settings->interwiki_index_location, "r");
	if($interwiki_csv_handle === false)
		throw new Exception("Error: Failed to read interwiki index from '{$settings->interwiki_index_location}'.");
	
	fgetcsv($interwiki_csv_handle); // Discard the header line
	while(($interwiki_data = fgetcsv($interwiki_csv_handle))) {
		$interwiki_def = new stdClass();
		$interwiki_def->name = $interwiki_data[0];
		$interwiki_def->prefix = $interwiki_data[1];
		$interwiki_def->root_url = $interwiki_data[2];
		
		$env->interwiki_index->{$interwiki_def->prefix} = $interwiki_def;
	}
	
	file_put_contents($paths->interwiki_index, json_encode($env->interwiki_index, JSON_PRETTY_PRINT));
}

/**
 * Parses an interwiki pagename into it's component parts.
 * @package interwiki-links
 * @param  string	$interwiki_pagename	The interwiki pagename to parse.
 * @return string[]	An array containing the parsed components of the interwiki pagename, in the form ["prefix", "page_name"].
 */
function interwiki_pagename_parse($interwiki_pagename) {
	if(strpos($interwiki_pagename, ":") === false)
		return null;
	$result = explode(":", $interwiki_pagename, 2);
	return array_map("trim", $result);
}

/**
 * Resolves an interwiki pagename to the associated
 * interwiki definition object.
 * @package interwiki-links
 * @param	string		$interwiki_pagename	An interwiki pagename. Should be in the form "prefix:page name".
 * @return	stdClass	The interwiki definition object.
 */
function interwiki_pagename_resolve($interwiki_pagename) {
	global $env;
	
	if(empty($env->interwiki_index))
		return null;
	
	// If it's not an interwiki link, then don't bother confusing ourselves
	if(strpos($interwiki_pagename, ":") === false)
		return null;
	
	[$prefix, $pagename] = interwiki_pagename_parse($interwiki_pagename); // Shorthand destructuring - introduced in PHP 7.1
	
	if(empty($env->interwiki_index->$prefix))
		return null;
	
	return $env->interwiki_index->$prefix;
}
/**
 * Converts an interwiki pagename into a url.
 * @package interwiki-links
 * @param	string	$interwiki_pagename		The interwiki pagename (in the form "prefix:page name")
 * @return	string	A url that points to the specified interwiki page.
 */
function interwiki_get_pagename_url($interwiki_pagename) {
	$interwiki_def = interwiki_pagename_resolve($interwiki_pagename);
	if($interwiki_def == null)
		return null;
	
	[$prefix, $pagename] = interwiki_pagename_parse($interwiki_pagename);
	
	return str_replace(
		"%s", rawurlencode($pagename),
		$interwiki_def->root_url
	);
}

/**
 * Returns whether a given pagename is an interwiki link or not.
 * Note that this doesn't guarantee that it's a _valid_ interwiki link - only that it looks like one :P
 * @package interwiki-links
 * @param	string	$pagename	The page name to check.
 * @return	bool	Whether the given page name is an interwiki link or not.
 */
function is_interwiki_link($pagename) {
	return strpos($pagename, ":") !== false;
}

?>
