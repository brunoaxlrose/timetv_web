$.ajaxSetup({ cache: false });
var __pageCache = window.__pageCache || {};
window.__pageCache = __pageCache;

function showGlobalLoader(show) {
    const $loader = $('#globalLoader');
    if ($loader.length) {
        if (show) {
            $loader.removeClass('d-none');
        } else {
            $loader.addClass('d-none');
        }
    }
}

function showToast(message, isSuccess = true) {
    const toastEl = document.getElementById('liveToast');
    if (!toastEl) return;
    const toast = new bootstrap.Toast(toastEl);
    const $msgEl = $('#toastMessage');
    const $iconEl = $('#toastIcon');
    $msgEl.text(message);
    $iconEl.attr('class', isSuccess ? 'bi bi-check-circle-fill text-success' : 'bi bi-exclamation-circle-fill text-danger');
    toast.show();
}

function loadPageContent(url, push = true, showLoader = true, forceRefresh = false) {
    if (showLoader !== false) showGlobalLoader(true);

    const $settingsModal = $('#editProfileModal');
    if ($settingsModal.length && $settingsModal.hasClass('show')) {
        const modalInstance = bootstrap.Modal.getInstance($settingsModal[0]);
        if (modalInstance) modalInstance.hide();
    }

    const cachedHtml = forceRefresh ? null : __pageCache[url];
    const renderPage = function(data) {
        const titleMatch = data.match(/<title>([\s\S]*?)<\/title>/i);
        if (titleMatch && titleMatch[1]) document.title = titleMatch[1].trim();
        if (push) history.pushState({ url: url }, '', url);

        const html = $.parseHTML(data, document, true);
        const $temp = $('<div>').append(html);
        const $newContent = $temp.find('.flex-grow-1');
        $('.flex-grow-1').html($newContent.length ? $newContent.html() : html);

        const $newFloatingBar = $temp.find('.bottom-floating-bar');
        if ($newFloatingBar.length) {
            if ($('.bottom-floating-bar').length) $('.bottom-floating-bar').replaceWith($newFloatingBar);
            else $('.flex-grow-1').append($newFloatingBar);
        } else {
            $('.bottom-floating-bar').remove();
        }

        const $allScripts = $temp.find('script');
        const $insideScripts = $newContent.find('script');
        $allScripts.filter(function() {
            let inside = false;
            $insideScripts.each(function() { if (this === arguments[0]) inside = true; });
            return !inside;
        }).each(function() {
            const src = $(this).attr('src');
            if (src) {
                if (src.indexOf('app.js') === -1 && src.indexOf('bootstrap') === -1 && src.indexOf('jquery') === -1) {
                    $.ajax({ url: src, dataType: 'script', cache: true, async: false });
                }
            } else {
                $.globalEval(this.text || this.textContent || this.innerHTML || '');
            }
        });

        $('.bottom-navbar .nav-item').removeClass('active');
        $('.bottom-navbar .nav-item').each(function() {
            const href = $(this).attr('href');
            const cleanUrl = url.split('?')[0];
            if (cleanUrl === href || (href !== '/dashboard' && cleanUrl.startsWith(href))) {
                $(this).addClass('active');
            }
        });

        if (url.indexOf('/detail') !== -1) {
            const savedTab = localStorage.getItem('activeTab_' + window.location.search) || 'sobre';
            if (typeof switchDetailTab === 'function') switchDetailTab(savedTab);
        }

        window.scrollTo({ top: 0, behavior: 'instant' });
    };

    if (cachedHtml) {
        renderPage(cachedHtml);
        showGlobalLoader(false);
        return;
    }

    $.get(url, function(data) {
        __pageCache[url] = data;
        renderPage(data);
    }).fail(function() {
        window.location.href = url;
    }).always(function() {
        showGlobalLoader(false);
    });
}

$(document).on('click', 'a', function(e) {
    const href = $(this).attr('href');
    const target = $(this).attr('target');
    if (!href || href.startsWith('http') || href.startsWith('#') || href.startsWith('javascript') || target === '_blank' || href === '/logout') return;
    e.preventDefault();
    const forceRefresh = $(this).attr('data-force-refresh') === '1';
    if (forceRefresh) {
        delete __pageCache[href];
    }
    loadPageContent(href, true, true, forceRefresh);
});

window.addEventListener('popstate', function() {
    loadPageContent(window.location.pathname + window.location.search, false);
});

$(document).ready(function() {
    $(document).on('submit', '#authForm', function(e) {
        e.preventDefault();

        const $authForm = $(this);
        const $alertBox = $('#authAlert');
        const $spinner = $('#btnSpinner');
        const $submitBtn = $authForm.find('button[type="submit"]');

        $alertBox.addClass('d-none');
        $spinner.removeClass('d-none');
        $submitBtn.attr('disabled', 'disabled');
        showGlobalLoader(true);

        $.ajax({
            url: $authForm.attr('action'),
            type: 'POST',
            data: $authForm.serialize(),
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    $alertBox.text(data.message).removeClass('d-none');
                    $spinner.addClass('d-none');
                    $submitBtn.removeAttr('disabled');
                    showGlobalLoader(false);
                }
            },
            error: function() {
                $alertBox.text('Erro de conexao. Tente novamente.').removeClass('d-none');
                $spinner.addClass('d-none');
                $submitBtn.removeAttr('disabled');
                showGlobalLoader(false);
            }
        });
    });

    window.submitProfileSettings = function() {
        $('#editProfileForm').trigger('submit');
    };

    $(document).on('submit', '#editProfileForm', function(e) {
        e.preventDefault();

        const $form = $(this);
        const $alertBox = $('#profileAlert');
        const $submitBtn = $('#btnSaveProfileSettings');
        const originalBtnText = $submitBtn.text();

        $alertBox.addClass('d-none').text('');
        $submitBtn.prop('disabled', true).text('Salvando...');
        showGlobalLoader(true);

        $.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showToast(data.message || 'Perfil atualizado com sucesso!', true);

                    const nome = ($('#profileNome').val() || '').trim();
                    const sobrenome = ($('#profileSobrenome').val() || '').trim();
                    const username = ($('#profileUsername').val() || '').trim();
                    const fullName = (nome + ' ' + sobrenome).trim();

                    $('.app-top-bar__user-name').text(fullName || username);
                    $('.app-top-bar__user-handle').text('@' + username);

                    const $statsHeaderName = $('h5').filter(function() {
                        return $(this).find('+ span').length > 0;
                    }).first();
                    if ($statsHeaderName.length && fullName) {
                        $statsHeaderName.contents().first()[0].textContent = fullName + ' ';
                    }

                    $('#passwordChangeFields').addClass('d-none');
                    $('#profileCurrentPassword, #profileNewPassword, #profileConfirmNewPassword').val('');
                    $('#btnTogglePasswordChange').html('<i class="bi bi-key me-1"></i> Trocar Senha');

                    const modalEl = document.getElementById('editProfileModal');
                    const modalInstance = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
                    if (modalInstance) {
                        modalInstance.hide();
                    }

                    Object.keys(__pageCache).forEach(function(key) {
                        if (key.indexOf('/stats') !== -1 || key.indexOf('/search') !== -1 || key.indexOf('/dashboard') !== -1) {
                            delete __pageCache[key];
                        }
                    });
                } else {
                    $alertBox.text(data.message || 'Erro ao atualizar perfil.').removeClass('d-none');
                }
            },
            error: function() {
                $alertBox.text('Erro de conexao ao atualizar perfil.').removeClass('d-none');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).text(originalBtnText);
                showGlobalLoader(false);
            }
        });
    });
});
