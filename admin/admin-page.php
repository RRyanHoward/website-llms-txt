<?php
if (!defined('ABSPATH')) {
    exit;
}

$llms_post = (new LLMS_Core())->get_llms_post();
$file_exists = file_exists(ABSPATH . 'llms.txt');
$is_auto_generated = $llms_post !== null;

// Handle status messages
$status = isset($_GET['status']) ? $_GET['status'] : '';
$status_message = '';

if ($status === 'llms-success') {
    $status_message = '<div class="notice notice-success"><p>' . __('LLMS.txt file uploaded successfully!', 'website-llms-txt') . '</p></div>';
} elseif ($status === 'error') {
    $status_message = '<div class="notice notice-error"><p>' . __('Error uploading LLMS.txt file. Please try again.', 'website-llms-txt') . '</p></div>';
}

if (isset($_GET['cache_cleared']) && $_GET['cache_cleared'] === 'true') {
    $status_message = '<div class="notice notice-success"><p>' . __('Caches cleared successfully!', 'website-llms-txt') . '</p></div>';
}

if (isset($_GET['settings-updated'])) {
    $status_message = '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'website-llms-txt') . '</p></div>';
}

if (isset($_GET['file_removed']) && $_GET['file_removed'] === 'true') {
    $status_message = '<div class="notice notice-success"><p>' . __('Manual LLMS.txt file removed successfully. Auto-generation is now active.', 'website-llms-txt') . '</p></div>';
}
?>

<div class="wrap">
    <h1><?php _e('Website llms.txt', 'website-llms-txt'); ?></h1>
    
    <?php echo $status_message; ?>

   <div class="card">
        <h2><?php _e('File Status', 'website-llms-txt'); ?></h2>
        <?php if ($file_exists): ?>
            <p><?php 
                if ($is_auto_generated) {
                    _e('File is being auto-generated based on your settings.', 'website-llms-txt');
                } else {
                    _e('File was manually uploaded.', 'website-llms-txt');
                    echo ' <a href="' . wp_nonce_url(admin_url('admin-post.php?action=remove_llms_file'), 'remove_llms_file', 'remove_llms_file_nonce') . '" class="button button-small" onclick="return confirm(\'' . esc_js(__('Are you sure you want to remove the manual file and enable auto-generation?', 'website-llms-txt')) . '\')">' . __('Remove Manual File', 'website-llms-txt') . '</a>';
                }
            ?></p>
            <p><?php _e('View files:', 'website-llms-txt'); ?></p>
            <ul>
                <li><a href="<?php echo home_url('/llms.txt'); ?>" target="_blank"><?php echo home_url('/llms.txt'); ?></a></li>
                <?php if (class_exists('RankMath') || (defined('WPSEO_VERSION') && class_exists('WPSEO_Sitemaps'))): ?>
                    <li><a href="<?php echo home_url('/sitemap_index.xml'); ?>" target="_blank"><?php echo home_url('/sitemap_index.xml'); ?></a></li>
                    <li><a href="<?php echo home_url('/llms-sitemap.xml'); ?>" target="_blank"><?php echo home_url('/llms-sitemap.xml'); ?></a></li>
                <?php endif; ?>
            </ul>
        <?php else: ?>
            <p style="color: red;">✗ <?php _e('No LLMS.txt file found in root directory', 'website-llms-txt'); ?></p>
        <?php endif; ?>
    </div>

   <div class="card">
        <h2><?php _e('Content Settings', 'website-llms-txt'); ?></h2>
        <form method="post" action="options.php" id="llms-settings-form">
            <?php
            settings_fields('llms_generator_settings');
            $settings = get_option('llms_generator_settings', array(
                'post_types' => array('page', 'documentation', 'post'),
                'max_posts' => 100,
                'include_meta' => true,
                'include_excerpts' => true,
                'include_taxonomies' => true,
                'update_frequency' => 'immediate'
            ));
            ?>
            
            <h3><?php _e('Post Types', 'website-llms-txt'); ?></h3>
            <p class="description"><?php _e('Select and order the post types to include in your llms.txt file. Drag to reorder.', 'website-llms-txt'); ?></p>
            
            <div id="llms-post-types-sortable" class="sortable-list">
                <?php
                $post_types = get_post_types(array('public' => true), 'objects');
                $ordered_types = array_flip($settings['post_types']); // Create lookup array
                $unordered_types = array(); // For types not in the current order

                // Separate ordered and unordered post types
                foreach ($post_types as $post_type) {
                    if (in_array($post_type->name, array('attachment', 'llms_txt'))) {
                        continue;
                    }
                    
                    if (!isset($ordered_types[$post_type->name])) {
                        $unordered_types[] = $post_type;
                    }
                }
                
                // Output ordered items first
                foreach ($settings['post_types'] as $type_name) {
                    if (isset($post_types[$type_name])) {
                        $post_type = $post_types[$type_name];
                        ?>
                        <div class="sortable-item active" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                            <label>
                                <input type="checkbox" 
                                       name="llms_generator_settings[post_types][]" 
                                       value="<?php echo esc_attr($post_type->name); ?>"
                                       checked>
                                <span class="dashicons dashicons-menu"></span>
                                <?php echo esc_html($post_type->labels->name); ?>
                            </label>
                        </div>
                        <?php
                    }
                }
                
                // Output unordered items
                foreach ($unordered_types as $post_type) {
                    ?>
                    <div class="sortable-item" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                        <label>
                            <input type="checkbox" 
                                   name="llms_generator_settings[post_types][]" 
                                   value="<?php echo esc_attr($post_type->name); ?>">
                            <span class="dashicons dashicons-menu"></span>
                            <?php echo esc_html($post_type->labels->name); ?>
                        </label>
                    </div>
                    <?php
                }
                ?>
            </div>

            <h3><?php _e('Content Options', 'website-llms-txt'); ?></h3>
            <p>
                <label>
                    <?php _e('Maximum posts per type:', 'website-llms-txt'); ?>
                    <input type="number" 
                           name="llms_generator_settings[max_posts]" 
                           value="<?php echo esc_attr($settings['max_posts']); ?>"
                           min="1"
                           max="1000">
                </label>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" 
                           name="llms_generator_settings[include_meta]" 
                           value="1"
                           <?php checked(!empty($settings['include_meta'])); ?>>
                    <?php _e('Include meta information (publish date, author, etc.)', 'website-llms-txt'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" 
                           name="llms_generator_settings[include_excerpts]" 
                           value="1"
                           <?php checked(!empty($settings['include_excerpts'])); ?>>
                    <?php _e('Include post excerpts', 'website-llms-txt'); ?>
                </label>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" 
                           name="llms_generator_settings[include_taxonomies]" 
                           value="1"
                           <?php checked(!empty($settings['include_taxonomies'])); ?>>
                    <?php _e('Include taxonomies (categories, tags, etc.)', 'website-llms-txt'); ?>
                </label>
            </p>

            <h3><?php _e('Update Frequency', 'website-llms-txt'); ?></h3>
            <p>
                <label>
                    <select name="llms_generator_settings[update_frequency]">
                        <option value="immediate" <?php selected($settings['update_frequency'], 'immediate'); ?>>
                            <?php _e('Immediate', 'website-llms-txt'); ?>
                        </option>
                        <option value="daily" <?php selected($settings['update_frequency'], 'daily'); ?>>
                            <?php _e('Daily', 'website-llms-txt'); ?>
                        </option>
                        <option value="weekly" <?php selected($settings['update_frequency'], 'weekly'); ?>>
                            <?php _e('Weekly', 'website-llms-txt'); ?>
                        </option>
                    </select>
                </label>
            </p>

            <?php submit_button(__('Save Settings', 'website-llms-txt')); ?>
        </form>
    </div>

   <div class="card">
        <h2><?php _e('Manual File Upload', 'website-llms-txt'); ?></h2>
        <?php if ($is_auto_generated): ?>
            <div class="notice notice-warning inline">
                <p><?php _e('Warning: Uploading a manual file will disable auto-generation and overwrite the current auto-generated file.', 'website-llms-txt'); ?></p>
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_llms_file">
            <?php wp_nonce_field('upload_llms_file', 'upload_llms_file_nonce'); ?>
            <p>
                <input type="file" name="llms_file" id="llms_file" accept=".txt" required>
            </p>
            <p class="description">
                <?php _e('Upload your LLMS.txt file (must be a .txt file).', 'website-llms-txt'); ?>
                <?php if ($file_exists && !$is_auto_generated): ?>
                    <?php _e('This will overwrite your existing manual file.', 'website-llms-txt'); ?>
                <?php endif; ?>
            </p>
            <?php submit_button(__('Upload File', 'website-llms-txt')); ?>
        </form>
    </div>

    <div class="card">
        <h2><?php _e('Cache Management', 'website-llms-txt'); ?></h2>
        <p><?php _e('This tool helps ensure your LLMS.txt file is properly reflected in your sitemap by:', 'website-llms-txt'); ?></p>
        <ul style="list-style-type: disc; margin-left: 20px; margin-bottom: 15px;">
            <li><?php _e('Clearing sitemap caches', 'website-llms-txt'); ?></li>
            <li><?php _e('Resetting WordPress rewrite rules', 'website-llms-txt'); ?></li>
            <li><?php _e('Forcing sitemap regeneration', 'website-llms-txt'); ?></li>
        </ul>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="clear_caches">
            <?php wp_nonce_field('clear_caches', 'clear_caches_nonce'); ?>
            <p class="submit">
                <?php submit_button(__('Clear Caches', 'website-llms-txt'), 'primary', 'submit', false); ?>
            </p>
        </form>
    </div>
</div>

<style>
.sortable-list {
    max-width: 500px;
    margin: 20px 0;
}

.sortable-item {
    padding: 10px;
    background: #fff;
    border: 1px solid #ccd0d4;
    margin-bottom: 5px;
    cursor: move;
    display: flex;
    align-items: center;
}

.sortable-item.active {
    background: #f0f6fc;
}

.sortable-item label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: move;
    flex-grow: 1;
}

.sortable-item .dashicons-menu {
    color: #999;
}

.sortable-item input[type="checkbox"] {
    cursor: pointer;
}

.notice.inline {
    margin: 15px 0;
}
</style>

<script>
jQuery(document).ready(function($) {
    const $sortable = $("#llms-post-types-sortable");
    const $form = $("#llms-settings-form");

    // Initialize sortable
    $sortable.sortable({
        items: '.sortable-item',
        axis: 'y',
        cursor: 'move',
        handle: 'label',
        update: function(event, ui) {
            updateActiveStates();
        }
    });

    // Handle checkbox changes
    $sortable.on('change', 'input[type="checkbox"]', function() {
        $(this).closest('.sortable-item').toggleClass('active', $(this).is(':checked'));
    });

    // Update active states
    function updateActiveStates() {
        $sortable.find('.sortable-item').each(function() {
            const $item = $(this);
            const $checkbox = $item.find('input[type="checkbox"]');
            $item.toggleClass('active', $checkbox.is(':checked'));
        });
    }

    // Ensure proper order on form submission
    $form.on('submit', function() {
        // Move unchecked items to the end
        $sortable.find('.sortable-item:not(.active)').appendTo($sortable);
        return true;
    });
});
</script>