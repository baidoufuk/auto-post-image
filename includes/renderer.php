<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;
require_once __DIR__ . '/settings.php';

final class Renderer {
    private static function error($code, $en, $zh, $s) {
        return new \WP_Error('auto_post_image_' . $code, Settings::label($en, $zh, $s));
    }

    public static function create($text, $s, $background_only = false) {
        $valid = Settings::validate($s);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
            return self::error('gd', 'Enable PHP GD with FreeType.', '请启用 PHP GD 与 FreeType。', $s);
        }
        if (!is_string($text) || !preg_match('//u', $text) || trim($text) === '' || preg_match_all('/\X/u', $text) > 500) {
            return self::error('title', 'Use a nonempty title of up to 500 characters.', '标题应为非空文字，最多 500 个字符。', $s);
        }
        $font = self::font($s);
        if (is_wp_error($font)) {
            return $font;
        }
        $layout = $background_only ? array('size' => 0, 'lines' => array()) : self::layout($text, $s, $font);
        if (is_wp_error($layout)) {
            return $layout;
        }
        wp_raise_memory_limit('image');
        if (!self::memory_available($s['width'] * $s['height'] * 8 + 16777216)) {
            return self::error('memory', 'Not enough image memory. Try a smaller size.', '图片内存不足，请减小尺寸。', $s);
        }
        $image = imagecreatetruecolor($s['width'], $s['height']);
        if (!$image) {
            return self::error('memory', 'Image allocation failed.', '无法分配图片内存。', $s);
        }
        $file = '';
        try {
            $result = self::background($image, $s);
            if (is_wp_error($result)) {
                return $result;
            }
            $color = self::color($image, self::rgb($s['textColor']));
            $line_height = $layout['size'] * $s['lineHeight'] / 100;
            $block_height = count($layout['lines']) * $line_height;
            $top = $s['verticalAlignment'] === 'top' ? $s['padding'] :
                ($s['verticalAlignment'] === 'bottom' ? $s['height'] - $s['padding'] - $block_height : ($s['height'] - $block_height) / 2);
            $start = $top + $line_height / 2;
            foreach ($layout['lines'] as $index => $line) {
                if ($line === '') {
                    continue;
                }
                $metrics = Fonts::metrics($line, $layout['size'], $font);
                if (!$metrics) {
                    return self::error('font', 'The font could not render this title.', '字体无法渲染此标题。', $s);
                }
                $left = $metrics['left'];
                $right = $metrics['right'];
                $top = $metrics['top'];
                $bottom = $metrics['bottom'];
                $x = $s['alignment'] === 'left' ? $s['padding'] :
                    ($s['alignment'] === 'right' ? $s['width'] - $s['padding'] - ($right - $left) : ($s['width'] - ($right - $left)) / 2);
                $y = $start + $index * $line_height - ($top + $bottom) / 2;
                if (!Fonts::draw($image, $metrics, $layout['size'], $x - $left, $y, $color)) {
                    return self::error('font', 'The font could not render this title.', '字体无法渲染此标题。', $s);
                }
            }
            $uploads = wp_upload_dir();
            if (!empty($uploads['error'])) {
                return self::error('write', 'The media folder is not writable.', '媒体文件夹不可写。', $s);
            }
            $extension = $s['format'] === 'png' ? 'png' : 'jpg';
            $file = trailingslashit($uploads['path']) . wp_unique_filename($uploads['path'], 'auto-post-image-' . wp_generate_uuid4() . '.' . $extension);
            $ok = $extension === 'png' ? imagepng($image, $file, 6) : imagejpeg($image, $file, 88);
            if (!$ok || !is_file($file) || filesize($file) === 0) {
                if (is_file($file)) {
                    wp_delete_file($file);
                }
                return self::error('write', 'The cover could not be saved.', '封面无法保存。', $s);
            }
            return array('file' => $file, 'size' => $layout['size'], 'lines' => $layout['lines'], 'truncated' => !empty($layout['truncated']));
        } catch (\Throwable $e) {
            if ($file && is_file($file)) {
                wp_delete_file($file);
            }
            return self::error('render', 'Cover generation failed; the existing image is unchanged.', '封面生成失败，原图片保持不变。', $s);
        } finally {
            self::dispose($image);
        }
    }

    private static function dispose(&$image) {
        if (is_resource($image)) {
            imagedestroy($image);
        }
        $image = null;
    }

    public static function memory_available($bytes) {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return true;
        }
        $limit = wp_convert_hr_to_bytes($limit);
        return $limit > memory_get_usage(true) + $bytes;
    }

    public static function font($s) {
        if ($s['font'] === 'custom') {
            $path = Settings::local_file($s['font_id']);
            if (!$path || !get_post_meta($s['font_id'], '_auto_post_image_font', true) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'ttf') {
                return self::error('font', 'Choose an uploaded local TTF font.', '请选择已上传的本地 TTF 字体。', $s);
            }
        } else {
            $path = Fonts::path($s['font']);
        }
        if (!is_readable($path) || !@imagettfbbox(12, 0, $path, '中文 ABC')) {
            return self::error('font', 'The local font is unavailable.', '本地字体不可用。', $s);
        }
        $entry = Fonts::catalog()[$s['font']] ?? array();
        if (!empty($entry['fallback'])) {
            $fallback = Fonts::path($entry['fallback']);
            if (!is_readable($fallback) || !@imagettfbbox(12, 0, $fallback, '中文 ABC')) {
                return self::error('font', 'The bundled fallback font is unavailable.', '内置备用字体不可用，请恢复字体文件。', $s);
            }
        }
        return $path;
    }

    private static function width($text, $px, $font) {
        if ($text === '') {
            return 0;
        }
        $metrics = Fonts::metrics($text, $px, $font);
        return $metrics ? $metrics['right'] - $metrics['left'] : INF;
    }

    private static function wrap($text, $px, $width, $font) {
        $lines = array();
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            if (trim($paragraph) === '') {
                $lines[] = '';
                continue;
            }
            preg_match_all('/(?:[A-Za-z0-9_]\p{M}*)+(?:[-.\/](?:[A-Za-z0-9_]\p{M}*)+)*|\s+|\X/u', $paragraph, $tokens);
            $line = '';
            foreach ($tokens[0] as $token) {
                if (self::width($line . $token, $px, $font) <= $width) {
                    $line .= $token;
                    continue;
                }
                if (trim($line) !== '') {
                    $lines[] = rtrim($line);
                }
                $line = '';
                if (trim($token) === '') {
                    continue;
                }
                if (self::width($token, $px, $font) <= $width) {
                    $line = $token;
                    continue;
                }
                preg_match_all('/\X/u', $token, $chars);
                foreach ($chars[0] as $char) {
                    if ($line !== '' && self::width($line . $char, $px, $font) > $width) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $char;
                }
            }
            if (trim($line) !== '') {
                $lines[] = rtrim($line);
            }
        }
        return $lines;
    }

    private static function layout($text, $s, $font) {
        $width = $s['width'] - 2 * $s['padding'];
        $height = $s['height'] - 2 * $s['padding'];
        $truncated = false;
        preg_match_all('/\X/u', $text, $characters);
        if ($s['maxCharacters'] && count($characters[0]) > $s['maxCharacters']) {
            $text = implode('', array_slice($characters[0], 0, max(0, $s['maxCharacters'] - 1))) . '…';
            $truncated = true;
        }
        // Measured binary search chooses the largest readable size that fits.
        $low = $s['minFontSize'];
        $high = $s['fontSize'];
        $best = null;
        while ($low <= $high) {
            $px = (int) floor(($low + $high) / 2);
            $lines = self::wrap($text, $px, $width, $font);
            $fits = count($lines) <= $s['maxLines'] && count($lines) * $px * $s['lineHeight'] / 100 <= $height;
            foreach ($lines as $line) {
                $fits = $fits && self::width($line, $px, $font) <= $width;
            }
            if ($fits) {
                $best = array('size' => $px, 'lines' => $lines, 'truncated' => $truncated);
                $low = $px + 1;
            } else {
                $high = $px - 1;
            }
        }
        if ($best) {
            return $best;
        }
        $px = $s['minFontSize'];
        $allowed = min($s['maxLines'], (int) floor($height / ($px * $s['lineHeight'] / 100)));
        if ($s['overflow'] !== 'ellipsis' || $allowed < 1 || self::width('…', $px, $font) > $width) {
            return self::error('long', 'The text does not fit. Adjust the layout or use shorter cover text.', '文字放不下，请调整排版或填写更短的封面文字。', $s);
        }
        $lines = array_slice(self::wrap($text, $px, $width, $font), 0, $allowed);
        $last = count($lines) - 1;
        preg_match_all('/\X/u', $lines[$last], $chars);
        while ($chars[0] && self::width(rtrim(implode('', $chars[0])) . '…', $px, $font) > $width) {
            array_pop($chars[0]);
        }
        $lines[$last] = rtrim(implode('', $chars[0])) . '…';
        foreach ($lines as $line) {
            if (self::width($line, $px, $font) > $width) {
                return self::error('long', 'A character does not fit. Reduce the minimum size or change the font.', '字符放不下，请减小最小字号或更换字体。', $s);
            }
        }
        return array('size' => $px, 'lines' => $lines, 'truncated' => true);
    }

    private static function rgb($hex) {
        return array(hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    }

    private static function color($image, $rgb) {
        return imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function background($image, $s) {
        $w = $s['width'];
        $h = $s['height'];
        $first = self::rgb($s['color1']);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, self::color($image, $first));
        if ($s['background'] === 'gradient') {
            $second = self::rgb($s['color2']);
            $small = imagecreatetruecolor(128, 128);
            try {
                for ($y = 0; $y < 128; $y++) {
                    for ($x = 0; $x < 128; $x++) {
                        $t = $s['direction'] === 'horizontal' ? $x / 127 : ($s['direction'] === 'vertical' ? $y / 127 :
                            (($x / 127) * $w * $w + ($y / 127) * $h * $h) / ($w * $w + $h * $h));
                        $rgb = array();
                        for ($c = 0; $c < 3; $c++) {
                            $rgb[] = (int) round($first[$c] + ($second[$c] - $first[$c]) * $t);
                        }
                        imagesetpixel($small, $x, $y, self::color($small, $rgb));
                    }
                }
                imagecopyresampled($image, $small, 0, 0, 0, 0, $w, $h, 128, 128);
            } finally {
                self::dispose($small);
            }
        } elseif ($s['background'] === 'image') {
            $path = Settings::local_file($s['image_id']);
            $info = $path && filesize($path) <= 8388608 ? wp_getimagesize($path) : false;
            if (!$info || $info[0] * $info[1] > 24000000 ||
                !self::memory_available($info[0] * $info[1] * 8 + 16777216)) {
                return self::error('background', 'Background is too large, missing, or exceeds available memory.', '背景图片过大、缺失，或超出可用内存。', $s);
            }
            $loaders = array('image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp');
            if (!isset($loaders[$info['mime']]) || !function_exists($loaders[$info['mime']])) {
                return self::error('background', 'Use a supported JPEG, PNG or WebP background.', '请使用服务器支持的 JPEG、PNG 或 WebP。', $s);
            }
            $source = $loaders[$info['mime']]($path);
            if (!$source) {
                return self::error('background', 'The background could not be decoded.', '无法读取背景图片。', $s);
            }
            try {
                $scale = max($w / $info[0], $h / $info[1]);
                $cw = $w / $scale;
                $ch = $h / $scale;
                imagecopyresampled($image, $source, 0, 0, (int) round(($info[0] - $cw) / 2), (int) round(($info[1] - $ch) / 2),
                    $w, $h, (int) round($cw), (int) round($ch));
            } finally {
                self::dispose($source);
            }
            $shade = imagecolorallocatealpha($image, 0, 0, 0, (int) round(127 * (1 - $s['overlay'] / 100)));
            imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, $shade);
        }
        return true;
    }
}
