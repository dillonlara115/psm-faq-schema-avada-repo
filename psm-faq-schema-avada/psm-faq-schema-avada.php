<?php
/**
 * Plugin Name:       PSM FAQ Schema (Avada)
 * Plugin URI:        https://pointsourcemarketing.com/tools/faq-schema
 * Description:       Auto-injects FAQPage JSON-LD by parsing Avada accordion shortcodes marked with the "faq-accordion" CSS class. Self-updates from the PSM update endpoint.
 * Version:           1.0.2
 * Author:            Point Source Marketing
 * Author URI:        https://pointsourcemarketing.com
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * License:           Proprietary
 * Update URI:        https://github.com/point-source-marketing/psm-faq-schema-avada
 *
 * Workflow:
 *   1. Content team marks Avada accordion with CSS Class "faq-accordion".
 *   2. Authors Q&A as fusion_toggle items inside it.
 *   3. Plugin renders FAQPage JSON-LD in <head> on next pageview.
 *
 * Updates:
 *   This plugin checks UPDATE_ENDPOINT (constant below) for new versions.
 *   See README-host.md for what to deploy to that endpoint.
 *
 * Filters:
 *   psm_faq_schema_enabled       (bool, WP_Post)   - false to skip injection
 *   psm_faq_schema_class_marker  (string)          - default 'faq-accordion'
 *   psm_faq_schema_min_count     (int)             - default 2
 *   psm_faq_schema_data          (array, WP_Post)  - mutate before encoding
 *   psm_faq_schema_debug         (bool)            - emit detection HTML comment
 */

if (!defined("ABSPATH")) {
    exit();
}

if (class_exists("PSM_FAQ_Schema_Avada_Plugin")) {
    return;
}

final class PSM_FAQ_Schema_Avada_Plugin
{
    const VERSION = "1.0.2";
    const SLUG = "psm-faq-schema-avada";
    const SCHEMA_TAG = "psm-faq-schema";
    const GITHUB_REPO = "dillonlara115/psm-faq-schema-avada-repo";
    const UPDATE_TTL = 12 * HOUR_IN_SECONDS;

    /** Q&A pairs harvested from rendered content (fallback path). */
    private static $rendered_pairs = [];

    /** True once schema has been printed for this request. */
    private static $printed = false;

    public static function boot()
    {
        add_action("wp_head", [__CLASS__, "inject"], 99);

        // Fallback: harvest Q&A from the *rendered* HTML, print at wp_footer.
        // Covers Avada versions whose stored shortcode format the head-pass
        // regex doesn't match. JSON-LD in <body> is valid for Google.
        add_filter("the_content", [__CLASS__, "harvest_rendered"], 99);
        add_action("wp_footer", [__CLASS__, "inject_footer"], 5);

        // Self-updater hooks
        add_filter("pre_set_site_transient_update_plugins", [
            __CLASS__,
            "check_for_update",
        ]);
        add_filter("plugins_api", [__CLASS__, "plugin_info"], 10, 3);
        add_action(
            "upgrader_process_complete",
            [__CLASS__, "clear_update_cache"],
            10,
            2,
        );
    }

    /* ============================================================
     *  FAQ schema generation
     * ============================================================ */

    public static function inject()
    {
        if (!is_singular()) {
            return;
        }

        $post = get_post();
        if (!$post || empty($post->post_content)) {
            return;
        }

        if (!apply_filters("psm_faq_schema_enabled", true, $post)) {
            return;
        }

        $marker = (string) apply_filters(
            "psm_faq_schema_class_marker",
            "faq-accordion",
        );
        $min = (int) apply_filters("psm_faq_schema_min_count", 2);
        $debug = (bool) apply_filters("psm_faq_schema_debug", false);

        $faqs = self::extract_faqs($post->post_content, $marker);
        $faqs = apply_filters("psm_faq_schema_data", $faqs, $post);

        if ($debug) {
            printf(
                "\n<!-- %s v%s | pass=head(shortcode) | marker=%s | found=%d -->\n",
                esc_html(self::SCHEMA_TAG),
                esc_html(self::VERSION),
                esc_html($marker),
                count($faqs),
            );
        }

        if (count($faqs) < $min) {
            return;
        }

        self::print_schema($faqs);
    }

    /**
     * the_content filter (priority 99): by this point Avada has rendered its
     * shortcodes to HTML. If the head pass found nothing, harvest Q&A pairs
     * from the rendered accordion markup instead. Content is returned
     * unchanged; pairs are stashed for wp_footer.
     */
    public static function harvest_rendered($content)
    {
        if (
            self::$printed ||
            !is_singular() ||
            !in_the_loop() ||
            !is_main_query()
        ) {
            return $content;
        }

        $marker = (string) apply_filters(
            "psm_faq_schema_class_marker",
            "faq-accordion",
        );

        if (false === strpos($content, $marker)) {
            return $content;
        }

        $pairs = self::extract_faqs_from_html($content, $marker);
        if (count($pairs) > count(self::$rendered_pairs)) {
            self::$rendered_pairs = $pairs;
        }

        return $content;
    }

    /**
     * wp_footer: print schema from the rendered-HTML pass if the head pass
     * didn't already. JSON-LD placed in <body> is valid and read by Google.
     */
    public static function inject_footer()
    {
        if (self::$printed || !is_singular()) {
            return;
        }

        $post = get_post();
        if (!$post || !apply_filters("psm_faq_schema_enabled", true, $post)) {
            return;
        }

        $min = (int) apply_filters("psm_faq_schema_min_count", 2);
        $debug = (bool) apply_filters("psm_faq_schema_debug", false);

        $faqs = apply_filters(
            "psm_faq_schema_data",
            self::$rendered_pairs,
            $post,
        );

        if ($debug) {
            printf(
                "\n<!-- %s v%s | pass=footer(rendered-html) | found=%d -->\n",
                esc_html(self::SCHEMA_TAG),
                esc_html(self::VERSION),
                count($faqs),
            );
        }

        if (count($faqs) < $min) {
            return;
        }

        self::print_schema($faqs);
    }

    /**
     * Encode and print the FAQPage JSON-LD block. Sets the printed flag so
     * the two passes never double-emit.
     *
     * @param array<int,array{q:string,a:string}> $faqs
     */
    private static function print_schema($faqs)
    {
        $schema = [
            "@context" => "https://schema.org",
            "@type" => "FAQPage",
            "mainEntity" => array_map(static function ($pair) {
                return [
                    "@type" => "Question",
                    "name" => $pair["q"],
                    "acceptedAnswer" => [
                        "@type" => "Answer",
                        "text" => $pair["a"],
                    ],
                ];
            }, $faqs),
        ];

        $json = wp_json_encode(
            $schema,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (false === $json) {
            return;
        }

        self::$printed = true;

        printf(
            "\n<script type=\"application/ld+json\" data-source=\"%s\">%s</script>\n",
            esc_attr(self::SCHEMA_TAG),
            $json,
        );
    }

    /**
     * Extract Q&A pairs from *rendered* Avada accordion HTML.
     *
     * Looks for any element whose class list contains the marker class, then
     * collects .fusion-toggle-heading (question) and .panel-body /
     * .toggle-content (answer) pairs inside each .fusion-panel.
     *
     * @param string $html   Rendered content HTML.
     * @param string $marker CSS class marking FAQ accordions.
     * @return array<int,array{q:string,a:string}>
     */
    private static function extract_faqs_from_html($html, $marker)
    {
        $pairs = [];

        if ("" === trim($html) || !class_exists("DOMDocument")) {
            return $pairs;
        }

        $prev_errors = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Hint UTF-8 to the parser; rendered fragments have no <head> charset.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="psm-faq-root">' . $html . "</div>",
            LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev_errors);

        if (!$loaded) {
            return $pairs;
        }

        $xpath = new DOMXPath($dom);
        $class_match =
            "contains(concat(' ', normalize-space(@class), ' '), ' " .
            $marker .
            " ')";
        $accordions = $xpath->query("//*[" . $class_match . "]");

        if (!$accordions || 0 === $accordions->length) {
            return $pairs;
        }

        $seen = [];

        foreach ($accordions as $accordion) {
            // descendant-or-self: when the marker is on the accordion wrapper the
            // panels are descendants; when it's on each toggle the marked node IS
            // a .fusion-panel, so it must match itself too.
            $panels = $xpath->query(
                "descendant-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' fusion-panel ')]",
                $accordion,
            );
            if (!$panels) {
                continue;
            }

            foreach ($panels as $panel) {
                // A marked accordion and its marked toggles can both match; dedupe.
                $oid = spl_object_id($panel);
                if (isset($seen[$oid])) {
                    continue;
                }
                $seen[$oid] = true;

                $q_node = $xpath->query(
                    ".//*[contains(concat(' ', normalize-space(@class), ' '), ' fusion-toggle-heading ')]",
                    $panel,
                );
                $a_node = $xpath->query(
                    ".//*[contains(concat(' ', normalize-space(@class), ' '), ' panel-body ')]" .
                        " | .//*[contains(concat(' ', normalize-space(@class), ' '), ' toggle-content ')]",
                    $panel,
                );

                $q =
                    $q_node && $q_node->length
                        ? $q_node->item(0)->textContent
                        : "";
                $a =
                    $a_node && $a_node->length
                        ? $a_node->item(0)->textContent
                        : "";

                $q = trim(preg_replace("/\s+/", " ", $q));
                $a = trim(preg_replace("/\s+/", " ", $a));

                if ("" !== $q && "" !== $a) {
                    $pairs[] = ["q" => $q, "a" => $a];
                }
            }
        }

        return $pairs;
    }

    private static function extract_faqs($content, $marker)
    {
        $faqs = [];
        $marker_escaped = preg_quote($marker, "/");

        // The marker may live on the accordion's CSS Class field or on each
        // individual toggle's. Capture every accordion's opening attributes and
        // its inner shortcodes, then decide per-toggle whether it's marked.
        $accordion_re =
            "/\[fusion_accordion\b([^\]]*)\](.*?)\[\/fusion_accordion\]/is";
        $class_has_marker =
            '/\bclass\s*=\s*["\'][^"\']*\b' .
            $marker_escaped .
            '\b[^"\']*["\']/i';
        $toggle_re = "/\[fusion_toggle\s+([^\]]+)\](.*?)\[\/fusion_toggle\]/is";

        if (
            !preg_match_all(
                $accordion_re,
                $content,
                $accordion_matches,
                PREG_SET_ORDER,
            )
        ) {
            return $faqs;
        }

        foreach ($accordion_matches as $accordion) {
            $accordion_marked = (bool) preg_match(
                $class_has_marker,
                $accordion[1],
            );

            if (
                !preg_match_all(
                    $toggle_re,
                    $accordion[2],
                    $toggle_matches,
                    PREG_SET_ORDER,
                )
            ) {
                continue;
            }

            foreach ($toggle_matches as $toggle) {
                $attrs = shortcode_parse_atts($toggle[1]);

                // Include the toggle if the accordion is marked, or the toggle
                // itself carries the marker class.
                $toggle_marked =
                    isset($attrs["class"]) &&
                    preg_match(
                        "/\b" . $marker_escaped . "\b/i",
                        $attrs["class"],
                    );
                if (!$accordion_marked && !$toggle_marked) {
                    continue;
                }

                $title = isset($attrs["title"]) ? (string) $attrs["title"] : "";
                $body = $toggle[2];

                $body = do_shortcode($body);
                $body = wp_strip_all_tags($body);
                $body = preg_replace("/\s+/", " ", $body);
                $body = trim(
                    html_entity_decode($body, ENT_QUOTES | ENT_HTML5, "UTF-8"),
                );
                $title = trim(
                    html_entity_decode($title, ENT_QUOTES | ENT_HTML5, "UTF-8"),
                );

                if ($title !== "" && $body !== "") {
                    $faqs[] = ["q" => $title, "a" => $body];
                }
            }
        }

        return $faqs;
    }

    /* ============================================================
     *  Self-updater (GitHub Releases)
     *  Queries https://api.github.com/repos/<owner>/<repo>/releases/latest
     *  Expects a release whose tag is a semver string (with or without 'v'
     *  prefix) and that has the plugin zip attached as a release asset.
     *  Release notes (the body) become the changelog shown in WP's
     *  "View details" popup.
     * ============================================================ */

    private static function plugin_basename()
    {
        return self::SLUG . "/" . self::SLUG . ".php";
    }

    private static function github_api_url()
    {
        return "https://api.github.com/repos/" .
            self::GITHUB_REPO .
            "/releases/latest";
    }

    /**
     * Find the plugin zip among a release's assets. Prefers an asset named
     * exactly {slug}.zip, then any zip whose name starts with {slug},
     * then the first zip available.
     */
    private static function pick_zip_asset($assets)
    {
        if (empty($assets) || !is_array($assets)) {
            return "";
        }
        $exact = "";
        $prefix = "";
        $any_zip = "";
        foreach ($assets as $a) {
            $name = $a["name"] ?? "";
            $url = $a["browser_download_url"] ?? "";
            if (!$url || substr($name, -4) !== ".zip") {
                continue;
            }
            if ($name === self::SLUG . ".zip") {
                $exact = $url;
            } elseif (!$prefix && strpos($name, self::SLUG) === 0) {
                $prefix = $url;
            } elseif (!$any_zip) {
                $any_zip = $url;
            }
        }
        return $exact ?: ($prefix ?: $any_zip);
    }

    private static function get_remote_info()
    {
        $cached = get_site_transient("psm_faq_update_info");
        if (false !== $cached) {
            return $cached;
        }

        $response = wp_remote_get(self::github_api_url(), [
            "timeout" => 10,
            "headers" => [
                "Accept" => "application/vnd.github+json",
                "User-Agent" => "PSM-FAQ-Schema-Avada/" . self::VERSION,
            ],
        ]);

        if (
            is_wp_error($response) ||
            200 !== wp_remote_retrieve_response_code($response)
        ) {
            set_site_transient("psm_faq_update_info", [], HOUR_IN_SECONDS);
            return [];
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || empty($release["tag_name"])) {
            set_site_transient("psm_faq_update_info", [], HOUR_IN_SECONDS);
            return [];
        }

        $version = ltrim($release["tag_name"], "vV");
        $zip_url = self::pick_zip_asset($release["assets"] ?? []);

        // If a release has no attached zip asset, we can't update.
        if (empty($zip_url)) {
            set_site_transient("psm_faq_update_info", [], HOUR_IN_SECONDS);
            return [];
        }

        $info = [
            "version" => $version,
            "download_url" => $zip_url,
            "homepage" =>
                $release["html_url"] ??
                "https://github.com/" . self::GITHUB_REPO,
            "tested" => "",
            "requires" => "5.8",
            "requires_php" => "7.4",
            "sections" => [
                "description" =>
                    'Auto-injects FAQPage JSON-LD for Avada accordions tagged with the "faq-accordion" CSS class.',
                "changelog" => !empty($release["body"])
                    ? wp_kses_post($release["body"])
                    : "",
            ],
        ];

        set_site_transient("psm_faq_update_info", $info, self::UPDATE_TTL);
        return $info;
    }

    public static function check_for_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $info = self::get_remote_info();
        if (empty($info)) {
            return $transient;
        }

        if (version_compare($info["version"], self::VERSION, ">")) {
            $transient->response[self::plugin_basename()] = (object) [
                "id" => self::plugin_basename(),
                "slug" => self::SLUG,
                "plugin" => self::plugin_basename(),
                "new_version" => $info["version"],
                "url" => $info["homepage"] ?? "",
                "package" => $info["download_url"],
                "tested" => $info["tested"] ?? "",
                "requires_php" => $info["requires_php"] ?? "7.4",
                "icons" => [],
                "banners" => [],
            ];
        } else {
            $transient->no_update[self::plugin_basename()] = (object) [
                "id" => self::plugin_basename(),
                "slug" => self::SLUG,
                "plugin" => self::plugin_basename(),
                "new_version" => self::VERSION,
                "url" => $info["homepage"] ?? "",
                "package" => "",
                "requires_php" => $info["requires_php"] ?? "7.4",
            ];
        }

        return $transient;
    }

    public static function plugin_info($result, $action, $args)
    {
        if (
            "plugin_information" !== $action ||
            empty($args->slug) ||
            self::SLUG !== $args->slug
        ) {
            return $result;
        }

        $info = self::get_remote_info();
        if (empty($info)) {
            return $result;
        }

        return (object) [
            "name" => "PSM FAQ Schema (Avada)",
            "slug" => self::SLUG,
            "version" => $info["version"],
            "author" =>
                '<a href="https://pointsourcemarketing.com">Point Source Marketing</a>',
            "homepage" => $info["homepage"] ?? "",
            "requires" => $info["requires"] ?? "5.8",
            "tested" => $info["tested"] ?? "",
            "requires_php" => $info["requires_php"] ?? "7.4",
            "download_link" => $info["download_url"],
            "sections" => $info["sections"] ?? [
                "description" =>
                    'Auto-injects FAQPage JSON-LD for Avada accordions tagged with the "faq-accordion" CSS class.',
            ],
        ];
    }

    public static function clear_update_cache($upgrader, $options)
    {
        if (empty($options["type"]) || "plugin" !== $options["type"]) {
            return;
        }
        if (empty($options["action"]) || "update" !== $options["action"]) {
            return;
        }
        delete_site_transient("psm_faq_update_info");
    }
}

PSM_FAQ_Schema_Avada_Plugin::boot();
