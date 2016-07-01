#Concat All#

Concat All is a wordpress plugin that will concatenate all external and in-file javascript and css in your site.

## Description ##

This is the list of changes the plugin makes:  

* Buffers output for the following processing
* Finds all `<script type="text/javascript">` elements (with or without `src`)
* Uses a hash of either the `src` or the content of the script block to create a cache filename
* Checks if the cache file has already been created. If so, it proceeds to concatenating css
* Grabs the js source from in-file script blocks as well as external files
* Concatenates all js and saves to cache directory, preserving the same order the originals appeared in output
* Does the same with `<link rel='stylesheet' ...>` and `<style>` elements and if cached file does not exist, creates it.
* Removes the now redundant *script*, *link*, and *style* elements from HTML source
* Adds a single *link* element to *head* and a single *script* element before *body* closes for the cached resources
* Minifies the HTML source and sends the output

### Why not minify CSS and JS? ###
We are not minifying css and js when we concatenate them. There are several reasons for this:

* It is the responsibility of each plugin or template author to minify js and css for production
* Many resources are already minified, but have no clue in the file name so we might attempt minification of minified source
* Often source files include a copyright block in the form of comments that are stripped during minification, which is bad, because you are supposed to preserve those notices

If, however, you see that resulting cahced css and js would shrink significantly if all sources are minified, do the minification on the source files. There are comments above each block that show where the original resource was taken from.

## Installation ##

1. If `wp-content/uploads` directory is not writable, manually add a `wp-content/uploads/concat_all_cache` directory and make it writable.
2. Upload `concat_all` folder into your `wp-content/plugins` directory.
3. In your Admin panel under Plugins, find and enable `Concat All` plugin.

### Known Issues ###

* The plugin uses output buffering which might interfere with setups/plugins that cannot have their output buffered. In such cases, the plugin cannot be used.

* If you have `<pre>`, `<code>` or filled `<textarea>` elements in your output, newlines and other whitespace will not be preserved while minifying the HTML. If this is the case, comment out the line that minifiys the output prior to sending it, or, change the minifier code to not remove whitespace within those elements!

### Maintainance ###

The concatenated files are saved in `wp-content/uploads/concat_all_cache` directory. When you install, activate or deactivate other plugins or change your template, the catched files become obsolute and you'd better delete them to avoid having unused files pile up there.

## Changelog ##

### 0.2 ###
In this version hard-coded paths where replaced by getting the path from `wp_upload_dir()` function.


## License ##

The plugin would not work without output buffering and the code for that has been taken verbatim from [this SO answer](http://stackoverflow.com/a/22818089/66580), thanks [@kfriend](http://stackoverflow.com/users/419673/kfriend).
For HTML minification I am using the `minify_html` function from [this gist](https://gist.github.com/tovic/d7b310dea3b33e4732c0), thanks [@Taufik Nurrohman](https://github.com/tovic).

Copyright © 2016 Majid Fouladpour | [MIT license](https://opensource.org/licenses/MIT)
