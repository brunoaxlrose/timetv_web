document.addEventListener("DOMContentLoaded", function() {
    window.switchProfileTab = function(tabElement, tabName) {
        $('.refract-profile-tab').removeClass('active');
        $(tabElement).addClass('active');

        $('.profile-tab-content').addClass('d-none');
        
        if (tabName === 'overview') {
            $('#profileTabOverview').removeClass('d-none');
        } else if (tabName === 'time') {
            $('#profileTabTime').removeClass('d-none');
        } else if (tabName === 'genres') {
            $('#profileTabGenres').removeClass('d-none');
        } else if (tabName === 'platforms') {
            $('#profileTabPlatforms').removeClass('d-none');
        }
    };

    window.openFeedbackModal = function() {
        $('#feedbackModal').addClass('active');
    };

    window.closeFeedbackModal = function() {
        $('#feedbackModal').removeClass('active');
    };

    window.switchFeedbackTab = function(type) {
        const $bugTab = $('#feedbackTabBug');
        const $suggestTab = $('#feedbackTabSuggest');
        const $typeInput = $('#feedbackType');
        const $textarea = $('#feedbackContent');
        
        $bugTab.removeClass('active');
        $suggestTab.removeClass('active');
        
        if (type === 'bug') {
            $bugTab.addClass('active');
            $typeInput.val('bug');
            $textarea.attr('placeholder', 'Descreve o bug: o que fizeste, o que aconteceu, o que esperavas.');
        } else {
            $suggestTab.addClass('active');
            $typeInput.val('suggest');
            $textarea.attr('placeholder', 'Tens alguma sugestão ou nova ideia? Compartilhe com a nossa equipe para ajudar a melhorar o app!');
        }
    };

    window.triggerScreenshotUpload = function() {
        $('#feedbackScreenshotInput').click();
    };

    window.handleScreenshotUpload = function(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const $spinner = $('#screenshotSpinner');
            const $icon = $('#screenshotIcon');
            const $btnText = $('#screenshotBtnText');

            // Show spinner effect
            $spinner.removeClass('d-none');
            $icon.addClass('d-none');
            $btnText.text('Processando imagem...');

            const reader = new FileReader();
            reader.onload = function(e) {
                $('#feedbackScreenshotBase64').val(e.target.result);
                $('#screenshotPreviewImg').attr('src', e.target.result);
                $('#screenshotPreviewContainer').removeClass('d-none');

                // Reset button text
                $spinner.addClass('d-none');
                $icon.removeClass('d-none');
                $btnText.text('Alterar screenshot');
            };
            reader.readAsDataURL(file);
        }
    };

    window.removeScreenshotPreview = function() {
        $('#feedbackScreenshotInput').val('');
        $('#feedbackScreenshotBase64').val('');
        $('#screenshotPreviewImg').attr('src', '');
        $('#screenshotPreviewContainer').addClass('d-none');
        $('#screenshotBtnText').text('Anexar screenshot');
    };

    const $profileForm = $('#editProfileForm');
    if ($profileForm.length) {
        $profileForm.on('submit', function(e) {
            e.preventDefault();
            const $alertBox = $('#profileAlert');
            $alertBox.addClass('d-none');
            showGlobalLoader(true);
            
            $.ajax({
                url: '/api/update-profile',
                type: 'POST',
                data: $profileForm.serialize(),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        $alertBox.text(data.message).removeClass('d-none');
                        showGlobalLoader(false);
                    }
                },
                error: function() {
                    $alertBox.text('Erro ao conectar. Tente novamente.').removeClass('d-none');
                    showGlobalLoader(false);
                }
            });
        });
    }

    const $feedbackForm = $('#feedbackForm');
    if ($feedbackForm.length) {
        $feedbackForm.on('submit', function(e) {
            e.preventDefault();
            showGlobalLoader(true);

            $.ajax({
                url: '/api/feedback',
                type: 'POST',
                data: $feedbackForm.serialize(),
                dataType: 'json',
                success: function(data) {
                    closeFeedbackModal();
                    showToast(data.message, data.success);
                    if (data.success) {
                        $feedbackForm[0].reset();
                        removeScreenshotPreview();
                    }
                },
                error: function() {
                    closeFeedbackModal();
                    showToast('Erro ao enviar feedback para o servidor.', false);
                },
                complete: function() {
                    showGlobalLoader(false);
                }
            });
        });
    }

    window.confirmClearLibrary = function() {
        if (confirm("Tens a certeza que desejas limpar TODA a tua biblioteca? Isto removerá todas as séries, filmes e episódios marcados como vistos.")) {
            showGlobalLoader(true);
            $.ajax({
                url: '/api/clear-library',
                type: 'POST',
                dataType: 'json',
                success: function(data) {
                    showToast(data.message, data.success);
                    if (data.success) {
                        setTimeout(function() { window.location.reload(); }, 1200);
                    }
                },
                error: function() {
                    showToast("Erro ao conectar com o servidor.", false);
                },
                complete: function() {
                    showGlobalLoader(false);
                }
            });
        }
    };

    window.confirmDeleteAccount = function() {
        if (confirm("ATENÇÃO: Tens a certeza que desejas ELIMINAR a tua conta? Esta ação é irreversível e desativará o teu acesso.")) {
            showGlobalLoader(true);
            $.ajax({
                url: '/api/delete-account',
                type: 'POST',
                dataType: 'json',
                success: function(data) {
                    showToast(data.message, data.success);
                    if (data.success) {
                        setTimeout(function() { window.location.href = '/login'; }, 1200);
                    }
                },
                error: function() {
                    showToast("Erro ao conectar com o servidor.", false);
                },
                complete: function() {
                    showGlobalLoader(false);
                }
            });
        }
    };
});

