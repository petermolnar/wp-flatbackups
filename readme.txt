=== wp-flatbackups ===
Contributors: cadeyrn
Donate link:
Tags: backup, YAML, flat files
Requires at least: 3.0
Tested up to: 4.4
Stable tag: 0.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Required minimum PHP version: 5.3

Auto-export all published content on visit to flat, folder + files based structure.

== Description ==

The plugin action is hooked into wp_footer, therefore executed on actual site visit.

Content will be exported to wp-content/flat/{post_slug}/ folder ( one folder per post), all attachments copied (or hardlinked, if possible with the filesystem; this is automatic ).

The content will be placed into in item.md file, in YAML + {post_content} format and the same format is applied to comments, named comment-{comment_id}.md in the same folder.

This is not a classic backup, rather an export, to have your content in a readable format for the future. It can't be imported to WordPress (yet).

== Requirements ==

* minimum PHP version: 5.3
* YAML PHP plugin

== Installation ==

1. Upload contents of `wp-flatbackups.zip` to the `/wp-content/plugins/` directory
2. Activate the plugin through the `Plugins` menu in WordPress

== Changelog ==

Version numbering logic:

* every A. indicates BIG changes.
* every .B version indicates new features.
* every ..C indicates bugfixes for A.B version.

= 0.1 =
*2015-12-10*

* first stable release
