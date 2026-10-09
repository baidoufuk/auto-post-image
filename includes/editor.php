<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;

final class Editor {
    public static function boot() {
        add_action('add_meta_boxes_post', array(__CLASS__, 'box'));
        add_action('save_post_post', array(__CLASS__, 'save'), 20);
    }

    public static function box($post) {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }
        add_meta_box('auto_post_image-text', Settings::label('Cover text', '封面文字'), array(__CLASS__, 'render'),
            'post', 'side', 'default', array('__block_editor_compatible_meta_box' => true));
    }

    public static function render($post) {
        wp_nonce_field('auto_post_image_text', 'auto_post_image_text_nonce');
        echo '<p><label for="auto_post_image-text-value">' . esc_html(Settings::label('Optional. Leave empty to use the article title. Applies to text covers only.', '可选，留空使用文章标题。仅用于文字封面。')) . '</label></p>';
        echo '<textarea id="auto_post_image-text-value" name="auto_post_image_text" rows="3" maxlength="500" style="width:100%">' .
            esc_textarea(get_post_meta($post->ID, '_auto_post_image_text', true)) . '</textarea>';
    }

    public static function save($id) {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ||
            !current_user_can('edit_post', $id) || !isset($_POST['auto_post_image_text_nonce'], $_POST['auto_post_image_text']) ||
            !is_string($_POST['auto_post_image_text_nonce']) || !is_string($_POST['auto_post_image_text']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['auto_post_image_text_nonce'])), 'auto_post_image_text')) {
            return;
        }
        $text = sanitize_textarea_field(wp_unslash($_POST['auto_post_image_text']));
        if (!preg_match('//u', $text)) {
            return;
        }
        preg_match_all('/\X/u', $text, $characters);
        $text = implode('', array_slice($characters[0], 0, 500));
        $before = get_post_meta($id, '_auto_post_image_text', true);
        if ($text === (string) $before) {
            return;
        }
        update_post_meta($id, '_auto_post_image_text', $text);
        Lifecycle::text_changed($id);
    }
}
