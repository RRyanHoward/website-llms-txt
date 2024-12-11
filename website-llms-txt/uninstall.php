<?php
// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options
delete_option('llms_generator_settings');

// Remove LLMS.txt file using WP_Filesystem
$llms_file = ABSPATH . 'llms.txt';
if (file_exists($llms_file)) {
    global $wp_filesystem;
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
    
    if ($wp_filesystem->exists($llms_file)) {
        $wp_filesystem->delete($llms_file);
    }
}

// Delete all posts of type llms_txt
$posts = get_posts([
    'post_type' => 'llms_txt',
    'posts_per_page' => -1,
    'post_status' => 'any'
]);

foreach ($posts as $post) {
    wp_delete_post($post->ID, true);
}