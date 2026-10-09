(() => {
    'use strict';
    const root = document.querySelector('.auto-post-image-admin');
    const config = window.AutoPostImageConfig;
    if (!root || !config) return;
    const $ = id => document.getElementById('apimg-' + id);
    const canvas = $('canvas'), ctx = canvas.getContext('2d'), form = $('settings');
    canvas.after($('server-image'));
    const numeric = ['width', 'height', 'padding', 'fontSize', 'overlay', 'font_id', 'image_id', 'default_id', 'minFontSize', 'maxLines', 'maxCharacters', 'lineHeight'];
    const flags = ['automatic', 'updateTitle', 'lockRatio'];
    let version = 0, frame = 0, request = null, media = null;
    let imageUrl = config.background || '', defaultUrl = config.defaultImage || '', aspect = config.settings.width / config.settings.height;
    const assets = new Map();
    const t = key => (config.labels[key] || [key, key])[$('language').value === 'zh-CN' ? 1 : 0];
    function values() {
        const s = {...config.settings};
        Object.keys(s).forEach(key => { if ($(key)) s[key] = flags.includes(key) ? $(key).checked : $(key).value; });
        numeric.forEach(key => { s[key] = Number(s[key]); });
        return s;
    }
    function message(text = '') { $('preview-status').textContent = text; }
    function translate() {
        root.lang = $('language').value;
        root.querySelectorAll('[data-apimg-label]').forEach(el => { el.textContent = t(el.dataset.apimgLabel); });
        const options = {mode:['title', 'content_only', 'default', 'content_then_title', 'content_then_default'], verticalAlignment:['top', 'center', 'bottom'], overflow:['ellipsis', 'error'], background:['solid', 'gradient', 'image'], direction:['diagonal', 'horizontal', 'vertical'], alignment:['left', 'center', 'right']};
        Object.keys(options).forEach(key => Array.from($(key).options).forEach(option => { option.textContent = t(key === 'mode' && option.value === 'title' ? 'mode_title' : option.value); }));
        Array.from($('font').options).forEach(option => {
            const entry = config.fonts[option.value];
            option.textContent = entry ? entry.label[$('language').value === 'zh-CN' ? 1 : 0] : t('custom');
        });
        root.querySelectorAll('[data-apimg-font-group]').forEach(group => { group.label = t('fontGroup_' + group.dataset.apimgFontGroup); });
        form.querySelector('[type=submit]').value = t('save');
        root.querySelectorAll('.apimg-tools form [type=submit]').forEach(button => {
            button.value = t(button.form.querySelector('[name=action]').value === 'auto_post_image_font' ? 'upload' : 'generate');
        });
        canvas.setAttribute('aria-label', t('preview') + ': ' + $('title').value);
        $('server-image').alt = t('serverPreview');
        $('default-preview').alt = t('default');
        root.querySelectorAll('[data-apimg-result]').forEach(el => { el.textContent = t('result_' + el.dataset.apimgResult); });
        const allCategories = $('batch-category').querySelector('option[value="0"]');
        if (allCategories) allCategories.textContent = t('allCategories');
    }
    function validate(s) {
        if (!numeric.every(key => Number.isInteger(s[key])) || s.width < 320 || s.width > 2400 || s.height < 240 || s.height > 2400 || s.width*s.height > 4000000 || s.padding < 0 || s.padding > 400 || s.padding*2 > Math.min(s.width, s.height)-100 || s.fontSize < 16 || s.fontSize > 200 || s.overlay < 0 || s.overlay > 85 || s.minFontSize < 16 || s.minFontSize > s.fontSize || s.maxLines < 1 || s.maxLines > 8 || s.maxCharacters < 0 || s.maxCharacters > 500 || s.lineHeight < 100 || s.lineHeight > 200 || !$('title').value.trim()) return t('invalid');
        return '';
    }
    function loadImage(url) {
        if (!assets.has(url)) assets.set(url, new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image); image.onerror = () => reject(new Error(t('needImage'))); image.src = url;
        }).catch(error => { assets.delete(url); throw error; }));
        return assets.get(url);
    }
    let fontSequence = 0;
    async function loadFont(url) {
        const key = 'font:' + url;
        if (!assets.has(key)) {
            const name = 'AutoPostImageLocal' + (++fontSequence);
            assets.set(key, new FontFace(name, 'url(' + JSON.stringify(url) + ')').load().then(face => {
                document.fonts.add(face); return name;
            }).catch(() => { assets.delete(key); throw new Error(t('fontUnavailable')); }));
        }
        return assets.get(key);
    }
    async function family(s) {
        if (s.font === 'custom') {
            if (!config.customFont) throw new Error(t('fontUnavailable'));
            return loadFont(config.customFont);
        }
        const entry = config.fonts[s.font];
        if (!entry) throw new Error(t('fontUnavailable'));
        const primary = await loadFont(entry.url);
        if (!entry.fallback) return primary;
        const fallback = config.fonts[entry.fallback];
        return primary + ', ' + await loadFont(fallback.url);
    }
    const pieces = value => typeof Intl.Segmenter === 'function' ? Array.from(new Intl.Segmenter(undefined, {granularity:'grapheme'}).segment(value), item => item.segment) : Array.from(value);
    function wrap(text, width) {
        const lines = [];
        text.split(/\r?\n/).forEach(paragraph => {
            if (!paragraph.trim()) { lines.push(''); return; }
            const tokens = [];
            let offset = 0;
            for (const match of paragraph.matchAll(/(?:[A-Za-z0-9_]\p{M}*)+(?:[-./](?:[A-Za-z0-9_]\p{M}*)+)*|\s+/gu)) {
                tokens.push(...pieces(paragraph.slice(offset, match.index)), match[0]);
                offset = match.index + match[0].length;
            }
            tokens.push(...pieces(paragraph.slice(offset)));
            let line = '';
            tokens.forEach(token => {
                if (ctx.measureText(line + token).width <= width) { line += token; return; }
                if (line.trim()) lines.push(line.trimEnd());
                line = '';
                if (!token.trim()) return;
                if (ctx.measureText(token).width <= width) { line = token; return; }
                pieces(token).forEach(char => { if (line && ctx.measureText(line + char).width > width) { lines.push(line); line = ''; } line += char; });
            });
            if (line.trim()) lines.push(line.trimEnd());
        });
        return lines;
    }
    async function draw(expected) {
        const s = values();
        translate();
        $('colors').hidden = s.background === 'image'; $('secondary').hidden = s.background !== 'gradient';
        $('direction-wrap').hidden = s.background !== 'gradient'; $('image-wrap').hidden = s.background !== 'image';
        $('default-wrap').hidden = !['default', 'content_then_default'].includes(s.mode);
        const useDefault = s.mode === 'default';
        $('title').closest('.apimg-field').hidden = useDefault;
        root.querySelectorAll('[data-apimg-sample]').forEach(button => { button.hidden = useDefault; });
        $('server-preview').hidden = useDefault; $('default-preview').hidden = true;
        $('preview-mode-hint').textContent = useDefault ? t('defaultPreviewHint') : (s.mode === 'title' ? '' : t('previewModeHint'));
        if (useDefault) {
            canvas.hidden = true; message(t('loading'));
            try {
                if (!defaultUrl) throw new Error(t('needDefault'));
                const image = await loadImage(defaultUrl);
                if (expected !== version) return;
                $('default-preview').src = defaultUrl; $('default-preview').hidden = false;
                $('preview-meta').textContent = image.naturalWidth + ' × ' + image.naturalHeight; message();
            } catch (error) { if (expected === version) { $('preview-meta').textContent = ''; message(error.message || t('needDefault')); } }
            return;
        }
        const invalid = validate(s);
        if (invalid) { message(invalid); return; }
        message(t('loading'));
        try {
            const font = await family(s);
            const image = s.background === 'image' ? await loadImage(imageUrl || (() => { throw new Error(t('needImage')); })()) : null;
            if (expected !== version || canvas.hidden) return;
            canvas.width = s.width; canvas.height = s.height;
            let fill = s.color1;
            if (s.background === 'gradient') {
                const point = s.direction === 'horizontal' ? [s.width, 0] : s.direction === 'vertical' ? [0, s.height] : [s.width, s.height];
                fill = ctx.createLinearGradient(0, 0, ...point); fill.addColorStop(0, s.color1); fill.addColorStop(1, s.color2);
            }
            ctx.fillStyle = fill; ctx.fillRect(0, 0, s.width, s.height);
            if (image) {
                const scale = Math.max(s.width/image.width, s.height/image.height), w = image.width*scale, h = image.height*scale;
                ctx.drawImage(image, (s.width-w)/2, (s.height-h)/2, w, h);
                ctx.fillStyle = 'rgba(0,0,0,' + s.overlay/100 + ')'; ctx.fillRect(0, 0, s.width, s.height);
            }
            let text = $('title').value, truncated = false;
            const chars = pieces(text);
            if (s.maxCharacters && chars.length > s.maxCharacters) { text = chars.slice(0, Math.max(0, s.maxCharacters-1)).join('') + '…'; truncated = true; }
            let low = s.minFontSize, high = s.fontSize, best = null;
            while (low <= high) {
                const size = Math.floor((low+high)/2); ctx.font = size + 'px ' + font;
                const lines = wrap(text, s.width-s.padding*2);
                if (lines.length <= s.maxLines && lines.length*size*s.lineHeight/100 <= s.height-s.padding*2 && lines.every(line => ctx.measureText(line).width <= s.width-s.padding*2)) { best = {size, lines}; low = size+1; } else high = size-1;
            }
            if (!best && s.overflow === 'ellipsis') {
                ctx.font = s.minFontSize + 'px ' + font;
                const width = s.width-s.padding*2, allowed = Math.min(s.maxLines, Math.floor((s.height-s.padding*2)/(s.minFontSize*s.lineHeight/100)));
                if (allowed < 1 || ctx.measureText('…').width > width) throw new Error(t('tooLong'));
                const lines = wrap(text, width).slice(0, allowed), last = lines.length-1, tail = pieces(lines[last]);
                while (tail.length && ctx.measureText(tail.join('').trimEnd() + '…').width > width) tail.pop();
                lines[last] = tail.join('').trimEnd() + '…';
                if (lines.some(line => ctx.measureText(line).width > width)) throw new Error(t('tooLong'));
                best = {size:s.minFontSize, lines}; truncated = true;
            }
            if (!best) throw new Error(t('tooLong'));
            ctx.font = best.size + 'px ' + font; ctx.fillStyle = s.textColor; ctx.textAlign = s.alignment;
            const x = s.alignment === 'left' ? s.padding : s.alignment === 'right' ? s.width-s.padding : s.width/2;
            const step = best.size*s.lineHeight/100, blockHeight = best.lines.length*step;
            const top = s.verticalAlignment === 'top' ? s.padding : s.verticalAlignment === 'bottom' ? s.height-s.padding-blockHeight : (s.height-blockHeight)/2;
            best.lines.forEach((line, index) => {
                const box = ctx.measureText(line), center = top + (index+0.5)*step;
                ctx.fillText(line, x, center+(box.actualBoundingBoxAscent-box.actualBoundingBoxDescent)/2);
            });
            $('preview-meta').textContent = s.width + ' × ' + s.height + ' · ' + s.format.toUpperCase() + ' · ' + best.size + ' px · ' + best.lines.length + ' ' + t('lines') + (truncated ? ' · ' + t('shortened') : '');
            message();
        } catch (error) { if (expected === version && !canvas.hidden) message(error.message || t('invalid'));  }
    }
    function schedule() {
        version++; cancelAnimationFrame(frame);
        if (request) { request.abort(); request = null; }
        $('server-image').hidden = true;
        canvas.hidden = false;
        const expected = version;
        frame = requestAnimationFrame(() => draw(expected));
    }
    form.addEventListener('input', event => {
        const s = values(), id = event.target.id;
        if (id === 'apimg-lockRatio' && s.lockRatio) aspect = s.width/s.height;
        if (s.lockRatio && Number.isFinite(aspect) && aspect > 0) {
            if (id === 'apimg-width') $('height').value = Math.round(s.width/aspect);
            if (id === 'apimg-height') $('width').value = Math.round(s.height*aspect);
        }
        schedule();
    });
    $('title').addEventListener('input', schedule);
    root.querySelectorAll('[data-apimg-sample]').forEach(button => button.addEventListener('click', () => { $('title').value = button.dataset.apimgSample; schedule(); }));
    $('choose-image').addEventListener('click', () => {
        if (!media) {
            media = wp.media({title:t('chooseImage'), library:{type:'image'}, multiple:false});
            media.on('select', () => {
                const selected = media.state().get('selection').first().toJSON();
                $('image_id').value = selected.id; imageUrl = selected.url;
                $('image-name').textContent = selected.filename || selected.title || ''; schedule();
            });
        }
        media.open();
    });
    $('remove-image').addEventListener('click', () => { $('image_id').value = '0'; imageUrl = ''; $('image-name').textContent = ''; schedule(); });
    let defaultMedia = null;
    $('choose-default').addEventListener('click', () => {
        if (!defaultMedia) {
            defaultMedia = wp.media({title:t('chooseDefault'), library:{type:'image'}, multiple:false});
            defaultMedia.on('select', () => {
                const selected = defaultMedia.state().get('selection').first().toJSON();
                $('default_id').value = selected.id; defaultUrl = selected.url;
                $('default-name').textContent = selected.filename || selected.title || ''; schedule();
            });
        }
        defaultMedia.open();
    });
    $('remove-default').addEventListener('click', () => { $('default_id').value = '0'; defaultUrl = ''; $('default-name').textContent = ''; schedule(); });
    let batchRunning = false, stopRequested = false;
    form.addEventListener('submit', event => { if (batchRunning) event.preventDefault(); });
    $('batch-stop').addEventListener('click', () => {
        stopRequested = true; $('batch-stop').disabled = true;
        $('batch-status').textContent = t('batchStopping');
    });
    $('batch-start').addEventListener('click', async () => {
        if (batchRunning) return;
        batchRunning = true; stopRequested = false;
        $('batch-start').disabled = true; $('batch-stop').disabled = false;
        $('batch-category').disabled = true; form.querySelector('[type=submit]').disabled = true;
        $('batch-results').replaceChildren();
        let cursor = 0, maximum = 0, styleHash = '', processed = 0, successes = 0, failures = 0, done = false;
        const category = $('batch-category').value;
        const summary = () => processed + ' · ' + t('batchSuccess') + ' ' + successes + ' · ' + t('batchErrors') + ' ' + failures;
        try {
            while (!done && !stopRequested) {
                $('batch-status').textContent = t('batchWorking') + ' ' + summary();
                const body = new URLSearchParams({action:'auto_post_image_batch', nonce:config.batchNonce,
                    cursor:String(cursor), maximum:String(maximum), category, styleHash});
                const response = await fetch(config.ajax, {method:'POST', credentials:'same-origin', body});
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.data?.message || t('batchFailed'));
                const data = result.data;
                if (!Number.isSafeInteger(data.cursor) || !Number.isSafeInteger(data.maximum) || !Number.isSafeInteger(data.processed) ||
                    typeof data.done !== 'boolean' || !Array.isArray(data.results) || data.processed !== data.results.length || data.processed > 2 ||
                    data.cursor < cursor || (!data.done && data.cursor === cursor) || (maximum && data.maximum !== maximum) ||
                    typeof data.styleHash !== 'string' || (styleHash && data.styleHash !== styleHash)) throw new Error(t('batchFailed'));
                cursor = data.cursor; maximum = data.maximum; styleHash = data.styleHash; processed += data.processed; done = data.done;
                data.results.forEach(item => {
                    if (!Number.isSafeInteger(item.id) || typeof item.title !== 'string' || typeof item.status !== 'string') throw new Error(t('batchFailed'));
                    if (['generated', 'content', 'default'].includes(item.status)) successes++;
                    if (item.status === 'error') failures++;
                    const row = document.createElement('tr'), article = document.createElement('td'), outcome = document.createElement('td');
                    article.textContent = item.id + ' · ' + item.title;
                    outcome.textContent = item.message || t('result_' + item.status);
                    row.append(article, outcome); $('batch-results').append(row);
                });
                while ($('batch-results').children.length > 100) $('batch-results').firstElementChild.remove();
            }
            $('batch-status').textContent = (done ? t('batchComplete') : t('batchStopped')) + ' ' + summary();
        } catch (error) { $('batch-status').textContent = error.message || t('batchFailed'); }
        finally {
            batchRunning = false; $('batch-start').disabled = false; $('batch-stop').disabled = true;
            $('batch-category').disabled = false; form.querySelector('[type=submit]').disabled = false;
        }
    });
    $('server-preview').addEventListener('click', async () => {
        if (request) request.abort();
        const controller = new AbortController(), expected = version, style = values();
        request = controller; message(t('serverLoading'));
        const timeout = setTimeout(() => controller.abort(), 30000);
        try {
            const body = new URLSearchParams({action:'auto_post_image_preview', nonce:config.nonce, settings:JSON.stringify(style), title:$('title').value});
            const response = await fetch(config.ajax, {method:'POST', credentials:'same-origin', body, signal:controller.signal});
            const result = await response.json();
            if (expected !== version || request !== controller) return;
            if (!response.ok || !result.success) throw new Error(result.data?.message || t('requestFailed'));
            if (!/^data:image\/(?:png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(result.data.image)) throw new Error(t('requestFailed'));
            $('server-image').src = result.data.image; canvas.hidden = true; $('server-image').hidden = false;
            $('preview-meta').textContent = style.width + ' × ' + style.height + ' · ' + style.format.toUpperCase() + ' · ' + result.data.size + ' px · ' + result.data.lines + ' ' + t('lines') + (result.data.truncated ? ' · ' + t('shortened') : '');
            message(t('rendered'));
        } catch (error) { if (expected === version && request === controller) message(error.name === 'AbortError' ? t('requestFailed') : error.message); }
        finally { clearTimeout(timeout); if (request === controller) request = null; }
    });
    schedule();
})();
