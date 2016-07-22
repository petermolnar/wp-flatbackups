=== wp-flatexport ===
Contributors: cadeyrn
Tags: plain text, export, backup
Requires at least: 3.0
Tested up to: 4.5.3
Stable tag: 0.5
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Auto-export WordPress flat, structured, readable plain text.

== Description ==

*WARNING*
This plugin is suited for my needs.
It only works well with Markdown content.
There are certain tricks done with the content.
Please be aware of this.

Content will be exported to wp-content/flat/{post_slug}/ folder ( one folder per post), all attachments copied (or hardlinked, if possible with the filesystem; this is automatic ).

The content will be placed into in item.md file. This is a markdown file, with some plain test headers.
Comments are exported into the same folder, named comment-{comment_id}.md; the format is similar for those as well.

**This is not a backup!**
The goal of the plugin is to have a portable, plain text, easy to read, easy to copy version of you content on WordPress. Since not all data is exported, your site cannot be reconstructed from these exports.

== Requirements ==

* minimum PHP version: 5.4

== Installation ==

1. Upload contents of `wp-flatbackups.zip` to the `/wp-content/plugins/` directory
2. Activate the plugin through the `Plugins` menu in WordPress

== Changelog ==

Version numbering logic:

* every A. indicates BIG changes.
* every .B version indicates new features.
* every ..C indicates bugfixes for A.B version.

= 0.5 =
*2016-07-22*

* everything is through filters now
* moar magic formatting:
** stong/em moved to strict `**`/`_` from `__`/`*`
** definition lists moved to strict `:    ` from lazy spaces
* code is strictly less, than 80 char per line
* comments format changed
* using index.txt instead of item.md
*

= 0.3 =
*2016-07-14*

* switched to filter-based content inserting for the end output; this way it's possible to add, remove, reorder, and insert in between the predefined output parts

= 0.2 =
*2016-06-27*

* plugin renamed to avoid confusion of the exports being backups
* removed YAML in favour of parseable plain text formatting

= 0.1.1 =
*2016-03-08*

* better debugging

= 0.1 =
*2015-12-10*

* first stable release
