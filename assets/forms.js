/**
 * Gratis Forms — front-end submission handler.
 * Progressively enhances [gratis_form] forms with AJAX submission, inline
 * validation feedback and optional reCAPTCHA v3 token injection.
 */
(function () {
    'use strict';

    var cfg = window.GratisForms || {};

    function feedback(form, message, isError) {
        var box = form.querySelector('.gratis-form-feedback');
        if (!box) {
            return;
        }
        box.textContent = message || '';
        box.classList.toggle('is-error', !!isError);
        box.classList.toggle('is-success', !isError && !!message);
    }

    function clearErrors(form) {
        form.querySelectorAll('.gratis-form-field.has-error').forEach(function (el) {
            el.classList.remove('has-error');
        });
    }

    function showFieldErrors(form, errors) {
        Object.keys(errors || {}).forEach(function (key) {
            var input = form.querySelector('[name="' + key + '"], [name="' + key + '[]"]');
            if (input) {
                var field = input.closest('.gratis-form-field');
                if (field) {
                    field.classList.add('has-error');
                }
            }
        });
    }

    function submitForm(form) {
        var button = form.querySelector('.gratis-form-submit');
        var data = new FormData(form);
        data.append('action', cfg.action || 'gratis_form_submit');
        data.append('form_id', form.getAttribute('data-form-id'));

        if (button) {
            button.disabled = true;
            button.dataset.label = button.textContent;
            button.textContent = (cfg.i18n && cfg.i18n.sending) || 'Sending…';
        }

        fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                clearErrors(form);
                if (res && res.success) {
                    form.reset();
                    evaluateConditional(form);
                    feedback(form, res.data && res.data.message, false);
                } else {
                    var d = (res && res.data) || {};
                    feedback(form, d.message || (cfg.i18n && cfg.i18n.error), true);
                    showFieldErrors(form, d.errors);
                }
            })
            .catch(function () {
                feedback(form, (cfg.i18n && cfg.i18n.error) || 'Error', true);
            })
            .finally(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = button.dataset.label || 'Submit';
                }
            });
    }

    function handleSubmit(e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('gratis-form')) {
            return;
        }
        e.preventDefault();
        clearErrors(form);

        var needsCaptcha = form.getAttribute('data-recaptcha') === '1' && cfg.recaptchaSiteKey && window.grecaptcha;
        if (needsCaptcha) {
            window.grecaptcha.ready(function () {
                window.grecaptcha.execute(cfg.recaptchaSiteKey, { action: 'submit' }).then(function (token) {
                    var input = form.querySelector('[name="gf_recaptcha_token"]');
                    if (input) {
                        input.value = token;
                    }
                    submitForm(form);
                });
            });
        } else {
            submitForm(form);
        }
    }

    document.addEventListener('submit', handleSubmit, false);

    // --- Conditional fields -------------------------------------------------
    // Evaluates data-gf-cond-* rules rendered by Renderer::render_field and
    // shows/hides each field accordingly. Hidden fields are disabled so their
    // inputs are excluded from the submission (mirroring the server's
    // required-validation skip).

    function condWrappers(form) {
        return Array.prototype.slice.call(form.querySelectorAll('[data-gf-cond-field]'));
    }

    function wrapperValues(wrapper) {
        var out = [];
        if (!wrapper) {
            return out;
        }
        Array.prototype.forEach.call(wrapper.querySelectorAll('input, select, textarea'), function (inp) {
            var type = (inp.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                if (inp.checked) {
                    out.push(inp.value);
                }
            } else if (inp.tagName === 'SELECT' && inp.multiple) {
                Array.prototype.forEach.call(inp.options, function (opt) {
                    if (opt.selected) {
                        out.push(opt.value);
                    }
                });
            } else {
                out.push(inp.value);
            }
        });
        return out.map(function (s) { return (s == null ? '' : String(s)).trim(); });
    }

    function ruleMatches(op, values, needle) {
        needle = (needle == null ? '' : String(needle)).trim();
        if (op === 'is') {
            return values.indexOf(needle) !== -1;
        }
        if (op === 'is_not') {
            return values.indexOf(needle) === -1;
        }
        if (op === 'contains') {
            if (needle === '') {
                return false;
            }
            for (var i = 0; i < values.length; i++) {
                if (values[i].indexOf(needle) !== -1) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    function setFieldVisible(wrapper, show) {
        wrapper.hidden = !show;
        wrapper.style.display = show ? '' : 'none';
        if (show) {
            wrapper.removeAttribute('aria-hidden');
        } else {
            wrapper.setAttribute('aria-hidden', 'true');
        }
        Array.prototype.forEach.call(wrapper.querySelectorAll('input, select, textarea'), function (inp) {
            inp.disabled = !show;
        });
    }

    function evaluateConditional(form) {
        var wrappers = condWrappers(form);
        if (!wrappers.length) {
            return;
        }

        // Settle iteratively so chained rules resolve; bounded by field count
        // so a circular rule set just settles instead of looping forever.
        var vis = {};
        var max = wrappers.length + 1;
        var pass, i;
        for (pass = 0; pass < max; pass++) {
            var changed = false;
            for (i = 0; i < wrappers.length; i++) {
                var w = wrappers[i];
                var ctrlKey = w.getAttribute('data-gf-cond-field');
                var ctrl = ctrlKey ? form.querySelector('[data-gf-key="' + ctrlKey + '"]') : null;
                var values;
                if (!ctrl) {
                    values = [];
                } else {
                    var ctrlOwnKey = ctrl.getAttribute('data-gf-key');
                    // A controlling field that is itself hidden counts as empty.
                    values = (ctrlOwnKey && vis[ctrlOwnKey] === false) ? [] : wrapperValues(ctrl);
                }
                var matched = ruleMatches(w.getAttribute('data-gf-cond-op'), values, w.getAttribute('data-gf-cond-value'));
                var show = (w.getAttribute('data-gf-cond-action') === 'hide') ? !matched : matched;
                var ownKey = w.getAttribute('data-gf-key');
                if (vis[ownKey] !== show) {
                    vis[ownKey] = show;
                    changed = true;
                }
            }
            if (!changed) {
                break;
            }
        }

        for (i = 0; i < wrappers.length; i++) {
            setFieldVisible(wrappers[i], vis[wrappers[i].getAttribute('data-gf-key')] !== false);
        }
    }

    function initConditional(form) {
        if (!condWrappers(form).length) {
            return;
        }
        evaluateConditional(form);
        form.addEventListener('input', function () { evaluateConditional(form); });
        form.addEventListener('change', function () { evaluateConditional(form); });
    }

    function initAllConditional() {
        Array.prototype.forEach.call(document.querySelectorAll('.gratis-form'), initConditional);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllConditional);
    } else {
        initAllConditional();
    }
})();
