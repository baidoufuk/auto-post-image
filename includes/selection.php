<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;
require_once __DIR__ . '/settings.php';

final class Selection {
    public static function choose($post, $settings) {
        if ($settings['mode'] === 'title') {
            return array('method' => 'title', 'attachment' => 0);
        }
        if ($settings['mode'] !== 'default') {
            $image = self::content_image($post->post_content);
            if ($image) {
                return array('method' => 'content', 'attachment' => $image);
            }
            if ($settings['mode'] === 'content_then_title') {
                return array('method' => 'title', 'attachment' => 0);
            }
            if ($settings['mode'] === 'content_only') {
                return new \WP_Error('content_image', Settings::label('No local media-library image found in the article.', '正文中没有找到本地媒体库图片。', $settings));
            }
        }
        $image = (int) $settings['default_id'];
        return self::valid_image($image) ? array('method' => 'default', 'attachment' => $image) :
            new \WP_Error('default_image', Settings::label('Choose an available local default image.', '请选择可用的本地默认图片。', $settings));
    }

    public static function valid_image($id) {
        return $id > 0 && wp_attachment_is_image($id) && Settings::local_file($id);
    }

    private static function content_image($content) {
        // Inspect real image tags only. No remote requests or processing of block JSON.
        $content = preg_replace(array('/<!--[\s\S]*?-->/', '/<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/i'), '', $content);
        preg_match_all('/<img\b[^>]*>/i', $content, $images);
        $uploads = wp_upload_dir(null, false);
        $base = trailingslashit($uploads['baseurl']);
        foreach (array_slice($images[0], 0, 50) as $tag) {
            if (preg_match('/\sclass\s*=\s*([\x22\x27])(.*?)\1/is', $tag, $class) &&
                preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class[2], $match) && self::valid_image((int) $match[1])) {
                return (int) $match[1];
            }
            if (!preg_match('/\ssrc\s*=\s*([\x22\x27])(.*?)\1/is', $tag, $source)) {
                continue;
            }
            $url = html_entity_decode($source[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = strtok($url, '?#');
            if (!$url || strpos($url, $base) !== 0) {
                continue;
            }
            $id = attachment_url_to_postid($url);
            if (!$id) {
                $original = preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url);
                if ($original !== $url) {
                    $id = attachment_url_to_postid($original);
                }
            }
            if (self::valid_image($id)) {
                return $id;
            }
        }
        return 0;
    }

    public static function text($post) {
        $custom = get_post_meta($post->ID, '_auto_post_image_text', true);
        $text = is_string($custom) && trim($custom) !== '' ? $custom : $post->post_title;
        return trim(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function fingerprint($post) {
        return hash('sha256', $post->post_title . "\0" . self::text($post) . "\0" . $post->post_content);
    }
}
