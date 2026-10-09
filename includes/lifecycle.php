<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;

final class Lifecycle {
    private static $removed = array();
    private static $previous = array();
    private static $text_changes = array();

    public static function text_changed($id) {
        self::$text_changes[$id] = true;
    }

    public static function before_save($data, $postarr) {
        if (($data['post_type'] ?? '') === 'post' && !empty($postarr['ID'])) {
            $id = (int) $postarr['ID'];
            self::$previous[$id] = (int) get_post_thumbnail_id($id);
            if (self::$previous[$id] && isset($_POST['_thumbnail_id']) && is_scalar($_POST['_thumbnail_id']) && (int) $_POST['_thumbnail_id'] === -1) {
                self::$removed[$id] = true;
            }
        }
        return $data;
    }

    public static function rest_intent($post, $request, $creating) {
        if (!$creating && !empty(self::$previous[$post->ID]) && $request->has_param('featured_media') && (int) $request['featured_media'] === 0) {
            self::$removed[$post->ID] = true;
        }
    }

    public static function after_save($id, $post, $update, $before) {
        $removed = isset(self::$removed[$id]);
        $text_changed = isset(self::$text_changes[$id]);
        unset(self::$removed[$id], self::$previous[$id], self::$text_changes[$id]);
        if (!$post instanceof \WP_Post || $post->post_type !== 'post' ||
            !in_array($post->post_status, array('publish', 'future'), true) ||
            wp_is_post_revision($id) || wp_is_post_autosave($id) ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ||
            (defined('DOING_CRON') && DOING_CRON) ||
            !current_user_can('edit_post', $id) || !current_user_can('upload_files') ||
            $removed) {
            return;
        }
        // REST handles the main Gutenberg save; its follow-up metabox save only updates cover text.
        if (is_admin() && isset($_GET['meta-box-loader'])) {
            if (!$text_changed || !get_post_thumbnail_id($id)) {
                return;
            }
        }
        require_once AUTO_POST_IMAGE_DIR . 'includes/generator.php';
        Generator::generate($id, false, false, $text_changed);
    }
}
