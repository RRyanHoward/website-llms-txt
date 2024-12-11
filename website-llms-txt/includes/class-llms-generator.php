<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once LLMS_PLUGIN_DIR . 'includes/class-llms-content-cleaner.php';

class LLMS_Generator {
    private $settings;
    private $content_cleaner;
    private $wp_filesystem;
    
    public function __construct() {
        $this->settings = get_option('llms_generator_settings', array(
            'post_types' => array('page', 'documentation', 'post'),
            'max_posts' => 100,
            'include_meta' => true,
            'include_excerpts' => true,
            'include_taxonomies' => true,
            'update_frequency' => 'immediate'
        ));

        // Initialize content cleaner
        $this->content_cleaner = new LLMS_Content_Cleaner();

        // Initialize WP_Filesystem
        $this->init_filesystem();

        // Move initial generation to init hook
        add_action('init', array($this, 'init_generator'), 20);
        
        // Hook into post updates
        add_action('save_post', array($this, 'handle_post_update'), 10, 3);
        add_action('before_delete_post', array($this, 'handle_post_deletion'));
        add_action('wp_update_term', array($this, 'handle_term_update'));
        
        // Schedule regular updates if needed
        if ($this->settings['update_frequency'] !== 'immediate') {
            add_action('wp', array($this, 'schedule_updates'));
        }
    }

    private function init_filesystem() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        $this->wp_filesystem = $wp_filesystem;
    }

    public function init_generator() {
        // Force initial generation
        $content = $this->generate_content();

        // Create file using WP_Filesystem
        $this->write_file($content);
    }

    private function write_file($content) {
        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        if ($this->wp_filesystem) {
            $file_path = ABSPATH . 'llms.txt';
            $this->wp_filesystem->put_contents($file_path, $content, FS_CHMOD_FILE);
        }
    }

    public function generate_content() {
        $output = $this->generate_site_info();
        $output .= $this->generate_overview();
        $output .= $this->generate_detailed_content();
        return $output;
    }

    private function generate_site_info() {
        // Try to get meta description from Yoast or RankMath
        $meta_description = $this->get_site_meta_description();
        
        $output = "# " . get_bloginfo('name') . "\n\n";
        if ($meta_description) {
            $output .= "> " . $meta_description . "\n\n";
        } else {
            $output .= "> " . get_bloginfo('description') . "\n\n";
        }
        $output .= "---\n\n";
        return $output;
    }

    private function generate_overview() {
        $output = "";
        
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;
            
            $posts = get_posts(array(
                'post_type' => $post_type,
                'posts_per_page' => $this->settings['max_posts'],
                'post_status' => 'publish',
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if (!empty($posts)) {
                // Filter out no-indexed posts
                $posts = array_filter($posts, array($this, 'is_post_indexed'));

                if (!empty($posts)) {  // Check again after filtering
                    $post_type_obj = get_post_type_object($post_type);
                    $output .= "## " . $post_type_obj->labels->name . "\n\n";

                    foreach ($posts as $post) {
                        $meta_description = $this->get_post_meta_description($post);
                        
                        if ($meta_description) {
                            // Use Rank Math/Yoast description directly if available
                            $description = $meta_description;
                        } else {
                            // Fallback to cleaned excerpt/content
                            $fallback_content = $post->post_excerpt ?: $post->post_content;
                            $fallback_content = $this->content_cleaner->clean($fallback_content);
                            $description = wp_trim_words($fallback_content, 20, '...');
                        }
                        
                        $output .= sprintf("- [%s](%s): %s\n",
                            $post->post_title,
                            get_permalink($post),
                            $description
                        );
                    }
                    $output .= "\n";
                }
            }
        }
        
        $output .= "---\n\n";
        return $output;
    }

    private function is_post_indexed($post) {
        // Check Rank Math
        if (class_exists('RankMath')) {
            $robots = get_post_meta($post->ID, 'rank_math_robots', true);
            if (is_array($robots) && in_array('noindex', $robots)) {
                return false;
            }
        }
        
        // Check Yoast
        if (class_exists('WPSEO_Meta')) {
            $robots = WPSEO_Meta::get_value('meta-robots-noindex', $post->ID);
            if ($robots === '1') {  // Yoast uses '1' for noindex
                return false;
            }
        }
        
        return true;
    }

    private function get_post_seo_title($post) {
        if (class_exists('WPSEO_Meta')) {
            $seo_title = WPSEO_Meta::get_value('title', $post->ID);
            if (!empty($seo_title)) {
                // Remove common Yoast variables and clean up
                $seo_title = str_replace(array(
                    '%%sep%%', 
                    '%%sitename%%',
                    ' - ' . get_bloginfo('name'),
                    ' | ' . get_bloginfo('name'),
                    ' » ' . get_bloginfo('name')
                ), '', $seo_title);
                return trim(wpseo_replace_vars($seo_title, $post));
            }
        } elseif (class_exists('RankMath\Post\Post')) {
            $seo_title = RankMath\Post\Post::get_meta('title', $post->ID);
            if (!empty($seo_title)) {
                // Remove common RankMath variables and clean up
                $seo_title = str_replace(array(
                    '%sep%', 
                    '%sitename%',
                    ' - ' . get_bloginfo('name'),
                    ' | ' . get_bloginfo('name'),
                    ' » ' . get_bloginfo('name')
                ), '', $seo_title);
                return trim(RankMath\Helper::replace_vars($seo_title, $post));
            }
        }
        return false;
    }

    private function generate_detailed_content() {
        $output = "# Detailed Content\n\n";
        
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;
            
            $posts = get_posts(array(
                'post_type' => $post_type,
                'posts_per_page' => $this->settings['max_posts'],
                'post_status' => 'publish',
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if (!empty($posts)) {
                // Filter out no-indexed posts
                $posts = array_filter($posts, array($this, 'is_post_indexed'));

                if (!empty($posts)) {  // Check again after filtering
                    $post_type_obj = get_post_type_object($post_type);
                    $output .= "## " . $post_type_obj->labels->name . "\n\n";

                    foreach ($posts as $post) {
                        $output .= $this->format_post_content($post);
                    }
                }
            }
        }
        
        return $output;
    }

    private function format_post_content($post) {
        $output = "### " . $post->post_title . "\n\n";

        if ($this->settings['include_meta']) {
            $meta_description = $this->get_post_meta_description($post);
            if ($meta_description) {
                $output .= "> " . $meta_description . "\n\n";
            }
            
            $output .= "- Published: " . get_the_date('Y-m-d', $post) . "\n";
            $output .= "- Modified: " . get_the_modified_date('Y-m-d', $post) . "\n";
            $output .= "- URL: " . get_permalink($post) . "\n";

            if ($this->settings['include_taxonomies']) {
                $taxonomies = get_object_taxonomies($post->post_type, 'objects');
                foreach ($taxonomies as $tax) {
                    $terms = get_the_terms($post, $tax->name);
                    if ($terms && !is_wp_error($terms)) {
                        $term_names = wp_list_pluck($terms, 'name');
                        $output .= "- " . $tax->labels->name . ": " . implode(', ', $term_names) . "\n";
                    }
                }
            }
        }

        $output .= "\n";

        if ($this->settings['include_excerpts'] && !empty($post->post_excerpt)) {
            $output .= trim($post->post_excerpt) . "\n\n";
        }

        // Clean and add the content
        $content = $this->content_cleaner->clean($post->post_content);
        $output .= $content . "\n\n";
        $output .= "---\n\n";

        return $output;
    }

    private function get_site_meta_description() {
        if (class_exists('WPSEO_Options')) {
            return WPSEO_Options::get('metadesc');
        } elseif (class_exists('RankMath')) {
            return get_option('rank_math_description');
        }
        return false;
    }

    private function get_post_meta_description($post) {
        if (class_exists('WPSEO_Meta')) {
            return WPSEO_Meta::get_value('metadesc', $post->ID);
        } elseif (class_exists('RankMath')) {
            // Try using RankMath's helper class first
            if (class_exists('RankMath\Helper')) {
                $desc = RankMath\Helper::get_post_meta('description', $post->ID);
                if (!empty($desc)) {
                    return $desc;
                }
            }
            
            // Fallback to Post class if Helper doesn't work
            if (class_exists('RankMath\Post\Post')) {
                return RankMath\Post\Post::get_meta('description', $post->ID);
            }
        }
        return false;
    }

    public function handle_post_update($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!in_array($post->post_type, $this->settings['post_types'])) return;

        if ($this->settings['update_frequency'] === 'immediate') {
            $this->update_llms_file();
        }
    }

    public function handle_post_deletion($post_id) {
        if ($this->settings['update_frequency'] === 'immediate') {
            $this->update_llms_file();
        }
    }

    public function handle_term_update($term_id) {
        if ($this->settings['update_frequency'] === 'immediate') {
            $this->update_llms_file();
        }
    }

    public function update_llms_file() {
        $content = $this->generate_content();
        
        // Update the hidden post
        $core = new LLMS_Core();
        $existing_post = $core->get_llms_post();
        
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

        // Update the physical file using WP_Filesystem
        $this->write_file($content);
        
        // Clear caches
        do_action('llms_clear_seo_caches');
    }

    public function schedule_updates() {
        if (!wp_next_scheduled('llms_scheduled_update')) {
            $frequency = $this->settings['update_frequency'] === 'daily' ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
            wp_schedule_event(time(), $frequency, 'llms_scheduled_update');
        }
    }
}