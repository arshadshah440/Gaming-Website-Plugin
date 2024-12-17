<?php
// if file is being called directly or not in the wordpress
if (! defined('ABSPATH')) exit; // Exit if accessed directly
/*
 * Package: RetroAPI
 * @subpackage retroapi/admin 
 * @since 1.0.0
 * @author Arshad Shah
 */
if (!class_exists('Advanced_AVIF_Converter')) {
    class Advanced_AVIF_Converter
    {
        public static function init()
        {
            // Hooks for different upload scenarios
            add_filter('wp_handle_upload', [__CLASS__, 'replace_uploaded_image_with_avif'], 10, 2);
            add_filter('upload_mimes', [__CLASS__, 'allow_avif_uploads']);
        }

        public static function replace_uploaded_image_with_avif($upload, $context)
        {
            $file_path = $upload['file']; // Full path of the uploaded file
            $file_info = pathinfo($file_path);

            // Check if the uploaded file is an image (and not already AVIF)
            if (isset($file_info['extension']) && in_array(strtolower($file_info['extension']), ['jpg', 'jpeg', 'png', 'gif'])) {
                $new_file_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.avif';

                // Convert the image to AVIF
                if (self::convert_image_to_avif($file_path, $new_file_path)) {
                    // Delete the original file
                    unlink($file_path);

                    // Update file path and URL
                    $upload['file'] = $new_file_path;
                    $upload['url'] = str_replace($file_info['basename'], $file_info['filename'] . '.avif', $upload['url']);
                    $upload['type'] = 'image/avif';

                    // Update attachment metadata in WordPress
                    add_action('add_attachment', function ($attachment_id) use ($new_file_path) {
                        self::update_attachment_metadata_with_avif($attachment_id, $new_file_path);
                    });
                }
            }

            return $upload;
        }

        public static function convert_image_to_avif($source_file, $destination_file)
        {
            // Check if imagick is available for AVIF conversion
            if (!extension_loaded('imagick')) {
                error_log('Imagick extension is not loaded. Cannot convert to AVIF.');
                return false;
            }

            try {
                // Create Imagick object
                $image = new Imagick($source_file);

                // Set AVIF specific compression parameters
                $image->setImageFormat('avif');

                // Optionally set compression quality (0-100, lower is smaller file size)
                $image->setImageCompressionQuality(80);

                // Write the AVIF file
                $result = $image->writeImage($destination_file);

                // Clear Imagick object
                $image->clear();

                return $result;
            } catch (Exception $e) {
                error_log('AVIF Conversion Error: ' . $e->getMessage());
                return false;
            }
        }

        public static function allow_avif_uploads($mimes)
        {
            $mimes['avif'] = 'image/avif';
            return $mimes;
        }

        public static function update_attachment_metadata_with_avif($attachment_id, $new_file_path)
        {
            // Get the attachment metadata
            $metadata = wp_get_attachment_metadata($attachment_id);

            // Update the file name in metadata
            if ($metadata && isset($metadata['file'])) {
                $metadata['file'] = str_replace(pathinfo($metadata['file'], PATHINFO_BASENAME), basename($new_file_path), $metadata['file']);
                wp_update_attachment_metadata($attachment_id, $metadata);
            }

            // Update the _wp_attached_file meta key
            $relative_path = str_replace(wp_upload_dir()['basedir'] . '/', '', $new_file_path);
            update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
        }
    }
}
