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
