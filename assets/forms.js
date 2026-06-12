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
})();
