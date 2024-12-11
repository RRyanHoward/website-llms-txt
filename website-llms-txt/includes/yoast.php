<?php
if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Yoast_Integration {
    private function __construct() {
        add_action('init', array($this, 'add_rewrite_rules'), 5);
        add_action('template_redirect', array($this, 'maybe_generate_sitemap'));
        add_filter('wpseo_sitemap_index', array($this, 'add_to_index'));
        add_filter('wpseo_sitemap_llms_content', array($this, 'generate_sitemap'));
        add_action('llms_clear_seo_caches', array($this, 'clear_sitemap_cache'));
    }

    public function add_rewrite_rules() {
        add_rewrite_rule('llms-sitemap\.xml$', 'index.php?sitemap=llms', 'top');
        add_filter('query_vars', function($vars) {
            $vars[] = 'sitemap';
            return $vars;
        });
    }

    public function maybe_generate_sitemap() {
    	$sitemap = get_query_var('sitemap');
    	if ($sitemap === 'llms') {
        	header('Content-Type: application/xml; charset=utf-8');
        	echo esc_xml($this->generate_sitemap());
        	exit;
    	}
	}

    public function generate_sitemap() {
        $latest_post = get_posts([
            'post_type' => 'llms_txt',
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);

        if (empty($latest_post)) {
            return '';
        }

        $url = array(
            'loc' => home_url('/llms.txt'),
            'lastmod' => get_post_modified_time('c', true, $latest_post[0]),
            'changefreq' => 'weekly',
            'priority' => '0.8'
        );

        $sitemap = '<?xml version="1.0" encoding="UTF-8"?>';
        $sitemap .= '<?xml-stylesheet type="text/xsl" href="' . esc_url(home_url('main-sitemap.xsl')) . '"?>';
        $sitemap .= '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
        $sitemap .= 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
        $sitemap .= 'xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 ';
        $sitemap .= 'http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">';
        $sitemap .= "\n\t<url>\n";
        $sitemap .= "\t\t<loc>" . esc_url($url['loc']) . "</loc>\n";
        $sitemap .= "\t\t<lastmod>" . esc_xml($url['lastmod']) . "</lastmod>\n";
        $sitemap .= "\t\t<changefreq>" . esc_xml($url['changefreq']) . "</changefreq>\n";
        $sitemap .= "\t\t<priority>" . esc_xml($url['priority']) . "</priority>\n";
        $sitemap .= "\t</url>\n";
        $sitemap .= "</urlset>";

        return $sitemap;
    }

    public function add_to_index($sitemap) {
        $latest_post = get_posts([
            'post_type' => 'llms_txt',
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);

        if (!empty($latest_post)) {
            $entry = "\n<sitemap>";
            $entry .= "\n\t<loc>" . esc_url(home_url('llms-sitemap.xml')) . "</loc>";
            $entry .= "\n\t<lastmod>" . esc_xml(get_post_modified_time('c', true, $latest_post[0])) . "</lastmod>";
            $entry .= "\n</sitemap>\n";
            return $sitemap . $entry;
        }

        return $sitemap;
    }

    public function clear_sitemap_cache() {
        do_action('wpseo_cache_clear_sitemap');
    }

    public static function get_instance() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }
}

LLMS_Yoast_Integration::get_instance();