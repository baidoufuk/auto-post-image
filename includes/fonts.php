<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;

final class Fonts {
    private static $catalog;

    public static function catalog() {
        if (self::$catalog === null) {
            $file = AUTO_POST_IMAGE_DIR . 'assets/fonts/catalog.json';
            $raw = is_readable($file) ? @file_get_contents($file) : false;
            $catalog = is_string($raw) ? json_decode($raw, true) : null;
            self::$catalog = is_array($catalog) && $catalog ? $catalog : array(
                'sans' => array('file' => 'NotoSansSC-Regular.otf', 'label' => array('Noto Sans SC', '思源黑体'), 'group' => 'cjk'),
                'serif' => array('file' => 'NotoSerifSC-Regular.otf', 'label' => array('Noto Serif SC', '思源宋体'), 'group' => 'cjk'));

        }
        return is_array(self::$catalog) ? self::$catalog : array();
    }

    public static function path($id) {
        $entry = self::catalog()[$id] ?? null;
        return $entry ? AUTO_POST_IMAGE_DIR . 'assets/fonts/' . $entry['file'] : '';
    }

    public static function browser_catalog() {
        $result = array();
        foreach (self::catalog() as $id => $entry) {
            $result[$id] = array('label' => $entry['label'], 'group' => $entry['group'],
                'url' => AUTO_POST_IMAGE_URL . 'assets/fonts/' . $entry['file'], 'fallback' => $entry['fallback'] ?? '');
        }
        return $result;
    }

    private static function codepoint($character) {
        $bytes = array_values(unpack('C*', $character));
        $cp = $bytes[0];
        if ($cp >= 240) {
            $cp = (($cp & 7) << 18) | (($bytes[1] & 63) << 12) | (($bytes[2] & 63) << 6) | ($bytes[3] & 63);
        } elseif ($cp >= 224) {
            $cp = (($cp & 15) << 12) | (($bytes[1] & 63) << 6) | ($bytes[2] & 63);
        } elseif ($cp >= 192) {
            $cp = (($cp & 31) << 6) | ($bytes[1] & 63);
        }
        return $cp;
    }

    private static function covered($character, $ranges) {
        $cp = self::codepoint($character);
        $low = 0;
        $high = count($ranges) - 1;
        while ($low <= $high) {
            $middle = (int) floor(($low + $high) / 2);
            if ($cp < $ranges[$middle][0]) {
                $high = $middle - 1;
            } elseif ($cp > $ranges[$middle][1]) {
                $low = $middle + 1;
            } else {
                return true;
            }
        }
        return false;
    }

    public static function runs($text, $primary) {
        $entry = null;
        foreach (self::catalog() as $face) {
            if (!empty($face['fallback']) && $primary === AUTO_POST_IMAGE_DIR . 'assets/fonts/' . $face['file']) {
                $entry = $face;
                break;
            }
        }
        if (!$entry) {
            return array(array('font' => $primary, 'text' => $text));
        }
        preg_match_all('/\X/u', $text, $clusters);
        $runs = array();
        foreach ($clusters[0] as $cluster) {
            preg_match_all('/./us', $cluster, $characters);
            $font = $primary;
            foreach ($characters[0] as $character) {
                if (!self::covered($character, $entry['coverage'])) {
                    $font = self::path($entry['fallback']);
                    break;
                }
            }
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['font'] === $font) {
                $runs[$last]['text'] .= $cluster;
            } else {
                $runs[] = array('font' => $font, 'text' => $cluster);
            }
        }
        return $runs;
    }

    private static function entry_for_path($path) {
        foreach (self::catalog() as $entry) {
            if ($path === AUTO_POST_IMAGE_DIR . 'assets/fonts/' . $entry['file']) {
                return $entry;
            }
        }
        return array();
    }

    private static function right_overhang($text, $font, $px) {
        $entry = self::entry_for_path($font);
        preg_match_all('/\X/u', $text, $clusters);
        $last = end($clusters[0]);
        if ($last === false) {
            return 0;
        }
        preg_match_all('/./us', $last, $characters);
        // Combining marks may have zero advance; use a conservative face bound.
        $extra = count($characters[0]) > 1 ? ($entry['maxOverhang'] ?? 0) :
            ($entry['overhang'][self::codepoint($characters[0][0])] ?? 0);
        return (int) ceil($extra * $px / ($entry['unitsPerEm'] ?? 1000));
    }

    public static function metrics($text, $px, $font) {
        $metrics = array('left' => INF, 'right' => -INF, 'top' => INF, 'bottom' => -INF, 'runs' => array());
        $cursor = 0;
        $entry = self::entry_for_path($font);
        $adjust = !empty($entry['fallback']);
        foreach (self::runs($text, $font) as $run) {
            $box = imagettfbbox($px * .75, 0, $run['font'], $run['text']);
            if (!$box) {
                return false;
            }
            $left = min($box[0], $box[2], $box[4], $box[6]);
            $right = max($box[0], $box[2], $box[4], $box[6]);
            $metrics['top'] = min($metrics['top'], $box[1], $box[3], $box[5], $box[7]);
            $metrics['bottom'] = max($metrics['bottom'], $box[1], $box[3], $box[5], $box[7]);
            $run['offset'] = $cursor;
            $metrics['runs'][] = $run;
            // A trailing space reveals the pen advance, including whitespace and bearings.
            $tail = imagettfbbox($px * .75, 0, $run['font'], $run['text'] . ' ');
            $space = imagettfbbox($px * .75, 0, $run['font'], ' ');
            if (!$tail || !$space) {
                return false;
            }
            $advance = max(0, $tail[2] - $space[2]);
            if ($adjust) {
                // GD can omit the final italic glyph's ink beyond its advance.
                $left -= 1;
                $right = max($right, $advance + self::right_overhang($run['text'], $run['font'], $px)) + 1;
            }
            $metrics['left'] = min($metrics['left'], $cursor + $left);
            $metrics['right'] = max($metrics['right'], $cursor + $right);
            $cursor += $advance;
        }
        return $metrics;
    }

    public static function draw($image, $metrics, $px, $x, $baseline, $color) {
        foreach ($metrics['runs'] as $run) {
            if (!imagettftext($image, $px * .75, 0, (int) round($x + $run['offset']),
                (int) round($baseline), $color, $run['font'], $run['text'])) {
                return false;
            }
        }
        return true;
    }
}
