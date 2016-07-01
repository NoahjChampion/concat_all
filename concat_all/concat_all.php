<?php
/*
Plugin Name: Concat All
Plugin URI:  https://github.com/majid4466/concat_all
Description: Concatenetes all CSS and JS code into a single cached file for each and replaces all script and link/style tags with that of the single file.
Version:     0.1
Author:      Majid Fouladpour
Author URI:  http://stackoverflow.com/users/66580/majid-fouladpour
License:     MIT
License URI: https://opensource.org/licenses/MIT
*/

// no direct access
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

// not for admins
if(!is_admin()) {

	/**
	 * Output Buffering
	 *
	 * Buffers the entire WP process, capturing the final output for manipulation.
	 */

	ob_start();

	add_action('shutdown', function() {
	    $final = '';

	    // We'll need to get the number of ob levels we're in, so that we can iterate over each, collecting
	    // that buffer's output into the final output.
	    $levels = ob_get_level();

	    for ($i = 0; $i < $levels; $i++) {
	        $final .= ob_get_clean();
	    }

	    // Apply any filters to the final output
	    $final = apply_filters('final_output', $final);
	    echo $final;

	}, 0);


	add_filter('final_output', function($output) {
	  return mf_minify_src($output);
	});

} 


function mf_minify_src($raw_html) {

  $upload_dir = wp_upload_dir();

  // cache dir and url
  define('MF_CACHE_DIR', $upload_dir['basedir'] . '/concat_all_cache');
  define('MF_CACHE_URL', $upload_dir['baseurl'] . '/concat_all_cache');

  // if cache dir does not exist, create it
  if(!file_exists(MF_CACHE_DIR)) {
    mkdir(MF_CACHE_DIR, 0755);
  }

  $html = $raw_html;

  $doc = new DOMDocument();
  $doc->preserveWhiteSpace = false;
  @$doc->loadHTML($html);

  $tags = $doc->getElementsByTagName('*');

  $resource_names = [
  	'js' => [], 
  	'css' => []
  ];
  $resources      = [];
  $to_be_removed  = [];

  foreach ($tags as $tag) {

    $tagName = $tag->tagName;
    switch($tagName) {

      case 'link':

        // we just want links like <link rel='stylesheet' href='https://www.example.com/path/style.css' type='text/css'/>
        if(!$tag->hasAttribute('rel')) break;
        if($tag->getAttribute('rel') != 'stylesheet') break;
        if(!$tag->hasAttribute('href')) break;

        $media 			      = $tag->hasAttribute('media') ? $tag->getAttribute('media') : 'all'; 
        $url              = $tag->getAttribute('href');
        $id               = md5($url);

        $to_be_removed[]  = $tag;
        $resource_names['css'][] = $id;
        $node_path        = $tag->getNodePath();

        $resources[$id]   = [
          'type'          => 'css',
          'media'		      => $media,
          'is_external'   => true,
          'url'     	    => $url,
          'node_path'     => $node_path,
          'content'       => ''
        ];

        break;

      case 'style':

        $node_path        = $tag->getNodePath();
        $content          = trim($tag->textContent);
        $id               = md5($content);
        $media 			      = $tag->hasAttribute('media') ? $tag->getAttribute('media') : 'all'; 

        $resource_names['css'][] = $id;
        $to_be_removed[]  = $tag;

        $resources[$id]   = [
          'type'          => 'css',
          'media'		  => $media,
          'is_external'   => false,
          'node_path'     => $node_path,
          'content'       => $content
        ];

        break;

      case 'script':

        // we are after javascript script elements only
        $type = $tag->hasAttribute('type') ? $tag->getAttribute('type') : 'text/javascript';
        if(strpos($type, 'javascript') === false) break;

        $node_path          = $tag->getNodePath();
        $to_be_removed[]    = $tag;

        // is this in-file or external?
        $is_external        = $tag->hasAttribute('src');

        if($is_external) {

          $url              = $tag->getAttribute('src');
          $id               = md5($url);
          $content          = '';

          $resource_names['js'][] = $id;

          $resources[$id]   = [
            'type'          => 'js',
            'is_external'   => true,
            'url'           => $url,
            'node_path'		  => $node_path,
            'content'       => $content
          ];

        } else {

          $content          = trim($tag->textContent);
          $id               = md5($content);

          $resource_names['js'][] = $id;

          $resources[$id]   = [
            'type'          => 'js',
            'is_external'   => false,
            'node_path'		  => $node_path,
            'content'       => $content
          ];

        }

        break;

    }

  }

  $js_name  = md5(implode('', $resource_names['js']));
  $css_name = md5(implode('', $resource_names['css']));

  // if minified js does not exist set flag to create it
  $js_file = "$js_name.js";
  $js      = file_exists(MF_CACHE_DIR . "/$js_file") ? false : $js_file;

  // if minified css does not exist set flag to create it
  $css_file = "$css_name.css";
  $css      = file_exists(MF_CACHE_DIR . "/$css_file") ? false : $css_file;

  // if either file is missing create it
  if($js || $css) {
    mf_concat($js, $css, $resources);
  }

  // remove script and styles from html
  foreach( $to_be_removed as $domElement ){
    $domElement->parentNode->removeChild($domElement);
  }

  // updated html source
  $html = $doc->saveHTML();

  // minify html
  $html = minify_html($html);

  // insert minified style and script elements
  $minified_css = '<link rel="stylesheet" href="' . MF_CACHE_URL . "/$css_file" . '" type="text/css"/>';
  $minified_js  = '<script type="text/javascript" src="' . MF_CACHE_URL . "/$js_file" . '" defer></script>';
  $html = str_replace('</head>', $minified_css . '</head>', $html);
  $html = str_replace('</head>', $minified_js  . '</head>', $html);
  return $html;

}

function mf_concat($js_file, $css_file, $resources) {
  $js  = [];
  $css = [];
  foreach($resources as $id => $res) {

    switch($res['type']) {

      case 'js':
        if($js_file === false) continue;
        if($res['is_external']) {
        	$sig = '/' . '***  From ' . $res['url'] . ' on ' . $res['node_path'] . '  ***' . '/' . PHP_EOL;
        	$c = file_get_contents($res['url']);
        } else {
        	$sig = '/' . '***  From in-file script on ' . $res['node_path'] . '  ***' . '/' . PHP_EOL;
        	$c = $res['content'];
        }
        $js[]  = $sig . $c;
        break;

      case 'css':
        if($css_file === false) continue;
       	$media = '@media ' . $res['media'] . ' {' . PHP_EOL;
        if($res['is_external']) {
        	$sig = '/' . '***  From ' . $res['url'] . ' on ' . $res['node_path'] . '  ***' . '/' . PHP_EOL;
        	$c = file_get_contents($res['url']);
        	$old_path = $res['url'];
        } else {
        	$sig = '/' . '***  From in-file style on ' . $res['node_path'] . '  ***' . '/' . PHP_EOL;
        	$c = $res['content'];
        	$old_path = MF_CACHE_DIR;
        }

        // adjust urls for backgrounds to be correct for the new path
        $c = mf_correct_paths($old_path, $c);

        //$c = $res['is_external'] ? file_get_contents($res['url']) : $res['content'];
        $css[] = $sig . $media . $c . PHP_EOL . '}';
        break;

    }

  }

  if($js_file !== false) {
  	$minjs = '';
  	foreach ($js as $j) {
  		$minjs .= 'try {' . PHP_EOL . $j . PHP_EOL . '} catch(err) { console.log(err); };' . PHP_EOL . PHP_EOL;
  	}
    file_put_contents(MF_CACHE_DIR . "/$js_file", $minjs);
  }

  if($css_file !== false) {
    $css = implode(PHP_EOL.PHP_EOL, $css);
    file_put_contents(MF_CACHE_DIR . "/$css_file", $css);
  }

}

function mf_correct_paths($file_name, $in_css) {

	preg_match_all("/url\(([^\)]*)\)/", $in_css, $results);

	$num_paths = count($results[0]);

	// no url(path) to rewrite
	if($num_paths == 0) {
		return $in_css;
	}

	$file_path = explode('/', $file_name);
	array_pop($file_path);
	$file_path = implode('/', $file_path);

	$css = $in_css;

	for($i = 0; $i < $num_paths; $i++) {
		$old_path = $results[1][$i];
		$old_path = str_replace(['"', "'"], '', $old_path);

		if(filter_var($old_path, FILTER_VALIDATE_URL)) {
			continue;
		}
		if(strpos($old_path, 'data:') !== false) {
			continue;
		}
		// we have a path, we first add it to $file_path
		$new_path = mf_clean_path($file_path, $old_path);
		$css = str_replace($results[0][$i], "url($new_path)", $css);
	}

	return $css;
}

// HTML Minifier
function minify_html($input) {
    if(trim($input) === "") return $input;
    // Remove extra white-space(s) between HTML attribute(s)
    $input = preg_replace_callback('#<([^\/\s<>!]+)(?:\s+([^<>]*?)\s*|\s*)(\/?)>#s', function($matches) {
        return '<' . $matches[1] . preg_replace('#([^\s=]+)(\=([\'"]?)(.*?)\3)?(\s+|$)#s', ' $1$2', $matches[2]) . $matches[3] . '>';
    }, str_replace("\r", "", $input));
    // Minify inline CSS declaration(s)
    if(strpos($input, ' style=') !== false) {
        $input = preg_replace_callback('#<([^<]+?)\s+style=([\'"])(.*?)\2(?=[\/\s>])#s', function($matches) {
            return '<' . $matches[1] . ' style=' . $matches[2] . minify_css($matches[3]) . $matches[2];
        }, $input);
    }
    return preg_replace(
        array(
            // t = text
            // o = tag open
            // c = tag close
            // Keep important white-space(s) after self-closing HTML tag(s)
            '#<(img|input)(>| .*?>)#s',
            // Remove a line break and two or more white-space(s) between tag(s)
            '#(<!--.*?-->)|(>)(?:\n*|\s{2,})(<)|^\s*|\s*$#s',
            '#(<!--.*?-->)|(?<!\>)\s+(<\/.*?>)|(<[^\/]*?>)\s+(?!\<)#s', // t+c || o+t
            '#(<!--.*?-->)|(<[^\/]*?>)\s+(<[^\/]*?>)|(<\/.*?>)\s+(<\/.*?>)#s', // o+o || c+c
            '#(<!--.*?-->)|(<\/.*?>)\s+(\s)(?!\<)|(?<!\>)\s+(\s)(<[^\/]*?\/?>)|(<[^\/]*?\/?>)\s+(\s)(?!\<)#s', // c+t || t+o || o+t -- separated by long white-space(s)
            '#(<!--.*?-->)|(<[^\/]*?>)\s+(<\/.*?>)#s', // empty tag
            '#<(img|input)(>| .*?>)<\/\1\x1A>#s', // reset previous fix
            '#(&nbsp;)&nbsp;(?![<\s])#', // clean up ...
            // Force line-break with `&#10;` or `&#xa;`
            '#&\#(?:10|xa);#',
            // Force white-space with `&#32;` or `&#x20;`
            '#&\#(?:32|x20);#',
            // Remove HTML comment(s) except IE comment(s)
            '#\s*<!--(?!\[if\s).*?-->\s*|(?<!\>)\n+(?=\<[^!])#s'
        ),
        array(
            "<$1$2</$1\x1A>",
            '$1$2$3',
            '$1$2$3',
            '$1$2$3$4$5',
            '$1$2$3$4$5$6$7',
            '$1$2$3',
            '<$1$2',
            '$1 ',
            "\n",
            ' ',
            ""
        ),
    $input);
}

function mf_clean_path($base, $sub) {

	$a = $base;
	$b = $sub;

	$barr = explode('/', $b);
	$aarr = explode('/', $a);
	$carr = [];

	$n = count($barr);
	for($i = 0; $i < $n; $i++) {
		$d = $barr[$i];
		switch($d) {
			case '.':  break;
			case '..': array_pop($aarr); break;
			default: $carr[] = $d;
		}
	}

	$a = implode('/', $aarr);
	$c = implode('/', $carr);

	return "$a/$c";
}
