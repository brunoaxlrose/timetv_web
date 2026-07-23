function toggleGoogleModal(show) {
    const $modal = $('#googleChooserModal');
    if (show) {
        $modal.removeClass('d-none').addClass('d-flex');
    } else {
        $modal.addClass('d-none').removeClass('d-flex');
    }
}

function submitGoogleOAuth(accountType) {
    const $alertBox = $('#authAlert');
    $alertBox.addClass('d-none');
    toggleGoogleModal(false);
    showGlobalLoader(true);
    
    $.ajax({
        url: '/api/google-login',
        type: 'POST',
        data: { account: accountType },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                window.location.href = data.redirect;
            } else {
                $alertBox.text(data.message).removeClass('d-none');
                showGlobalLoader(false);
            }
        },
        error: function() {
            $alertBox.text('Erro ao conectar com a API Google.').removeClass('d-none');
            showGlobalLoader(false);
        }
    });
}

function promptCustomGoogleAccount() {
    showGoogleAccountList(false);
}

function showGoogleAccountList(showList) {
    if (showList) {
        $('#googleChooserMain').removeClass('d-none');
        $('#googleCustomInputContainer').addClass('d-none');
    } else {
        $('#googleChooserMain').addClass('d-none');
        $('#googleCustomInputContainer').removeClass('d-none');
        $('#googleCustomEmailInput').focus();
    }
}

function submitCustomGoogleAccount() {
    const val = $('#googleCustomEmailInput').val().trim();
    if (val === '') {
        alert('Por favor, digite um e-mail válido.');
        return;
    }
    submitGoogleOAuth(val);
}

$(document).ready(function() {
    const $password = $('#password');
    const $passwordConfirm = $('#password_confirm');
    const $strengthBar = $('#passwordStrengthBar');
    const $strengthText = $('#passwordStrengthText');
    const $matchText = $('#passwordMatchText');
    const $form = $('#authForm');
    const $alertBox = $('#authAlert');

    function checkStrength() {
        const val = $password.val();
        let score = 0;

        if (val.length === 0) {
            $strengthBar.css('width', '0%').removeClass('bg-danger bg-warning bg-success');
            $strengthText.text('Mínimo de 6 caracteres').removeClass().addClass('text-muted small mt-1');
            return 0;
        }

        if (val.length >= 6) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[A-Z]/.test(val) || /[^A-Za-z0-9]/.test(val)) score++;

        $strengthBar.removeClass('bg-danger bg-warning bg-success');

        if (val.length < 6) {
            $strengthBar.css('width', '25%').addClass('bg-danger');
            $strengthText.text('Muito curta (mínimo 6 caracteres)').removeClass().addClass('text-danger small mt-1');
            return 1;
        }

        if (score === 1) {
            $strengthBar.css('width', '35%').addClass('bg-danger');
            $strengthText.text('Fraca').removeClass().addClass('text-danger small mt-1');
        } else if (score === 2) {
            $strengthBar.css('width', '65%').addClass('bg-warning');
            $strengthText.text('Média').removeClass().addClass('text-warning small mt-1');
        } else if (score >= 3) {
            $strengthBar.css('width', '100%').addClass('bg-success');
            $strengthText.text('Forte').removeClass().addClass('text-success small mt-1');
        }
        return score;
    }

    function checkMatch() {
        const p1 = $password.val();
        const p2 = $passwordConfirm.val();

        if (p2.length === 0) {
            $matchText.addClass('d-none');
            return false;
        }

        $matchText.removeClass('d-none');
        if (p1 === p2) {
            $matchText.text('As senhas coincidem').removeClass('text-danger').addClass('text-success');
            return true;
        } else {
            $matchText.text('As senhas não coincidem').removeClass('text-success').addClass('text-danger');
            return false;
        }
    }

    $password.on('input', function() {
        checkStrength();
        checkMatch();
    });

    $passwordConfirm.on('input', checkMatch);

    if ($form.length) {
        $form.off('submit').on('submit', function(e) {
            e.preventDefault();
            $alertBox.addClass('d-none');

            if ($password.val().length < 6) {
                $alertBox.text('A senha deve ter pelo menos 6 caracteres.').removeClass('d-none');
                return;
            }

            if (!checkMatch()) {
                $alertBox.text('As senhas digitadas não coincidem.').removeClass('d-none');
                return;
            }

            const $spinner = $('#btnSpinner');
            const $submitBtn = $form.find('button[type="submit"]');

            $spinner.removeClass('d-none');
            $submitBtn.attr('disabled', 'disabled');
            showGlobalLoader(true);

            $.ajax({
                url: $form.attr('action'),
                type: 'POST',
                data: $form.serialize(),
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
                    $alertBox.text('Erro interno no servidor.').removeClass('d-none');
                    $spinner.addClass('d-none');
                    $submitBtn.removeAttr('disabled');
                    showGlobalLoader(false);
                }
            });
        });
    }
});
