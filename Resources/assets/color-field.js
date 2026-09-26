/*
 * Farbfächer: enhances the free color field of customers, projects and activities
 * with a native color picker, live clash hints and clickable suggestions.
 *
 *   input[data-farbfaecher-type]  ──input──►  GET data-farbfaecher-url?type&id&parent&name&color
 *                                  ◄──JSON──  {suggestions: [hex], clashes: [{path, color, distance, weeks, sibling, level}], threshold}
 *
 * Edit forms are loaded into modals via fetch, so new fields are detected with a MutationObserver.
 * Texts come from data-farbfaecher-i18n (translated on the server). Only textContent, never innerHTML.
 */
(function () {
    'use strict';

    if (window.KimaiFarbfaecher) {
        return;
    }
    window.KimaiFarbfaecher = true;

    var PARENT_FIELD = {project: 'customer', activity: 'project'};
    var TIER = {info: '1', warning: '2', critical: '3'};
    var TIER_CLASS = {info: 'bg-yellow-lt', warning: 'bg-orange-lt', critical: 'bg-red-lt'};
    var HEX = /^#[0-9a-f]{6}$/i;
    var NO_COLOR = '#d2d6de';
    var DEBOUNCE_MS = 200;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function mark(color) {
        var node = el('span', 'ff-mark kpu-mark');
        node.style.backgroundColor = color;
        node.title = color;
        return node;
    }

    function init(input) {
        if (input.getAttribute('data-farbfaecher-init') === '1') {
            return;
        }
        input.setAttribute('data-farbfaecher-init', '1');

        var type = input.getAttribute('data-farbfaecher-type');
        var url = input.getAttribute('data-farbfaecher-url');
        var id = input.getAttribute('data-farbfaecher-id');
        var texts = {};
        try {
            texts = JSON.parse(input.getAttribute('data-farbfaecher-i18n') || '{}');
        } catch (e) {
            texts = {};
        }
        var t = function (key) { return texts[key] || key; };
        var form = input.form;

        // [color picker][hex input], hints below
        var row = el('div', 'ff-field-row');
        var picker = el('input', 'form-control form-control-color');
        picker.type = 'color';
        picker.title = t('pick');
        input.parentNode.insertBefore(row, input);
        row.appendChild(picker);
        row.appendChild(input);

        var hints = el('div', 'ff-hints');
        hints.setAttribute('aria-live', 'polite');
        row.parentNode.insertBefore(hints, row.nextSibling);

        function syncPicker() {
            var value = input.value.trim().toLowerCase();
            picker.value = HEX.test(value) ? value : NO_COLOR;
        }

        function parentSelect() {
            if (!form || !PARENT_FIELD[type]) {
                return null;
            }
            return form.querySelector('select[name$="[' + PARENT_FIELD[type] + ']"]');
        }

        var timer = null;
        var requestNo = 0;

        function refresh() {
            window.clearTimeout(timer);
            timer = window.setTimeout(load, DEBOUNCE_MS);
        }

        function load() {
            if (!url || !type) {
                return;
            }
            var params = new URLSearchParams({type: type, color: input.value.trim()});
            if (id) {
                params.set('id', id);
            }
            var parent = parentSelect();
            if (parent) {
                params.set('parent', parent.value || '');
            }
            var name = form ? form.querySelector('input[name$="[name]"]') : null;
            if (name) {
                params.set('name', name.value);
            }

            // only the latest answer counts, typing fires many requests
            var current = ++requestNo;
            window.fetch(url + '?' + params.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(function (data) {
                    if (current === requestNo) {
                        render(data);
                    }
                })
                .catch(function () {
                    hints.textContent = '';
                });
        }

        function apply(color) {
            input.value = color;
            syncPicker();
            input.dispatchEvent(new Event('change', {bubbles: true}));
            refresh();
        }

        function sameGroupText() {
            if (type === 'project') {
                return t('same_customer');
            }
            if (type === 'activity') {
                var parent = parentSelect();
                return parent && parent.value ? t('same_project') : t('both_global');
            }
            return '';
        }

        function clashItem(clash) {
            var li = el('li');
            var badge = el('span', 'badge kpu-tier ' + TIER_CLASS[clash.level], t('level_' + clash.level));
            badge.setAttribute('data-kpu-tier', TIER[clash.level]);
            li.appendChild(badge);
            li.appendChild(mark(clash.color));

            var parts = [clash.path, 'ΔE ' + clash.distance.toLocaleString()];
            if (clash.weeks > 0) {
                parts.push(t('weeks').replace('%count%', String(clash.weeks)));
            }
            if (clash.sibling && sameGroupText() !== '') {
                parts.push(sameGroupText());
            }
            li.appendChild(el('span', '', parts.join(' · ')));
            return li;
        }

        function renderClashes(data) {
            var value = input.value.trim();
            if (value === '') {
                hints.appendChild(el('div', 'text-secondary', t('none')));
                return;
            }
            if (!HEX.test(value)) {
                return;
            }
            if (data.clashes.length === 0) {
                hints.appendChild(el('div', 'text-success', t('ok')));
                return;
            }

            // only "info" left: similar entries that are not used together
            var relevant = data.clashes.some(function (c) { return c.level !== 'info'; });
            hints.appendChild(el('div', relevant ? 'text-body' : 'text-secondary', relevant ? t('similar') : t('unrelated')));
            var list = el('ul');
            data.clashes.forEach(function (clash) { list.appendChild(clashItem(clash)); });
            hints.appendChild(list);
        }

        function renderSuggestions(data) {
            if (data.suggestions.length === 0) {
                return;
            }
            var box = el('div', 'ff-suggestions');
            box.appendChild(el('span', 'text-secondary', t('suggestions')));
            data.suggestions.forEach(function (color) {
                var button = el('button', 'ff-suggestion kpu-mark');
                button.type = 'button';
                button.title = t('use') + ': ' + color;
                button.setAttribute('aria-label', button.title);
                button.style.backgroundColor = color;
                button.addEventListener('click', function () { apply(color); });
                box.appendChild(button);
            });
            hints.appendChild(box);
        }

        function render(data) {
            hints.textContent = '';
            renderClashes(data);
            renderSuggestions(data);
        }

        picker.addEventListener('input', function () {
            input.value = picker.value;
            refresh();
        });
        input.addEventListener('input', function () {
            syncPicker();
            refresh();
        });
        var parent = parentSelect();
        if (parent) {
            parent.addEventListener('change', refresh);
        }

        syncPicker();
        load();
    }

    function scan(root) {
        if (root.querySelectorAll) {
            root.querySelectorAll('input[data-farbfaecher-type]').forEach(init);
        }
    }

    var started = false;

    function start() {
        if (started) {
            return;
        }
        started = true;
        scan(document);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) { m.addedNodes.forEach(scan); });
        }).observe(document.body, {childList: true, subtree: true});
    }

    // like kimai-plugin-ui: after Kimai is ready, DOMContentLoaded as fallback
    document.addEventListener('kimai.initialized', start);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
