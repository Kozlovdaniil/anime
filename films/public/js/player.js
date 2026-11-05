/**
 * Обвязка плеера локального кинотеатра (консервативный ES5 под старый Tizen).
 *
 * Плеер везде — iframe, но src всегда идёт через наш прокси-фрейм
 * /player/frame: страница источника (aser.pro, kodik, rutube, vk) отдаётся
 * с нашего origin, внутрь внедряется player-shim.js — поэтому фрейм
 * перестаёт быть кросс-доменным: время, перемотка на секунду, плей/пауза
 * и «конец серии» читаются напрямую, а реклама вырезана из HTML
 * и заблокирована. Если прокси невозможен — контроллер отдаёт 302
 * на оригинал, и работает обычный iframe (транспорт при этом прячется).
 *
 * Оверлеи поверх iframe:
 *  - тонкая строка: озвучка, серия, прогресс, время;
 *  - панель (OK): озвучки, сезоны, крупная сетка серий, транспорт
 *    (старт/пауза, ±10 с, точная перемотка кликом/стрелками,
 *    «во весь экран»);
 *  - надстройка быстрого переключения серий ◀ E5 ▶ (видна по действию);
 *  - автопереход к следующей серии по ended.
 *
 * Управление пультом Tizen/Smart TV: стрелки, OK, назад, цветные
 * и медиа-кнопки. Клавиши навешиваются и на document самого фрейма
 * (one-origin) — фокус внутри плеера не «съедает» пульт.
 *
 * Код намеренно написан на ES5 (без стрелок/шаблонных строк),
 * чтобы работать на старых браузерах Tizen.
 */
(function () {
    'use strict';

    var wrap = document.getElementById('player-wrap');
    if (!wrap) return;

    var frameBox = document.getElementById('frame-box');
    var frame = document.getElementById('player-frame');
    var loading = document.getElementById('player-loading');
    var slim = document.getElementById('slim-bar');
    var slimLabel = document.getElementById('slim-label');
    var slimFill = document.getElementById('slim-progress-fill');
    var slimTime = document.getElementById('slim-time');
    var quickEp = document.getElementById('quick-ep');
    var qeLabel = document.getElementById('qe-label');
    var panel = document.getElementById('control-panel');
    var nowPlaying = document.getElementById('now-playing');
    var voiceSelect = document.getElementById('voice-select');
    var dataEl = document.getElementById('player-data');
    var transportRow = document.getElementById('cp-transport');
    var cpTime = document.getElementById('cp-time');
    var progress = document.getElementById('cp-progress');
    var progressFill = document.getElementById('cp-progress-fill');

    // Полная панель управления (озвучки + серии) временно отключена по запросу.
    // Чтобы вернуть — поставьте true (вёрстка #control-panel в watch.html.twig и
    // весь код панели остаются на месте).
    var PANEL_ENABLED = false;

    var playerLink = wrap.getAttribute('data-player-link') || '';
    var titleId = wrap.getAttribute('data-title-id') || '';
    var isSerial = wrap.getAttribute('data-is-serial') === '1';
    // куда слать «просмотренно» (прогресс хранится в БД, а не в localStorage)
    var progressUrl = wrap.getAttribute('data-progress-url') || '';
    var progressToken = wrap.getAttribute('data-progress-token') || '';

    /* ---------- нормализация данных ---------- */

    /**
     * Серии и озвучки приходят из шаблона и массивом, и объектом: Twig
     * sort/map сохраняют исходные ключи, поэтому json_encode иногда отдавал
     * объект ({"0":…,"2":…,"1":…}). Приводим к массиву — от этого зависят
     * .length и обход по индексу. Шаблон теперь тоже отдаёт массив, но
     * закешированные/старые страницы могли сохранить объект.
     */
    function asList(value) {
        if (!value) return [];
        if (Object.prototype.toString.call(value) === '[object Array]') return value;
        var out = [];
        for (var k in value) {
            if (Object.prototype.hasOwnProperty.call(value, k)) out.push(value[k]);
        }
        return out;
    }

    /** Серии — по сезону и номеру (как в шаблоне). */
    function sortEpisodes(list) {
        return list.sort(function (a, b) {
            if (a.s !== b.s) return a.s - b.s;
            return a.e - b.e;
        });
    }

    /** Озвучки — по убыванию приоритета (как в шаблоне). */
    function sortTranslations(list) {
        return list.sort(function (a, b) {
            return (b.priority || 0) - (a.priority || 0);
        });
    }

    var data = { translations: [] };
    if (dataEl) {
        try { data = JSON.parse(dataEl.textContent) || data; } catch (e) {}
        data.translations = sortTranslations(asList(data.translations));
        for (var di = 0; di < data.translations.length; di++) {
            data.translations[di].episodes = sortEpisodes(asList(data.translations[di].episodes));
        }
    }

    var state = {
        translationId: null,
        season: 1,
        episode: 1,
        panelVisible: false,
        focusIndex: 0,
        autoHideTimer: null,
        overlayTimer: null,
        api: null,               // window.__cinemaPlayer из прокси-фрейма
        pageUrl: '',             // src текущего фрейма
        ct: 0,                   // текущая позиция, с (для сохранения)
        pendingSeek: null,       // позиция, которую надо восстановить в плеере
        seekAttempts: 0,         // сколько раз пробовали применить pendingSeek
        savedKey: null,          // ключ серии (s:e), откуда восстановлена позиция
        savedCt: 0,              // восстановленная позиция, с
        advanced: false,         // был автопереход по ended для этой серии
        fakeFs: false,           // «во весь экран» без Fullscreen API
        fsTimer: null,           // отложенная проверка: сработал ли нативный фуллскрин
        fsLock: 0,               // дедуп переключений: играем один раз на жест
        reportedKey: null,       // серия (s:e), которую уже отправили в БД как просмотренную
        lastSave: 0
    };

    var storeKey = 'kino:title:' + titleId;

    /* ---------- хранилище (с фолбэком, если localStorage недоступен) ---------- */

    function saveState() {
        try {
            localStorage.setItem(storeKey, JSON.stringify({
                t: state.translationId,
                s: state.season,
                e: state.episode,
                ct: Math.floor(state.ct) || 0
            }));
        } catch (e) {}
    }

    function loadState() {
        try {
            var raw = localStorage.getItem(storeKey);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /**
     * Отметить текущую серию как просмотренную (в БД, не localStorage).
     * Подсветка серий на карточке и счётчик «просмотрено N из M» считаются
     * кумулятивно по последней серии, поэтому серверу достаточно сезона/номера.
     * Шлём один раз на серию (пока идёт её первый просмотр).
     */
    function reportProgress() {
        if (!isSerial || !progressUrl || !state.translationId) return;
        // прогресс хранится отдельно по каждой озвучке — ключ включает её id
        var key = String(state.translationId) + ':' + String(state.season) + ':' + String(state.episode);
        if (state.reportedKey === key) return;
        state.reportedKey = key;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', progressUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-TOKEN', progressToken);
        xhr.send('t=' + encodeURIComponent(state.translationId)
            + '&s=' + encodeURIComponent(state.season)
            + '&e=' + encodeURIComponent(state.episode));
    }

    /**
     * Параметры текущего URL: ?t=озвучка&s=сезон&e=серия.
     * Позволяют дать ссылку на конкретную серию/озвучку; имеют приоритет
     * над сохранённым состоянием.
     */
    function readQuery() {
        var out = {};
        var q = window.location.search || '';
        if (q.charAt(0) === '?') q = q.slice(1);
        // ссылку могли открыть с HTML-сущностью (&amp; вместо &) — иначе
        // параметры разберутся как «amp;s» и серия/озвучка выберутся неверно
        q = q.replace(/&amp;/g, '&');
        if (!q) return out;
        var parts = q.split('&');
        for (var i = 0; i < parts.length; i++) {
            if (!parts[i]) continue;
            var eq = parts[i].indexOf('=');
            var k = eq === -1 ? parts[i] : parts[i].slice(0, eq);
            var v = eq === -1 ? '' : parts[i].slice(eq + 1);
            try {
                k = decodeURIComponent(k.replace(/\+/g, ' '));
                v = decodeURIComponent(v.replace(/\+/g, ' '));
            } catch (e) {}
            if (k) out[k] = v;
        }
        return out;
    }

    /** Отразить текущую озвучку/серию в адресной строке (ссылка на момент). */
    function syncUrl() {
        try {
            if (!window.history || !window.history.replaceState) return;
            var q = '?t=' + encodeURIComponent(state.translationId)
                + (isSerial ? '&s=' + encodeURIComponent(state.season)
                    + '&e=' + encodeURIComponent(state.episode) : '');
            window.history.replaceState(null, '', window.location.pathname + q);
        } catch (e) {}
    }

    /* ---------- поиск по DOM ---------- */

    function $id(id) { return document.getElementById(id); }

    function visibleFocusables() {
        var els = panel.querySelectorAll('.tv-focus');
        var out = [];
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            var p = el;
            var hidden = false;
            while (p && p !== document.body) {
                if (p.hidden || (p.className && (' ' + p.className + ' ').indexOf(' hidden ') !== -1)) { hidden = true; break; }
                p = p.parentNode;
            }
            if (!hidden) out.push(el);
        }
        return out;
    }

    function applyFocus() {
        var els = visibleFocusables();
        if (!els.length) return;
        if (state.focusIndex >= els.length) state.focusIndex = 0;
        if (state.focusIndex < 0) state.focusIndex = els.length - 1;
        var el = els[state.focusIndex];
        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
        // прокрутка внутри панели к фокусу
        if (el.scrollIntoView) {
            try { el.scrollIntoView({ block: 'nearest' }); } catch (e) { el.scrollIntoView(false); }
        }
    }

    /**
     * Геометрическая навигация по панели: dx/dy — направление,
     * кандидаты отбираются по offsetTop/offsetLeft (работает и для
     * строк-чипов, и для сетки серий).
     */
    function moveFocus(dx, dy) {
        var els = visibleFocusables();
        if (!els.length) return;
        var cur = els[state.focusIndex] || els[0];
        var ct = cur.offsetTop, cl = cur.offsetLeft;
        var best = null, bestScore = Infinity;

        for (var i = 0; i < els.length; i++) {
            var cand = els[i];
            if (cand === cur) continue;
            var t = cand.offsetTop, l = cand.offsetLeft;
            var primary = 0, secondary = 0, ok = false;

            if (dx > 0 && l > cl) { ok = true; primary = l - cl; secondary = Math.abs(t - ct); }
            else if (dx < 0 && l < cl) { ok = true; primary = cl - l; secondary = Math.abs(t - ct); }
            else if (dy > 0 && t > ct) { ok = true; primary = t - ct; secondary = Math.abs(l - cl); }
            else if (dy < 0 && t < ct) { ok = true; primary = ct - t; secondary = Math.abs(l - cl); }
            if (!ok) continue;

            var score = primary + secondary * 3;
            if (score < bestScore) { bestScore = score; best = cand; }
        }

        if (best) {
            state.focusIndex = els.indexOf(best);
            applyFocus();
        }
    }

    /* ---------- панель ---------- */

    function showPanel() {
        if (!PANEL_ENABLED) return; // панель временно отключена
        panel.classList.remove('hidden');
        state.panelVisible = true;
        slim.classList.add('hidden');       // панель перекрывает нижнюю зону
        quickEp.classList.add('hidden');
        applyFocus();
        resetAutoHide();
    }

    function hidePanel() {
        panel.classList.add('hidden');
        state.panelVisible = false;
        if (state.autoHideTimer) { clearTimeout(state.autoHideTimer); state.autoHideTimer = null; }
        flashOverlays();
    }

    function resetAutoHide() {
        if (state.autoHideTimer) clearTimeout(state.autoHideTimer);
        state.autoHideTimer = setTimeout(function () {
            if (state.panelVisible) hidePanel();
        }, 12000);
    }

    function goToCard() {
        var a = wrap.querySelector('.watch-topbar a');
        if (a) window.location.href = a.href;
    }

    /** Открыть панель и сразу выставить фокус на сетку серий (синяя кнопка). */
    function focusEpisodes() {
        if (!PANEL_ENABLED) return; // панель временно отключена
        showPanel();
        var els = visibleFocusables();
        for (var i = 0; i < els.length; i++) {
            var p = els[i].parentNode;
            if (p && p.id === 'cp-episodes') { state.focusIndex = i; break; }
        }
        applyFocus();
    }

    /* ---------- тонкая строка времени + надстройка серий ---------- */

    function hasApi() {
        try { return !!(state.api && state.api.ready()); } catch (e) { return false; }
    }

    function flashOverlays() {
        if (state.api && hasApi()) slim.classList.remove('hidden');
        else slim.classList.add('hidden');
        if (isSerial && !state.panelVisible) quickEp.classList.remove('hidden');
        else quickEp.classList.add('hidden');

        if (state.overlayTimer) clearTimeout(state.overlayTimer);
        state.overlayTimer = setTimeout(function () {
            if (state.panelVisible) return;
            var paused = false;
            try { paused = state.api && state.api.paused(); } catch (e) {}
            if (!paused) {
                slim.classList.add('hidden');
                quickEp.classList.add('hidden');
            }
        }, 6000);
    }

    function fmtTime(sec) {
        sec = Math.floor(sec || 0);
        if (sec < 0) sec = 0;
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        var ms = (h > 0 && m < 10 ? '0' : '') + m;
        var ss = (s < 10 ? '0' : '') + s;
        return (h > 0 ? h + ':' : '') + ms + ':' + ss;
    }

    function updateTime(cur, dur) {
        var txt = fmtTime(cur) + ' / ' + (dur > 0 ? fmtTime(dur) : '…');
        slimTime.textContent = txt;
        cpTime.textContent = txt;
        var pct = dur > 0 ? Math.min(100, cur / dur * 100) : 0;
        slimFill.style.width = pct + '%';
        progressFill.style.width = pct + '%';
    }

    /* ---------- подсказка/загрузка ---------- */

    function showLoading(text) {
        loading.textContent = text || 'Загрузка плеера…';
        loading.classList.remove('error');
        loading.classList.remove('hidden');
    }

    function showError(text) {
        loading.textContent = text || 'Не удалось загрузить видео.';
        loading.classList.add('error');
        loading.classList.remove('hidden');
    }

    function hideLoading() { loading.classList.add('hidden'); }

    /* ---------- построение ссылки плеера ---------- */

    /**
     * Ссылки из HTML/старых данных иногда содержат HTML-сущность `&amp;`
     * вместо `&` (двойное экранирование). Раскодируем, иначе апстрим
     * получает битый URL (`...&amp;episode=1`) и адресная строка плеера
     * показывает ссылку с `&amp;`.
     */
    function fixUrl(url) {
        return String(url || '').replace(/&amp;/g, '&');
    }

    function currentTranslation() {
        for (var i = 0; i < data.translations.length; i++) {
            if (String(data.translations[i].id) === String(state.translationId)) return data.translations[i];
        }
        return data.translations[0] || null;
    }

    function findCurrentEpisode() {
        var tr = currentTranslation();
        if (!isSerial || !tr || !tr.episodes) return null;
        for (var i = 0; i < tr.episodes.length; i++) {
            if (tr.episodes[i].s === state.season && tr.episodes[i].e === state.episode) {
                return tr.episodes[i];
            }
        }
        return null;
    }

    /** Есть ли текущая серия (state.season/state.episode) в этой озвучке. */
    function translationHasEpisode(tr) {
        if (!isSerial) return true;             // у фильма серий нет — озвучка всегда доступна
        if (!tr || !tr.episodes) return false;
        for (var i = 0; i < tr.episodes.length; i++) {
            if (tr.episodes[i].s === state.season && tr.episodes[i].e === state.episode) return true;
        }
        return false;
    }

    function buildSrc() {
        var tr = currentTranslation();
        var ep = findCurrentEpisode();

        // 1) готовая ссылка на серию (amedia: aser.pro, kodik-озвучки: kodikplayer.com)
        if (ep && ep.link) return fixUrl(ep.link);

        // 2) базовая ссылка озвучки (фильмы и запасной путь)
        var base = fixUrl((tr && tr.link) || playerLink);
        if (!base) return '';
        if (!isSerial) return base;

        // 3) старое поведение Kodik API: дописываем season/episode к базовой ссылке
        var params = [];
        if (!(tr && tr.link) && state.translationId) {
            params.push('translation_id=' + encodeURIComponent(state.translationId));
        }
        params.push('season=' + encodeURIComponent(state.season));
        params.push('episode=' + encodeURIComponent(state.episode));

        var sep = base.indexOf('?') === -1 ? '?' : '&';
        return base + sep + params.join('&');
    }

    function updateNowPlaying() {
        var parts = [];
        var tr = currentTranslation();
        if (tr) parts.push(tr.name);
        if (isSerial) parts.push('S' + state.season + 'E' + state.episode);
        var txt = parts.join(' · ');
        nowPlaying.textContent = txt;
        slimLabel.textContent = txt;
        updateQuickEp();
    }

    /** Метка надстройки быстрого переключения серий (+ селект в той же надстройке). */
    function updateQuickEp() {
        if (!isSerial) { qeLabel.textContent = ''; return; }
        var tr = currentTranslation();
  
        var eps = (tr && tr.episodes) ? tr.episodes : [];
           
        var multi = false;
        for (var i = 1; i < eps.length; i++) {
            if (eps[i].s !== eps[0].s) { multi = true; break; }
        }
        qeLabel.textContent = multi
            ? 'S' + state.season + 'E' + state.episode
            : 'Серия ' + state.episode;
        renderQuickSelect(tr, eps, multi);
    }

    /**
     * Селект выбора серии внутри надстройки. options перестраиваем только
     * когда изменился состав серий (смена озвучки/размера), иначе просто
     * отмечаем текущую серию — чтобы не сбрасывать открытый dropdown.
     */
    function renderQuickSelect(tr, eps, multi) {
        var sel = $id('qe-select');
        if (!sel) return;
        var len = eps.length;
        var sig = (tr ? String(tr.id) : '') + ':' + len;
        if (sel.getAttribute('data-sig') !== sig) {
            sel.innerHTML = '';
            for (var i = 0; i < len; i++) {
                var op = document.createElement('option');
                op.value = eps[i].s + ':' + eps[i].e;
                op.textContent = multi
                    ? ('S' + eps[i].s + ' · E' + eps[i].e)
                    : ('Серия ' + eps[i].e);
                
                sel.appendChild(op);
            }
            sel.setAttribute('data-sig', sig);
        }
        sel.value = state.season + ':' + state.episode;

        // числовое поле «прыжок к серии»: не трогаем, пока в нём набирают
        var inp = $id('qe-input');
        if (inp) {
            var maxE = 0;
            for (var q = 0; q < eps.length; q++) { if (eps[q].e > maxE) maxE = eps[q].e; }
            inp.max = maxE > 0 ? maxE : '';
            if (document.activeElement !== inp) inp.value = state.episode;
        }
    }

    /**
     * Прыжок к серии по введённому номеру. Ищем номер сначала в текущем
     * сезоне, затем в любом другом — чтобы не листать длинный список.
     */
    function jumpToEpisode(num) {
        if (!isSerial || !num || num < 1) return false;
        var tr = currentTranslation();
        var eps = (tr && tr.episodes) ? tr.episodes : [];
        var found = null, i;
        for (i = 0; i < eps.length; i++) {
            if (eps[i].s === state.season && eps[i].e === num) { found = eps[i]; break; }
        }
        if (!found) {
            for (i = 0; i < eps.length; i++) {
                if (eps[i].e === num) { found = eps[i]; break; }
            }
        }
        if (!found) return false;

        state.season = found.s;
        state.episode = found.e;
        state.pendingSeek = null;
        state.ct = 0;
        state.savedKey = null;
        renderPanel();
        loadCurrent();
        flashOverlays();
        return true;
    }

    /* ---------- выбор озвучки в топбаре ---------- */

    /**
     * Заполнить селект озвучки: все озвучки тайтла, выбранная — текущая.
     * Пункт озвучки, в которой нет текущей серии, — disabled; если
     * переключаться некуда, disabled весь селект. Перестраиваем DOM
     * только при смене набора озвучек или серии.
     */
    function renderVoiceSelect() {
        if (!voiceSelect) return;
        var list = data.translations || [];

        var sig = (isSerial ? '1' : '0') + ':' + state.season + ':' + state.episode;
        for (var k = 0; k < list.length; k++) sig += '|' + list[k].id;

        if (voiceSelect.getAttribute('data-sig') !== sig) {
            voiceSelect.innerHTML = '';
            for (var i = 0; i < list.length; i++) {
                var tr = list[i];
                var op = document.createElement('option');
                op.value = tr.id;
                var opText = tr.name + (tr.techTitle ? ' (' + tr.techTitle + ')' : '');
                if (data.episodeTotal > 0) {
                    opText += ' · ' + (tr.episodes ? tr.episodes.length : 0) + '/' + data.episodeTotal;
                }
                op.textContent = opText;
                if (!translationHasEpisode(tr)) {
                    op.disabled = true;
                    op.title = 'Этой серии нет в данной озвучке';
                }
                voiceSelect.appendChild(op);
            }
            voiceSelect.setAttribute('data-sig', sig);
        }

        var cur = currentTranslation();
        if (cur) voiceSelect.value = cur.id;

        // селект активен только если есть другая озвучка с этой серией
        var alternatives = 0;
        for (var j = 0; j < list.length; j++) {
            if (String(list[j].id) !== String(state.translationId) && translationHasEpisode(list[j])) alternatives++;
        }
        voiceSelect.disabled = alternatives === 0;
    }

    /** Перейти на ту же серию в выбранной озвучке (без перезагрузки страницы). */
    function switchTranslation(newId) {
        if (String(newId) === String(state.translationId)) return;

        var tr = null;
        for (var i = 0; i < data.translations.length; i++) {
            if (String(data.translations[i].id) === String(newId)) { tr = data.translations[i]; break; }
        }
        if (!tr || !translationHasEpisode(tr)) { renderVoiceSelect(); return; }

        state.translationId = tr.id;
        state.pendingSeek = null;
        state.ct = 0;
        state.savedKey = null;
        renderPanel();
        loadCurrent();
        flashOverlays();
    }

    /* ---------- связь с прокси-фреймом (player-shim.js) ---------- */

    /** Найти __cinemaPlayer, заглядывая и во вложенные same-origin фреймы. */
    function findApi(win, depth) {
        if (!win || depth > 3) return null;
        try {
            var a = win.__cinemaPlayer;
            if (a && typeof a.ready === 'function' && a.ready()) return a;
            var n = win.frames ? win.frames.length : 0;
            for (var i = 0; i < n; i++) {
                var r = findApi(win.frames[i], depth + 1);
                if (r) return r;
            }
        } catch (e) { /* кросс-доменный фрейм — пропускаем */ }
        return null;
    }

    /**
     * Наш обработчик клавиш навешивается и на document фрейма (он same-origin
     * благодаря прокси): пульт продолжает работать, даже когда фокус ушёл
     * внутрь плеера. Туда же пересылаем движение мыши — события внутри
     * iframe не всплывают наружу, иначе надстройки не показываются
     * по курсору над видео (в том числе в полноэкранном режиме).
     */
    function bindFrameKeys() {
        try {
            var fdoc = frame.contentDocument;
            if (fdoc && !fdoc.__cinemaKeysBound) {
                fdoc.__cinemaKeysBound = true;
                fdoc.addEventListener('keydown', onKey, false);
            }
            if (fdoc && !fdoc.__cinemaMoveBound) {
                fdoc.__cinemaMoveBound = true;
                fdoc.addEventListener('mousemove', onActivityMove, false);
            }
            // кнопка «во весь экран» самого плеера — ведём на наш CSS-фуллскрин
            if (fdoc && !fdoc.__cinemaFsBound) {
                fdoc.__cinemaFsBound = true;
                fdoc.addEventListener('click', onFrameFsClick, true);
            }
            // двойной клик по видео — тоже наш фуллскрин (нативный в фрейме
            // на Tizen «залипает»: плеер думает, что уже развёрнут)
            if (fdoc && !fdoc.__cinemaFsDblBound) {
                fdoc.__cinemaFsDblBound = true;
                fdoc.addEventListener('dblclick', onFrameFsDbl, true);
            }
        } catch (e) { /* 302-фолбэк на чужой origin — не навешиваемся */ }
    }

    function onApiReady() {
        transportRow.hidden = false;
        bindFrameKeys();
        updatePlayButton();
        flashOverlays();
    }

    /** Восстановление позиции: плеер может отдать duration не сразу — пробуем до 10 раз. */
    function applyPendingSeek(api, dur) {
        if (state.pendingSeek === null) return;
        var target = state.pendingSeek;
        var applied = false;

        if (dur > 0) {
            if (target >= dur - 5) {
                // позиция дальше конца (серия сменилась раньше) — не восстанавливаем
                state.pendingSeek = null;
                state.seekAttempts = 0;
                return;
            }
            try { api.seek(target); } catch (e) {}
            applied = Math.abs((api.time() || 0) - target) < 30;
        }

        state.seekAttempts++;
        if (applied || state.seekAttempts >= 10) {
            if (applied) {
                state.ct = target;
                state.lastSave = new Date().getTime();
                saveState();
            }
            state.pendingSeek = null;
            state.seekAttempts = 0;
        }
    }

    /**
     * Основной такт (800 мс): переподключаемся к шиму, читаем время/паузу,
     * обновляем оверлеи, сохраняем позицию, ловим конец серии.
     */
    function tick() {
        if (!frame.getAttribute('src')) return;

        var api = findApi(frame.contentWindow, 0);
        if (!api) {
            if (state.api) {
                // документ фрейма сменился/стал чужим — контролы прячем
                state.api = null;
                transportRow.hidden = true;
            }
            return;
        }
        if (api !== state.api) {
            state.api = api;
            onApiReady();
        }

        var t = 0, d = 0, paused = true, ended = false;
        try {
            t = api.time();
            d = api.duration();
            paused = api.paused();
            ended = api.ended();
        } catch (e) {
            state.api = null;
            transportRow.hidden = true;
            return;
        }

        if (state.pendingSeek !== null) applyPendingSeek(api, d);

        updateTime(t, d);
        updatePlayButton(paused);
        state.ct = t;

        // досмотрели хотя бы пару секунд — серия считается просмотренной
        if (isSerial && t >= 3) reportProgress();

        var now = new Date().getTime();
        if (now - state.lastSave > 5000) {
            state.lastSave = now;
            saveState();
        }

        if (ended && !state.advanced && state.pendingSeek === null) {
            state.advanced = true;
            if (isSerial && stepEpisode(1)) {
                showLoading('Следующая серия…');
            } else if (!isSerial) {
                flashOverlays();
            } else {
                showPanel(); // последняя серия — открыть выбор
            }
        }
    }

    /* ---------- загрузка фрейма ---------- */

    function loadCurrent() {
        updateNowPlaying();
        renderVoiceSelect();
        syncUrl();
        saveState();

        var src = buildSrc();
        if (!src) { showError('Нет ссылки на плеер.'); return; }

        // восстановление позиции — только для той серии, откуда её взяли
        var key = state.season + ':' + state.episode;
        if (state.savedKey && key === state.savedKey && state.savedCt > 0) {
            state.pendingSeek = state.savedCt;
            state.ct = state.savedCt;
            state.savedKey = null;
            state.savedCt = 0;
        } else {
            state.pendingSeek = null;
            state.ct = 0;
        }
        state.seekAttempts = 0;
        state.api = null;
        state.advanced = false;
        state.reportedKey = null;
        transportRow.hidden = true;

        // прокси сам решит, проксировать или 302 на оригинал (обычный iframe)
        var proxied = '/player/frame?url=' + encodeURIComponent(src);
        state.pageUrl = proxied;
        showLoading('Загрузка плеера…');
        flashOverlays();
        frame.src = proxied;
    }

    /* ---------- транспорт (через шим внутри фрейма) ---------- */

    function togglePlay() {
        if (!hasApi()) return false;
        try { state.api.toggle(); } catch (e) {}
        updatePlayButton();
        flashOverlays();
        return true;
    }

    function seek(delta) {
        if (!hasApi()) return false;
        var t = 0, d = 0;
        try {
            d = state.api.duration();
            t = (state.api.time() || 0) + delta;
        } catch (e) { return false; }
        if (t < 0) t = 0;
        if (d > 0 && t > d - 1) t = d - 1;
        try { state.api.seek(t); } catch (e) {}

        state.pendingSeek = null;      // ручная перемотка отменяет восстановление
        state.seekAttempts = 0;
        state.ct = t;
        updateTime(t, d);
        state.lastSave = new Date().getTime();
        saveState();
        flashOverlays();
        return true;
    }

    /** Перемотка по доле длительности (клик по полосе прогресса). */
    function seekFraction(frac) {
        if (!hasApi()) return false;
        var d = 0;
        try { d = state.api.duration(); } catch (e) { return false; }
        if (!d) return false;
        var t = 0;
        try { t = state.api.time() || 0; } catch (e) {}
        return seek(frac * d - t);
    }

    function updatePlayButton(paused) {
        var b = $id('btn-play');
        if (!b || transportRow.hidden) return;
        if (typeof paused !== 'boolean') {
            try { paused = state.api ? state.api.paused() : true; } catch (e) { paused = true; }
        }
        b.textContent = paused ? '▶ Старт' : '⏸ Пауза';
    }

    /* ---------- «во весь экран» ---------- */

    function fsElement() {
        return document.fullscreenElement || document.webkitFullscreenElement ||
            document.mozFullScreenElement || document.msFullscreenElement || null;
    }

    /**
     * Одно действие пользователя (кнопка/двойной клик по плееру) приходит
     * дважды: нашему перехватчику и обработчику плеера внутри фрейма (тот
     * зовёт requestFullscreen, который шим переадресует сюда). Гасим
     * повторные срабатывания в пределах короткого окна, иначе фуллскрин
     * «мигает» (включился и тут же выключился).
     */
    function fsLockNow() { state.fsLock = new Date().getTime() + 350; }
    function fsLocked() { return new Date().getTime() < state.fsLock; }

    function updateFullscreenBtn() {
        var on = !!(fsElement() || state.fakeFs);
        var label = on ? '⛶ Свернуть' : '⛶ Во весь экран';
        var b = $id('btn-fullscreen');
        if (b) b.textContent = label;
        var g = $id('btn-fullscreen-ghost');
        if (g) {
            g.setAttribute('title', label);
            g.setAttribute('aria-label', label);
        }
    }

    /**
     * Прячем шапку сайта в полноэкранном режиме.
     *
     * Полагаться на CSS-псевдокласс `:fullscreen` нельзя: старый WebKit на
     * Tizen его не применяет к потомкам, и шапка сайта остаётся висеть поверх
     * развёрнутого плеера. Поэтому следим за состоянием сами и вешаем класс
     * на корень документа — он работает на любом движке.
     */
    function syncFsChrome() {
        var on = !!(fsElement() || state.fakeFs);
        var root = document.documentElement;
        if (!root || !root.classList) return;
        if (on) root.classList.add('fs-active');
        else root.classList.remove('fs-active');
    }

    /**
     * Явные размеры «во весь экран» в пикселях.
     *
     * На старых WebKit (Tizen) у `position: fixed` элемента `width: 100%`
     * иногда считается не от окна, и контейнер не растягивается. Поэтому
     * дублируем размеры окна в inline-стиле — это надёжнее.
     */
    function applyFakeFsSize() {
        if (!state.fakeFs) return;
        var w = window.innerWidth || document.documentElement.clientWidth || 0;
        var h = window.innerHeight || document.documentElement.clientHeight || 0;
        frameBox.style.left = '0px';
        frameBox.style.top = '0px';
        frameBox.style.width = (w > 0 ? w : 0) + 'px';
        frameBox.style.height = (h > 0 ? h : 0) + 'px';
    }

    function clearFakeFsSize() {
        frameBox.style.left = '';
        frameBox.style.top = '';
        frameBox.style.width = '';
        frameBox.style.height = '';
    }

    /**
     * После смены размеров контейнера плеер внутри iframe на Tizen может
     * не пересчитать картинку — пинаем его событием `resize`.
     */
    function notifyFrameResize() {
        try {
            var w = frame.contentWindow;
            if (!w || !w.dispatchEvent) return;
            var ev;
            if (typeof w.Event === 'function') {
                ev = new w.Event('resize');
            } else {
                ev = w.document.createEvent('UIEvents');
                ev.initUIEvent('resize', true, false, w, 1);
            }
            w.dispatchEvent(ev);
        } catch (e) { /* документ ещё не готов / чужой origin */ }
    }

    function setFakeFs(on) {
        state.fakeFs = on;
        if (on) {
            frameBox.classList.add('fs-fake');
            applyFakeFsSize();
        } else {
            frameBox.classList.remove('fs-fake');
            clearFakeFsSize();
        }
        if (state.fsTimer) { clearTimeout(state.fsTimer); state.fsTimer = null; }
        updateFullscreenBtn();
        syncFsChrome();
        flashOverlays();
        notifyFrameResize();
        fsLockNow();
    }

    // при смене размера окна обновляем пиксельные размеры «фуллскрина»
    window.addEventListener('resize', function () { applyFakeFsSize(); }, false);


    /** Запрос нативного фуллскрина у элемента (с учётом вендорных префиксов). */
    function requestNativeFs(el) {
        if (!el) return false;
        var req = el.requestFullscreen || el.webkitRequestFullscreen ||
            el.mozRequestFullScreen || el.msRequestFullscreen;
        if (!req) return false;
        try {
            var p = req.call(el);
            if (p && typeof p.catch === 'function') {
                // запрос отклонён (нет жеста/разрешения) — сработает
                // CSS-фолбэк по таймеру в enterFullscreen()
                p.catch(function () {});
            }
            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * «Во весь экран».
     *
     * Настоящий полноэкранный режим (скрыть адресную строку браузера) даёт
     * только нативный Fullscreen API — CSS-режим `fs-fake` лишь растягивает
     * страницу по видимой области, хром браузера остаётся. Поэтому сначала
     * всегда пробуем нативный фуллскрин: от самого надёжного варианта (весь
     * документ — картинка не «чернеет») к самому iframe плеера, и лишь затем к
     * контейнеру (его разворот на части прошивок Tizen давал чёрный экран).
     * Если API нет или запрос отклонён — включаем CSS-режим как резерв.
     */
    function enterFullscreen() {
        if (state.fakeFs || fsElement()) return;

        var targets = [document.documentElement, frame, frameBox];
        var started = false;
        for (var i = 0; i < targets.length && !started; i++) {
            started = requestNativeFs(targets[i]);
        }
        if (!started) { setFakeFs(true); return; } // Fullscreen API нет совсем

        fsLockNow();
        updateFullscreenBtn();
        if (state.fsTimer) clearTimeout(state.fsTimer);
        state.fsTimer = setTimeout(function () {
            state.fsTimer = null;
            if (state.fakeFs) return;
            if (!fsElement()) {
                // запрос не сработал (нет разрешения/жеста) — CSS-режим
                setFakeFs(true);
            }
        }, 500);
    }

    function toggleFullscreen() {
        if (fsLocked()) return; // уже обработали это действие
        if (state.fakeFs) { setFakeFs(false); return; }
        if (fsElement()) { exitFullscreen(); return; }
        enterFullscreen();
    }

    function exitFullscreen() {
        if (state.fakeFs) { setFakeFs(false); return; }
        var exit = document.exitFullscreen || document.webkitExitFullscreen ||
            document.mozCancelFullScreen || document.msExitFullscreen;
        if (exit && fsElement()) {
            try { exit.call(document); } catch (e) {}
            fsLockNow();
        }
    }

    function onNativeFsChange() {
        // Нативный режим вошёл — CSS-фолбэк больше не нужен.
        if (fsElement() && state.fakeFs) setFakeFs(false);
        updateFullscreenBtn();
        syncFsChrome();
        // Контейнер только что изменил размер (шапка/панель скрылись или
        // вернулись) — просим плеер пересчитать картинку.
        notifyFrameResize();
    }

    (function () {
        var events = ['fullscreenchange', 'webkitfullscreenchange',
            'mozfullscreenchange', 'MSFullscreenChange'];
        for (var i = 0; i < events.length; i++) {
            document.addEventListener(events[i], onNativeFsChange, false);
        }
    })();

    // Мост для прокси-фрейма: player-shim.js переадресует сюда все запросы
    // плеера к Fullscreen API (кнопка плеера, двойной клик, клавиша).
    window.__cinemaFullscreen = {
        enter: function () { if (fsLocked()) return; enterFullscreen(); },
        exit: function () { if (fsLocked()) return; exitFullscreen(); },
        toggle: function () { toggleFullscreen(); },
        isOn: function () { return !!(state.fakeFs || fsElement()); }
    };

    /**
     * Внутри прокси-фрейма (плеер Kodik/Flowplayer) кнопка «во весь экран»
     * опирается на нативный Fullscreen API и на Tizen не срабатывает.
     * Перехватываем клик по этой кнопке и включаем наш CSS-фуллскрин.
     */
    function fsControlFrom(el) {
        while (el && el.nodeType === 1) {
            var cls = (typeof el.className === 'string') ? el.className : '';
            var id = el.id || '';
            var tip = '', role = '', tag = el.tagName ? el.tagName.toUpperCase() : '';
            if (el.getAttribute) {
                tip = el.getAttribute('data-tooltip') || el.getAttribute('title') || '';
                role = (el.getAttribute('role') || '').toLowerCase();
            }
            if (/fullscreen|full[_-]screen|во весь экран/i.test(cls + ' ' + id + ' ' + tip)) {
                // только сама кнопка, а не контейнер-состояние (напр. .is-fullscreen)
                if (tag === 'A' || tag === 'BUTTON' || role === 'button') return el;
                if (/full[_-]?screen|во весь экран/i.test(tip)) return el;
            }
            el = el.parentNode;
        }
        return null;
    }

    function onFrameFsClick(ev) {
        if (!fsControlFrom(ev.target)) return;
        ev.preventDefault();
        if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
        ev.stopPropagation();
        toggleFullscreen();
    }

    /**
     * Двойной клик внутри фрейма у Flowplayer (Kodik) включает СВОЙ
     * нативный фуллскрин. На Tizen он «залипает»: плеер считает, что уже
     * развёрнут, а картинка не меняется — дальше двойной клик ничего не
     * делает. Перехватываем событие до плеера и разворачиваем нашим
     * режимом (тем же, что и кнопка «во весь экран»).
     */
    function onFrameFsDbl(ev) {
        ev.preventDefault();
        if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
        ev.stopPropagation();
        toggleFullscreen();
    }

    /* ---------- события фрейма ---------- */

    frame.addEventListener('load', function () {
        hideLoading();
        bindFrameKeys(); // клавиши/мышь внутри нового документа фрейма — сразу
        tick();       // ловим шим сразу — документ уже загружен
        flashOverlays();
    });

    // уход со страницы — дописываем позицию
    window.addEventListener('pagehide', saveState);
    window.addEventListener('beforeunload', saveState);

    /* ---------- рендер панели ---------- */

    function renderPanel() {
        if (!PANEL_ENABLED) return; // панель временно отключена
        var trRow = $id('cp-translation-row');
        var seasonRow = $id('cp-season-row');
        var epBlock = $id('cp-episode-block');

        // озвучки
        var box = $id('cp-translations');
        box.innerHTML = '';
        for (var i = 0; i < data.translations.length; i++) {
            (function (tr) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'chip btn-focus tv-focus';
                b.textContent = tr.name;
                if (String(tr.id) === String(state.translationId)) b.classList.add('active');
                b.onclick = function () {
                    state.translationId = tr.id;
                    state.pendingSeek = null;
                    state.ct = 0;
                    state.savedKey = null;
                    renderPanel();
                    loadCurrent();
                    resetAutoHide();
                };
                box.appendChild(b);
            })(data.translations[i]);
        }
        trRow.hidden = data.translations.length <= 1;

        if (!isSerial) {
            seasonRow.hidden = true;
            epBlock.hidden = true;
            return;
        }

        var tr = currentTranslation();
        var episodes = (tr && tr.episodes) ? tr.episodes : [];

        // сезоны
        var seasons = [];
        for (var j = 0; j < episodes.length; j++) {
            if (seasons.indexOf(episodes[j].s) === -1) seasons.push(episodes[j].s);
        }
        seasonRow.hidden = seasons.length <= 1;

        var sBox = $id('cp-seasons');
        sBox.innerHTML = '';
        for (var k = 0; k < seasons.length; k++) {
            (function (season) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'chip btn-focus tv-focus';
                b.textContent = 'Сезон ' + season;
                if (season === state.season) b.classList.add('active');
                b.onclick = function () {
                    state.season = season;
                    var first = null;
                    for (var m = 0; m < episodes.length; m++) {
                        if (episodes[m].s === season) { first = episodes[m].e; break; }
                    }
                    if (first !== null) state.episode = first;
                    state.pendingSeek = null;
                    state.ct = 0;
                    state.savedKey = null;
                    renderPanel();
                    loadCurrent();
                    resetAutoHide();
                };
                sBox.appendChild(b);
            })(seasons[k]);
        }

        // серии — крупные плитки
        epBlock.hidden = false;
        var eBox = $id('cp-episodes');
        eBox.innerHTML = '';
        for (var n = 0; n < episodes.length; n++) {
            (function (ep) {
                if (ep.s !== state.season) return;
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'chip btn-focus tv-focus';
                b.textContent = ep.e + (ep.name ? ' · ' + ep.name : '');
                if (ep.e === state.episode && ep.s === state.season) b.classList.add('active');
                b.onclick = function () {
                    state.episode = ep.e;
                    state.pendingSeek = null;
                    state.ct = 0;
                    state.savedKey = null;
                    renderPanel();
                    loadCurrent();
                    resetAutoHide();
                };
                eBox.appendChild(b);
            })(episodes[n]);
        }

        if (state.panelVisible) applyFocus();
    }

    /* ---------- переходы между сериями ---------- */

    function stepEpisode(delta) {
        if (!isSerial) return false;
        var tr = currentTranslation();
        if (!tr || !tr.episodes) return false;

        var flat = tr.episodes; // уже отсортировано на сервере/при нормализации
        var idx = -1;
        var len = flat.length;
        for (var j = 0; j < len; j++) {
            if (flat[j].s === state.season && flat[j].e === state.episode) { idx = j; break; }
        }
        var next = idx + delta;
        if (next < 0 || next >= len) return false;

        state.season = flat[next].s;
        state.episode = flat[next].e;
        state.pendingSeek = null;
        state.ct = 0;
        state.savedKey = null;
        renderPanel();
        loadCurrent();
        return true;
    }

    /* ---------- клавиатура / пульт ---------- */

    // Коды Tizen/Smart TV: 10009 — Return/назад, 461 — альтернативный «назад»,
    // 415 — play, 413 — stop/pause, 412 — rewind, 417 — fastForward,
    // 10252 — play/pause (Samsung), 403..406 — цветные кнопки.
    var KEY = {
        LEFT: 37, UP: 38, RIGHT: 39, DOWN: 40, ENTER: 13, ESC: 27, SPACE: 32,
        BACK: 10009, BACK_ALT: 461,
        RED: 403, GREEN: 404, YELLOW: 405, BLUE: 406,
        PAGE_UP: 33, PAGE_DOWN: 34,
        MEDIA_PLAY: 415, MEDIA_PAUSE: 413, MEDIA_TOGGLE: 10252,
        MEDIA_REWIND: 412, MEDIA_FORWARD: 417
    };

    function progressFocused() {
        var ae = document.activeElement;
        return !!(ae && ae.id === 'cp-progress');
    }

    function onKey(ev) {
        var k = ev.keyCode;

        // селект в надстройке: стрелки/Enter/буквы оставляем браузеру
        // (иначе стрелки будут перехватываться плеером/панелью)
        if (ev.target && ev.target.id === 'qe-select') {
            if (k === KEY.BACK || k === KEY.BACK_ALT || k === KEY.ESC) {
                ev.preventDefault();
                ev.target.blur(); // закрыть список и вернуть управление плееру
            }
            return;
        }

        // числовое поле «прыжок к серии»: цифры и Enter оставляем полю,
        // иначе глобальный обработчик перехватил бы ввод и запуск видео
        if (ev.target && ev.target.id === 'qe-input') {
            if (k === KEY.ENTER || k === KEY.BACK || k === KEY.BACK_ALT || k === KEY.ESC) {
                ev.preventDefault();
                ev.target.blur(); // blur фиксирует значение -> change -> переход
            }
            return;
        }

        // Нативные контролы вне панели (кнопки, ссылки, поля) обрабатывает
        // сам браузер: Enter на «+ Смотрю», «◀» или ссылке должен «нажимать»
        // их, а не запускать видео. Панель намеренно не затрагиваем — там
        // стрелками управляет onKey.
        var tg = ev.target;
        if (tg && tg.tagName) {
            var tn = tg.tagName.toLowerCase();
            var isNative = (tn === 'button' || tn === 'a' || tn === 'input'
                || tn === 'select' || tn === 'textarea');
            if (isNative && !(panel && panel.contains(tg))) return;
        }

        /* ----- панель скрыта ----- */
        if (!state.panelVisible) {
            switch (k) {
                case KEY.ENTER:
                case KEY.UP:
                case KEY.DOWN:
                    ev.preventDefault();
                    // Панель отключена: OK/Enter работает как пуск-пауза,
                    // вверх/вниз ничего не делают (раньше открывали панель).
                    if (PANEL_ENABLED) showPanel();
                    else if (k === KEY.ENTER) togglePlay();
                    return;
                case KEY.LEFT:
                    ev.preventDefault();
                    if (!seek(-10)) stepEpisode(-1);
                    return;
                case KEY.RIGHT:
                    ev.preventDefault();
                    if (!seek(10)) stepEpisode(1);
                    return;
                case KEY.SPACE:
                    ev.preventDefault();
                    if (!togglePlay()) showPanel();
                    return;
                case KEY.MEDIA_PLAY:
                    ev.preventDefault();
                    if (hasApi()) { try { state.api.play(); } catch (e) {} flashOverlays(); }
                    return;
                case KEY.MEDIA_PAUSE:
                    ev.preventDefault();
                    if (hasApi()) { try { state.api.pause(); } catch (e) {} flashOverlays(); }
                    return;
                case KEY.MEDIA_TOGGLE:
                    ev.preventDefault();
                    togglePlay();
                    return;
                case KEY.MEDIA_REWIND:
                    ev.preventDefault();
                    seek(-10);
                    return;
                case KEY.MEDIA_FORWARD:
                    ev.preventDefault();
                    seek(10);
                    return;
                case KEY.PAGE_UP:
                case KEY.GREEN:
                    if (stepEpisode(-1)) ev.preventDefault();
                    return;
                case KEY.PAGE_DOWN:
                case KEY.YELLOW:
                    if (stepEpisode(1)) ev.preventDefault();
                    return;
                case KEY.BLUE:
                    ev.preventDefault();
                    if (isSerial) focusEpisodes();
                    else showPanel();
                    return;
                case KEY.RED:
                    ev.preventDefault();
                    goToCard();
                    return;
                case KEY.BACK:
                case KEY.BACK_ALT:
                case KEY.ESC:
                    ev.preventDefault();
                    if (state.fakeFs) { setFakeFs(false); return; }
                    if (fsElement()) { exitFullscreen(); return; }
                    window.history.back();
                    return;
            }
            return; // остальное отдаём плееру/браузеру
        }

        /* ----- панель открыта ----- */
        switch (k) {
            case KEY.LEFT:
                ev.preventDefault();
                if (progressFocused()) seek(-10);
                else moveFocus(-1, 0);
                resetAutoHide();
                break;
            case KEY.RIGHT:
                ev.preventDefault();
                if (progressFocused()) seek(10);
                else moveFocus(1, 0);
                resetAutoHide();
                break;
            case KEY.UP:
                ev.preventDefault();
                moveFocus(0, -1);
                resetAutoHide();
                break;
            case KEY.DOWN:
                ev.preventDefault();
                moveFocus(0, 1);
                resetAutoHide();
                break;
            case KEY.PAGE_UP:
                ev.preventDefault();
                if (progressFocused()) seek(-300); // ±5 мин на полосе перемотки
                else stepEpisode(-1);
                resetAutoHide();
                break;
            case KEY.PAGE_DOWN:
                ev.preventDefault();
                if (progressFocused()) seek(300);
                else stepEpisode(1);
                resetAutoHide();
                break;
            case KEY.ENTER:
                ev.preventDefault();
                if (progressFocused()) hidePanel(); // смотрим с выбранного места
                else {
                    var els = visibleFocusables();
                    if (els[state.focusIndex]) els[state.focusIndex].click();
                }
                resetAutoHide();
                break;
            case KEY.BACK:
            case KEY.BACK_ALT:
            case KEY.ESC:
                ev.preventDefault();
                if (state.fakeFs) { setFakeFs(false); return; }
                hidePanel();
                break;
            case KEY.RED:
                ev.preventDefault();
                goToCard();
                break;
            case KEY.GREEN:
                ev.preventDefault();
                stepEpisode(-1);
                resetAutoHide();
                break;
            case KEY.YELLOW:
                ev.preventDefault();
                stepEpisode(1);
                resetAutoHide();
                break;
            case KEY.BLUE:
                ev.preventDefault();
                if (isSerial) focusEpisodes();
                else showPanel();
                break;
            default:
                resetAutoHide();
        }
    }

    document.addEventListener('keydown', onKey, false);

    /* ---------- мышь (настольная отладка) ---------- */

    frameBox.addEventListener('click', function (ev) {
        var t = ev.target;
        if (panel.contains(t)) return;              // клики по кнопкам панели
        if (quickEp && quickEp.contains(t)) return; // клики по ◀ ▶ надстройки
        if (t === frame) return;                    // iframe — свой пульт внутри
        if (!state.panelVisible) showPanel();
    });

    /** Любое движение мыши (родитель или фрейм) — показать/продлить оверлеи. */
    function onActivityMove() {
        if (state.panelVisible) resetAutoHide();
        else flashOverlays();
    }
    document.addEventListener('mousemove', onActivityMove);

    // точная перемотка кликом по полосе прогресса
    progress.addEventListener('click', function (ev) {
        var frac = 0;
        var rect = progress.getBoundingClientRect ? progress.getBoundingClientRect() : null;
        if (rect && rect.width > 0) frac = (ev.clientX - rect.left) / rect.width;
        else frac = (ev.offsetX || 0) / (progress.offsetWidth || 1);
        if (frac < 0) frac = 0;
        if (frac > 1) frac = 1;
        seekFraction(frac);
        resetAutoHide();
    });

    $id('btn-prev').onclick = function () { stepEpisode(-1); resetAutoHide(); };
    $id('btn-next').onclick = function () { stepEpisode(1); resetAutoHide(); };
    $id('btn-hide').onclick = function () { hidePanel(); };
    $id('btn-play').onclick = function () { togglePlay(); resetAutoHide(); };
    $id('btn-seek-back').onclick = function () { seek(-10); resetAutoHide(); };
    $id('btn-seek-fwd').onclick = function () { seek(10); resetAutoHide(); };
    $id('btn-fullscreen').onclick = function () { toggleFullscreen(); resetAutoHide(); };
    (function () {
        var g = $id('btn-fullscreen-ghost'); // невидимая кнопка в топбаре
        if (g) g.onclick = function () { toggleFullscreen(); };
    })();
    $id('qe-prev').onclick = function () { stepEpisode(-1); flashOverlays(); };
    $id('qe-next').onclick = function () { stepEpisode(1); flashOverlays(); };

    // выбор конкретной серии селектом в той же надстройке
    (function () {
        var sel = $id('qe-select');
        if (!sel) return;
        sel.onchange = function () {
            var v = sel.value.split(':');
            var s = parseInt(v[0], 10), e = parseInt(v[1], 10);
            if (!s || !e) return;
            state.season = s;
            state.episode = e;
            state.pendingSeek = null;
            state.ct = 0;
            state.savedKey = null;
            renderPanel();
            loadCurrent();
            flashOverlays();
        };
        sel.onfocus = flashOverlays; // пока работаем с селектом — надстройку не прячем
    })();

    // выбор озвучки в топбаре: та же серия в другой озвучке
    (function () {
        if (!voiceSelect) return;
        voiceSelect.onchange = function () { switchTranslation(voiceSelect.value); };
    })();

    // числовое поле: фиксируем номер серии и переходим
    (function () {
        var inp = $id('qe-input');
        if (!inp) return;
        inp.onchange = function () {
            var n = parseInt(inp.value, 10);
            // нет такой серии — вернуть текущий номер
            if (!n || !jumpToEpisode(n)) inp.value = state.episode;
            flashOverlays();
        };
        inp.onfocus = function () {
            flashOverlays();            // пока набираем номер — надстройку не прячем
            try { inp.select(); } catch (e) {} // ввод новой цифры заменяет старую
        };
    })();

    /* ---------- старт ---------- */

    (function init() {
        var saved = loadState();
        var qp = readQuery();
        var hasQuery = qp.t !== undefined || qp.s !== undefined || qp.e !== undefined;

        if (data.translations.length) {
            // озвучка: ?t= → сохранённая → первая
            state.translationId = data.translations[0].id;
            var wantT = qp.t !== undefined ? qp.t : (saved ? saved.t : null);
            if (wantT !== null && wantT !== undefined) {
                for (var i = 0; i < data.translations.length; i++) {
                    if (String(data.translations[i].id) === String(wantT)) {
                        state.translationId = data.translations[i].id;
                        break;
                    }
                }
            }

            var tr = currentTranslation();
            var episodes = (tr && tr.episodes) ? tr.episodes : [];
            var lenEp = episodes.length;
            if (lenEp) {
                // сезон/серия: ?s=&e= → сохранённые → последняя просмотренная (БД) → первая
                var wantS = qp.s !== undefined ? parseInt(qp.s, 10) : null;
                var wantE = qp.e !== undefined ? parseInt(qp.e, 10) : null;
                if (wantS === null && wantE === null) {
                    // сохранённую позицию применяем только к той же озвучке:
                    // у разных озвучек/плееров своя нумерация и свой прогресс
                    if (saved && String(saved.t) === String(state.translationId)) {
                        wantS = saved.s || null; wantE = saved.e || null;
                    }
                    if (wantS === null && wantE === null && tr && tr.lastE) {
                        // нет ?s&e и нет сохранённого состояния — продолжаем с
                        // последней просмотренной серии ЭТОЙ озвучки (прогресс в БД)
                        wantS = tr.lastS || null;
                        wantE = parseInt(tr.lastE, 10) || null;
                    }
                }
                var found = false;

                if (wantE) {
                    for (var j = 0; j < lenEp; j++) {
                        if ((!wantS || episodes[j].s === wantS) && episodes[j].e === wantE) {
                            state.season = episodes[j].s;
                            state.episode = episodes[j].e;
                            found = true;
                            break;
                        }
                    }
                }
                if (!found) {
                    state.season = episodes[0].s;
                    state.episode = episodes[0].e;
                } else if (!hasQuery && saved && saved.ct) {
                    // восстановление позиции — только без явных параметров URL
                    state.savedKey = state.season + ':' + state.episode;
                    state.savedCt = saved.ct;
                }
            }
        }

        renderPanel();
        loadCurrent();
        setInterval(tick, 800);
    })();
})();
