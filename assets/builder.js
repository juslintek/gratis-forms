/**
 * Gratis Forms — admin drag-and-drop field builder.
 * Maintains a JSON model of form fields in the hidden #gratis-forms-fields
 * textarea (decoded server-side on save). Supports add, edit, remove and
 * native HTML5 drag-to-reorder.
 */
(function () {
    'use strict';

    var cfg = window.GratisFormsBuilder || { types: [], i18n: {} };
    var i18n = cfg.i18n || {};
    var TYPES = cfg.types || ['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'file'];
    var WITH_OPTIONS = ['select', 'checkbox', 'radio'];

    var root = document.getElementById('gratis-forms-builder-root');
    var store = document.getElementById('gratis-forms-fields');
    if (!root || !store) {
        return;
    }

    var model = [];
    try {
        model = JSON.parse(store.value || '[]');
        if (!Array.isArray(model)) {
            model = [];
        }
    } catch (e) {
        model = [];
    }

    function sync() {
        store.value = JSON.stringify(model);
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            if (k === 'text') {
                node.textContent = attrs[k];
            } else {
                node.setAttribute(k, attrs[k]);
            }
        });
        (children || []).forEach(function (c) { node.appendChild(c); });
        return node;
    }

    function render() {
        root.innerHTML = '';

        if (!model.length) {
            root.appendChild(el('p', { 'class': 'gf-builder-empty', text: i18n.empty || 'No fields yet.' }));
        }

        var list = el('div', { 'class': 'gf-builder-list' });

        model.forEach(function (field, index) {
            list.appendChild(renderRow(field, index));
        });
        root.appendChild(list);

        var controls = el('div', { 'class': 'gf-builder-controls' });
        var select = el('select', { 'class': 'gf-builder-type' });
        TYPES.forEach(function (t) {
            select.appendChild(el('option', { value: t, text: t }));
        });
        var addBtn = el('button', { type: 'button', 'class': 'button', text: i18n.addField || 'Add field' });
        addBtn.addEventListener('click', function () {
            var type = select.value;
            var field = { type: type, label: '', placeholder: '', required: false };
            if (WITH_OPTIONS.indexOf(type) !== -1) {
                field.options = [];
            }
            model.push(field);
            sync();
            render();
        });
        controls.appendChild(select);
        controls.appendChild(addBtn);
        root.appendChild(controls);
    }

    function renderRow(field, index) {
        var row = el('div', { 'class': 'gf-builder-row', draggable: 'true', 'data-index': String(index) });

        var handle = el('span', { 'class': 'gf-builder-handle', title: i18n.drag || 'Drag', text: '⋮⋮' });
        row.appendChild(handle);

        var body = el('div', { 'class': 'gf-builder-body' });

        var typeBadge = el('span', { 'class': 'gf-builder-badge', text: field.type });
        body.appendChild(typeBadge);

        var label = el('input', { type: 'text', 'class': 'gf-builder-label widefat', placeholder: i18n.label || 'Label', value: field.label || '' });
        label.addEventListener('input', function () { field.label = label.value; sync(); });
        body.appendChild(label);

        if (field.type !== 'file') {
            var ph = el('input', { type: 'text', 'class': 'gf-builder-ph widefat', placeholder: i18n.placeholder || 'Placeholder', value: field.placeholder || '' });
            ph.addEventListener('input', function () { field.placeholder = ph.value; sync(); });
            body.appendChild(ph);
        }

        if (WITH_OPTIONS.indexOf(field.type) !== -1) {
            var opts = el('textarea', { 'class': 'gf-builder-options widefat', placeholder: i18n.options || 'Options (one per line)' });
            opts.value = (field.options || []).join('\n');
            opts.addEventListener('input', function () {
                field.options = opts.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                sync();
            });
            body.appendChild(opts);
        }

        var reqLabel = el('label', { 'class': 'gf-builder-req' });
        var req = el('input', { type: 'checkbox' });
        req.checked = !!field.required;
        req.addEventListener('change', function () { field.required = req.checked; sync(); });
        reqLabel.appendChild(req);
        reqLabel.appendChild(document.createTextNode(' ' + (i18n.required || 'Required')));
        body.appendChild(reqLabel);

        var remove = el('button', { type: 'button', 'class': 'button-link gf-builder-remove', text: i18n.remove || 'Remove' });
        remove.addEventListener('click', function () {
            model.splice(index, 1);
            sync();
            render();
        });
        body.appendChild(remove);

        row.appendChild(body);

        row.addEventListener('dragstart', function (e) {
            e.dataTransfer.setData('text/plain', String(index));
            row.classList.add('is-dragging');
        });
        row.addEventListener('dragend', function () { row.classList.remove('is-dragging'); });
        row.addEventListener('dragover', function (e) { e.preventDefault(); });
        row.addEventListener('drop', function (e) {
            e.preventDefault();
            var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
            var to = index;
            if (isNaN(from) || from === to) {
                return;
            }
            var moved = model.splice(from, 1)[0];
            model.splice(to, 0, moved);
            sync();
            render();
        });

        return row;
    }

    sync();
    render();
})();
