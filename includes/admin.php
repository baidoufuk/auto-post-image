<?php
namespace AutoPostImage;
defined('ABSPATH') || exit;
require_once __DIR__ . '/settings.php';

final class Admin {
    private static $hook = '';

    public static function boot() {
        add_filter('plugin_action_links_' . plugin_basename(AUTO_POST_IMAGE_DIR . 'auto-post-image.php'), array(__CLASS__, 'action_links'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_auto_post_image_font', array(__CLASS__, 'upload_font'));
        add_action('admin_post_auto_post_image_generate', array(__CLASS__, 'generate'));
        add_action('wp_ajax_auto_post_image_preview', array(__CLASS__, 'preview'));
        add_action('wp_ajax_auto_post_image_batch', array(__CLASS__, 'batch'));
        require_once __DIR__ . '/editor.php';
        Editor::boot();
    }

    public static function action_links($links) {
        if (!current_user_can('manage_options')) {
            return $links;
        }
        $url = admin_url('options-general.php?page=auto-post-image');
        $shortcut = '<a href="' . esc_url($url) . '">' . esc_html(Settings::label('Settings', '设置')) . '</a>';
        return array('auto_post_image_settings' => $shortcut) + $links;
    }

    public static function menu() {
        self::$hook = add_options_page('Auto Post Image', 'Auto Post Image', 'manage_options', 'auto-post-image', array(__CLASS__, 'page'));
    }

    public static function register() {
        register_setting('auto-post-image', Settings::OPTION, array('type' => 'array', 'sanitize_callback' => array('AutoPostImage\Settings', 'sanitize'), 'show_in_rest' => false));
    }

    public static function assets($hook) {
        if ($hook !== self::$hook || !current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('auto-post-image-admin', AUTO_POST_IMAGE_URL . 'assets/admin.css', array(), AUTO_POST_IMAGE_VERSION);
        wp_enqueue_script('auto-post-image-admin', AUTO_POST_IMAGE_URL . 'assets/admin.js', array('media-views'), AUTO_POST_IMAGE_VERSION, true);
        $settings = Settings::get();
        $background = $settings['image_id'] ? wp_get_attachment_url($settings['image_id']) : '';
        $custom = $settings['font_id'] ? wp_get_attachment_url($settings['font_id']) : '';
        wp_localize_script('auto-post-image-admin', 'AutoPostImageConfig', array('settings' => $settings,
            'fonts' => Fonts::browser_catalog(), 'labels' => json_decode(file_get_contents(AUTO_POST_IMAGE_DIR . 'assets/labels.json'), true),
            'background' => $background, 'customFont' => $custom, 'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auto_post_image_preview'), 'batchNonce' => wp_create_nonce('auto_post_image_batch'),
            'defaultImage' => $settings['default_id'] ? wp_get_attachment_url($settings['default_id']) : ''));
    }

    private static function label($key, $english, $chinese) {
        return '<span data-apimg-label="' . esc_attr($key) . '">' . esc_html(Settings::label($english, $chinese)) . '</span>';
    }

    private static function field($key, $en, $zh, $type, $settings, $attributes = '') {
        echo '<div class="apimg-field"><label for="apimg-' . esc_attr($key) . '">' . self::label($key, $en, $zh) . '</label>';
        echo '<input id="apimg-' . esc_attr($key) . '" name="auto_post_image_settings[' . esc_attr($key) . ']" type="' . esc_attr($type) . '" value="' . esc_attr($settings[$key]) . '" ' . $attributes . '></div>';
    }

    private static function select($key, $en, $zh, $choices, $s) {
        echo '<div class="apimg-field"><label for="apimg-' . esc_attr($key) . '">' . self::label($key, $en, $zh) . '</label>';
        echo '<select id="apimg-' . esc_attr($key) . '" name="auto_post_image_settings[' . esc_attr($key) . ']">';
        foreach ($choices as $value => $pair) {
            echo '<option value="' . esc_attr($value) . '"' . selected($s[$key], $value, false) . '>' . esc_html(Settings::label($pair[0], $pair[1])) . '</option>';
        }
        echo '</select></div>';
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Settings::get();
        echo '<div class="wrap auto-post-image-admin"><div class="apimg-heading"><div><h1>Auto Post Image</h1><p>' . self::label('intro', 'Choose how articles get their images, then keep writing.', '选好文章配图方式，之后专心写文章。') . '</p></div><span class="apimg-version">' . esc_html(AUTO_POST_IMAGE_VERSION) . '</span></div>';
        settings_errors(Settings::OPTION);
        if (count(Fonts::catalog()) !== 7) {
            echo '<div class="notice notice-error"><p>' . esc_html(Settings::label('Some bundled fonts are missing. Reinstall the complete plugin package.', '内置字体目录不完整，请重新安装完整插件包。', $s)) . '</p></div>';
        }
        if (isset($_GET['apimg_notice']) && is_string($_GET['apimg_notice'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['apimg_notice']))) . '</p></div>';
        }
        if (!function_exists('imagettftext')) {
            echo '<div class="notice notice-error"><p>' . esc_html(Settings::label('Enable PHP GD with FreeType to generate covers.', '生成封面需要启用 PHP GD 与 FreeType。')) . '</p></div>';
        }
        echo '<div class="apimg-columns"><form id="apimg-settings" action="options.php" method="post" class="apimg-panel">';
        settings_fields('auto-post-image');
        echo '<section>';
        self::select('language', 'Interface language', '界面语言', array('en' => array('English', 'English'), 'zh-CN' => array('简体中文', '简体中文')), $s);
        self::select('mode', 'Image source', '配图方式', array(
            'title' => array('Generate a text cover', '生成文字封面'),
            'content_only' => array('Use a content image', '使用正文图片'),
            'default' => array('Use the default image', '使用默认图片'),
            'content_then_title' => array('Content image, then text cover', '正文图片优先，没有则生成文字封面'),
            'content_then_default' => array('Content image, then default image', '正文图片优先，没有则使用默认图片')), $s);
        echo '<p class="apimg-hint">' . self::label('modeHint', 'Content modes use the first available local media-library image. External images are skipped.', '正文模式使用第一张可用的本地媒体库图片，跳过外链图片。') . '</p>';
        echo '<div id="apimg-default-wrap"><input type="hidden" id="apimg-default_id" name="auto_post_image_settings[default_id]" value="' . esc_attr($s['default_id']) . '"><div class="apimg-buttons"><button class="button" type="button" id="apimg-choose-default">' . self::label('chooseDefault', 'Choose default image', '选择默认图片') . '</button><button class="button" type="button" id="apimg-remove-default">' . self::label('remove', 'Remove', '移除') . '</button></div><p id="apimg-default-name">' . esc_html($s['default_id'] ? get_the_title($s['default_id']) : '') . '</p></div>';
        echo '</section><section><h2>' . self::label('backgroundHeading', '01 · Background', '01 · 背景') . '</h2>';
        self::select('background', 'Background type', '背景类型', array('solid' => array('Solid color', '纯色'), 'gradient' => array('Gradient', '渐变'), 'image' => array('Image', '图片')), $s);
        echo '<div id="apimg-colors" class="apimg-grid">';
        self::field('color1', 'Primary color', '主色', 'color', $s);
        echo '<div id="apimg-secondary">';
        self::field('color2', 'Secondary color', '第二种颜色', 'color', $s);
        echo '</div></div><div id="apimg-direction-wrap">';
        self::select('direction', 'Gradient direction', '渐变方向', array('diagonal' => array('Diagonal', '对角线'), 'horizontal' => array('Left to right', '从左到右'), 'vertical' => array('Top to bottom', '从上到下')), $s);
        echo '</div><div id="apimg-image-wrap"><input type="hidden" id="apimg-image_id" name="auto_post_image_settings[image_id]" value="' . esc_attr($s['image_id']) . '"><div class="apimg-buttons"><button class="button" type="button" id="apimg-choose-image">' . self::label('chooseImage', 'Choose image', '选择图片') . '</button><button class="button" type="button" id="apimg-remove-image">' . self::label('remove', 'Remove', '移除') . '</button></div><p id="apimg-image-name">' . esc_html($s['image_id'] ? get_the_title($s['image_id']) : '') . '</p>';
        self::field('overlay', 'Dark overlay · %', '深色遮罩 · %', 'number', $s, 'min="0" max="85" step="1"');
        echo '<p class="apimg-hint">' . self::label('imageHint', 'Local JPEG, PNG or WebP, up to 8 MB and 24 million pixels.', '本地 JPEG、PNG 或 WebP，最大 8 MB、2400 万像素。') . '</p></div></section>';
        echo '<section><h2>' . self::label('typographyHeading', '02 · Typography', '02 · 字体与文字') . '</h2>';
        echo '<div class="apimg-field"><label for="apimg-font">' . self::label('font', 'Font family', '字体') . '</label><select id="apimg-font" name="auto_post_image_settings[font]">';
        foreach (array('cjk' => array('Chinese & English', '中文与英文'), 'latin' => array('Latin display styles', '英文字体风格')) as $group => $pair) {
            echo '<optgroup data-apimg-font-group="' . esc_attr($group) . '" label="' . esc_attr(Settings::label($pair[0], $pair[1], $s)) . '">';
            foreach (Fonts::catalog() as $id => $entry) {
                if ($entry['group'] === $group) {
                    echo '<option value="' . esc_attr($id) . '"' . selected($s['font'], $id, false) . '>' . esc_html(Settings::label($entry['label'][0], $entry['label'][1], $s)) . '</option>';
                }
            }
            echo '</optgroup>';
        }
        if ($s['font_id'] && get_post_meta($s['font_id'], '_auto_post_image_font', true)) {
            echo '<option value="custom"' . selected($s['font'], 'custom', false) . '>' . esc_html(Settings::label('Uploaded TTF', '已上传 TTF', $s)) . '</option>';
        }
        echo '</select><p class="apimg-hint">' . self::label('fontCatalogHint', '7 local, commercially usable fonts. Missing glyphs in additional styles use a bundled Chinese fallback. Fonts load only in this settings screen.', '7 款本地免费可商用字体。新增字体缺少的字形使用内置中文字体补齐，字体仅在本设置页面加载。') . '</p></div>';
        echo '<input type="hidden" id="apimg-font_id" name="auto_post_image_settings[font_id]" value="' . esc_attr($s['font_id']) . '"><div class="apimg-grid">';
        self::field('fontSize', 'Maximum font size · px', '最大字号 · 像素', 'number', $s, 'min="16" max="200" step="1"');
        self::field('minFontSize', 'Minimum font size · px', '最小字号 · 像素', 'number', $s, 'min="16" max="200" step="1"');
        self::field('textColor', 'Text color', '文字颜色', 'color', $s);
        echo '</div><p class="apimg-hint">' . self::label('wrapHint', 'Chinese and English wrap automatically; long titles scale down.', '中英文自动换行，长标题适当缩小字号。') . '</p>';
        echo '<details><summary>' . self::label('layout', 'Alignment & spacing', '对齐与留白') . '</summary><div class="apimg-grid">';
        self::select('alignment', 'Text alignment', '文字对齐', array('left' => array('Left', '左对齐'), 'center' => array('Center', '居中'), 'right' => array('Right', '右对齐')), $s);
        self::select('verticalAlignment', 'Vertical alignment', '垂直对齐', array('top' => array('Top', '顶部'), 'center' => array('Center', '居中'), 'bottom' => array('Bottom', '底部')), $s);
        self::field('lineHeight', 'Line spacing · %', '行距 · %', 'number', $s, 'min="100" max="200" step="1"');
        self::field('padding', 'Edge padding · px', '四周留白 · 像素', 'number', $s, 'min="0" max="400" step="1"');
        self::field('maxLines', 'Maximum lines', '最多行数', 'number', $s, 'min="1" max="8" step="1"');
        self::field('maxCharacters', 'Maximum characters · 0 = no limit', '最多字符 · 0 表示不限制', 'number', $s, 'min="0" max="500" step="1"');
        self::select('overflow', 'When text still does not fit', '文字仍然放不下时', array('ellipsis' => array('Shorten with an ellipsis', '截短并显示省略号'), 'error' => array('Skip and record the issue', '跳过并记录问题')), $s);
        echo '</div></details></section><section><h2>' . self::label('outputHeading', '03 · Image output', '03 · 图片输出') . '</h2>';
        self::select('format', 'File format', '图片格式', array('jpeg' => array('JPEG', 'JPEG'), 'png' => array('PNG', 'PNG')), $s);
        echo '<div class="apimg-grid">';
        self::field('width', 'Width · px', '宽度 · 像素', 'number', $s, 'min="320" max="2400" step="1"');
        self::field('height', 'Height · px', '高度 · 像素', 'number', $s, 'min="240" max="2400" step="1"');
        echo '</div>';
        self::checkbox('lockRatio', 'Keep the current aspect ratio', '保持当前宽高比例', $s);
        echo '<p class="apimg-hint">' . self::label('sizeHint', 'Default 1200 × 800. Maximum 4 million pixels.', '默认 1200 × 800，最多 400 万像素。') . '</p></section>';
        echo '<section><h2>' . self::label('automationHeading', '04 · When to generate', '04 · 何时生成') . '</h2>';
        self::checkbox('automatic', 'Generate missing covers on publish', '发布时生成缺失的封面', $s);
        self::checkbox('updateTitle', 'Update owned covers when titles change', '标题变化时更新本插件的封面', $s);
        echo '<p class="apimg-hint">' . self::label('automationHint', 'Manual images stay untouched. New settings affect new covers only. Scheduled articles are prepared when you schedule them.', '保留手动配图。新设置只用于新封面。定时文章在设置定时时预生成封面。') . '</p></section><div class="apimg-actions">';
        submit_button(Settings::label('Save settings', '保存设置'), 'primary', 'submit', false);
        echo '</div></form><aside class="apimg-preview apimg-panel"><section><h2>' . self::label('preview', 'Cover preview', '封面预览') . '</h2><div class="apimg-buttons"><button class="button" type="button" data-apimg-sample="React 的十二个钩子">' . self::label('sampleReact', 'React · Chinese', 'React · 中文') . '</button><button class="button" type="button" data-apimg-sample="“Navicat Premium”已损坏，无法打开。你应该将它移到废纸篓。">' . self::label('sampleLong', 'Long title', '长标题') . '</button><button class="button" type="button" data-apimg-sample="A practical guide to Python and Django">' . self::label('sampleEnglish', 'English title', '英文标题') . '</button></div>';
        echo '<div class="apimg-field"><label for="apimg-title">' . self::label('title', 'Sample article title', '示例文章标题') . '</label><textarea id="apimg-title" rows="3" maxlength="500">React 的十二个钩子</textarea></div><canvas id="apimg-canvas" width="1200" height="800" role="img" aria-label="' . esc_attr(Settings::label('Cover preview', '封面预览')) . '"></canvas><img id="apimg-default-preview" alt="" hidden><p id="apimg-preview-mode-hint" class="apimg-hint"></p><p id="apimg-preview-meta" class="apimg-hint"></p><p id="apimg-preview-status" role="status" aria-live="polite"></p><button type="button" id="apimg-server-preview" class="button">' . self::label('serverPreview', 'Check actual server output', '查看服务器实际效果') . '</button><p class="apimg-hint">' . self::label('previewHint', 'Live browser preview is approximate. Check server output before saving; it uses the same renderer as published covers.', '浏览器实时预览为近似效果。保存前可查看服务器实际效果，与发布封面使用同一渲染器。') . '</p><img id="apimg-server-image" alt="' . esc_attr(Settings::label('Actual server-rendered cover', '服务器生成的实际封面')) . '" hidden></section></aside></div>';
        echo '<div class="apimg-panel apimg-tools"><section><h2>' . self::label('fontUpload', 'Local TTF font', '本地 TTF 字体') . '</h2><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="auto_post_image_font">';
        wp_nonce_field('auto_post_image_font');
        echo '<label for="apimg-font-file">' . self::label('uploadFont', 'Upload a TTF with Chinese glyphs, up to 20 MB. Verify it with server preview.', '上传包含中文字形的 TTF，最大 20 MB。请用服务器预览确认效果。') . '</label> <input type="file" id="apimg-font-file" name="auto_post_image_font" accept=".ttf" required>';
        submit_button(Settings::label('Upload font', '上传字体'), 'secondary', 'submit', false);
        echo '</form></section><section><h2>' . self::label('regenerate', 'Generate one article', '生成单篇文章') . '</h2><p class="apimg-hint">' . self::label('regenerateHint', 'Optional: apply the saved style to an existing article. Manual featured images are always preserved.', '可选：将已保存样式用于一篇旧文章，始终保留手动设置的特色图片。') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="auto_post_image_generate">';
        wp_nonce_field('auto_post_image_generate');
        echo '<label for="apimg-post-id">' . self::label('postId', 'Article ID', '文章 ID') . '</label> <input type="number" id="apimg-post-id" name="post_id" min="1" step="1" required>';
        submit_button(Settings::label('Generate cover', '生成封面'), 'secondary', 'submit', false);
        echo '</form></section>';
        self::batch_controls();
        self::results();
        echo '</div></div>';
    }

    private static function batch_controls() {
        echo '<section><h2>' . self::label('batchHeading', 'Fill missing article images', '批量补齐文章配图') . '</h2><p class="apimg-hint">' . self::label('batchHint', 'Uses saved settings for published articles. Existing images are skipped. Keep this page open.', '使用已保存设置处理已发布文章，跳过已有配图。请保持本页面打开。') . '</p>';
        echo '<div class="apimg-field"><label for="apimg-batch-category">' . self::label('batchCategory', 'Category', '分类') . '</label>';
        wp_dropdown_categories(array('name' => 'auto_post_image_batch_category', 'id' => 'apimg-batch-category', 'show_option_all' => Settings::label('All categories', '所有分类'), 'hide_empty' => false, 'hierarchical' => true));
        echo '</div><div class="apimg-buttons"><button type="button" class="button button-primary" id="apimg-batch-start">' . self::label('batchStart', 'Start', '开始') . '</button><button type="button" class="button" id="apimg-batch-stop" disabled>' . self::label('batchStop', 'Stop after this group', '处理完当前组后停止') . '</button></div><p id="apimg-batch-status" role="status" aria-live="polite"></p><div class="apimg-table-scroll"><table class="widefat striped"><thead><tr><th>' . self::label('article', 'Article', '文章') . '</th><th>' . self::label('result', 'Result', '结果') . '</th></tr></thead><tbody id="apimg-batch-results"></tbody></table></div></section>';
    }

    private static function results() {
        echo '<section><h2>' . self::label('resultsHeading', 'Recent article results', '最近文章处理结果') . '</h2><p class="apimg-hint">' . self::label('resultsHint', 'Latest result for the 20 most recently processed articles. Reload to refresh.', '显示最近处理的 20 篇文章的最新结果，刷新页面可更新。') . '</p>';
        $posts = get_posts(array('post_type' => 'post', 'post_status' => array('publish', 'future'), 'posts_per_page' => 20,
            'meta_key' => '_auto_post_image_processed', 'orderby' => array('meta_value_num' => 'DESC', 'ID' => 'DESC'), 'no_found_rows' => true));
        echo '<div class="apimg-table-scroll"><table class="widefat striped"><thead><tr><th>' . self::label('article', 'Article', '文章') . '</th><th>' . self::label('coverTextColumn', 'Cover text', '封面文字') . '</th><th>' . self::label('result', 'Result', '结果') . '</th><th>' . self::label('time', 'Time · UTC', '时间 · UTC') . '</th></tr></thead><tbody>';
        foreach ($posts as $post) {
            $record = get_post_meta($post->ID, '_auto_post_image_result', true);
            if (!is_array($record)) {
                continue;
            }
            $status = $record['status'] ?? '';
            $cover_text = $status === 'generated' && isset($record['cover_text']) && is_string($record['cover_text']) ? $record['cover_text'] : '';
            $cover_cell = $cover_text !== '' ? esc_html($cover_text) : ($status === 'generated' ? self::label('coverNotRecorded', 'Not recorded', '未记录') : '—');
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html($post->post_title) . '</a></td><td class="apimg-cover-text">' . $cover_cell . '</td><td><span data-apimg-result="' . esc_attr($status) . '">' . esc_html(self::outcome($status)) . '</span> ' . esc_html($record['message'] ?? '') . '</td><td>' . esc_html($record['time'] ?? '') . '</td></tr>';
        }
        if (!$posts) {
            echo '<tr><td colspan="4">' . self::label('noResults', 'No results yet.', '暂无处理记录。') . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function outcome($status) {
        $labels = array('generated' => array('Text cover generated', '文字封面已生成'), 'content' => array('Content image assigned', '已使用正文图片'),
            'default' => array('Default image assigned', '已使用默认图片'), 'error' => array('Failed', '失败'),
            'manual' => array('Existing image preserved', '已保留现有图片'), 'changed' => array('Article changed; skipped', '文章有变化，已跳过'));
        $pair = $labels[$status] ?? array($status, $status);
        return Settings::label($pair[0], $pair[1]);
    }

    public static function batch() {
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }
        check_ajax_referer('auto_post_image_batch', 'nonce');
        $args = array();
        foreach (array('cursor', 'maximum', 'category') as $key) {
            $args[$key] = isset($_POST[$key]) && is_scalar($_POST[$key]) ? absint($_POST[$key]) : 0;
        }
        $hash = isset($_POST['styleHash']) && is_string($_POST['styleHash']) ? sanitize_text_field(wp_unslash($_POST['styleHash'])) : '';
        require_once __DIR__ . '/batch.php';
        $result = Batch::run($args['cursor'], $args['maximum'], $args['category'], $hash);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), $result->get_error_code() === 'forbidden' ? 403 : 400);
        }
        wp_send_json_success($result);
    }

    private static function checkbox($key, $en, $zh, $s) {
        echo '<input type="hidden" name="auto_post_image_settings[' . esc_attr($key) . ']" value="0"><label class="apimg-check"><input id="apimg-' . esc_attr($key) . '" type="checkbox" name="auto_post_image_settings[' . esc_attr($key) . ']" value="1"' . checked($s[$key], true, false) . '>' . self::label($key, $en, $zh) . '</label>';
    }

    private static function authorize($nonce) {
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_die('Not allowed.', '', array('response' => 403));
        }
        check_admin_referer($nonce);
    }

    private static function redirect($message) {
        wp_safe_redirect(add_query_arg(array('page' => 'auto-post-image', 'apimg_notice' => $message), admin_url('options-general.php')));
        exit;
    }

    public static function generate() {
        self::authorize('auto_post_image_generate');
        require_once __DIR__ . '/generator.php';
        $id = isset($_POST['post_id']) && is_scalar($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $result = Generator::generate($id, true);
        $message = is_wp_error($result) ? $result->get_error_message() :
            Settings::label(in_array($result['status'], array('generated', 'content', 'default'), true) ? 'Article image prepared.' : 'The existing manual image was preserved, or the article changed. Retry if needed.',
                in_array($result['status'], array('generated', 'content', 'default'), true) ? '文章配图已准备。' : '已保留手动图片，或文章发生变化；需要时请重试。');
        self::redirect($message);
    }

    public static function upload_font() {
        self::authorize('auto_post_image_font');
        if (!function_exists('imagettfbbox')) {
            self::redirect(Settings::label('Enable PHP GD with FreeType first.', '请先启用 PHP GD 与 FreeType。'));
        }
        $f = $_FILES['auto_post_image_font'] ?? null;
        if (!is_array($f) || !isset($f['error'], $f['tmp_name'], $f['size'], $f['name']) ||
            !is_int($f['error']) || !is_int($f['size']) || !is_string($f['tmp_name']) || !is_string($f['name']) ||
            !empty($f['error']) || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name']) ||
            $f['size'] > 20971520 || $f['size'] < 100 || strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'ttf') {
            self::redirect(Settings::label('Choose a valid TTF up to 20 MB.', '请选择最大 20 MB 的有效 TTF。'));
        }
        $handle = fopen($f['tmp_name'], 'rb');
        $header = $handle ? fread($handle, 12) : '';
        if ($handle) {
            fclose($handle);
        }
        if (strlen($header) !== 12 || substr($header, 0, 4) !== "\x00\x01\x00\x00" || !@imagettfbbox(12, 0, $f['tmp_name'], '中文 ABC')) {
            self::redirect(Settings::label('The TrueType font could not be read.', '无法读取此 TrueType 字体。'));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $f['name'] = 'auto-post-image-font-' . wp_generate_uuid4() . '.ttf';
        $_FILES['auto_post_image_font'] = $f;
        $allow = function ($type, $path, $name) use ($f) {
            return $path === $f['tmp_name'] && $name === $f['name'] ?
                array('ext' => 'ttf', 'type' => 'font/ttf', 'proper_filename' => false) : $type;
        };
        add_filter('wp_check_filetype_and_ext', $allow, 10, 3);
        try {
            $id = media_handle_upload('auto_post_image_font', 0, array(), array('test_form' => false, 'mimes' => array('ttf' => 'font/ttf')));
        } finally {
            remove_filter('wp_check_filetype_and_ext', $allow, 10);
        }
        if (is_wp_error($id)) {
            self::redirect($id->get_error_message());
        }
        update_post_meta($id, '_auto_post_image_font', 1);
        $s = Settings::get();
        $s['font_id'] = $id;
        $s['font'] = 'custom';
        update_option(Settings::OPTION, $s, false);
        self::redirect(Settings::label('Font uploaded and selected.', '字体已上传并选中。'));
    }

    public static function preview() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }
        check_ajax_referer('auto_post_image_preview', 'nonce');
        $input = isset($_POST['settings']) && is_string($_POST['settings']) ? json_decode(wp_unslash($_POST['settings']), true) : null;
        if (!is_array($input)) {
            wp_send_json_error(array('message' => 'Invalid settings.'), 400);
        }
        // Preview does not save options or create media attachments.
        $style = Settings::sanitize($input);
        if (get_settings_errors(Settings::OPTION)) {
            $errors = get_settings_errors(Settings::OPTION);
            wp_send_json_error(array('message' => $errors[0]['message']), 400);
        }
        $title = isset($_POST['title']) && is_string($_POST['title']) ? sanitize_textarea_field(wp_unslash($_POST['title'])) : '';
        require_once __DIR__ . '/renderer.php';
        $result = Renderer::create($title, $style);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        $file = $result['file'];
        try {
            $data = base64_encode(file_get_contents($file));
            $payload = array('image' => 'data:image/' . ($style['format'] === 'png' ? 'png' : 'jpeg') . ';base64,' . $data,
                'size' => $result['size'], 'lines' => count($result['lines']), 'truncated' => !empty($result['truncated']));
        } finally {
            wp_delete_file($file);
        }
        wp_send_json_success($payload);
    }
}
