=== PSM FAQ Schema (Avada) ===
Contributors: pointsourcemarketing
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: Proprietary

Auto-injects FAQPage JSON-LD by parsing Avada accordion shortcodes marked with the "faq-accordion" CSS class.

== Description ==

Content team marks any Avada/Fusion accordion with the CSS class "faq-accordion".
This plugin parses the post content, extracts the toggle title/body pairs from
those accordions, and emits valid FAQPage JSON-LD in the page <head>.

No per-page configuration required. Schema renders server-side, visible to crawlers
in the initial HTML response.

Self-updates from the PSM update endpoint.

== Changelog ==

= 1.0.0 =
* Initial release
