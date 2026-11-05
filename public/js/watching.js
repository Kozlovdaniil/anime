/**
 * Кнопка «Смотрю» (раздел «Смотрю»).
 *
 * Переключение идёт AJAX-ом на app_watching_toggle, без перезагрузки —
 * иначе на странице плеера сбрасывалось бы воспроизведение. Работает на
 * старых браузерах: XMLHttpRequest, без fetch/стрелок/closest.
 *
 * На странице может быть несколько кнопок одного тайтла (шапка плеера и
 * надстройка озвучки) — после ответа синхронизируем все по data-url.
 */
(function () {
    'use strict';

    function closestToggle(el) {
        while (el && el !== document.body && el.getAttribute) {
            if (el.hasAttribute && el.hasAttribute('data-watching-toggle')) return el;
            el = el.parentNode;
        }
        return null;
    }

    function closestClass(el, cls) {
        while (el && el !== document.body && el.getAttribute) {
            if ((' ' + (el.className || '') + ' ').indexOf(' ' + cls + ' ') !== -1) return el;
            el = el.parentNode;
        }
        return null;
    }

    function setLabel(btn, on) {
        var label = btn.querySelector('[data-watching-label]');
        if (label) label.textContent = on ? '✓ В «Смотрю»' : '+ Смотрю';
        if (on) btn.classList.add('is-on');
        else btn.classList.remove('is-on');
        btn.setAttribute('data-watching', on ? '1' : '0');
        btn.setAttribute('title', on ? 'Убрать из «Смотрю»' : 'Добавить в «Смотрю»');
    }

    function setCount(n) {
        var els = document.querySelectorAll('[data-watching-count]');
        for (var i = 0; i < els.length; i++) {
            els[i].textContent = n;
            if (n > 0) els[i].removeAttribute('hidden');
            else els[i].setAttribute('hidden', 'hidden');
        }
    }

    function syncAll(url, on, count) {
        var all = document.querySelectorAll('[data-watching-toggle]');
        for (var i = 0; i < all.length; i++) {
            if (all[i].getAttribute('data-url') === url) setLabel(all[i], on);
        }
        if (typeof count === 'number') setCount(count);
    }

    document.addEventListener('click', function (ev) {
        var btn = closestToggle(ev.target);
        if (!btn) return;
        ev.preventDefault();
        if (btn.disabled) return;
        btn.disabled = true;

        var url = btn.getAttribute('data-url');
        var token = btn.getAttribute('data-token') || '';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-TOKEN', token);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            btn.disabled = false;
            if (xhr.status >= 200 && xhr.status < 300) {
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) {}
                if (data && typeof data.watching === 'boolean') {
                    syncAll(url, data.watching, data.count);
                    // список «Смотрю»: убрали тайтл — перерисуем страницу,
                    // чтобы не осталось пустой карточки и обновилось «пусто»
                    if (closestClass(btn, 'watching-cell')) {
                        window.location.reload();
                    }
                }
            }
        };
        xhr.send();
    }, false);
})();
