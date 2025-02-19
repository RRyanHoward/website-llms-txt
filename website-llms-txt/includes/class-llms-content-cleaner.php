<?php
/**
 * LLMS Content Cleaner
 *
 * @package Website_LLMS_TXT
 */

if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Content_Cleaner {
    /**
     * List of page builder patterns to clean
     */
    private $builder_patterns = array(
        // Basic Layout Shortcodes
        '/\[([^\]]*)\](.*?)\[\/\1\]/s',  // Match any paired shortcodes with content
        '/\[[^\]]+\]/',                   // Match any single shortcodes
        '/\[row[^\]]*\].*?\[\/row\]/s',
        '/\[col[^\]]*\].*?\[\/col\]/s',
        '/\[column[s]?[^\]]*\].*?\[\/column[s]?\]/s',
        '/\[one[^\]]*\].*?\[\/one[^\]]*\]/s',
        '/\[two[^\]]*\].*?\[\/two[^\]]*\]/s',
        '/\[three[^\]]*\].*?\[\/three[^\]]*\]/s',
        '/\[four[^\]]*\].*?\[\/four[^\]]*\]/s',
        '/\[half[^\]]*\].*?\[\/half[^\]]*\]/s',
        '/\[third[^\]]*\].*?\[\/third[^\]]*\]/s',
        '/\[fourth[^\]]*\].*?\[\/fourth[^\]]*\]/s',
        '/\[quarters[^\]]*\].*?\[\/quarters[^\]]*\]/s',
        '/\[section[^\]]*\].*?\[\/section[^\]]*\]/s',
        '/\[container[^\]]*\].*?\[\/container[^\]]*\]/s',

        // Gutenberg Blocks
        '/<!-- wp:.*?\/-->/s',
        '/<!-- wp:.*?-->.*?<!-- \/wp:.*?-->/s',
        
        // WPBakery
        '/\[vc_[^\]]*\].*?\[\/vc_[^\]]*\]/s',
        
        // Elementor
        '/<!-- elementor.*?-->/s',
        '/data-elementor-[^\"]*=\"[^\"]*\"/',
        '/class=\"elementor-.*?\"/',
        
        // Beaver Builder
        '/<!-- wp:fl-builder\/layout.*?-->/s',
        '/class=\"fl-builder-content.*?\"/',
        
        // Divi
        '/\[et_pb_[^\]]*\].*?\[\/et_pb_[^\]]*\]/s',
        
        // Oxygen
        '/<!-- wp:oxygen.*?-->/s',
        '/\[oxygen.*?\].*?\[\/oxygen\]/s',
        
        // Common HTML cleanup
        '/class="[^"]*"/i',
        '/style="[^"]*"/i',
        '/id="[^"]*"/i',
        '/data-[^=]*="[^"]*"/i',
        
        // Extra HTML cleanup
        '/\s*<div[^>]*>\s*/',
        '/\s*<\/div>\s*/',
        '/\s*<span[^>]*>\s*/',
        '/\s*<\/span>\s*/'
    );

    /**
     * Clean content from a post
     *
     * @param string $content The raw post content
     * @return string The cleaned content
     */
    public function clean($content) {
        if (empty($content)) {
            return '';
        }

        // Store original content
        $original_content = $content;

        // FIRST: Process shortcodes to get their content
        $content = do_shortcode($content);

        // SECOND: Remove any remaining raw shortcodes
        $content = preg_replace('/\[[^\]]*\]/', '', $content);

        // THIRD: Convert HTML entities early
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = str_replace(
            array('&hellip;', '&ndash;', '&mdash;', '&nbsp;', '…', '...', '&quot;', '&apos;', '&lt;', '&gt;', '&amp;'),
            array('...', '-', '-', ' ', '...', '...', '"', "'", '<', '>', '&'),
            $content
        );

        // FOURTH: Remove any remaining HTML entities
        $content = preg_replace('/&[a-z0-9#]+;/i', '', $content);

        // FIFTH: Remove page builder content
        $content = $this->remove_page_builder_content($content);
        
        // SIXTH: Clean WordPress content
        $content = $this->clean_wordpress_content($content);
        
        // SEVENTH: Final cleanup
        $content = $this->final_cleanup($content);

        // If content is empty after cleaning, return original stripped of tags
        if (trim($content) === '') {
            return wp_strip_all_tags($original_content, true);
        }
        
        return $content;
    }

    /**
     * Remove page builder specific content
     */
    private function remove_page_builder_content($content) {
        // Remove HTML comments first
        $content = preg_replace('/<!--[\s\S]*?-->/', '', $content);
        
        // Apply all cleaning patterns
        foreach ($this->builder_patterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }
        
        return $content;
    }

    /**
     * Clean WordPress specific content
     */
    private function clean_wordpress_content($content) {
        // Strip HTML tags but preserve paragraphs as newlines
        $content = wp_strip_all_tags($content, true);
        
        // Normalize line endings
        $content = preg_replace('/\r\n|\r/', "\n", $content);
        
        // Remove any remaining HTML entities
        $content = preg_replace('/&[a-z0-9#]+;/i', '', $content);
        
        return $content;
    }

    /**
     * Final cleanup and formatting
     */
    private function final_cleanup($content) {
        // Remove multiple spaces
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Fix spacing after punctuation
        $content = preg_replace('/([.!?])(?! |\n)/', '$1 ', $content);
        
        // Remove empty parentheses and brackets
        $content = preg_replace('/\(\s*\)|\[\s*\]/', '', $content);
        
        // Remove any remaining shortcode-like structures
        $content = preg_replace('/\[[^\]]*\]/', '', $content);
        
        // Remove lines with only spaces or punctuation
        $content = preg_replace('/^\s*[\p{P}\s]*\s*$/m', '', $content);
        
        // Clean up multiple newlines
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Fix ellipsis consistency
        $content = str_replace(array('...', '. . .', '…'), '...', $content);
        
        // Final trim
        return trim($content);
    }

    /**
     * Add additional cleaning pattern
     *
     * @param string $pattern Regular expression pattern to add
     */
    public function add_cleaning_pattern($pattern) {
        $this->builder_patterns[] = $pattern;
    }
}