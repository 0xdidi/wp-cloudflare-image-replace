<?php
/*
Plugin Name: WP Image Replace
Description: Replace all product images with Cloudflare-transformed versions in batches, with real-time progress tracking, stop/start functionality, and AJAX-based updates. Only processes product images and ensures images aren't processed more than once.
Version: 1.6
Author: Great Anthony
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('CLOUD_IMAGE_REPLACE_BATCH_SIZE', 300);

// Hook into admin menu
add_action('admin_menu', 'cloudflare_image_replace_menu');

// Enqueue the necessary scripts for AJAX functionality
add_action('admin_enqueue_scripts', 'cloudflare_image_replace_enqueue_scripts');

// Register activation hook to ensure cron is scheduled
register_activation_hook(__FILE__, 'cloudflare_image_replace_activation');

// Register deactivation hook to clear the cron event
register_deactivation_hook(__FILE__, 'cloudflare_image_replace_deactivation');

// Add custom cron schedule
add_filter('cron_schedules', 'cloudflare_image_custom_schedule');
function cloudflare_image_custom_schedule($schedules) {
    $schedules['every_two_minutes'] = array(
        'interval' => 120, // 2 minutes in seconds
        'display'  => esc_html__('Every 2 Minutes'),
    );
    return $schedules;
}

// Enqueue AJAX scripts
function cloudflare_image_replace_enqueue_scripts() {
    wp_enqueue_script('cloudflare-image-replace-ajax', plugin_dir_url(__FILE__) . 'cloudflare-image-replace.js', array('jquery'), null, true);
    wp_localize_script('cloudflare-image-replace-ajax', 'cloudflareImageReplaceAjax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('cloudflare_image_replace_nonce')
    ));
}

// Activation Hook: Schedule the cron event
function cloudflare_image_replace_activation() {
    if (!wp_next_scheduled('cloudflare_image_replace_cron')) {
        wp_schedule_event(time(), 'every_two_minutes', 'cloudflare_image_replace_cron');
    }
}

// Deactivation Hook: Clear the cron event
function cloudflare_image_replace_deactivation() {
    wp_clear_scheduled_hook('cloudflare_image_replace_cron');
}

// Admin Menu
function cloudflare_image_replace_menu() {
    add_menu_page('Cloudflare Image Replace', 'Image Replace', 'manage_options', 'cloudflare-image-replace', 'cloudflare_image_replace_page');
}

// Admin Page
function cloudflare_image_replace_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $is_in_progress = get_option('cloudflare_image_replace_in_progress', false);
    $total_images = get_option('cloudflare_image_replace_total_images', 0);
    $processed_images = get_option('cloudflare_image_replace_processed', 0);
    $successful_images = get_option('cloudflare_image_replace_successful', 0);
    $failed_images = get_option('cloudflare_image_replace_failed', 0);

    echo '<div class="wrap">';
    echo '<h1>Cloudflare Image Replace</h1>';
    echo '<p>This plugin replaces product images with Cloudflare-transformed versions in batches.</p>';

    echo '<button id="cloudflare-image-replace-button" class="button button-primary">';
    echo $is_in_progress ? 'Stop Image Replacement' : 'Start Image Replacement';
    echo '</button>';

    // Display progress counters
    echo '<h2>Progress</h2>';
    echo '<p>Total Images: <span id="total-images">' . esc_html($total_images) . '</span></p>';
    echo '<p>Processed Images: <span id="processed-images">' . esc_html($processed_images) . '</span></p>';
    echo '<p>Successful Updates: <span id="successful-images">' . esc_html($successful_images) . '</span></p>';
    echo '<p>Failed Updates: <span id="failed-images">' . esc_html($failed_images) . '</span></p>';

    // Display progress bar
    echo '<div style="margin-top: 20px; width: 100%; background-color: #f3f3f3; border: 1px solid #ccc;">';
    echo '<div id="progress-bar" style="width: 0%; height: 30px; background-color: #4caf50;"></div>';
    echo '</div>';

    echo '</div>';
}

// AJAX handler to start or stop the image replacement process
add_action('wp_ajax_toggle_image_replace', 'cloudflare_image_replace_toggle');
function cloudflare_image_replace_toggle() {
    check_ajax_referer('cloudflare_image_replace_nonce', 'nonce');

    $is_in_progress = get_option('cloudflare_image_replace_in_progress', false);

    if ($is_in_progress) {
        cloudflare_image_replace_stop();
        wp_send_json_success(array('status' => 'stopped'));
    } else {
        cloudflare_image_replace_start();
        wp_send_json_success(array('status' => 'started'));
    }
}

// Start the image replacement process
function cloudflare_image_replace_start() {
    global $wpdb;

    // Get total number of product images to process
    $total_images = $wpdb->get_var("
        SELECT COUNT(p.ID)
        FROM {$wpdb->prefix}posts p
        INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.meta_value
        WHERE p.post_type = 'attachment' 
        AND pm.meta_key = '_thumbnail_id'
        AND EXISTS (
            SELECT 1 FROM {$wpdb->prefix}posts p2
            WHERE p2.ID = pm.post_id 
            AND p2.post_type = 'product'
        )
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->prefix}postmeta pm2
            WHERE pm2.post_id = p.ID 
            AND pm2.meta_key = '_cloudflare_image_processed'
        )
    ");

    update_option('cloudflare_image_replace_total_images', $total_images);

    // Reset progress tracking
    update_option('cloudflare_image_replace_processed', 0);
    update_option('cloudflare_image_replace_successful', 0);
    update_option('cloudflare_image_replace_failed', 0);
    update_option('cloudflare_image_replace_offset', 0);
    update_option('cloudflare_image_replace_in_progress', true);

    // Run the first batch immediately
    cloudflare_image_replace_cron();
}

// Stop the image replacement process
function cloudflare_image_replace_stop() {
    delete_option('cloudflare_image_replace_in_progress');
    delete_option('cloudflare_image_replace_offset');
}

// Cron Job Hook
add_action('cloudflare_image_replace_cron', 'cloudflare_image_replace_cron');

// Batch Processing Function - Now only processing images attached to products and not already processed
function cloudflare_image_replace_cron() {
    if (!get_option('cloudflare_image_replace_in_progress')) {
        return; // Exit if no job is in progress
    }

    global $wpdb;
    $offset = get_option('cloudflare_image_replace_offset', 0);
    $batch_size = CLOUD_IMAGE_REPLACE_BATCH_SIZE;

    // Get a batch of product images that haven't been processed yet
    $query = $wpdb->prepare("
        SELECT p.ID, p.guid 
        FROM {$wpdb->prefix}posts p
        INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.meta_value
        WHERE p.post_type = 'attachment'
        AND pm.meta_key = '_thumbnail_id'
        AND EXISTS (
            SELECT 1 FROM {$wpdb->prefix}posts p2
            WHERE p2.ID = pm.post_id 
            AND p2.post_type = 'product'
        )
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->prefix}postmeta pm2
            WHERE pm2.post_id = p.ID 
            AND pm2.meta_key = '_cloudflare_image_processed'
        )
        LIMIT %d OFFSET %d", $batch_size, $offset);

    $images = $wpdb->get_results($query);

    // Get progress tracking data
    $processed_images = get_option('cloudflare_image_replace_processed', 0);
    $successful_images = get_option('cloudflare_image_replace_successful', 0);
    $failed_images = get_option('cloudflare_image_replace_failed', 0);

    // Process each image in the batch
    foreach ($images as $image) {
        $image_id = $image->ID;
        $image_url = $image->guid;

        // Generate Cloudflare transformation URL
        $cloudflare_url = 'https://img.offscent.co.uk/cdn-cgi/image/w=2500,h=2500,fit=pad,background=white,quality=100/' . $image_url;

        // Get the image content from the Cloudflare URL
        $new_image_content = @file_get_contents($cloudflare_url);

        if ($new_image_content !== false) {
            // Get the upload directory
            $upload_dir = wp_upload_dir();
            $old_image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);

            // Save the new image over the old one
            if (file_put_contents($old_image_path, $new_image_content)) {
                $successful_images++;
                update_post_meta($image_id, '_cloudflare_image_processed', true); // Mark image as processed
            } else {
                $failed_images++;
            }
        } else {
            $failed_images++;
        }

        $processed_images++;
    }

    // Update progress tracking options
    update_option('cloudflare_image_replace_processed', $processed_images);
    update_option('cloudflare_image_replace_successful', $successful_images);
    update_option('cloudflare_image_replace_failed', $failed_images);

    // Check if we've processed all images
    if ($processed_images >= get_option('cloudflare_image_replace_total_images')) {
        cloudflare_image_replace_stop();
    } else {
        // Update the offset for the next batch
        update_option('cloudflare_image_replace_offset', $offset + $batch_size);
    }
}

// AJAX handler to get the real-time progress
add_action('wp_ajax_get_image_replace_progress', 'cloudflare_image_replace_get_progress');
function cloudflare_image_replace_get_progress() {
    check_ajax_referer('cloudflare_image_replace_nonce', 'nonce');

    $total_images = get_option('cloudflare_image_replace_total_images', 0);
    $processed_images = get_option('cloudflare_image_replace_processed', 0);
    $successful_images = get_option('cloudflare_image_replace_successful', 0);
    $failed_images = get_option('cloudflare_image_replace_failed', 0);

    wp_send_json_success(array(
        'total_images' => $total_images,
        'processed_images' => $processed_images,
        'successful_images' => $successful_images,
        'failed_images' => $failed_images
    ));
}
