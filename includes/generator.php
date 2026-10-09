<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/selection.php';

final class Generator {
    public static function generate($id, $force = false, $fill_only = false, $text_changed = false) {
        global $wpdb;
        $id = absint($id);
        $post = self::fresh($id);
        if (!$post || $post->post_type !== 'post' || !in_array($post->post_status, array('publish', 'future'), true) ||
            !current_user_can('edit_post', $id) || !current_user_can('upload_files')) {
            return new \WP_Error('forbidden', 'This article cannot be processed.');
        }
        $current = (int) get_post_thumbnail_id($id);
        $owned = self::owns($current, $id) && !in_array(get_post_meta($id, '_auto_post_image_source', true), array('content', 'default'), true);
        $settings = Settings::get();
        if ($fill_only && $current) {
            return array('status' => 'skipped');
        }
        if ($current && !$owned) {
            return array('status' => 'manual');
        }
        if (!$force && (($owned && !$settings['updateTitle'] && !$text_changed) || (!$current && !$settings['automatic']))) {
            return array('status' => 'disabled');
        }
        $title = self::title($post);
        $hash = hash('sha256', $post->post_title . "\0" . $title);
        if (!$force && $owned && get_post_meta($current, '_auto_post_image_hash', true) === $hash) {
            return array('status' => 'unchanged');
        }
        $style = !$force && $owned ? get_post_meta($current, '_auto_post_image_style', true) : $settings;
        $style = Settings::normalize($style);
        $fingerprint = Selection::fingerprint($post);
        $valid = Settings::validate($style);
        if (is_wp_error($valid)) {
            return self::failure($id, $valid);
        }
        $lock = self::lock($id);
        if (!$lock) {
            return new \WP_Error('busy', Settings::label('This cover is already being generated.', '此封面正在生成。', $settings));
        }
        $candidate = 0;
        $file = '';
        try {
            // The lock can be obtained after a concurrent update; do not render stale input.
            $latest = self::fresh($id);
            if (!$latest || !in_array($latest->post_status, array('publish', 'future'), true) ||
                (int) get_post_thumbnail_id($id) !== $current ||
                Selection::fingerprint($latest) !== $fingerprint) {
                return array('status' => 'changed');
            }
            $selection = $owned && !$force ? array('method' => 'title', 'attachment' => 0) : Selection::choose($latest, $settings);
            if (is_wp_error($selection)) {
                return self::failure($id, $selection);
            }
            if ($selection['attachment']) {
                $image = (int) $selection['attachment'];
                $latest = self::fresh($id);
                $token = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $lock['name']));
                if (!$latest || !in_array($latest->post_status, array('publish', 'future'), true) ||
                    Selection::fingerprint($latest) !== $fingerprint || (int) get_post_thumbnail_id($id) !== $current ||
                    $token !== $lock['token'] || !Selection::valid_image($image)) {
                    return array('status' => 'changed');
                }
                if ($image !== $current && !self::assign($id, $image, $current)) {
                    return self::failure($id, new \WP_Error('thumbnail', Settings::label('Could not set the featured image.', '无法设置特色图片。', $settings)));
                }
                // Reuse without changing attachment ownership or deleting shared files.
                update_post_meta($id, '_auto_post_image_source', $selection['method']);
                delete_post_meta($id, '_auto_post_image_error');
                self::record($id, $selection['method']);
                clean_post_cache($id);
                return array('status' => $selection['method'], 'attachment' => $image);
            }
            require_once __DIR__ . '/renderer.php';
            $render = Renderer::create($title, $style);
            if (is_wp_error($render)) {
                return self::failure($id, $render);
            }
            $file = $render['file'];
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $type = wp_check_filetype($file);
            $candidate = wp_insert_attachment(array(
                'post_parent' => $id, 'post_author' => $post->post_author, 'post_status' => 'inherit',
                'post_title' => $post->post_title, 'post_mime_type' => $type['type'],
                'meta_input' => array('_auto_post_image_owner' => $id, '_auto_post_image_hash' => $hash,
                    '_auto_post_image_style' => $style, '_auto_post_image_layout' => array('size' => $render['size'], 'lines' => count($render['lines']), 'truncated' => !empty($render['truncated'])),
                    '_auto_post_image_rendered_text' => wp_slash(implode("\n", $render['lines'])), '_wp_attachment_image_alt' => $title),
            ), $file, $id, true);
            if (is_wp_error($candidate)) {
                $error = $candidate;
                $candidate = 0;
                return self::failure($id, $error);
            }
            $metadata = wp_generate_attachment_metadata($candidate, $file);
            if (empty($metadata['width']) || empty($metadata['height'])) {
                return self::failure($id, new \WP_Error('metadata', Settings::label('WordPress could not prepare the image.', 'WordPress 无法生成图片信息。', $settings)));
            }
            wp_update_attachment_metadata($candidate, $metadata);
            $stored = wp_get_attachment_metadata($candidate);
            if (empty($stored['width']) || empty($stored['height'])) {
                return self::failure($id, new \WP_Error('metadata', 'WordPress could not store image metadata.'));
            }
            $latest = self::fresh($id);
            $token = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $lock['name']));
            if (!$latest || !in_array($latest->post_status, array('publish', 'future'), true) ||
                Selection::fingerprint($latest) !== $fingerprint ||
                (int) get_post_thumbnail_id($id) !== $current || ($current && !self::owns($current, $id)) ||
                $token !== $lock['token']) {
                return array('status' => 'changed');
            }
            if (!self::assign($id, $candidate, $current)) {
                return self::failure($id, new \WP_Error('thumbnail', Settings::label('Could not set the featured image.', '无法设置特色图片。', $settings)));
            }
            $result = array('status' => 'generated', 'attachment' => $candidate);
            $candidate = 0;
            $file = '';
            delete_post_meta($id, '_auto_post_image_error');
            update_post_meta($id, '_auto_post_image_source', 'title');
            update_post_meta($id, '_auto_post_image_generated', gmdate('c'));
            self::record($id, 'generated', !empty($render['truncated']) ? Settings::label('Text shortened with an ellipsis.', '文字已截短并添加省略号。', $settings) : '', implode("\n", $render['lines']));
            clean_post_cache($id);
            return $result;
        } catch (\Throwable $e) {
            return self::failure($id, new \WP_Error('generation', Settings::label('Cover generation failed. The current image was preserved.', '生成失败，当前图片保持不变。', $settings)));
        } finally {
            self::fresh($id);
            if ($candidate && (int) get_post_thumbnail_id($id) === $candidate) {
                $file = '';
            }
            if ($candidate && (int) get_post_thumbnail_id($id) !== $candidate && self::owns($candidate, $id)) {
                wp_delete_attachment($candidate, true);
                $file = '';
            }
            if ($file && is_file($file)) {
                wp_delete_file($file);
            }
            self::unlock($lock);
        }
    }

    private static function fresh($id) {
        wp_cache_delete($id, 'posts');
        wp_cache_delete($id, 'post_meta');
        return get_post($id);
    }

    private static function assign($id, $image, $expected) {
        global $wpdb;
        if (!wp_get_attachment_image($image, 'thumbnail')) {
            return false;
        }
        self::fresh($id);
        if ((int) get_post_thumbnail_id($id) !== $expected) {
            return false;
        }
        if (!$expected) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_thumbnail_id' LIMIT 1", $id));
            if ($row && in_array($row->meta_value, array('0', ''), true)) {
                $check = apply_filters('update_post_metadata', null, $id, '_thumbnail_id', $image, 0);
                if ($check !== null) {
                    self::fresh($id);
                    return (bool) $check && (int) get_post_thumbnail_id($id) === $image;
                }
                // Core ignores a falsey previous-value condition; use a conditional zero-row update.
                do_action('update_post_meta', (int) $row->meta_id, $id, '_thumbnail_id', $image);
                do_action('update_postmeta', (int) $row->meta_id, $id, '_thumbnail_id', $image);
                $saved = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND post_id=%d AND meta_key='_thumbnail_id' AND meta_value=%s", (string) $image, (int) $row->meta_id, $id, $row->meta_value));
                wp_cache_delete($id, 'post_meta');
                if ($saved) {
                    do_action('updated_post_meta', (int) $row->meta_id, $id, '_thumbnail_id', $image);
                    do_action('updated_postmeta', (int) $row->meta_id, $id, '_thumbnail_id', $image);
                }
            } else {
                $saved = add_post_meta($id, '_thumbnail_id', $image, true);
            }
        } else {
            // Prevent update_metadata's add fallback when another writer removes the image.
            $guard = function ($check, $object_id, $key, $value) use ($id, $image) {
                return $check === null && (int) $object_id === $id && $key === '_thumbnail_id' && (int) $value === $image ? false : $check;
            };
            add_filter('add_post_metadata', $guard, PHP_INT_MAX, 4);
            try {
                $saved = update_post_meta($id, '_thumbnail_id', $image, $expected);
            } finally {
                remove_filter('add_post_metadata', $guard, PHP_INT_MAX);
            }
        }
        self::fresh($id);
        return (bool) $saved && (int) get_post_thumbnail_id($id) === $image;
    }

    private static function title($post) {
        if (!$post instanceof \WP_Post) {
            return '';
        }
        return Selection::text($post);
    }

    public static function owns($attachment, $id) {
        return $attachment && get_post_type($attachment) === 'attachment' &&
            (int) get_post_meta($attachment, '_auto_post_image_owner', true) === (int) $id;
    }

    private static function failure($id, $error) {
        update_post_meta($id, '_auto_post_image_error', array('time' => gmdate('c'), 'message' => sanitize_text_field($error->get_error_message())));
        self::record($id, 'error', $error->get_error_message());
        return $error;
    }

    public static function record($id, $status, $message = '', $cover_text = '') {
        update_post_meta($id, '_auto_post_image_result', wp_slash(array('time' => gmdate('c'), 'status' => $status, 'message' => sanitize_text_field($message),
            'cover_text' => $status === 'generated' && is_string($cover_text) ? $cover_text : '')));
        update_post_meta($id, '_auto_post_image_processed', time());
    }

    private static function lock($id) {
        global $wpdb;
        $name = 'auto_post_image_lock_' . $id;
        $token = wp_generate_uuid4() . '|' . (time() + 600);
        if (add_option($name, $token, '', false)) {
            return array('name' => $name, 'token' => $token);
        }
        $old = get_option($name);
        $parts = is_string($old) ? explode('|', $old) : array();
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || (int) $parts[1] >= time()) {
            return false;
        }
        $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", $token, $name, $old));
        if ($changed !== 1) {
            return false;
        }
        wp_cache_delete($name, 'options');
        return array('name' => $name, 'token' => $token);
    }

    private static function unlock($lock) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock['name'], $lock['token']));
        wp_cache_delete($lock['name'], 'options');
    }
}
