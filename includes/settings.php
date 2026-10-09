<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;
require_once __DIR__ . '/fonts.php';

final class Settings {
    const OPTION = 'auto_post_image_settings';

    public static function defaults() {
        return array('language' => 'en', 'background' => 'gradient', 'color1' => '#142a4d',
            'color2' => '#4266d5', 'direction' => 'diagonal', 'overlay' => 40,
            'font' => 'sans', 'font_id' => 0, 'image_id' => 0, 'fontSize' => 88,
            'textColor' => '#ffffff', 'alignment' => 'center', 'padding' => 110,
            'format' => 'jpeg', 'width' => 1200, 'height' => 800, 'lockRatio' => true,
            'automatic' => true, 'updateTitle' => true, 'mode' => 'title', 'default_id' => 0,
            'minFontSize' => 32, 'maxLines' => 3, 'maxCharacters' => 0, 'overflow' => 'ellipsis',
            'verticalAlignment' => 'center', 'lineHeight' => 130);
    }

    public static function normalize($saved) {
        if (!is_array($saved)) {
            return $saved;
        }
        $defaults = self::defaults();
        return array_merge($defaults, array_intersect_key($saved, $defaults));
    }

    public static function get() {
        $saved = get_option(self::OPTION, array());
        $settings = self::normalize(is_array($saved) ? $saved : array());
        return is_wp_error(self::validate($settings)) ? self::defaults() : $settings;
    }

    public static function label($english, $chinese, $settings = null) {
        $settings = $settings === null ? self::get() : $settings;
        return $settings['language'] === 'zh-CN' ? $chinese : $english;
    }

    public static function sanitize($input) {
        $old = self::get();
        if (!is_array($input)) {
            return $old;
        }
        $result = array_merge(self::defaults(), array_intersect_key($input, self::defaults()));
        foreach (array('overlay', 'fontSize', 'padding', 'width', 'height', 'font_id', 'image_id',
            'default_id', 'minFontSize', 'maxLines', 'maxCharacters', 'lineHeight') as $key) {
            $value = filter_var($result[$key], FILTER_VALIDATE_INT);
            if ($value === false) {
                add_settings_error(self::OPTION, 'number', self::label('Please use whole numbers.', '请使用整数。', $old));
                return $old;
            }
            $result[$key] = $value;
        }
        foreach (array('automatic', 'updateTitle', 'lockRatio') as $key) {
            $result[$key] = !empty($input[$key]) && in_array($input[$key], array(true, 1, '1', 'on'), true);
        }
        $error = self::validate($result);
        if (!is_wp_error($error) && $result['background'] === 'image') {
            $path = self::local_file($result['image_id']);
            $info = $path ? wp_getimagesize($path) : false;
            if (!$info || !in_array($info['mime'], array('image/jpeg', 'image/png', 'image/webp'), true) ||
                filesize($path) > 8388608 || $info[0] * $info[1] > 24000000) {
                $error = new \WP_Error('background', self::label('Choose a local JPEG, PNG or WebP up to 8 MB and 24 million pixels.', '请选择本地 JPEG、PNG 或 WebP，最大 8 MB、2400 万像素。', $old));
            }
        }
        if (!is_wp_error($error) && $result['font'] === 'custom' &&
            (!self::local_file($result['font_id']) || !get_post_meta($result['font_id'], '_auto_post_image_font', true))) {
            $error = new \WP_Error('font', self::label('Upload and choose a valid TTF font.', '请上传并选择有效 TTF 字体。', $old));
        }
        if (!is_wp_error($error) && in_array($result['mode'], array('default', 'content_then_default'), true) &&
            (!self::local_file($result['default_id']) || !wp_attachment_is_image($result['default_id']))) {
            $error = new \WP_Error('default_image', self::label('Choose a local media-library default image.', '请选择一张本地媒体库默认图片。', $old));
        }
        if (is_wp_error($error)) {
            add_settings_error(self::OPTION, $error->get_error_code(), $error->get_error_message());
            return $old;
        }
        return $result;
    }

    public static function validate($s) {
        if (!is_array($s)) {
            return new \WP_Error('style', 'Invalid cover settings.');
        }
        $enums = array('language' => array('en', 'zh-CN'), 'background' => array('solid', 'gradient', 'image'),
            'direction' => array('diagonal', 'horizontal', 'vertical'), 'font' => array_merge(array_keys(Fonts::catalog()), array('custom')),
            'alignment' => array('left', 'center', 'right'), 'format' => array('jpeg', 'png'),
            'mode' => array('title', 'content_only', 'default', 'content_then_title', 'content_then_default'),
            'verticalAlignment' => array('top', 'center', 'bottom'), 'overflow' => array('ellipsis', 'error'));
        foreach ($enums as $key => $values) {
            if (!isset($s[$key]) || !in_array($s[$key], $values, true)) {
                return new \WP_Error('style', 'Invalid cover style. / 封面样式无效。');
            }
        }
        foreach (array('color1', 'color2', 'textColor') as $key) {
            if (!isset($s[$key]) || !is_string($s[$key]) || !preg_match('/^#[a-f0-9]{6}$/i', $s[$key])) {
                return new \WP_Error('color', 'Use a complete hex color, e.g. #142A4D. / 请输入完整色号。');
            }
        }
        foreach (array('width', 'height', 'padding', 'fontSize', 'overlay', 'font_id', 'image_id',
            'default_id', 'minFontSize', 'maxLines', 'maxCharacters', 'lineHeight') as $key) {
            if (!isset($s[$key]) || !is_int($s[$key])) {
                return new \WP_Error('number', 'Use whole numbers. / 请使用整数。');
            }
        }
        if ($s['width'] < 320 || $s['width'] > 2400 || $s['height'] < 240 || $s['height'] > 2400 || $s['width'] * $s['height'] > 4000000) {
            return new \WP_Error('size', 'Width 320–2400, height 240–2400; maximum 4 million pixels. / 图片总计最多 400 万像素。');
        }
        if ($s['fontSize'] < 16 || $s['fontSize'] > 200 || $s['padding'] < 0 || $s['padding'] > 400 ||
            $s['padding'] * 2 > min($s['width'], $s['height']) - 100 || $s['overlay'] < 0 || $s['overlay'] > 85 ||
            $s['font_id'] < 0 || $s['image_id'] < 0 || $s['default_id'] < 0 ||
            $s['minFontSize'] < 16 || $s['minFontSize'] > $s['fontSize'] || $s['maxLines'] < 1 ||
            $s['maxLines'] > 8 || $s['maxCharacters'] < 0 || $s['maxCharacters'] > 500 ||
            $s['lineHeight'] < 100 || $s['lineHeight'] > 200) {
            return new \WP_Error('spacing', 'Check font size, spacing and overlay. / 请检查字号、留白与遮罩。');
        }
        return true;
    }

    public static function local_file($id) {
        if (!$id || get_post_type($id) !== 'attachment') {
            return false;
        }
        $file = get_attached_file($id);
        $uploads = wp_upload_dir(null, false);
        $base = realpath($uploads['basedir']);
        $path = is_string($file) ? realpath($file) : false;
        return $base && $path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path) && is_readable($path) ? $path : false;
    }
}
