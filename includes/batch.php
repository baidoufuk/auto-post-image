<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;
require_once __DIR__ . '/generator.php';

final class Batch {
    public static function run($cursor, $maximum, $category = 0, $style_hash = '') {
        global $wpdb;
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            return new \WP_Error('forbidden', 'Not allowed.');
        }
        $cursor = absint($cursor);
        $maximum = absint($maximum);
        $category = absint($category);
        $hash = hash('sha256', wp_json_encode(Settings::get()));
        if ($style_hash && !hash_equals($hash, $style_hash)) {
            return new \WP_Error('settings_changed', Settings::label('Saved settings changed. Start a new batch.', '已保存设置发生变化，请重新开始。'));
        }
        if ($category && !term_exists($category, 'category')) {
            return new \WP_Error('category', Settings::label('Choose an existing category.', '请选择存在的分类。'));
        }
        if (!$maximum) {
            $maximum = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'");
        }
        $where = function ($sql, $query) use ($cursor, $maximum, $wpdb) {
            return $query->get('auto_post_image_batch') ? $sql . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d", $cursor, $maximum) : $sql;
        };
        add_filter('posts_where', $where, 10, 2);
        try {
            $query = new \WP_Query(array('post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids',
                'posts_per_page' => 2, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
                'auto_post_image_batch' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => false,
                'category__in' => $category ? array($category) : array(), 'meta_query' => array('relation' => 'OR',
                    array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS'),
                    array('key' => '_thumbnail_id', 'value' => 0, 'compare' => '=', 'type' => 'NUMERIC'))));
        } finally {
            remove_filter('posts_where', $where, 10);
        }
        $results = array();
        foreach ($query->posts as $id) {
            $cursor = max($cursor, (int) $id);
            wp_cache_delete($id, 'posts');
            wp_cache_delete($id, 'post_meta');
            $result = get_post_status($id) !== 'publish' || ($category && !has_term($category, 'category', $id)) ?
                array('status' => 'skipped') : Generator::generate($id, true, true);
            $results[] = array('id' => (int) $id, 'title' => get_the_title($id),
                'status' => is_wp_error($result) ? 'error' : $result['status'],
                'message' => is_wp_error($result) ? $result->get_error_message() : '');
        }
        return array('cursor' => $cursor, 'maximum' => $maximum, 'done' => count($query->posts) < 2,
            'processed' => count($results), 'results' => $results, 'styleHash' => $hash);
    }
}
