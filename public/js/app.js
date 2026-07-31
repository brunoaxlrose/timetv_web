$.ajaxSetup({ cache: false });

// Helper function to show/hide global loading spinner overlay
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

// Global Toast function
function showToast(message, isSuccess = true) {
    const toastEl = document.getElementById('liveToast');
    if (!toastEl) return;
    const toast = new bootstrap.Toast(toastEl);
    const $msgEl = $('#toastMessage');
    const $iconEl = $('#toastIcon');
    
    $msgEl.text(message);
    if (isSuccess) {
        $iconEl.attr('class', 'bi bi-check-circle-fill text-success');
    } else {
        $iconEl.attr('class', 'bi bi-exclamation-circle-fill text-danger');
    }
    toast.show();
}

function loadPageContent(url, push = true, showLoader = true) {
    if (showLoader !== false) {
        showGlobalLoader(true);
    }

    // Programmatically close settings modal if open on navigation transition
    const $settingsModal = $('#editProfileModal');
    if ($settingsModal.length && $settingsModal.hasClass('show')) {
        const modalInstance = bootstrap.Modal.getInstance($settingsModal[0]);
        if (modalInstance) {
            modalInstance.hide();
        }
    }

    $.get(url, function(data) {
        // Update browser tab title
        const titleMatch = data.match(/<title>([\s\S]*?)<\/title>/i);
        if (titleMatch && titleMatch[1]) {
            document.title = titleMatch[1].trim();
        }

        if (push) {
            history.pushState({ url: url }, '', url);
        }
        const html = $.parseHTML(data, document, true);
        
        // Swap content using temporary container wrapper
        const $temp = $('<div>').append(html);
        const $newContent = $temp.find('.flex-grow-1');
        if ($newContent.length) {
            $('.flex-grow-1').html($newContent.html());
        } else {
            // Fallback: server returned view without layout (setTerminal(true))
            $('.flex-grow-1').html(html);
        }

        // Swap floating bar if exists
        const $newFloatingBar = $temp.find('.bottom-floating-bar');
        if ($newFloatingBar.length) {
            if ($('.bottom-floating-bar').length) {
                $('.bottom-floating-bar').replaceWith($newFloatingBar);
            } else {
                $('.flex-grow-1').append($newFloatingBar);
            }
        } else {
            $('.bottom-floating-bar').remove();
        }

        // Find and execute script tags (only if they are outside .flex-grow-1 to prevent duplicate runs)
        const $allScripts = $temp.find('script');
        const $insideScripts = $newContent.find('script');
        const $outsideScripts = $allScripts.filter(function() {
            const script = this;
            let isInside = false;
            $insideScripts.each(function() {
                if (this === script) {
                    isInside = true;
                }
            });
            return !isInside;
        });

        $outsideScripts.each(function() {
            const src = $(this).attr('src');
            if (src) {
                if (src.indexOf('app.js') === -1 && src.indexOf('bootstrap') === -1 && src.indexOf('jquery') === -1) {
                    $.ajax({
                        url: src,
                        dataType: "script",
                        cache: true,
                        async: false
                    });
                }
            } else {
                const scriptText = this.text || this.textContent || this.innerHTML || '';
                $.globalEval(scriptText);
            }
        });

        // Update active class on bottom-navbar
        $('.bottom-navbar .nav-item').removeClass('active');
        $('.bottom-navbar .nav-item').each(function() {
            const href = $(this).attr('href');
            const cleanUrl = url.split('?')[0]; // ignore query parameters
            if (cleanUrl === href || (href !== '/dashboard' && cleanUrl.startsWith(href))) {
                $(this).addClass('active');
            }
        });

        // Initialize dynamic tabs if we are on the detail page
        if (url.indexOf('/detail') !== -1) {
            const savedTab = localStorage.getItem('activeTab_' + window.location.search) || 'sobre';
            if (typeof switchDetailTab === 'function') {
                switchDetailTab(savedTab);
            } else {
                // Inline fallback if switchDetailTab is not globally declared yet
                $('#tabSobreBtn, #tabEpisodiosBtn').removeClass('active');
                $('#tabSobreContent, #tabEpisodiosContent').addClass('d-none');
                if (savedTab === 'sobre') {
                    $('#tabSobreBtn').addClass('active');
                    $('#tabSobreContent').removeClass('d-none');
                } else {
                    $('#tabEpisodiosBtn').addClass('active');
                    $('#tabEpisodiosContent').removeClass('d-none');
                }
            }
        }

        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'instant' });
    }).fail(function() {
        window.location.href = url;
    }).always(function() {
        showGlobalLoader(false);
    });
}

// Global Interceptor for SPA links
$(document).on('click', 'a', function(e) {
    const href = $(this).attr('href');
    const target = $(this).attr('target');
    
    if (!href || href.startsWith('http') || href.startsWith('#') || href.startsWith('javascript') || target === '_blank' || href === '/logout') {
        return;
    }
    
    e.preventDefault();
    loadPageContent(href, true);
});

// Browser navigation popstate listener
window.addEventListener('popstate', function(e) {
    loadPageContent(window.location.pathname + window.location.search, false);
});

$(document).ready(function() {
    // --- AUTHENTICATION HANDLERS VIA JQUERY $.ajax ---
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
            error: function(xhr, status, error) {
                let errMsg = 'Erro de conexão. Tente novamente.';
                if (xhr.status >= 400) {
                    try {
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errMsg = 'Erro no servidor: ' + xhr.responseJSON.message;
                        } else if (xhr.responseText) {
                            // Tenta extrair a mensagem de exceção do HTML do Laminas
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(xhr.responseText, 'text/html');
                            const preText = doc.querySelector('pre');
                            const h3Text = doc.querySelector('h3');
                            if (preText) {
                                errMsg = 'Erro no servidor: ' + preText.textContent.trim();
                            } else if (h3Text) {
                                errMsg = 'Erro no servidor: ' + h3Text.textContent.trim();
                            } else {
                                errMsg = 'Erro no servidor (' + xhr.status + '): ' + error;
                            }
                        }
                    } catch (e) {
                        errMsg = 'Erro no servidor (' + xhr.status + '): ' + error;
                    }
                }
                $alertBox.text(errMsg).removeClass('d-none');
                $spinner.addClass('d-none');
                $submitBtn.removeAttr('disabled');
                showGlobalLoader(false);
            }
        });
    });

    // Helper: silently refresh the layout's content container via AJAX
    function reloadKeepEpisodesTab() {
        const activeTab = localStorage.getItem('activeTab_' + window.location.search) || 'sobre';
        showGlobalLoader(true);
        
        $.get(window.location.href, function(data) {
            const html = $.parseHTML(data);
            
            // Swap the main flex-grow-1 content area
            const $newContent = $(html).find('.flex-grow-1');
            if ($newContent.length) {
                $('.flex-grow-1').html($newContent.html());
            }
            
            // Swap the bottom floating bar if it exists
            const $newFloatingBar = $(html).find('.bottom-floating-bar');
            if ($newFloatingBar.length) {
                $('.bottom-floating-bar').replaceWith($newFloatingBar);
            }
            
            // Restore active tab on detail page
            if (window.location.pathname.indexOf('/detail') !== -1) {
                localStorage.setItem('activeTab_' + window.location.search, activeTab);
                const tabBtnId = `#tab${activeTab.charAt(0).toUpperCase() + activeTab.slice(1)}Btn`;
                $(tabBtnId).click();
            }
        }).always(function() {
            showGlobalLoader(false);
        });
    }

    // --- GLOBAL DELEGATED CLICK EVENTS WITH JQUERY ---
    $(document).on('click', '.btn-track-toggle', function(e) {
        e.preventDefault();
        const $btnTrack = $(this);
        const itemId = $btnTrack.attr('data-item-id');
        const tvmazeId = $btnTrack.attr('data-tvmaze-id') || '';
        const action = $btnTrack.attr('data-action');
        const status = $btnTrack.attr('data-status') || 'watching';

        $btnTrack.attr('disabled', 'disabled');
        showGlobalLoader(true);

        $.ajax({
            url: '/api/track',
            type: 'POST',
            data: {
                item_id: itemId,
                tvmaze_id: tvmazeId,
                action: action,
                status: status
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showToast(data.message, true);
                    reloadKeepEpisodesTab();
                } else {
                    showToast(data.message, false);
                }
            },
            error: function() {
                showToast('Erro ao processar watchlist.', false);
            },
            complete: function() {
                $btnTrack.removeAttr('disabled');
                showGlobalLoader(false);
            }
        });
    });

    // 2. INDIVIDUAL EPISODE CHECK
    $(document).on('click', '.btn-check-episode', function(e) {
        e.preventDefault();
        const $btnEpisode = $(this);
        const episodeId = $btnEpisode.attr('data-episode-id');
        const itemId = $btnEpisode.attr('data-item-id');
        const isChecked = $btnEpisode.hasClass('checked');
        
        if (!isChecked) {
            const $allEps = $('.detail-episode-row .btn-check-episode');
            const clickedIndex = $allEps.index($btnEpisode);
            const hasPrecedingUnwatched = clickedIndex > 0 && $allEps.slice(0, clickedIndex).filter(function() {
                return !$(this).hasClass('checked');
            }).length > 0;

            if (hasPrecedingUnwatched) {
                const $modal = $('#tvtimeCustomModal');
                if ($modal.length) {
                    $modal.addClass('active');

                    const submitAction = function(toggleType) {
                        $modal.removeClass('active');
                        showGlobalLoader(true);
                        $btnEpisode.css('pointer-events', 'none');

                        $.ajax({
                            url: '/api/episode',
                            type: 'POST',
                            data: {
                                episode_id: episodeId,
                                item_id: itemId,
                                status: 'watch',
                                toggle_type: toggleType
                            },
                            dataType: 'json',
                            success: function(data) {
                                if (data.success) {
                                    showToast(data.message, true);
                                    reloadKeepEpisodesTab();
                                } else {
                                    showToast(data.message, false);
                                    showGlobalLoader(false);
                                    $btnEpisode.css('pointer-events', 'auto');
                                }
                            },
                            error: function() {
                                showToast('Erro ao atualizar episódios.', false);
                                showGlobalLoader(false);
                                $btnEpisode.css('pointer-events', 'auto');
                            }
                        });
                    };

                    $('#tvtimeModalBtnPreceding').off('click').on('click', function() { submitAction('preceding'); });
                    $('#tvtimeModalBtnAll').off('click').on('click', function() { submitAction('all'); });
                    $('#tvtimeModalBtnOnlyThis').off('click').on('click', function() { submitAction('episode'); });
                    $('#tvtimeModalBtnCancel').off('click').on('click', function() { $modal.removeClass('active'); });
                    return;
                }
            }
        }

        $btnEpisode.css('pointer-events', 'none');
        showGlobalLoader(true);

        $.ajax({
            url: '/api/episode',
            type: 'POST',
            data: {
                episode_id: episodeId,
                item_id: itemId,
                status: isChecked ? 'unwatch' : 'watch'
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showToast(data.message, true);
                    reloadKeepEpisodesTab();
                } else {
                    showToast(data.message, false);
                    $btnEpisode.css('pointer-events', 'auto');
                }
            },
            error: function() {
                showToast('Erro ao atualizar episódio.', false);
                $btnEpisode.css('pointer-events', 'auto');
            },
            complete: function() {
                showGlobalLoader(false);
            }
        });
    });

    // 3. SEASON CHECK
    $(document).on('click', '.btn-check-season', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $btnSeason = $(this);
        const seasonNum = $btnSeason.attr('data-season-number');
        const itemId = $btnSeason.attr('data-item-id');
        const isChecked = $btnSeason.hasClass('checked');

        $btnSeason.css('pointer-events', 'none');
        showGlobalLoader(true);

        $.ajax({
            url: '/api/episode',
            type: 'POST',
            data: {
                item_id: itemId,
                toggle_type: 'season',
                season_number: seasonNum,
                status: isChecked ? 'unwatch' : 'watch'
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showToast(data.message, true);
                    reloadKeepEpisodesTab();
                } else {
                    showToast(data.message, false);
                    $btnSeason.css('pointer-events', 'auto');
                }
            },
            error: function() {
                showToast('Erro ao atualizar temporada.', false);
                $btnSeason.css('pointer-events', 'auto');
            },
            complete: function() {
                showGlobalLoader(false);
            }
        });
    });

    // 4. CHECK ALL SHOW BUTTON
    $(document).on('click', '.btn-check-all-show', function(e) {
        e.preventDefault();
        const $btnCheckAll = $(this);
        const itemId = $btnCheckAll.attr('data-item-id');
        const isChecked = $btnCheckAll.hasClass('checked');

        $btnCheckAll.css('pointer-events', 'none');
        showGlobalLoader(true);

        $.ajax({
            url: '/api/episode',
            type: 'POST',
            data: {
                item_id: itemId,
                toggle_type: 'all',
                status: isChecked ? 'unwatch' : 'watch'
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showToast(data.message, true);
                    reloadKeepEpisodesTab();
                } else {
                    showToast(data.message, false);
                    $btnCheckAll.css('pointer-events', 'auto');
                }
            },
            error: function() {
                showToast('Erro ao atualizar toda a série.', false);
                $btnCheckAll.css('pointer-events', 'auto');
            },
            complete: function() {
                showGlobalLoader(false);
            }
        });
    });
});

/* =============================================
   GLOBAL TOP BAR — Notifications & User Menu
   ============================================= */
(function() {
    'use strict';

    // ---- Helpers ----
    function timeAgo(dateStr) {
        const now = new Date();
        const then = new Date(dateStr);
        const diffMs = now - then;
        const diffMin = Math.floor(diffMs / 60000);
        if (diffMin < 1)   return 'agora';
        if (diffMin < 60)  return diffMin + 'm atrás';
        const diffH = Math.floor(diffMin / 60);
        if (diffH < 24)    return diffH + 'h atrás';
        const diffD = Math.floor(diffH / 24);
        if (diffD < 7)     return diffD + 'd atrás';
        return then.toLocaleDateString('pt-BR');
    }

    const iconMap = {
        new_episode:  'bi-play-circle-fill',
        release_date: 'bi-calendar-event-fill',
        info:         'bi-info-circle-fill',
    };

    function renderNotifications(notifications) {
        const $list  = $('#notifList');
        const $empty = $('#notifEmpty');
        const $badge = $('#notifBadge');

        // Remove old cards (keep empty placeholder)
        $list.find('.notif-card').remove();

        if (!notifications || notifications.length === 0) {
            $empty.show();
            $badge.addClass('d-none');
            return;
        }

        $empty.hide();
        $badge.text(notifications.length > 99 ? '99+' : notifications.length).removeClass('d-none');

        notifications.forEach(function(n) {
            const iconClass = iconMap[n.tipo] || 'bi-bell-fill';
            const $card = $(`
                <div class="notif-card" data-id="${n.id_notificacao}" data-item="${n.id_item || ''}">
                    <div class="notif-card__icon notif-card__icon--${n.tipo}">
                        <i class="bi ${iconClass}"></i>
                    </div>
                    <div class="notif-card__body">
                        <div class="notif-card__title">${$('<span>').text(n.titulo).html()}</div>
                        <div class="notif-card__msg">${$('<span>').text(n.mensagem).html()}</div>
                        <div class="notif-card__time">${timeAgo(n.ts_criacao)}</div>
                    </div>
                    <div class="notif-card__dot"></div>
                </div>
            `);

            // Click: navigate to item detail if available
            $card.on('click', function() {
                const itemId = $(this).data('item');
                if (itemId) {
                    window.location.href = '/detail?id=' + itemId;
                }
            });

            $list.append($card);
        });
    }

    // ---- Fetch notifications ----
    function fetchNotifications() {
        const $bell = $('#notifBellBtn');
        if (!$bell.length) return; // Not logged in or no bar

        $.ajax({
            url: '/api/notifications',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data && data.success) {
                    renderNotifications(data.notifications);
                }
            },
            error: function() { /* silently ignore */ }
        });
    }

    // ---- Mark all read ----
    function markAllRead() {
        $.ajax({
            url: '/api/notifications/read',
            type: 'POST',
            dataType: 'json',
            success: function() {
                $('#notifList .notif-card').remove();
                $('#notifEmpty').show();
                $('#notifBadge').addClass('d-none');
            }
        });
    }

    // ---- Panel open/close ----
    function openNotifPanel() {
        $('#notifPanel').addClass('open');
        $('#notifBellBtn').addClass('active');
        $('#notifOverlay').removeClass('d-none');
    }

    function closeNotifPanel() {
        $('#notifPanel').removeClass('open');
        $('#notifBellBtn').removeClass('active');
        $('#notifOverlay').addClass('d-none');
    }

    // ---- User dropdown ----
    function toggleUserDropdown(e) {
        e.stopPropagation();
        const $dd = $('#userDropdown');
        const $btn = $('#userMenuBtn');
        const isOpen = $dd.hasClass('show');
        // Close all first
        closeAll();
        if (!isOpen) {
            $dd.addClass('show');
            $btn.addClass('active');
        }
    }

    function closeAll() {
        closeNotifPanel();
        $('#userDropdown').removeClass('show');
        $('#userMenuBtn').removeClass('active');
    }

    // ---- Init ----
    $(document).ready(function() {
        // Fetch on load
        fetchNotifications();

        // Bell button
        $('#notifBellBtn').on('click', function(e) {
            e.stopPropagation();
            const isOpen = $('#notifPanel').hasClass('open');
            closeAll();
            if (!isOpen) {
                openNotifPanel();
            }
        });

        // User menu button
        $('#userMenuBtn').on('click', toggleUserDropdown);

        // Mark all read
        $('#markAllReadBtn').on('click', function(e) {
            e.stopPropagation();
            markAllRead();
        });

        // Click outside or on overlay to close everything
        $('#notifOverlay').on('click', function() {
            closeAll();
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('#notifPanel, #notifBellBtn, #userMenuWrap, #notifOverlay').length) {
                closeAll();
            }
        });

        // Prevent dropdown from closing when clicking inside it
        $('#userDropdown').on('click', function(e) {
            e.stopPropagation();
        });

        // Close notif panel when clicking on a card's item link
        $('#notifPanel').on('click', '.notif-card', function() {
            closeNotifPanel();
        });

        // Auto-refresh notifications every 5 minutes
        setInterval(fetchNotifications, 5 * 60 * 1000);
    });
})();

/* =============================================
   GLOBAL MODAL & SETTINGS FUNCTIONS
   (available on every page since modals are in layout.phtml)
   ============================================= */

// --- Feedback Modal ---
window.openFeedbackModal  = function() { $('#feedbackModal').addClass('active'); };
window.closeFeedbackModal = function() { $('#feedbackModal').removeClass('active'); };

window.switchFeedbackTab = function(type) {
    $('#feedbackTabBug, #feedbackTabSuggest').removeClass('active');
    if (type === 'bug') {
        $('#feedbackTabBug').addClass('active');
        $('#feedbackType').val('bug');
        $('#feedbackContent').attr('placeholder', 'Descreve o bug: o que fizeste, o que aconteceu, o que esperavas.');
    } else {
        $('#feedbackTabSuggest').addClass('active');
        $('#feedbackType').val('suggest');
        $('#feedbackContent').attr('placeholder', 'Tens alguma sugestão ou nova ideia? Compartilhe conosco!');
    }
};

window.triggerScreenshotUpload = function() { $('#feedbackScreenshotInput').click(); };

window.handleScreenshotUpload = function(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        $('#screenshotSpinner').removeClass('d-none');
        $('#screenshotIcon').addClass('d-none');
        $('#screenshotBtnText').text('Processando imagem...');
        reader.onload = function(e) {
            $('#feedbackScreenshotBase64').val(e.target.result);
            $('#screenshotPreviewImg').attr('src', e.target.result);
            $('#screenshotPreviewContainer').removeClass('d-none');
            $('#screenshotSpinner').addClass('d-none');
            $('#screenshotIcon').removeClass('d-none');
            $('#screenshotBtnText').text('Alterar screenshot');
        };
        reader.readAsDataURL(input.files[0]);
    }
};

window.removeScreenshotPreview = function() {
    $('#feedbackScreenshotInput').val('');
    $('#feedbackScreenshotBase64').val('');
    $('#screenshotPreviewImg').attr('src', '');
    $('#screenshotPreviewContainer').addClass('d-none');
    $('#screenshotBtnText').text('Anexar screenshot');
};

// --- Profile Password Toggle ---
window.togglePasswordChangeFields = function() {
    var $f = $('#passwordChangeFields'), $b = $('#btnTogglePasswordChange');
    if ($f.hasClass('d-none')) {
        $f.removeClass('d-none');
        $b.html('<i class="bi bi-x-circle me-1"></i> Cancelar Alteração');
    } else {
        $f.addClass('d-none');
        $b.html('<i class="bi bi-key me-1"></i> Trocar Senha');
        $('#profileCurrentPassword, #profileNewPassword, #profileConfirmNewPassword').val('');
    }
};

// --- Settings Tab Switch ---
window.switchSettingsTab = function(tab) {
    $('#settingsTabPerfil, #settingsTabDados').removeClass('active');
    $('#settingsPanelPerfil, #settingsPanelDados').addClass('d-none');
    if (tab === 'perfil') {
        $('#settingsTabPerfil').addClass('active');
        $('#settingsPanelPerfil').removeClass('d-none');
    } else {
        $('#settingsTabDados').addClass('active');
        $('#settingsPanelDados').removeClass('d-none');
    }
};

// --- Custom Confirm ---
window.customConfirm = function(options) {
    var title     = options.title   || 'Confirmação';
    var message   = options.message || '';
    var onConfirm = options.onConfirm;
    var isDanger  = options.isDanger !== false;
    $('#confirmModalTitle').text(title);
    $('#confirmModalMessage').text(message);
    if (isDanger) {
        $('#confirmModalIconBg').css('background-color', 'rgba(255,59,48,0.1)');
        $('#confirmModalIcon').attr('class', 'bi bi-exclamation-triangle-fill fs-4 text-danger');
        $('#btnConfirmAction').attr('class', 'btn btn-danger rounded-pill w-50 py-2 small fw-bold');
    } else {
        $('#confirmModalIconBg').css('background-color', 'rgba(124,77,255,0.1)');
        $('#confirmModalIcon').attr('class', 'bi bi-question-circle-fill fs-4').css('color', '#a88beb');
        $('#btnConfirmAction').attr('class', 'btn rounded-pill w-50 py-2 small fw-bold text-white').css('background-color', '#7c4dff');
    }
    $('#btnConfirmAction').off('click').on('click', function() {
        bootstrap.Modal.getInstance(document.getElementById('customConfirmModal')).hide();
        if (onConfirm) onConfirm();
    });
    new bootstrap.Modal(document.getElementById('customConfirmModal')).show();
};

// --- Logout Confirm ---
window.confirmLogout = function(event) {
    if (event) event.preventDefault();
    window.customConfirm({
        title: 'Sair do Sistema',
        message: 'Tem certeza que deseja sair do aplicativo Time View?',
        isDanger: false,
        onConfirm: function() {
            showToast('Efetuando logout...', true);
            setTimeout(function() { window.location.href = '/logout'; }, 1000);
        }
    });
};

// --- Clear Library Confirm ---
window.confirmClearLibrary = function() {
    window.customConfirm({
        title: 'Limpar Biblioteca',
        message: 'Tem certeza que deseja limpar toda a sua biblioteca? Isso removerá permanentemente todas as séries, filmes e episódios assistidos.',
        isDanger: true,
        onConfirm: function() {
            showGlobalLoader(true);
            $.ajax({ url: '/api/clear-library', type: 'POST', dataType: 'json',
                success: function(data) {
                    showToast(data.message, data.success);
                    if (data.success) setTimeout(function() { window.location.reload(); }, 1200);
                },
                error: function() { showToast('Erro ao conectar com o servidor.', false); },
                complete: function() { showGlobalLoader(false); }
            });
        }
    });
};

// --- Delete Account Confirm ---
window.confirmDeleteAccount = function() {
    window.customConfirm({
        title: 'Eliminar Conta',
        message: 'ATENÇÃO: Esta ação é irreversível. Todos os seus dados serão removidos permanentemente.',
        isDanger: true,
        onConfirm: function() {
            showGlobalLoader(true);
            $.ajax({ url: '/api/delete-account', type: 'POST', dataType: 'json',
                success: function(data) {
                    showToast(data.message, data.success);
                    if (data.success) setTimeout(function() { window.location.href = '/login'; }, 1200);
                },
                error: function() { showToast('Erro ao conectar com o servidor.', false); },
                complete: function() { showGlobalLoader(false); }
            });
        }
    });
};

/* =============================================
   IMPORT / EXPORT HANDLERS
   ============================================= */
$(document).ready(function() {
    // Profile form submit (settings modal)
    $(document).on('submit', '#editProfileForm', function(e) {
        e.preventDefault();
        var $alertBox = $('#profileAlert');
        $alertBox.addClass('d-none');
        showGlobalLoader(true);
        $.ajax({
            url: '/api/update-profile', type: 'POST', data: $(this).serialize(), dataType: 'json',
            success: function(data) {
                if (data.success) { window.location.reload(); }
                else { $alertBox.text(data.message).removeClass('d-none'); showGlobalLoader(false); }
            },
            error: function() { $alertBox.text('Erro ao conectar.').removeClass('d-none'); showGlobalLoader(false); }
        });
    });

    // Feedback form submit
    $(document).on('submit', '#feedbackForm', function(e) {
        e.preventDefault();
        showGlobalLoader(true);
        $.ajax({
            url: '/api/feedback', type: 'POST', data: $(this).serialize(), dataType: 'json',
            success: function(data) {
                closeFeedbackModal();
                showToast(data.message, data.success);
                if (data.success) { $('#feedbackForm')[0].reset(); removeScreenshotPreview(); }
            },
            error: function() { closeFeedbackModal(); showToast('Erro ao enviar feedback.', false); },
            complete: function() { showGlobalLoader(false); }
        });
    });

    // Toggle widget panel visibility
    window.toggleImportWidget = function(showPanel) {
        var $panel = $('#importWidgetPanel');
        if (showPanel) {
            $panel.toggleClass('d-none');
        } else {
            $panel.addClass('d-none');
        }
    };

    // CSV file selection
    $(document).on('change', '#importCsvFile', function() {
        var fileName = this.files[0] ? this.files[0].name : '';
        if (fileName) {
            $('#importFileName').text(fileName);
            $('#importUploadLabel').addClass('has-file');
            $('#btnStartImport').removeClass('d-none');
            $('#importResult').addClass('d-none');
        }
    });

    // Import button click (background process)
    $(document).on('click', '#btnStartImport', function() {
        var fileInput = document.getElementById('importCsvFile');
        if (!fileInput || !fileInput.files[0]) return;

        var file = fileInput.files[0];
        var formData = new FormData();
        formData.append('csv_file', file);

        // Close settings modal immediately to let the user browse
        var settingsModal = bootstrap.Modal.getInstance(document.getElementById('editProfileModal'));
        if (settingsModal) {
            settingsModal.hide();
        }

        // Show and populate the floating background import widget
        var $widget = $('#importWidget');
        var $widgetName = $('#widgetFileName');
        $widgetName.text(file.name);
        $widget.removeClass('d-none');
        $('#importWidgetPanel').removeClass('d-none'); // open panel on start

        // Reset the form input inside settings so they can import another CSV if they open settings again
        $('#importCsvFile').val('');
        $('#importFileName').text('Clique para selecionar o CSV');
        $('#importUploadLabel').removeClass('has-file');
        $('#btnStartImport').addClass('d-none');

        $.ajax({
            url: '/api/import',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 300000, // 5 min
            success: function(data) {
                if (data.success) {
                    showToast('Importação de "' + file.name + '" finalizada com sucesso! Verifique suas notificações.', true);
                } else {
                    showToast('Falha na importação: ' + data.message, false);
                }
            },
            error: function(xhr) {
                showToast('Erro ao importar o arquivo "' + file.name + '".', false);
            },
            complete: function() {
                // Hide floating widget
                $widget.addClass('d-none');
                $('#importWidgetPanel').addClass('d-none');

                // Refresh global top bar notifications
                if (typeof fetchNotifications === 'function') {
                    fetchNotifications();
                } else {
                    // Try direct call via jquery trigger or refresh the badge count
                    $.ajax({
                        url: '/api/notifications',
                        type: 'GET',
                        dataType: 'json',
                        success: function(d) {
                            if (d && d.success && typeof renderNotifications === 'function') {
                                renderNotifications(d.notifications);
                            } else if (d && d.success) {
                                var $badge = $('#notifBadge');
                                if (d.notifications.length > 0) {
                                    $badge.text(d.notifications.length > 99 ? '99+' : d.notifications.length).removeClass('d-none');
                                } else {
                                    $badge.addClass('d-none');
                                }
                            }
                        }
                    });
                }
            }
        });
    });

    // Reset import state when settings modal closes
    $(document).on('hidden.bs.modal', '#editProfileModal', function() {
        switchSettingsTab('perfil');
        $('#importCsvFile').val('');
        $('#importFileName').text('Clique para selecionar o CSV');
        $('#importUploadLabel').removeClass('has-file');
        $('#btnStartImport').addClass('d-none');
        $('#importProgress').addClass('d-none');
        $('#importResult').addClass('d-none');
    });
});



