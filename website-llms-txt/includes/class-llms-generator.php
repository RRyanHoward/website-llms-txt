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
        add_action('init', array($this, 'llms_create_txt_cache_table_if_not_exists'), 999);
        add_action('updates_all_posts', array($this, 'updates_all_posts'), 999);
    }

    public function llms_create_txt_cache_table_if_not_exists()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'llms_txt_cache';
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        ));

        if ($table_exists !== $table) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                `post_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                `show` TINYINT NULL DEFAULT NULL,
                `status` VARCHAR(20) DEFAULT NULL,
                `type` VARCHAR(20) DEFAULT NULL,
                `title` TEXT DEFAULT NULL,
                `link` VARCHAR(255) DEFAULT NULL,
                `sku` VARCHAR(255) DEFAULT NULL,
                `price` VARCHAR(125) DEFAULT NULL,
                `excerpts` TEXT DEFAULT NULL,
                `overview` TEXT DEFAULT NULL,
                `meta` TEXT DEFAULT NULL,
                `content` LONGTEXT DEFAULT NULL,
                `published` DATETIME DEFAULT NULL,
                `modified` DATETIME DEFAULT NULL
            ) $charset_collate;";

            dbDelta($sql);
        }
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
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
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

            file_put_contents($this->llms_path, (string)$content, FILE_APPEND | LOCK_EX);
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

    public function updates_all_posts()
    {
        global $wpdb;
        $table_cache = $wpdb->prefix . 'llms_txt_cache';
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('Processing type: ' . $post_type);
            }

            $offset = 0;
            do {
                $params = [$post_type];
                $params[] = $this->limit;
                $params[] = $offset;
                $params = [$post_type, $this->limit, $offset];
                $conditions = "WHERE p.post_type = %s AND cache.post_id IS NULL";
                $joins = " LEFT JOIN {$table_cache} cache ON p.ID = cache.post_id ";
                $posts = $wpdb->get_results($wpdb->prepare("SELECT p.ID, cache.* FROM {$wpdb->posts} p $joins $conditions ORDER BY p.post_date DESC LIMIT %d OFFSET %d", ...$params));

                if (!empty($posts)) {
                    foreach ($posts as $cache_post) {
                        if(!$cache_post->post_id) {
                            $post = get_post($cache_post->ID);
                            $this->handle_post_update($cache_post->ID, $post, 'manual');
                            unset($post);
                        }
                    }
                }

                $offset = $offset + $this->limit;
            } while (!empty($posts));

            unset($posts);

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('END processing type: ' . $post_type);
            }
        }
    }

    public function generate_content()
    {
        $this->updates_all_posts();
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
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start generate overview');
        }

        $table_cache = $wpdb->prefix . 'llms_txt_cache';
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {
                $this->write_file(mb_convert_encoding("\n## {$post_type_obj->labels->name}\n\n", 'UTF-8', 'auto'));
            }

            $offset = 0;
            $i = 0;
            $exit = false;

            do {
                $conditions = " WHERE `type` = %s AND `show`=1 AND `status`='publish' ";
                $params = [
                    $post_type,
                    $this->limit,
                    $offset
                ];

                $posts = $wpdb->get_results($wpdb->prepare("SELECT `post_id`, `overview` FROM $table_cache $conditions ORDER BY `published` DESC LIMIT %d OFFSET %d", ...$params));
                if (defined('WP_CLI') && WP_CLI) {
                    \WP_CLI::log('Count: ' . count($posts));
                    \WP_CLI::log($wpdb->prepare("SELECT `post_id`, `overview` FROM $table_cache $conditions ORDER BY `published` DESC LIMIT %d OFFSET %d", ...$params));
                }
                if (!empty($posts)) {
                    $output = '';
                    foreach ($posts as $data) {
                        if($i > $this->settings['max_posts']) {
                            $exit = true;
                            break;
                        }

                        if($data->overview) {
                            $output .= $data->overview;
                            $i++;
                        }

                        unset($data);
                    }

                    $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
                    unset($output);
                }

                $offset += $this->limit;

            } while (!empty($posts) && !$exit);

            $this->write_file(mb_convert_encoding("\n---\n\n", 'UTF-8', 'auto'));

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('End generate overview');
            }
        }
    }

    private function generate_detailed_content()
    {
        global $wpdb;

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start generate detailed content');
        }

        $output = "#\n" . "# Detailed Content\n\n";
        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));

        $table_cache = $wpdb->prefix . 'llms_txt_cache';

        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {
                $output = "\n## " . $post_type_obj->labels->name . "\n\n";
                $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
            }

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('Generate detailed: ' . $post_type);
            }

            $offset = 0;
            $exit = false;
            $i = 0;

            do {
                $conditions = " WHERE `type` = %s AND `show`=1 AND `status`='publish' ";
                $params = [
                    $post_type,
                    $this->limit,
                    $offset
                ];

                $posts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_cache $conditions ORDER BY `published` DESC LIMIT %d OFFSET %d", ...$params));
                if (!empty($posts)) {
                    $output = '';
                    foreach ($posts as $data) {
                        if (!$data->content) continue;
                        if ($i > $this->settings['max_posts']) {
                            $exit = true;
                            break;
                        }

                        if ($this->settings['include_meta']) {
                            if ($data->meta) {
                                $output .= "> " . wp_trim_words($data->meta, $this->settings['max_words'] ?? 250, '...') . "\n\n";
                            }

                            $output .= "- Published: " . esc_html(date('Y-m-d', strtotime($data->published))) . "\n";
                            $output .= "- Modified: " . esc_html(date('Y-m-d', strtotime($data->modified))) . "\n";
                            $output .= "- URL: " . esc_html($data->link) . "\n";

                            if ($data->sku) {
                                $output .= '- SKU: ' . esc_html($data->sku) . "\n";
                            }

                            if ($data->price) {
                                $output .= '- Price: ' . esc_html($data->price) . "\n";
                            }

                            if ($this->settings['include_taxonomies']) {
                                $taxonomies = get_object_taxonomies($data->type, 'objects');
                                foreach ($taxonomies as $tax) {
                                    $terms = get_the_terms($data->post_id, $tax->name);
                                    if ($terms && !is_wp_error($terms)) {
                                        $term_names = wp_list_pluck($terms, 'name');
                                        $output .= "- " . $tax->labels->name . ": " . implode(', ', $term_names) . "\n";
                                    }
                                }
                            }
                        }

                        $content = wp_trim_words($data->content, $this->settings['max_words'] ?? 250, '...');
                        $output .= "\n";

                        if ($this->settings['include_excerpts'] && $data->excerpts) {
                            $output .= $data->excerpts . "\n\n";
                        }

                        if ($content) {
                            $output .= $content . "\n\n";
                        }

                        $output .= "---\n\n";
                        unset($data);

                        $i++;
                    }
                }

                $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
                unset($output);

                $offset += $this->limit;

            } while (!empty($posts) && !$exit);

            $this->write_file(mb_convert_encoding("\n---\n\n", 'UTF-8', 'auto'));

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('End generate detailed content');
            }
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

    private function get_site_meta_description()
    {
        if (class_exists('WPSEO_Options')) {
            return YoastSEO()->meta->for_posts_page()->description;
        } elseif (class_exists('RankMath')) {
            return get_option('rank_math_description');
        } else {
            $description = get_bloginfo('description');
            if ($description) {
                return get_bloginfo('description');
            } else {
                $front_page_id = get_option('page_on_front');
                $description = '';
                if ($front_page_id) {
                    $description = get_the_excerpt($front_page_id);
                    if (empty($description)) {
                        $description = get_post_field('post_content', $front_page_id);
                    }
                }

                $description = $this->remove_shortcodes(str_replace(']]>', ']]&gt;', apply_filters('the_content', $description)));
                return wp_trim_words(strip_tags(preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2060}]/u', ' ', html_entity_decode($description))), 30, '');
            }
        }
    }

    private function get_post_meta_description( $post )
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

    /**
     * @param int $post_id
     * @param WP_Post $post
     * @param $update
     * @return void
     */
    public function handle_post_update($post_id, $post, $update)
    {
        global $wpdb;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!in_array($post->post_type, $this->settings['post_types'])) {
            return;
        }

        $table = $wpdb->prefix . 'llms_txt_cache';
        $overview = '';
        $price = '';
        $sku = '';

        $permalink = get_permalink($post->ID);

        $description = $this->get_post_meta_description( $post );
        if (!$description) {
            $fallback_content = $this->remove_shortcodes(apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ?: get_the_content(null, false, $post));
            $fallback_content = $this->content_cleaner->clean($fallback_content);
            $description = wp_trim_words(strip_tags($fallback_content), 20, '...');
            if($description) {
                $overview = sprintf("- [%s](%s): %s\n", $post->post_title, $permalink, preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $description));
            }
        } else {
            $overview = sprintf("- [%s](%s): %s\n", $post->post_title, $permalink, preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $description));
        }

        if (isset($post->post_type) && $post->post_type === 'product') {
            $sku = get_post_meta($post->ID, '_sku', true);
            $price = get_post_meta($post->ID, '_price', true);
            $currency = get_option('woocommerce_currency');
            if (!empty($price)) {
                $price = number_format((float)$price, 2) . " " . $currency;
            }
        }

        $clean_description = '';
        $meta_description = $this->get_post_meta_description( $post );
        if ($meta_description) {
            $clean_description = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $meta_description);
        }

        $show = 1;
        $use_yoast = class_exists('WPSEO_Meta');
        $use_rankmath = function_exists('rank_math');
        if($use_yoast) {
            $robots_noindex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
            $robots_nofollow = get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true);
            if($robots_noindex || $robots_nofollow) {
                $show = 0;
            }
        }

        if ($use_rankmath) {
            $robots_noindex = get_post_meta($post_id, 'rank_math_robots', true);
            if($robots_noindex) {
                $show = 0;
            }
        }

        $aioseo_enabled = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}aioseo_posts'") === "{$wpdb->prefix}aioseo_posts";
        if($aioseo_enabled) {
            $row = $wpdb->get_row("SELECT robots_noindex, robots_nofollow FROM {$wpdb->prefix}aioseo_posts WHERE post_id=" . intval($post_id));
            if(isset($row->robots_noindex) && $row->robots_noindex) {
                $show = 0;
            }

            if(isset($row->robots_nofollow) && $row->robots_nofollow) {
                $show = 0;
            }
        }

        $excerpts = $this->remove_shortcodes($post->post_excerpt);
        ob_start();
        echo $this->content_cleaner->clean($this->remove_emojis( $this->remove_shortcodes(do_shortcode(get_the_content(null, false, $post)))));
        $content = ob_get_contents();

        $wpdb->replace(
            $table,
            [
                'post_id' => $post_id,
                'show' => $show,
                'status' => $post->post_status,
                'type' => $post->post_type,
                'title' => $post->post_title,
                'link' => $permalink,
                'sku' => $sku,
                'price' => $price,
                'meta' => $clean_description,
                'excerpts' => $excerpts,
                'overview' => $overview,
                'content' => $content,
                'published' => get_the_date('Y-m-d', $post),
                'modified' => get_the_modified_date('Y-m-d', $post),
            ], [
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s'
            ]
        );

        if ($this->settings['update_frequency'] === 'immediate' && $update !== 'manual') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function handle_post_deletion($post_id, $post)
    {
        global $wpdb;
        if (!$post || $post->post_type === 'revision') {
            return;
        }

        $table = $wpdb->prefix . 'llms_txt_cache';
        $wpdb->delete($table, [
            'post_id' => $post_id
        ], [
            '%d'
        ]);

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
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start');
        }

        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/llms.txt';
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }

        $upload_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }

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

        $this->generate_content();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('End generate_content event');
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

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Clear cache event');
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