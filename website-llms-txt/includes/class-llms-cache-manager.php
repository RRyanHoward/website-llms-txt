<?php
/**
 * LLMS Cache Management
 *
 * @package Website_LLMS_TXT
 */

if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Cache_Manager {
    /**
     * Initialize cache management
     */
    public function __construct() {
        // Add actions for clearing caches
        add_action('llms_clear_seo_caches', array($this, 'clear_all_caches'));
        
        // Add filters for cache exclusion
        add_action('plugins_loaded', array($this, 'setup_cache_exclusions'));
    }

    /**
     * Setup cache exclusions for various caching plugins
     */
    public function setup_cache_exclusions() {
        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            add_filter('autoptimize_filter_noptimize', array($this, 'exclude_autoptimize'), 10, 1);
        }

        // WP Rocket
        if (defined('WP_ROCKET_VERSION')) {
            add_filter('rocket_cache_reject_uri', array($this, 'exclude_wp_rocket'));
        }

        // W3 Total Cache
        if (defined('W3TC')) {
            add_filter('w3tc_pagecache_reject_uri', array($this, 'exclude_w3_total_cache'));
        }

        // WP Super Cache
        if (defined('WPSUPERCACHE')) {
            add_filter('wp_cache_reject_uri', array($this, 'exclude_wp_super_cache'));
        }

        // LiteSpeed Cache
        if (defined('LSCWP_V')) {
            add_filter('litespeed_cache_exclude_path', array($this, 'exclude_litespeed'));
        }
    }

    /**
     * Clear all possible caches
     */
    public function clear_all_caches() {
        $this->clear_autoptimize_cache();
        $this->clear_wp_rocket_cache();
        $this->clear_w3_total_cache();
        $this->clear_wp_super_cache();
        $this->clear_litespeed_cache();
        $this->clear_wp_fastest_cache();
    }

    /**
     * Clear Autoptimize cache
     */
    private function clear_autoptimize_cache() {
        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
        }
    }

    /**
     * Clear WP Rocket cache
     */
    private function clear_wp_rocket_cache() {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
    }

    /**
     * Clear W3 Total Cache
     */
    private function clear_w3_total_cache() {
        if (function_exists('w3tc_pgcache_flush')) {
            w3tc_pgcache_flush();
        }
    }

    /**
     * Clear WP Super Cache
     */
    private function clear_wp_super_cache() {
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
    }

    /**
     * Clear LiteSpeed Cache
     */
    private function clear_litespeed_cache() {
        if (class_exists('LiteSpeed\Purge')) {
            \LiteSpeed\Purge::purge_all();
        }
    }

    /**
     * Clear WP Fastest Cache
     */
    private function clear_wp_fastest_cache() {
        if (class_exists('WpFastestCache')) {
            if (method_exists('WpFastestCache', 'deleteCache')) {
                $wpfc = new WpFastestCache();
                $wpfc->deleteCache(true);
            }
        }
    }

    /**
 	* Exclude from Autoptimize
 	*/
	public function exclude_autoptimize($exclude) {
    if (is_string($exclude) && isset($_SERVER['REQUEST_URI'])) {
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        if (strpos($request_uri, 'sitemap') !== false) {
            return true;
        }
    }
    return $exclude;
}

    /**
     * Exclude from WP Rocket
     */
    public function exclude_wp_rocket($excluded) {
        $excluded[] = '/(.*)sitemap(.*).xml';
        $excluded[] = '/(.*)sitemap.xsl';
        return $excluded;
    }

    /**
     * Exclude from W3 Total Cache
     */
    public function exclude_w3_total_cache($excluded) {
        $excluded[] = 'sitemap(_index)?\.xml(\.gz)?';
        $excluded[] = '[a-z0-9_\-]*sitemap[a-z0-9_\-]*\.(xml|xsl|html)(\.gz)?';
        $excluded[] = '([a-z0-9_\-]*?)sitemap([a-z0-9_\-]*)?\.xml';
        return $excluded;
    }

    /**
     * Exclude from WP Super Cache
     */
    public function exclude_wp_super_cache($excluded) {
        $excluded[] = 'sitemap(_index)?\.xml(\.gz)?';
        $excluded[] = '[a-z0-9_\-]*sitemap[a-z0-9_\-]*\.(xml|xsl|html)(\.gz)?';
        $excluded[] = '([a-z0-9_\-]*?)sitemap([a-z0-9_\-]*)?\.xml';
        return $excluded;
    }

    /**
     * Exclude from LiteSpeed Cache
     */
    public function exclude_litespeed($excluded) {
        $excluded[] = '/(.*)sitemap(.*).xml';
        $excluded[] = '/(.*)sitemap.xsl';
        return $excluded;
    }
}