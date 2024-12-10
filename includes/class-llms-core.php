<?php
if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Core {
    /** @var LLMS_Generator */
    private $generator;

    public function __construct() {
        // Register activation hook
        register_activation_hook(LLMS_PLUGIN_FILE, array($this, 'activate'));
        
        // Initialize core functionality
        add_action('init', array($this, 'init'), 0);
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('plugin_action_links_' . plugin_basename(LLMS_PLUGIN_FILE), array($this, 'add_settings_link'));
        
        // Handle file operations
        add_action('admin_post_upload_llms_file', array($this, 'handle_file_upload'));
        add_action('admin_post_remove_llms_file', array($this, 'handle_file_removal'));
        
        // Handle cache clearing
        add_action('admin_post_clear_caches', array($this, 'handle_cache_clearing'));
        
        // Initialize SEO integrations before post type registration
        add_action('init', array($this, 'init_seo_integrations'), -1);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add required scripts for admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function init() {
        // Register post type
        $this->create_post_type();
        
        // Initialize generator after post type
        require_once LLMS_PLUGIN_DIR . 'includes/class-llms-generator.php';
        $this->generator = new LLMS_Generator();
        
        // Add rewrite rules
        $this->add_rewrite_rule();
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_llms_request'));
    }

    public function init_seo_integrations() {
        if (class_exists('RankMath')) {
            require_once LLMS_PLUGIN_DIR . 'includes/providers/class-llms-provider.php';
            require_once LLMS_PLUGIN_DIR . 'includes/rank-math.php';
        }

        if (defined('WPSEO_VERSION') && class_exists('WPSEO_Sitemaps')) {
            require_once LLMS_PLUGIN_DIR . 'includes/yoast.php';
        }
    }

    public function register_settings() {
        register_setting(
            'llms_generator_settings',
            'llms_generator_settings',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'post_types' => array('page', 'documentation', 'post'),
                    'max_posts' => 100,
                    'include_meta' => true,
                    'include_excerpts' => true,
                    'include_taxonomies' => true,
                    'update_frequency' => 'immediate'
                )
            )
        );
    }

    public function sanitize_settings($value) {
        if (!is_array($value)) {
            return array();
        }

        $clean = array();
        
        // Ensure post_types is an array and contains only valid post types
        $clean['post_types'] = array();
        if (isset($value['post_types']) && is_array($value['post_types'])) {
            $valid_types = get_post_types(array('public' => true));
            foreach ($value['post_types'] as $type) {
                if (in_array($type, $valid_types) && $type !== 'attachment' && $type !== 'llms_txt') {
                    $clean['post_types'][] = $type;
                }
            }
        }
        
        // Sanitize max posts
        $clean['max_posts'] = isset($value['max_posts']) ? 
            min(max(absint($value['max_posts']), 1), 1000) : 100;
        
        // Sanitize boolean values
        $clean['include_meta'] = !empty($value['include_meta']);
        $clean['include_excerpts'] = !empty($value['include_excerpts']);
        $clean['include_taxonomies'] = !empty($value['include_taxonomies']);
        
        // Sanitize update frequency
        $clean['update_frequency'] = isset($value['update_frequency']) && 
            in_array($value['update_frequency'], array('immediate', 'daily', 'weekly')) ? 
            $value['update_frequency'] : 'immediate';
        
        // Trigger file update after settings change
        if ($this->generator) {
            $this->generator->update_llms_file();
        }
        
        return $clean;
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_llms-file-manager' !== $hook) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
    }

    public function create_post_type() {
        register_post_type('llms_txt', array(
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,      
            'show_in_admin_bar' => false, 
            'show_in_nav_menus' => false, 
            'show_in_rest' => false,      
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'supports' => array('title', 'editor'),
            'exclude_from_sitemap' => true
        ));
    }

    public function activate() {
        $this->create_post_type();
        flush_rewrite_rules();
    }

    public function add_admin_menu() {
        add_menu_page(
            'LLMS.txt Manager',
            'LLMS.txt',
            'manage_options',
            'llms-file-manager',
            array($this, 'render_admin_page'),
            'dashicons-media-text'
        );
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=llms-file-manager">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function render_admin_page() {
        include LLMS_PLUGIN_DIR . 'admin/admin-page.php';
    }

    public function handle_file_upload() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('upload_llms_file', 'upload_llms_file_nonce');

        if (!isset($_FILES['llms_file'])) {
            wp_redirect(admin_url('admin.php?page=llms-file-manager&status=error'));
            exit;
        }

        $file = $_FILES['llms_file'];
        
        if ($file['type'] !== 'text/plain') {
            wp_redirect(admin_url('admin.php?page=llms-file-manager&status=error'));
            exit;
        }

        $content = file_get_contents($file['tmp_name']);
        $existing_post = $this->get_llms_post();
        
        $post_data = array(
            'post_title' => 'LLMS.txt',
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'llms_txt'
        );

        if ($existing_post) {
            $post_data['ID'] = $existing_post->ID;
            wp_update_post($post_data);
        } else {
            wp_insert_post($post_data);
        }

        file_put_contents(ABSPATH . 'llms.txt', $content);
        do_action('llms_clear_seo_caches');

        wp_redirect(admin_url('admin.php?page=llms-file-manager&status=llms-success'));
        exit;
    }

    public function handle_file_removal() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('remove_llms_file', 'remove_llms_file_nonce');

        // Get existing post
        $existing_post = $this->get_llms_post();
        
        // Delete the post if it exists
        if ($existing_post) {
            wp_delete_post($existing_post->ID, true);
        }

        // Trigger regeneration through the generator
        if ($this->generator) {
            $this->generator->update_llms_file();
        }

        // Clear caches
        do_action('llms_clear_seo_caches');

        // Redirect back with success message
        wp_redirect(admin_url('admin.php?page=llms-file-manager&file_removed=true'));
        exit;
    }

    public function handle_cache_clearing() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('clear_caches', 'clear_caches_nonce');
        do_action('llms_clear_seo_caches');
        flush_rewrite_rules();

        wp_redirect(admin_url('admin.php?page=llms-file-manager&cache_cleared=true'));
        exit;
    }

    public function get_llms_post() {
        $posts = get_posts(array(
            'post_type' => 'llms_txt',
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ));

        return !empty($posts) ? $posts[0] : null;
    }

    public function add_rewrite_rule() {
        add_rewrite_rule('^llms\.txt$', 'index.php?llms_txt=1', 'top');
    }

    public function add_query_vars($vars) {
        $vars[] = 'llms_txt';
        return $vars;
    }

    public function handle_llms_request() {
        if (get_query_var('llms_txt')) {
            $post = $this->get_llms_post();
            if ($post) {
                header('Content-Type: text/plain');
                echo $post->post_content;
                exit;
            }
        }
    }
}