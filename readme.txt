=== Auto Post Image ===
Tags: featured image, title, cover, chinese
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local text covers, content images and default images for WordPress posts, prepared during authenticated publishing rather than public page views.

== Description ==
Choose an image source and global text-cover style in Settings > Auto Post Image. Publish an ordinary post without a featured image to assign a standard WordPress media attachment. The optional Cover text field in classic and block editors lets you shorten the cover wording without changing the article title; leave it empty to use the title.

* English interface by default, with a Simplified Chinese switch.
* 7 locally bundled font families with SIL OFL licenses: Noto Sans SC, Noto Serif SC, ZCOOL QingKe HuangYou, ZCOOL KuaiLe, Poppins, Abril Fatface and Russo One; optional locally uploaded TTF.
* Solid, gradient, or local JPEG/PNG/WebP image background.
* Text cover, first local content image, default image, or content-first fallback modes.
* Measured Chinese/English wrapping, configurable minimum size, line/character limits and ellipsis.
* Horizontal/vertical alignment, line spacing and padding.
* Optional Cover text in the article editor.
* Category-filtered bulk filling of missing images, two articles per request, with a safe Stop button.
* Recent processing results with the actual generated Cover text, per-article errors and truncation notes.
* JPEG or PNG output; default 1200 x 800; maximum four million pixels.
* Manual featured images are preserved. Only Auto Post Image-owned images update on title changes.
* Style changes apply to new covers, not automatically to old ones.
* An optional article-ID tool can generate one eligible existing post using saved settings.
* Scheduled posts are prepared when an authorized author schedules them.
* No public scripts, styles, font requests, external services, or visitor-time rendering.

== Installation ==
1. Deactivate any competing automatic featured-image generator.
2. Upload the ZIP in Plugins > Add Plugin > Upload Plugin, then activate Auto Post Image.
3. Open Settings > Auto Post Image. Choose language, image source and text-cover style. Check actual server output before saving.
4. Publish a post with no featured image. For text covers, optionally enter shorter Cover text. New covers are saved to the media library; content/default attachments are reused.
5. To fill older articles, save settings first, optionally choose a category, then start Bulk fill missing images. Keep the page open; Stop finishes the current group. Restarting skips already filled posts.

Requires PHP GD with working FreeType and writable WordPress uploads. Activation checks both bundled fonts. If your upload limit is smaller than this ZIP, extract it and upload the auto-post-image folder to wp-content/plugins/ through your normal hosting file manager.

== Frequently Asked Questions ==

= Does it slow down visitors? =
Rendering runs during authenticated post publication, eligible title/cover-text changes, or explicit administrator operations. Content/default modes reuse local attachments without drawing a new cover. Public requests do not load the renderer, settings UI or plugin assets. Generating a cover adds work to the author's publishing request; its duration depends on hosting. Stored images use the normal theme/media delivery path.

= What happens to existing images? =
Existing featured images from another source stay untouched. Replaced Auto Post Image images remain in the media library for recovery. Disable/delete the plugin without automatically deleting settings, articles, or generated media. Remove unused media through WordPress when appropriate.

= What if generation fails? =
The previous featured image is preserved. See Recent article results on the settings page. Uncommitted candidate attachments/files are cleaned. Empty or overlong titles and invalid dimensions are rejected.

= Can every character or emoji be rendered? =
Additional bundled styles automatically fall back to the Noto Chinese faces for missing glyphs. Emoji and characters outside both selected and fallback fonts may still be missing. Custom TTF files must contain the characters you use; check the actual server preview. Text input is limited to 500 graphemes. Covers default to three lines and a minimum size of 32 px, with an ellipsis for overflow. These limits are configurable.

= Which content images can be reused? =
Only available images already in the local WordPress media library. The plugin checks image tags in content, skips external URLs, comments and scripts, and does not fetch remote images. Content-first modes fall back to your chosen text cover or default attachment. Reused attachments are not copied, retagged or deleted. A default image retains its original size.

= Does editing Cover text replace a manual image? =
No. It updates only a text cover owned by this plugin. This explicit change works even when automatic title updates are disabled. Clearing the field returns an existing owned cover to the article title. The block editor's secondary save preserves featured-image removal, including when Cover text changes in the same save.

= Are all drafts or cron publications covered? =
Drafts do not generate covers until an authorized publication or scheduling save. Scheduling prepares the cover beforehand; cron itself never renders. Imports or unattended writes without an authorized user are not automatically processed. Only ordinary posts are supported in this version.

= Why does the blog still show an old cover? =
Page caches and CDN nodes can retain older HTML. Clear the affected article/homepage cache using your existing cache tools and purge the relevant CDN URLs if needed, or wait for their TTL. Auto Post Image does not change cache settings or invoke broad remote purges.

= Does it change Sakura's article header? =
No. The plugin assigns a standard featured image. A theme that overlays the article title on the same image can display two titles. Theme layout/cropping and optional Sakura adaptation are separate from this plugin.

== Changelog ==

= 1.0.0 =
Initial release with seven bundled fonts, bilingual settings, text/content/default cover modes, optional Cover text, previews, and bulk filling of missing featured images.

== Third-party notices ==
Plugin code: GPL-2.0-or-later, see LICENSE.txt.
Bundled Noto CJK fonts: SIL Open Font License 1.1; see assets/fonts/README.md and OFL-Sans.txt/OFL-Serif.txt.
