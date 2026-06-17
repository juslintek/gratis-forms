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

    // Stable per-field id, referenced by conditional rules so reordering or
    // relabelling never breaks a rule. Kept to [a-z0-9_-] to survive the
    // server-side sanitize_key() round-trip.
    function genId(used) {
        var id;
        do {
            id = ('f' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4))
                .toLowerCase().replace(/[^a-z0-9_-]/g, '');
            if (id.length < 3) {
                id = 'f' + id + 'x';
            }
        } while (used[id]);
        used[id] = true;
        return id;
    }

    function ensureIds() {
        var used = {};
        model.forEach(function (f) {
            var id = (f && typeof f.id === 'string') ? f.id.toLowerCase().replace(/[^a-z0-9_-]/g, '') : '';
            f.id = (id && !used[id]) ? id : genId(used);
            used[f.id] = true;
        });
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
            ensureIds();
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

        body.appendChild(renderConditional(field, index));

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

    // Per-field conditional-logic editor: an enable toggle plus
    // action / source-field / operator / value controls. The source field is
    // referenced by its stable id, so reordering fields keeps rules intact.
    function renderConditional(field, index) {
        var wrap = el('div', { 'class': 'gf-builder-cond' });

        var toggleLabel = el('label', { 'class': 'gf-builder-cond-toggle' });
        var toggle = el('input', { type: 'checkbox' });
        toggle.checked = !!field.conditional;
        toggle.addEventListener('change', function () {
            if (toggle.checked) {
                field.conditional = field.conditional || { action: 'show', field: '', operator: 'is', value: '' };
            } else {
                delete field.conditional;
            }
            sync();
            render();
        });
        toggleLabel.appendChild(toggle);
        toggleLabel.appendChild(document.createTextNode(' ' + (i18n.conditional || 'Conditional logic')));
        wrap.appendChild(toggleLabel);

        if (!field.conditional) {
            return wrap;
        }

        var others = model.filter(function (f, i) { return i !== index; });
        if (!others.length) {
            wrap.appendChild(el('p', { 'class': 'gf-builder-cond-note', text: i18n.condNoFields || 'Add another field first to use it as a condition.' }));
            return wrap;
        }

        var cond = field.conditional;
        var line = el('div', { 'class': 'gf-builder-cond-line' });

        var actionSel = el('select', { 'class': 'gf-builder-cond-action' });
        [['show', i18n.condShow || 'Show'], ['hide', i18n.condHide || 'Hide']].forEach(function (p) {
            actionSel.appendChild(el('option', { value: p[0], text: p[1] }));
        });
        actionSel.value = cond.action || 'show';
        actionSel.addEventListener('change', function () { cond.action = actionSel.value; sync(); });
        line.appendChild(actionSel);

        line.appendChild(el('span', { 'class': 'gf-builder-cond-text', text: ' ' + (i18n.condWhen || 'this field when') + ' ' }));

        var fieldSel = el('select', { 'class': 'gf-builder-cond-field' });
        fieldSel.appendChild(el('option', { value: '', text: i18n.condSelect || '— Select a field —' }));
        model.forEach(function (f, i) {
            if (i === index) {
                return;
            }
            var text = f.label || ((i18n.label || 'Field') + ' ' + (i + 1));
            fieldSel.appendChild(el('option', { value: f.id || '', text: text }));
        });
        fieldSel.value = cond.field || '';
        fieldSel.addEventListener('change', function () { cond.field = fieldSel.value; sync(); });
        line.appendChild(fieldSel);

        var opSel = el('select', { 'class': 'gf-builder-cond-op' });
        [['is', i18n.condIs || 'is'], ['is_not', i18n.condIsNot || 'is not'], ['contains', i18n.condContains || 'contains']].forEach(function (p) {
            opSel.appendChild(el('option', { value: p[0], text: p[1] }));
        });
        opSel.value = cond.operator || 'is';
        opSel.addEventListener('change', function () { cond.operator = opSel.value; sync(); });
        line.appendChild(opSel);

        var valInput = el('input', { type: 'text', 'class': 'gf-builder-cond-value', placeholder: i18n.condValue || 'Value', value: cond.value || '' });
        valInput.addEventListener('input', function () { cond.value = valInput.value; sync(); });
        line.appendChild(valInput);

        wrap.appendChild(line);
        return wrap;
    }

    ensureIds();
    sync();
    render();
})();
