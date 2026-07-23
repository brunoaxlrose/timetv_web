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

$(document).ready(function() {
    // --- AUTHENTICATION HANDLERS VIA JQUERY $.ajax ---
    const $authForm = $('#authForm');
    if ($authForm.length) {
        $authForm.on('submit', function(e) {
            e.preventDefault();
            
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
                    $alertBox.text('Erro de conexão. Tente novamente.').removeClass('d-none');
                    $spinner.addClass('d-none');
                    $submitBtn.removeAttr('disabled');
                    showGlobalLoader(false);
                }
            });
        });
    }

    // Helper: save current tab as episodios and reload
    function reloadKeepEpisodesTab() {
        localStorage.setItem('activeTab_' + window.location.pathname, 'episodios');
        window.location.reload();
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
                    if (tvmazeId && action === 'add') {
                        setTimeout(function() { window.location.reload(); }, 800);
                        return;
                    }

                    if (action === 'add') {
                        $btnTrack.attr('data-action', 'remove');
                        $btnTrack.removeClass('btn-accent-outline').addClass('btn-danger');
                        $btnTrack.html('<i class="bi bi-bookmark-dash-fill"></i>');
                    } else {
                        $btnTrack.attr('data-action', 'add');
                        $btnTrack.removeClass('btn-danger').addClass('btn-accent-outline');
                        $btnTrack.html('<i class="bi bi-plus-lg"></i>');
                    }
                    setTimeout(function() { window.location.reload(); }, 600);
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
                                    setTimeout(reloadKeepEpisodesTab, 600);
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
                    if (isChecked) {
                        $btnEpisode.removeClass('checked').html('<i class="bi bi-check"></i>');
                    } else {
                        $btnEpisode.addClass('checked').html('<i class="bi bi-check-lg"></i>');
                    }
                    setTimeout(reloadKeepEpisodesTab, 600);
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
                    setTimeout(reloadKeepEpisodesTab, 600);
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
                    setTimeout(reloadKeepEpisodesTab, 600);
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
