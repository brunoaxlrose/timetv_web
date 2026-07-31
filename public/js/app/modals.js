(function() {
    function invalidatePageCache() {
        Object.keys(window.__pageCache || {}).forEach(function(key) {
            if (key.indexOf('/lists') !== -1 || key.indexOf('/dashboard') !== -1 || key.indexOf('/stats') !== -1 || key.indexOf('/search') !== -1) {
                delete window.__pageCache[key];
            }
        });
    }

    window.openFeedbackModal = function() { $('#feedbackModal').addClass('active'); };
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
            $('#feedbackContent').attr('placeholder', 'Tens alguma sugestao ou nova ideia? Compartilhe conosco!');
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
    window.togglePasswordChangeFields = function() {
        var $f = $('#passwordChangeFields');
        var $b = $('#btnTogglePasswordChange');
        if ($f.hasClass('d-none')) {
            $f.removeClass('d-none');
            $b.html('<i class="bi bi-x-circle me-1"></i> Cancelar Alteracao');
        } else {
            $f.addClass('d-none');
            $b.html('<i class="bi bi-key me-1"></i> Trocar Senha');
            $('#profileCurrentPassword, #profileNewPassword, #profileConfirmNewPassword').val('');
        }
    };
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
    window.customConfirm = function(options) {
        var title = options.title || 'Confirmacao';
        var message = options.message || '';
        var onConfirm = options.onConfirm;
        var isDanger = options.isDanger !== false;
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
    window.confirmDeleteList = function(listId, listName) {
        window.customConfirm({
            title: 'Excluir lista',
            message: 'Deseja realmente excluir "' + listName + '"? Todos os itens associados serao removidos.',
            isDanger: true,
            onConfirm: function() {
                $.post('/api/lists/delete', { list_id: listId }, function(res) {
                    if (res.success) {
                        invalidatePageCache();
                        showToast(res.message, true);
                        loadPageContent('/lists', false);
                    } else {
                        showToast(res.message, false);
                    }
                }, 'json');
            }
        });
    };
    window.confirmClearLibrary = function() {
        window.customConfirm({
            title: 'Limpar Biblioteca',
            message: 'Tem certeza que deseja limpar toda a sua biblioteca?',
            isDanger: true,
            onConfirm: function() {
                showGlobalLoader(true);
                $.post('/api/clear-library', function(data) {
                    showToast(data.message, data.success);
                    if (data.success) setTimeout(function() { window.location.reload(); }, 1200);
                }, 'json').always(function() { showGlobalLoader(false); });
            }
        });
    };
    window.confirmDeleteAccount = function() {
        window.customConfirm({
            title: 'Eliminar Conta',
            message: 'ATENCAO: Esta acao e irreversivel.',
            isDanger: true,
            onConfirm: function() {
                showGlobalLoader(true);
                $.post('/api/delete-account', function(data) {
                    showToast(data.message, data.success);
                    if (data.success) setTimeout(function() { window.location.href = '/login'; }, 1200);
                }, 'json').always(function() { showGlobalLoader(false); });
            }
        });
    };
    window.openFilterModal = function() { $('#filterModal').addClass('active'); };
    window.closeFilterModal = function() { $('#filterModal').removeClass('active'); };
    window.openMediaTypeModal = function() { $('#mediaTypeModal').addClass('active'); };
    window.closeMediaTypeModal = function() { $('#mediaTypeModal').removeClass('active'); };
    window.promptCreateList = function() {
        const modalEl = document.getElementById('createListModal');
        if (!modalEl) return;
        const modal = new bootstrap.Modal(modalEl);
        const input = document.getElementById('newListNameInput');
        if (input) input.value = '';
        modal.show();
    };
    window.submitCreateList = function() {
        const input = document.getElementById('newListNameInput');
        const modalEl = document.getElementById('createListModal');
        if (!input || !modalEl) return;
        const name = input.value.trim();
        if (!name) {
            showToast('O nome da lista e obrigatorio!', false);
            return;
        }
        const modal = bootstrap.Modal.getInstance(modalEl);
        const btn = modalEl.querySelector('button[onclick="submitCreateList()"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Criando...';
        }
        $.post('/api/lists/create', { name: name }, function(res) {
            if (res.success) {
                invalidatePageCache();
                if (modal) modal.hide();
                showToast(res.message, true);
                loadPageContent(window.location.pathname.indexOf('/lists') !== -1 ? '/lists' : '/dashboard', false);
            } else {
                showToast(res.message, false);
            }
        }, 'json').fail(function() {
            showToast('Erro ao criar lista.', false);
        }).always(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerText = 'Criar';
            }
        });
    };
    window.openListItemsModal = function(listId) {
        const modalEl = document.getElementById('listItemsModal');
        const bodyEl = document.getElementById('listItemsModalBody');
        const titleEl = document.getElementById('listItemsModalTitle');
        const countEl = document.getElementById('listItemsModalCount');
        if (!modalEl || !bodyEl || !titleEl || !countEl) {
            loadPageContent('/lists', true);
            return;
        }

        titleEl.textContent = 'Carregando...';
        countEl.textContent = '';
        bodyEl.innerHTML = '<div class="text-center py-4 text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Carregando itens...</div>';

        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        $.getJSON('/api/lists/items?list_id=' + encodeURIComponent(listId), function(res) {
            if (!res.success) {
                bodyEl.innerHTML = '<div class="text-center py-4 text-danger small">' + (res.message || 'Erro ao carregar lista.') + '</div>';
                return;
            }

            titleEl.textContent = res.list_name || 'Lista';
            countEl.textContent = (res.items || []).length + ' itens';

            if (!res.items || res.items.length === 0) {
                bodyEl.innerHTML = '<div class="text-center py-4" style="color:#c8c8de;font-size:0.8rem;">Nenhum item nesta lista ainda.</div>';
                return;
            }

            var html = '<div class="list-modal-grid">';
            res.items.forEach(function(item) {
                var safeTitle = $('<div>').text(item.title || '').html();
                var safePoster = $('<div>').text(item.poster_url || '').html();
                html += '<div class="position-relative" id="list-modal-item-' + item.id_item + '">';
                html += '<button type="button" class="btn btn-danger btn-sm rounded-circle position-absolute top-0 end-0 m-1 d-flex align-items-center justify-content-center" style="width:20px;height:20px;font-size:0.6rem;z-index:10;padding:0;" onclick="removeItemFromCurrentList(' + listId + ',' + item.id_item + ')"><i class="bi bi-x"></i></button>';
                html += '<a href="/detail?id=' + item.id_item + '" class="text-decoration-none" onclick="closeListItemsModal()">';
                html += '<img src="' + safePoster + '" class="list-modal-poster" alt="">';
                html += '<div class="list-modal-title">' + safeTitle + '</div>';
                html += '</a></div>';
            });
            html += '</div>';
            bodyEl.innerHTML = html;
        }).fail(function() {
            bodyEl.innerHTML = '<div class="text-center py-4 text-danger small">Erro ao carregar itens da lista.</div>';
        });
    };
    window.closeListItemsModal = function() {
        var modalEl = document.getElementById('listItemsModal');
        if (!modalEl) return;
        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    };
    window.removeItemFromCurrentList = function(listId, itemId) {
        $.post('/api/lists/remove', { list_id: listId, item_id: itemId }, function(res) {
            if (res.success) {
                invalidatePageCache();
                showToast(res.message, true);
                openListItemsModal(listId);
            } else {
                showToast(res.message, false);
            }
        }).fail(function() {
            showToast('Erro ao remover item da lista.', false);
        });
    };
})();
