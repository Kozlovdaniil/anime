/**
 * player-shim.js — внедряется в страницу плеера через прокси /player/frame.
 *
 * Поскольку документ отдаётся с нашего origin, родительская страница может
 * дергать window.__cinemaPlayer напрямую (same-origin): время, длительность,
 * перемотка на секунду, плей/пауза, «закончилось» — всё, что нужно нашей
 * обвязке для панели управления поверх iframe.
 *
 * Плюс: вырезает рекламные узлы, которые плеер добавляет на лету
 * (список селекторов приходит из window.__cinemaAdSelectors; статичная
 * реклама в HTML и так вырезана на сервере).
 *
 * И заворачивает AJAX плеера к его собственному API на наш /player/api
 * (у апстрима нет CORS — см. блок про шлюз ниже).
 *
 * ES5 — страница плеера грузится и на старых браузерах (Tizen).
 */
(function () {
    'use strict';

    if (window.__cinemaPlayer) {
        return;
    }

    /* ------------------------------------------------------------------
     * Рекламный брандмауэр.
     *
     * Статичная реклама вырезана из HTML на сервере (PlayerProxy::stripAds).
     * Реклама, которую скрипты плеера добавляют на лету, гасится здесь ДО
     * попытки загрузки — плееру она не достаётся, консоль остаётся чистой.
     *
     * Список должен коррелировать с AD_URL_RE в PlayerProxy.
     * ------------------------------------------------------------------ */
    if (!window.__cinemaNoAdGuard) { // отключаемо для отладки (см. тесты)
    var AD_URL_RE = /(doubleclick|googlesyndication|googleadservices|pagead2|adsterra|propellerads|popads|popcash|adnxs|criteo|taboola|outbrain|mgid|adfox|adlab|reklama|banman|adserver|adslot|adition|openx|rubiconproject|pubmatic|yieldmanager|yandex\.ru\/ads|adsdk|amedia\.online|kxcdn|serving-sys|buzzoola|ufouxbwn|snsv\.ru|adriver|vidoomy|mc\.yandex\.ru|metri[ck]a)/i;

    function isAdUrl(u) {
        var s;
        try { s = String(u); } catch (e) { return false; }
        var hit = AD_URL_RE.test(s);
        if (hit && window.__cinemaAdLog) console.warn('[adguard] ' + s.slice(0, 200));
        return hit;
    }

    /** Убрать рекламные url(...) из CSS-строки. */
    function scrubCssUrls(s) {
        return String(s).replace(/url\(\s*(['"]?)[^)'"]*\1\s*\)/gi, function (m) {
            if (AD_URL_RE.test(m)) {
                if (window.__cinemaAdLog) console.warn('[adguard-css] ' + m.slice(0, 200));
                return ' ';
            }
            return m;
        });
    }

    /**
     * Сымитировать сетевую ошибку элемента,
     * но молча: рекламный код плеера получает свой onerror и идёт дальше —
     * преролл не зависает, консоль без ошибок.
     */
    function fireAdError(el) {
        if (!el || !el.dispatchEvent) return;
        setTimeout(function () {
            try {
                var ev;
                if (typeof Event === 'function') {
                    ev = new Event('error');
                } else {
                    ev = document.createEvent('Event');
                    ev.initEvent('error', false, false);
                }
                el.dispatchEvent(ev);
            } catch (e) {}
        }, 0);
    }

    // fetch() — быстрый отказ без ошибки в консоли
    if (typeof window.fetch === 'function') {
        var origFetch = window.fetch;
        window.fetch = function (input) {
            var url = input;
            try { url = input && input.url ? input.url : input; } catch (e) {}
            if (isAdUrl(url)) {
                return window.Promise
                    ? window.Promise.reject(new TypeError('Load failed'))
                    : undefined;
            }
            return origFetch.apply(this, arguments);
        };
    }

    // XMLHttpRequest — блокируем на open(), send() шлёт событие error
    if (window.XMLHttpRequest) {
        var origXhrOpen = XMLHttpRequest.prototype.open;
        var origXhrSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            if (isAdUrl(url)) { this.__cinemaAdBlocked = true; return; }
            return origXhrOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            if (!this.__cinemaAdBlocked) return origXhrSend.apply(this, arguments);
            var self = this;
            // «Заблокированный» XHR должен выглядеть как завершившийся со
            // status = 0 (ошибка сети): иначе обработчики, ждущие
            // readyState === 4 в readystatechange, зависают навсегда.
            setTimeout(function () {
                try {
                    Object.defineProperty(self, 'readyState', { configurable: true, value: 4 });
                    Object.defineProperty(self, 'status', { configurable: true, value: 0 });
                } catch (e) {}
                ['readystatechange', 'error', 'loadend'].forEach(function (type) {
                    try {
                        var ev;
                        if (typeof Event === 'function') ev = new Event(type);
                        else { ev = document.createEvent('Event'); ev.initEvent(type, false, false); }
                        self.dispatchEvent(ev);
                    } catch (e) {}
                });
            }, 0);
        };
    }

    // src/href-сеттеры (el.src = '…'): рекламный URL не применяется,
    // элементу шлётся onerror — загрузчик не ждёт вечно
    function guardUrlProp(ProtoName, prop) {
        var proto = window[ProtoName];
        if (!proto || !proto.prototype) return;
        var desc;
        try { desc = Object.getOwnPropertyDescriptor(proto.prototype, prop); } catch (e) { return; }
        if (!desc || !desc.set) return;
        try {
            Object.defineProperty(proto.prototype, prop, {
                configurable: true,
                enumerable: desc.enumerable,
                get: desc.get,
                set: function (v) {
                    if (isAdUrl(v)) { fireAdError(this); return; }
                    desc.set.call(this, v);
                }
            });
        } catch (e) {}
    }
    guardUrlProp('HTMLScriptElement', 'src');
    guardUrlProp('HTMLImageElement', 'src');
    guardUrlProp('HTMLIFrameElement', 'src');
    guardUrlProp('HTMLLinkElement', 'href');
    guardUrlProp('HTMLMediaElement', 'src');
    guardUrlProp('HTMLSourceElement', 'src');

    // setAttribute('src'|'href'|'style', …)
    if (window.Element && Element.prototype.setAttribute) {
        var origSetAttr = Element.prototype.setAttribute;
        Element.prototype.setAttribute = function (name, value) {
            var n = String(name).toLowerCase();
            if (n === 'style' && AD_URL_RE.test(String(value))) {
                // strict mode: arguments не связан с параметрами — передаём явно
                return origSetAttr.call(this, name, scrubCssUrls(value));
            }
            if ((n === 'src' || n === 'href') && isAdUrl(value)) {
                fireAdError(this);
                return;
            }
            return origSetAttr.apply(this, arguments);
        };
    }

    // innerHTML/insertAdjacentHTML: рекламные теги вырезаются,
    // рекламные url(...) в CSS вычищаются
    function stripAdMarkup(html) {
        var s = String(html);
        if (!AD_URL_RE.test(s)) return s;
        s = s.replace(/<(script|img|iframe|link|ins|video|source|embed|object|audio)\b[^>]*>/gi,
            function (tag) {
                if (AD_URL_RE.test(tag)) {
                    if (window.__cinemaAdLog) console.warn('[adguard-tag] ' + tag.slice(0, 200));
                    return '';
                }
                return tag;
            });
        return scrubCssUrls(s);
    }
    if (window.Element) {
        var innerDesc = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
        if (innerDesc && innerDesc.set) {
            try {
                Object.defineProperty(Element.prototype, 'innerHTML', {
                    configurable: true,
                    enumerable: innerDesc.enumerable,
                    get: innerDesc.get,
                    set: function (v) {
                        // null/undefined обязаны вести себя как оригинал, а не превращаться в "null"
                        if (v === null || v === undefined) return innerDesc.set.call(this, v);
                        return innerDesc.set.call(this, stripAdMarkup(v));
                    }
                });
            } catch (e) {}
        }
        if (Element.prototype.insertAdjacentHTML) {
            var origIah = Element.prototype.insertAdjacentHTML;
            Element.prototype.insertAdjacentHTML = function (pos, html) {
                if (html === null || html === undefined) return origIah.call(this, pos, html);
                return origIah.call(this, pos, stripAdMarkup(html));
            };
        }
    }

    // textContent: так плеер обычно вставляет свои <style> с рекламным фоном
    if (window.Node) {
        var textDesc = Object.getOwnPropertyDescriptor(Node.prototype, 'textContent');
        if (textDesc && textDesc.set) {
            try {
                Object.defineProperty(Node.prototype, 'textContent', {
                    configurable: true,
                    enumerable: textDesc.enumerable,
                    get: textDesc.get,
                    set: function (v) {
                        if (v === null || v === undefined) return textDesc.set.call(this, v);
                        var s = String(v);
                        if (this.tagName === 'STYLE' && AD_URL_RE.test(s)) s = scrubCssUrls(s);
                        return textDesc.set.call(this, s);
                    }
                });
            } catch (e) {}
        }
    }

    // CSSOM: el.style.backgroundImage = 'url(реклама…)'.
    //
    // В Chrome у CSS-свойств нет accessor'ов на CSSStyleDeclaration.prototype
    // (они спрятаны внутри движка), поэтому обернуть «штатный» сеттер нельзя.
    // Зато defineProperty нового свойства на прототипе ВСЕГДА перехватывает
    // присваивание (проверено экспериментом): ставим свои getter/setter.
    // Сеттер чистит рекламный url(...) и прогоняет значение через setProperty,
    // геттер читает через getPropertyValue — поведение чужого кода не меняется.
    if (window.CSSStyleDeclaration && CSSStyleDeclaration.prototype) {
        ['background', 'backgroundImage', 'borderImage', 'listStyleImage', 'cursor'].forEach(function (prop) {
            var cssName = prop.replace(/[A-Z]/g, function (m) { return '-' + m.toLowerCase(); });
            var existing = null;
            try { existing = Object.getOwnPropertyDescriptor(CSSStyleDeclaration.prototype, prop); } catch (e) {}
            var origGet = existing && existing.get;
            var origSet = existing && existing.set;
            try {
                Object.defineProperty(CSSStyleDeclaration.prototype, prop, {
                    configurable: true,
                    enumerable: true,
                    get: function () {
                        if (origGet) return origGet.call(this);
                        return this.getPropertyValue(cssName);
                    },
                    set: function (v) {
                        var s = String(v);
                        if (AD_URL_RE.test(s)) s = scrubCssUrls(s);
                        if (origSet) return origSet.call(this, s);
                        return this.setProperty(cssName, s);
                    }
                });
            } catch (e) {}
        });
        // el.style.setProperty('background-image', 'url(…)')
        if (CSSStyleDeclaration.prototype.setProperty) {
            var origSetProp = CSSStyleDeclaration.prototype.setProperty;
            CSSStyleDeclaration.prototype.setProperty = function (name, value, priority) {
                if (AD_URL_RE.test(String(value))) value = scrubCssUrls(value);
                return origSetProp.call(this, name, value, priority);
            };
        }
        // el.style.cssText = '…'
        var cssTextDesc;
        try { cssTextDesc = Object.getOwnPropertyDescriptor(CSSStyleDeclaration.prototype, 'cssText'); } catch (e) {}
        if (cssTextDesc && cssTextDesc.set) {
            try {
                Object.defineProperty(CSSStyleDeclaration.prototype, 'cssText', {
                    configurable: true,
                    enumerable: cssTextDesc.enumerable,
                    get: cssTextDesc.get,
                    set: function (v) {
                        if (v !== null && v !== undefined && AD_URL_RE.test(String(v))) v = scrubCssUrls(v);
                        return cssTextDesc.set.call(this, v);
                    }
                });
            } catch (e) {}
        }
    }

    // sheet.insertRule('.x { background: url(реклама…) }') — правило добавляется
    // в CSSOM, textContent <style> при этом не меняется
    if (window.CSSStyleSheet && CSSStyleSheet.prototype && CSSStyleSheet.prototype.insertRule) {
        var origInsertRule = CSSStyleSheet.prototype.insertRule;
        CSSStyleSheet.prototype.insertRule = function (rule, index) {
            if (typeof rule === 'string' && AD_URL_RE.test(rule)) rule = scrubCssUrls(rule);
            return origInsertRule.call(this, rule, index);
        };
    }

    // beacon и WebSocket на рекламные хосты — быстрый отказ/тишина
    if (navigator.sendBeacon) {
        var origBeacon = navigator.sendBeacon;
        navigator.sendBeacon = function (url) {
            if (isAdUrl(url)) return false;
            return origBeacon.apply(navigator, arguments);
        };
    }
    if (window.WebSocket) {
        var OrigWs = window.WebSocket;
        window.WebSocket = function (url, protocols) {
            if (isAdUrl(url)) {
                var stub = {
                    close: function () {},
                    send: function () {},
                    addEventListener: function () {},
                    removeEventListener: function () {}
                };
                setTimeout(function () {
                    if (typeof stub.onerror === 'function') stub.onerror({ type: 'error' });
                }, 0);
                return stub;
            }
            return protocols !== undefined ? new OrigWs(url, protocols) : new OrigWs(url);
        };
        window.WebSocket.prototype = OrigWs.prototype;
    }
    }

    /* ------------------------------------------------------------------
     * Same-origin шлюз для запросов плеера к ЕГО собственному хосту.
     *
     * В обычном iframe документ плеера и его API — один origin, CORS не
     * нужен. У нас документ отдаётся с нашего origin, поэтому POST-запросы
     * плеера к себе (/ftor, /stats у Kodik) становятся кросс-доменными,
     * а их API не отдаёт CORS → браузер режет ответ и видео не грузится.
     * Заворачиваем такие запросы на наш /player/api: он ходит к апстриму
     * с нужным Referer, а для браузера это остаётся same-origin.
     * ------------------------------------------------------------------ */
    var playerHost = window.__cinemaPlayerHost || '';
    if (playerHost && window.XMLHttpRequest) {
        var origin = window.location.origin
            || (window.location.protocol + '//' + window.location.host);

        function resolveUrl(u) {
            // a.href резолвится против document.baseURI (его ставит прокси) —
            // тот же результат, что new URL(), но работает и на старых WebKit.
            // Ссылка могла прийти из HTML/скриптов плеера с HTML-сущностью
            // &amp; вместо & — раскодируем, иначе шлюз уходит на битый URL.
            var a = document.createElement('a');
            a.href = String(u).replace(/&amp;/g, '&');
            return a;
        }

        function samePlayerHost(u) {
            try {
                var h = resolveUrl(u).hostname.toLowerCase();
                return h === playerHost
                    || h.slice(-(playerHost.length + 1)) === '.' + playerHost;
            } catch (e) {
                return false;
            }
        }

        function viaPlayerApi(u) {
            return origin + '/player/api?url=' + encodeURIComponent(resolveUrl(u).href);
        }

        // Медиа и статику через шлюз НЕ гоним: у большинства апстримов на них
        // есть CORS (`Access-Control-Allow-Origin: *`), а проксирование ломает
        // разбор относительных ссылок внутри плейлистов (варианты .m3u8 и
        // сегменты .ts разрешаются от нашего /player/api, а не от апстрима) и
        // зря гоняет видео через PHP. Проксируем только «API-подобные» URL
        // (например POST /ftor у Kodik) — там CORS и не хватает.
        var NO_PROXY_RE = /\.(m3u8|m3u|ts|m4s|mp4|m4v|webm|mpd|aac|mp3|ogg|oga|vtt|srt|key|jpg|jpeg|png|gif|webp|avif|svg|ico|css|woff2?|ttf|eot|otf)(\?|#|$)/i;

        function shouldProxy(u) {
            if (!samePlayerHost(u)) return false;
            var pathname = '';
            try { pathname = resolveUrl(u).pathname || ''; } catch (e) {}
            return !NO_PROXY_RE.test(pathname);
        }

        var prevXhrOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function (method, url) {
            var args = Array.prototype.slice.call(arguments);
            if (typeof url === 'string' && shouldProxy(url)) {
                try { args[1] = viaPlayerApi(url); } catch (e) {}
            }
            return prevXhrOpen.apply(this, args);
        };

        if (typeof window.fetch === 'function') {
            var prevFetch = window.fetch;
            window.fetch = function (input, init) {
                try {
                    if (typeof input === 'string' && shouldProxy(input)) {
                        input = viaPlayerApi(input);
                    } else if (input && input.url && shouldProxy(input.url)) {
                        input = new Request(viaPlayerApi(input.url), input);
                    }
                } catch (e) {}
                return prevFetch.call(this, input, init);
            };
        }
    }

    var video = null;
    // Событие ended на выбранном <video> — на части прошивок Tizen
    // video.ended не выставляется, поэтому запоминаем сам факт окончания.
    var endedFlag = false;
    // Позиция подходила к концу — страховка, если плеер в конце убирает/меняет
    // <video> и свойство ended (или событие) уже не прочитать.
    var sawNearEnd = false;

    function pickVideo() {
        var list = document.getElementsByTagName('video');
        var fallback = null;
        for (var i = 0; i < list.length; i++) {
            var v = list[i];
            if (!fallback) {
                fallback = v;
            }
            // предпочитаем видео, у которого уже известна длительность
            if (isFinite(v.duration) && v.duration > 0) {
                return v;
            }
        }
        return fallback;
    }

    function hook() {
        var v = pickVideo();

        // плеер убрал <video> у самого конца — считаем серию законченной
        // (video не обнуляем: родительская страница должна дочитать ended)
        if (!v && video && sawNearEnd) {
            endedFlag = true;
        }
        if (v && v !== video) {
            if (video && sawNearEnd) {
                endedFlag = true; // <video> заменён у конца (не новая серия)
            }
            video = v;
            sawNearEnd = false;
            autoplayTried = false; // новый <video> (другая серия) — автостарт заново
            // старые WebKit не всегда выставляют video.ended — слушаем событие
            if (!v.__cinemaEndedBound) {
                v.__cinemaEndedBound = true;
                v.addEventListener('ended', function () { endedFlag = true; }, false);
            }
        }
        if (video && isFinite(video.duration) && video.duration > 0
            && video.currentTime >= video.duration - 1.5) {
            sawNearEnd = true;
        }

        tryStart();
        tryAutoplay();
    }

    /**
     * Запуск плеера, который ждёт тапа по оверлею.
     *
     * У Kodik <video> создаётся только после нажатия на оверлей
     * (.play_background → запрос /ftor → источник видео). На ТВ тапа нет,
     * поэтому «нажимаем» сами. Повторяем несколько раз: обработчик клика
     * у плеера вешается асинхронно, слишком ранний клик пропадёт.
     * Как только видео появилось — прекращаем.
     */
    var startAttempts = 0;
    function tryStart() {
        if (video || startAttempts > 12 || !document.querySelector) return;
        var el = document.querySelector('.play_button')
            || document.querySelector('.play_background')
            || document.querySelector('.center_pass_button');
        if (!el) return;
        startAttempts++;
        try { el.click(); } catch (e) {}
    }

    /**
     * Автостарт.
     *
     * Страницы плеера (aser/kodik) рассчитывают на тап по оверлею
     * «Нажмите для просмотра» — на ТВ-пульте тапа нет, поэтому запускаем
     * воспроизведение сами, как только у видео известна длительность.
     * Их PREROLL-логика при первом play (SDK не загружен → resumeMain)
     * сама возвращает картинку и звук.
     *
     * Если браузер запретил play() без жеста (десктоп) — ждём первого
     * касания/клавиши внутри фрейма и пробуем снова.
     */
    var autoplayTried = false;
    var gestureArmed = false;

    function armGestureRetry() {
        if (gestureArmed) return;
        gestureArmed = true;
        var events = ['click', 'keydown', 'touchstart', 'mousedown'];
        function onGesture() {
            gestureArmed = false;
            for (var i = 0; i < events.length; i++) {
                document.removeEventListener(events[i], onGesture, true);
            }
            autoplayTried = false;
            tryAutoplay();
        }
        for (var j = 0; j < events.length; j++) {
            document.addEventListener(events[j], onGesture, true);
        }
    }

    function tryAutoplay() {
        if (!video || autoplayTried) return;
        if (!isFinite(video.duration) || video.duration <= 0) return;
        autoplayTried = true;
        var p;
        try {
            p = video.play();
        } catch (e) {
            autoplayTried = false;
            armGestureRetry();
            return;
        }
        if (p && p.catch) {
            p.catch(function () {
                autoplayTried = false;
                armGestureRetry();
            });
        }
    }

    var api = {
        ready: function () {
            return !!video;
        },
        time: function () {
            return video && isFinite(video.currentTime) ? video.currentTime : 0;
        },
        duration: function () {
            return video && isFinite(video.duration) && video.duration > 0 ? video.duration : 0;
        },
        paused: function () {
            return video ? !!video.paused : true;
        },
        ended: function () {
            // 1) событие ended (запомнено в hook) — не зависит от того,
            //    выставляет ли прошивка video.ended;
            if (endedFlag) {
                return true;
            }
            if (!video) {
                return false;
            }
            if (video.ended) {
                return true;
            }
            // 2) страховка для прошивок без video.ended/события: доехали до
            //    конца или плеер остановился у самого конца.
            var d = video.duration;
            if (isFinite(d) && d > 0) {
                if (video.currentTime >= d - 0.25) {
                    return true;
                }
                if (video.paused && video.currentTime >= d - 3) {
                    return true;
                }
            }
            return false;
        },
        muted: function () {
            return video ? !!video.muted : false;
        },
        play: function () {
            if (video && video.play) {
                var p = video.play();
                if (p && p.catch) {
                    p.catch(function () {});
                }
            }
        },
        pause: function () {
            if (video && video.pause) {
                video.pause();
            }
        },
        toggle: function () {
            if (!video) {
                return;
            }
            if (video.paused || video.ended) {
                api.play();
            } else {
                api.pause();
            }
        },
        seek: function (t) {
            if (video && isFinite(t)) {
                try {
                    video.currentTime = t < 0 ? 0 : t;
                } catch (e) {
                    /* метаданные ещё не пришли — молча */
                }
            }
        },
        volume: function (v) {
            if (video) {
                video.volume = v < 0 ? 0 : (v > 1 ? 1 : v);
            }
        }
    };

    window.__cinemaPlayer = api;

    /* ------------------------------------------------------------------
     * Фуллскрин.
     *
     * Нативный Fullscreen API внутри прокси-фрейма на Tizen ломается:
     * плеер (Flowplayer у Kodik) «входит» в полноэкранный режим по своему
     * состоянию, а картинка не растягивается, и повторный двойной клик уже
     * ничего не делает. Поэтому ЛЮБОЙ запрос на разворот/сворачивание из
     * фрейма (кнопка плеера, двойной клик, клавиша, внутренний API)
     * переадресуем родителю, а он разворачивает контейнер своим CSS-режимом
     * (см. __cinemaFullscreen в player.js) — он работает везде.
     * ------------------------------------------------------------------ */
    (function () {
        function callParent(method) {
            try {
                var p = window.parent;
                if (p && p !== window && p.__cinemaFullscreen &&
                        typeof p.__cinemaFullscreen[method] === 'function') {
                    p.__cinemaFullscreen[method]();
                    return true;
                }
            } catch (e) { /* родитель с другого origin — не трогаем */ }
            return false;
        }

        function resolved() {
            // Flowplayer ждёт промис и может звать .catch() — отдаём resolved
            return (typeof Promise === 'function') ? Promise.resolve() : undefined;
        }

        var reqNames = ['requestFullscreen', 'webkitRequestFullscreen',
            'mozRequestFullScreen', 'msRequestFullscreen'];
        var proto = (window.Element && Element.prototype) ? Element.prototype : null;
        var i;
        if (proto) {
            for (i = 0; i < reqNames.length; i++) {
                (function (name) {
                    var orig = proto[name];
                    proto[name] = function () {
                        if (callParent('enter')) return resolved();
                        return orig ? orig.apply(this, arguments) : undefined;
                    };
                })(reqNames[i]);
            }
        }

        // мобильный/Safari-подобный вход у <video> (на всякий случай)
        if (window.HTMLVideoElement && HTMLVideoElement.prototype.webkitEnterFullscreen) {
            var origVideoEnter = HTMLVideoElement.prototype.webkitEnterFullscreen;
            HTMLVideoElement.prototype.webkitEnterFullscreen = function () {
                if (callParent('enter')) return;
                return origVideoEnter.apply(this, arguments);
            };
        }

        var exitNames = ['exitFullscreen', 'webkitExitFullscreen',
            'mozCancelFullScreen', 'msExitFullscreen'];
        for (i = 0; i < exitNames.length; i++) {
            (function (name) {
                var orig = document[name];
                document[name] = function () {
                    if (callParent('exit')) return;
                    return orig ? orig.apply(document, arguments) : undefined;
                };
            })(exitNames[i]);
        }
    })();

    /* --- <video> может появиться после старта плеера --- */
    hook();
    setInterval(hook, 500);
    if (window.MutationObserver) {
        new MutationObserver(hook).observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }

    /* --- реклама, вставляемая скриптами плеера на лету --- */
    var selectors = window.__cinemaAdSelectors || [];
    var stripping = false;

    function stripAds() {
        if (stripping || !document.querySelectorAll) {
            return;
        }
        stripping = true;
        try {
            for (var i = 0; i < selectors.length; i++) {
                var nodes;
                try {
                    nodes = document.querySelectorAll(selectors[i]);
                } catch (e) {
                    continue;
                }
                for (var j = 0; j < nodes.length; j++) {
                    var n = nodes[j];
                    if (n.parentNode) {
                        n.parentNode.removeChild(n);
                    }
                }
            }
        } finally {
            stripping = false;
        }
    }

    stripAds();
    setInterval(stripAds, 2000);
    if (window.MutationObserver) {
        new MutationObserver(stripAds).observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }
})();
