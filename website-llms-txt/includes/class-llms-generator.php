<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once LLMS_PLUGIN_DIR . 'includes/class-llms-content-cleaner.php';

class LLMS_Generator
{
    private $settings;
    private $content_cleaner;
    private $wp_filesystem;
    private $llms_path;
    private $write_log;
    private $llms_name;
    private $limit = 500;

    public function __construct()
    {
        $this->settings = get_option('llms_generator_settings', array(
            'post_types' => array('page', 'documentation', 'post'),
            'max_posts' => 100,
            'max_words' => 250,
            'include_meta' => true,
            'include_excerpts' => true,
            'include_taxonomies' => true,
            'update_frequency' => 'immediate',
            'need_check_option' => true,
        ));

        // Initialize content cleaner
        $this->content_cleaner = new LLMS_Content_Cleaner();

        // Initialize WP_Filesystem
        $this->init_filesystem();

        // Move initial generation to init hook
        add_action('init', array($this, 'init_generator'), 20);

        // Hook into post updates
        add_action('save_post', array($this, 'handle_post_update'), 10, 3);
        add_action('deleted_post', array($this, 'handle_post_deletion'), 999, 2);
        add_action('wp_update_term', array($this, 'handle_term_update'));
        add_action('llms_scheduled_update', array($this, 'llms_scheduled_update'));
        add_action('schedule_updates', array($this, 'schedule_updates'));
        add_filter('get_llms_content', array($this, 'get_llms_content'));
        add_action('init', array($this, 'llms_maybe_create_ai_sitemap_page'));
        add_action('llms_update_llms_file_cron', array($this, 'update_llms_file'));
    }

    public function llms_maybe_create_ai_sitemap_page()
    {
        if (!isset($this->settings['removed_ai_sitemap']))
        {
            $page = get_page_by_path('ai-sitemap');
            if ($page && $page->post_type === 'page')
            {
                wp_delete_post($page->ID, true);
                $this->settings['removed_ai_sitemap'] = true;
                update_option('llms_generator_settings', $this->settings);
            }
        }
    }

    public function llms_scheduled_update()
    {
        $this->init_generator(true);
    }

    private function init_filesystem()
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        $this->wp_filesystem = $wp_filesystem;
    }

    public function init_generator($force = false)
    {

        $siteurl = get_option('siteurl');
        if($siteurl) {
            $this->llms_name = parse_url($siteurl)['host'];
        }

        if ($this->settings['update_frequency'] !== 'immediate') {
            do_action('schedule_updates');
        }

        if (isset($_POST['llms_generator_settings'], $_POST['llms_generator_settings']['update_frequency']) || $force) {
            $this->update_llms_file();
        }
    }

    private function write_log($content)
    {
        if (!$this->write_log) {
            $upload_dir = wp_upload_dir();
            $this->write_log = $upload_dir['basedir'] . '/log.txt';
        }

        file_put_contents($this->write_log, $content, FILE_APPEND | LOCK_EX);
    }

    private function write_file($content)
    {
        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        if ($this->wp_filesystem) {
            if (!$this->llms_path) {
                $upload_dir = wp_upload_dir();
                $this->llms_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
            }

            file_put_contents($this->llms_path, $content, FILE_APPEND | LOCK_EX);
        }
    }

    public function get_llms_content($content)
    {
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
        if (file_exists($upload_path)) {
            $content .= file_get_contents($upload_path);
        }
        return $content;
    }

    public function generate_content()
    {
        $this->generate_site_info();
        $this->generate_overview();
        $this->generate_detailed_content();
    }

    private function generate_site_info()
    {
        // Try to get meta description from Yoast or RankMath
        $meta_description = $this->get_site_meta_description();
        $slug = 'ai-sitemap';
        $existing_page = get_page_by_path( $slug );
        $output = "\xEF\xBB\xBF";
        if(is_a($existing_page,'WP_Post')) {
            $output .= "# Learn more:" . get_permalink($existing_page) . "\n\n";
        }
        $output .= "# " . get_bloginfo('name') . "\n\n";
        if ($meta_description) {
            $output .= "> " . $meta_description . "\n\n";
        } else {
            $description = get_bloginfo('description');
            if($description) {
                $output .= "> " . $description . "\n\n";
            } else {
                $front_page_id = get_option('page_on_front');
                $description = '';
                if ($front_page_id) {
                    $description = get_the_excerpt($front_page_id);
                    if (empty($description)) {
                        $description = get_post_field('post_content', $front_page_id);
                    }
                }

                if($description) {
                    $output .= "> " . wp_trim_words(strip_tags(preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2060}]/u', ' ', html_entity_decode($description))), 30, '') . "\n\n";
                }
            }
        }
        $output .= "---\n\n";
        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
        unset($output);
        unset($meta_description);
    }

    private function remove_shortcodes($content)
    {
        $clean = preg_replace('/\[[^\]]+\]/', '', $content);

        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $clean);

        $clean = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2060}]/u', ' ', $clean);

        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $clean = preg_replace('/[ \t]+/', ' ', $clean);
        $clean = preg_replace('/\s{2,}/u', ' ', $clean);
        $clean = preg_replace('/[\r\n]+/', "\n", $clean);

        return trim(strip_tags($clean));
    }

    private function generate_overview()
    {
        global $wpdb;

        $use_yoast    = class_exists('WPSEO_Meta');
        $use_rankmath = function_exists('rank_math');
        $aioseo_enabled = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}aioseo_posts'") === "{$wpdb->prefix}aioseo_posts";

        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {
                $this->write_file(mb_convert_encoding("\n## {$post_type_obj->labels->name}\n\n", 'UTF-8', 'auto'));
            }

            $offset = 0;
            $i = 0;

            do {
                $joins = '';
                $conditions = "WHERE p.post_type = %s AND p.post_status = 'publish'";
                $params = [$post_type];

                if ($use_yoast) {
                    $joins .= " LEFT JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_yoast_wpseo_meta-robots-noindex' ";
                    $joins .= " LEFT JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_yoast_wpseo_meta-robots-nofollow' ";
                    $conditions .= " AND (m1.meta_value != '1' OR m1.post_id IS NULL) AND (m2.meta_value != '1' OR m2.post_id IS NULL)";
                }

                if ($use_rankmath) {
                    $joins .= " LEFT JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = 'rank_math_robots' ";
                    $conditions .= " AND (m3.meta_value NOT LIKE '%noindex%' OR m3.post_id IS NULL)";
                }

                if ($aioseo_enabled) {
                    $joins .= " LEFT JOIN {$wpdb->prefix}aioseo_posts aioseo ON p.ID = aioseo.post_id ";
                    $conditions .= " AND (aioseo.robots_noindex != 1 AND aioseo.robots_nofollow != 1 OR aioseo.post_id IS NULL)";
                }

                $params[] = $this->limit;
                $params[] = $offset;
                $post_ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p $joins $conditions ORDER BY p.post_date DESC LIMIT %d OFFSET %d", ...$params));

                if (!empty($post_ids)) {
                    foreach ($post_ids as $post_id) {
                        if($i > $this->settings['max_posts']) {
                            break 2;
                        }

                        $post = get_post($post_id);
                        $description = $this->get_post_meta_description($post);
                        if (!$description) {
                            $fallback_content = $this->remove_shortcodes(apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ?: get_the_content(null, false, $post));
                            $fallback_content = $this->content_cleaner->clean($fallback_content);
                            $description = wp_trim_words(strip_tags($fallback_content), 20, '...');
                        }

                        $output = sprintf("- [%s](%s): %s\n", $post->post_title, get_permalink($post->ID), $clean_description = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $description));
                        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));

                        unset($description, $fallback_content, $output);
                    }
                }

                $offset += $this->limit;

            } while (!empty($post_ids));

            $this->write_file(mb_convert_encoding("\n---\n\n", 'UTF-8', 'auto'));
        }
    }

    private function generate_detailed_content()
    {
        global $wpdb;

        $output = "#\n" . "# Detailed Content\n\n";
        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));

        $aioseo_enabled = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}aioseo_posts'") === "{$wpdb->prefix}aioseo_posts";
        $use_yoast = class_exists('WPSEO_Meta');
        $use_rankmath = function_exists('rank_math');

        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {
                $output = "\n## " . $post_type_obj->labels->name . "\n\n";
                $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
            }

            $offset = 0;
            $i = 0;

            do {
                $joins = '';
                $conditions = "WHERE p.post_type = %s AND p.post_status = 'publish'";
                $params = [$post_type, $this->limit, $offset];

                if ($use_yoast) {
                    $joins .= " LEFT JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_yoast_wpseo_meta-robots-noindex' ";
                    $joins .= " LEFT JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_yoast_wpseo_meta-robots-nofollow' ";
                    $conditions .= " AND (m1.meta_value != '1' OR m1.post_id IS NULL) AND (m2.meta_value != '1' OR m2.post_id IS NULL)";
                }

                if ($use_rankmath) {
                    $joins .= " LEFT JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = 'rank_math_robots' ";
                    $conditions .= " AND (m3.meta_value NOT LIKE '%noindex%' OR m3.post_id IS NULL)";
                }

                if ($aioseo_enabled) {
                    $joins .= " LEFT JOIN {$wpdb->prefix}aioseo_posts aioseo ON p.ID = aioseo.post_id ";
                    $conditions .= " AND (aioseo.robots_noindex != 1 AND aioseo.robots_nofollow != 1 OR aioseo.post_id IS NULL)";
                }

                $post_ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p $joins $conditions ORDER BY p.post_date DESC LIMIT %d OFFSET %d", ...$params));

                if (!empty($post_ids)) {
                    foreach ($post_ids as $post_id) {
                        if($i > $this->settings['max_posts']) {
                            break 2;
                        }

                        $post = get_post($post_id);
                        $content = $this->format_post_content($post);
                        $this->write_file(mb_convert_encoding($content, 'UTF-8', 'auto'));

                        unset($post, $content);
                    }
                }

                $offset += $this->limit;

            } while (!empty($post_ids));

            $this->write_file(mb_convert_encoding("\n---\n\n", 'UTF-8', 'auto'));
        }
    }

    public function remove_emojis($text) {
        return preg_replace('/[\x{1F600}-\x{1F64F}'
            . '\x{1F300}-\x{1F5FF}'
            . '\x{1F680}-\x{1F6FF}'
            . '\x{1F1E0}-\x{1F1FF}'
            . '\x{2600}-\x{26FF}'
            . '\x{2700}-\x{27BF}'
            . '\x{FE00}-\x{FE0F}'
            . '\x{1F900}-\x{1F9FF}'
            . '\x{1F018}-\x{1F270}'
            . '\x{238C}-\x{2454}'
            . '\x{20D0}-\x{20FF}]/u', '', $text);
    }

    private function format_post_content($post)
    {
        $output = "### " . $post->post_title . "\n\n";

        if ($this->settings['include_meta']) {
            $meta_description = $this->get_post_meta_description($post);
            if ($meta_description) {
                $clean_description = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $meta_description);
                $output .= "> " . wp_trim_words($clean_description, $this->settings['max_words'] ?? 250, '...') . "\n\n";
            }

            $output .= "- Published: " . get_the_date('Y-m-d', $post) . "\n";
            $output .= "- Modified: " . get_the_modified_date('Y-m-d', $post) . "\n";
            $output .= "- URL: " . get_permalink($post) . "\n";

            if (isset($post->post_type) && $post->post_type === 'product') {
                $sku = get_post_meta($post->ID, '_sku', true);
                if (!empty($sku)) {
                    $output .= '- SKU: ' . esc_html($sku) . "\n";
                }

                $price = get_post_meta($post->ID, '_price', true);
                $currency = get_option('woocommerce_currency');
                if (!empty($price)) {
                    $output .= "- Price: " . number_format((float)$price, 2) . " " . $currency . "\n";
                }
            }

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
            $output .= $this->remove_shortcodes($post->post_excerpt) . "\n\n";
        }

        // Clean and add the content
        $content = wp_trim_words($this->content_cleaner->clean($this->remove_emojis( $this->remove_shortcodes(get_the_content(null, false, $post)))), $this->settings['max_words'] ?? 250, '...');
        $output .= $content . "\n\n";
        $output .= "---\n\n";

        return $output;
    }

    private function get_site_meta_description()
    {
        if (class_exists('WPSEO_Options')) {
            return YoastSEO()->meta->for_posts_page()->description;
        } elseif (class_exists('RankMath')) {
            return get_option('rank_math_description');
        }
        return false;
    }

    private function get_post_meta_description($post)
    {
        if (class_exists('WPSEO_Meta')) {
            return YoastSEO()->meta->for_post($post->ID)->description;
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

    public function handle_post_update($post_id, $post, $update)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!in_array($post->post_type, $this->settings['post_types'])) {
            return;
        }

        if ($this->settings['update_frequency'] === 'immediate') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function handle_post_deletion($post_id, $post)
    {
        if (!$post || $post->post_type === 'revision') {
            return;
        }

        if ($this->settings['update_frequency'] === 'immediate') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function handle_term_update($term_id)
    {
        if ($this->settings['update_frequency'] === 'immediate') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function update_llms_file()
    {
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/llms.txt';
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }

        $upload_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }
        $this->generate_content();
        if(defined('FLYWHEEL_PLUGIN_DIR')) {
            $file_path = dirname(ABSPATH) . 'www/' . 'llms.txt';
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        } else {
            $file_path = ABSPATH . 'llms.txt';
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        if ( ! is_multisite() ) {
            if (file_exists($upload_path)) {
                $this->wp_filesystem->copy($upload_path, $file_path, true);
            }
        }

        // Update the hidden post
        $core = new LLMS_Core();
        $existing_post = $core->get_llms_post();

        $post_data = array(
            'post_title' => 'LLMS.txt',
            'post_content' => 'content',
            'post_status' => 'publish',
            'post_type' => 'llms_txt'
        );

        if ($existing_post) {
            $post_data['ID'] = $existing_post->ID;
            wp_update_post($post_data);
        } else {
            wp_insert_post($post_data);
        }

        do_action('llms_clear_seo_caches');
    }

    public function schedule_updates()
    {
        if (!wp_next_scheduled('llms_scheduled_update')) {
            $interval = ($this->settings['update_frequency'] === 'daily') ? 'daily' : 'weekly';
            wp_schedule_event(time(), $interval, 'llms_scheduled_update');
        }
    }
}