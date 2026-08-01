window.switchDetailTab = function(tab) {
    $('#tabSobreBtn').removeClass('active');
    const $epBtn = $('#tabEpisodiosBtn');
    if ($epBtn.length) $epBtn.removeClass('active');
    
    $('#tabSobreContent').addClass('d-none');
    const $epContent = $('#tabEpisodiosContent');
    if ($epContent.length) $epContent.addClass('d-none');

    if (tab === 'sobre') {
        $('#tabSobreBtn').addClass('active');
        $('#tabSobreContent').removeClass('d-none');
    } else {
        $('#tabEpisodiosBtn').addClass('active');
        $('#tabEpisodiosContent').removeClass('d-none');
    }
    
    localStorage.setItem('activeTab_' + window.location.search, tab);
}

function switchSeasonView(seasonNum) {
    const $content = $('#seasonContent' + seasonNum);
    const $arrow = $('#accordionArrow' + seasonNum);
    if ($content.length) {
        $content.removeClass('d-none').css('display', 'block');
        if ($arrow.length) $arrow.attr('class', 'bi bi-chevron-up text-gray');
        const box = document.getElementById('refractSeasonBox' + seasonNum);
        if (box) box.scrollIntoView({ behavior: 'smooth' });
    }
}

function toggleSeasonAccordion(season, event) {
    if (event && event.target.closest('.tvtime-circle-check')) {
        return; 
    }
    const $content = $('#seasonContent' + season);
    const $arrow = $('#accordionArrow' + season);
    if ($content.hasClass('d-none') || $content.css('display') === 'none') {
        $content.removeClass('d-none').css('display', 'block');
        $arrow.attr('class', 'bi bi-chevron-up text-gray');
    } else {
        $content.addClass('d-none').css('display', 'none');
        $arrow.attr('class', 'bi bi-chevron-down text-gray');
    }
}

$(document).ready(function() {
    const savedTab = localStorage.getItem('activeTab_' + window.location.search);
    if (savedTab === 'episodios' && !$('#tabEpisodiosBtn').length) {
        switchDetailTab('sobre');
    } else if (savedTab) {
        switchDetailTab(savedTab);
    }
});

window.showMoreEpisodes = function(seasonNum, button) {
    const $container = $('#seasonContent' + seasonNum);
    $container.find('.refract-episode-row.d-none').slice(0, 10).removeClass('d-none');
    if ($container.find('.refract-episode-row.d-none').length === 0) {
        $(button).closest('.btn-show-more-eps-container').remove();
    }
};

// --- STAR RATING & PERSONAL REVIEW LOGIC ---
var selectedRating = selectedRating || 0;

// Initialize selected rating from current stars on page load
$(function() {
    const TODAY = new Date('2026-07-31T00:00:00');
    const $metaBlock = $('#detailMetaBlock');
    const releaseType = String($metaBlock.data('type') || '');
    const releaseYear = parseInt($metaBlock.data('release-year') || '0', 10);
    const releaseDateRaw = String($metaBlock.data('release-date') || '');
    const releaseStatus = String($metaBlock.data('status') || '');
    const initialReleased = String($metaBlock.data('released') || '0') === '1';
    let forceLocked = !initialReleased;

    if (releaseDateRaw) {
        const parsed = new Date(releaseDateRaw + 'T00:00:00');
        if (!Number.isNaN(parsed.getTime())) {
            forceLocked = parsed > TODAY;
        }
    } else {
        const visibleText = $('#detailMetaBlock').text() || '';
        const visibleDateMatch = visibleText.match(/(\d{2})\/(\d{2})\/(\d{4})/);
        if (visibleDateMatch) {
            const day = parseInt(visibleDateMatch[1], 10);
            const month = parseInt(visibleDateMatch[2], 10) - 1;
            const year = parseInt(visibleDateMatch[3], 10);
            const parsedVisible = new Date(year, month, day);
            if (!Number.isNaN(parsedVisible.getTime())) {
                forceLocked = parsedVisible > TODAY;
            }
        } else if (releaseStatus === 'Upcoming') {
            forceLocked = true;
        } else if (releaseType === 'movie' && releaseYear >= 2026) {
            forceLocked = true;
        }
    }

    if (forceLocked) {
        $metaBlock.attr('data-released', '0');
        $('#ratingStarsContainer').attr('data-locked', '1').css({ pointerEvents: 'none', opacity: '0.35' });
        $('#userComment').prop('disabled', true).attr('placeholder', 'Disponível após o lançamento.');
        $('#btnSaveReview').prop('disabled', true).text('Disponível após o lançamento').css('pointer-events', 'none');
        $('.btn-track-toggle[data-status="completed"]').removeClass('btn-track-toggle').addClass('disabled').prop('disabled', true)
            .html('<i class="bi bi-clock me-2"></i> CONTEUDO AINDA NÃO LANÇADO')
            .css({ backgroundColor: '#2c2c3e', color: '#a5a5c0', cursor: 'not-allowed', opacity: '0.7' });
    }

    const $filledStars = $('#ratingStarsContainer .bi-star-fill');
    if ($filledStars.length) {
        selectedRating = parseInt($filledStars.last().data('value')) || 0;
    }

    if ($('#ratingStarsContainer').data('locked') === 1 || $('#ratingStarsContainer').data('locked') === '1') {
        $('#ratingStarsContainer, #userComment, #btnSaveReview').prop('disabled', true);
        $('#ratingStarsContainer .star-btn').css({ cursor: 'not-allowed', pointerEvents: 'none' });
    }

    // Re-enable save button when user types in textarea
    $(document).off('input', '#userComment').on('input', '#userComment', function() {
        if ($('#ratingStarsContainer').data('locked') !== 1 && $('#ratingStarsContainer').data('locked') !== '1') {
            $('#btnSaveReview').prop('disabled', false);
        }
    });
});

window.setStarRating = function(rating) {
    const locked = $('#ratingStarsContainer').data('locked');
    if (locked === 1 || locked === '1') return;
    selectedRating = rating;
    $('#ratingStarsContainer .star-btn').each(function() {
        const val = parseInt($(this).data('value'));
        if (val <= rating) {
            $(this).removeClass('bi-star text-muted').addClass('bi-star-fill text-warning');
        } else {
            $(this).removeClass('bi-star-fill text-warning').addClass('bi-star text-muted');
        }
    });
    $('#btnSaveReview').prop('disabled', false);
};

window.savePersonalReview = function(itemId) {
    const locked = $('#ratingStarsContainer').data('locked');
    if (locked === 1 || locked === '1') {
        showToast('Avaliação liberada somente após o lançamento.', false);
        return;
    }
    const commentVal = $('#userComment').val().trim();
    const $status = $('#saveStatus');
    const $btn = $('#btnSaveReview');

    if ($btn.prop('disabled')) return;

    $btn.prop('disabled', true).text('Salvando...');
    $status.text('Salvando...').removeClass('text-success text-danger').addClass('text-muted');

    $.ajax({
        url: '/api/save-review',
        type: 'POST',
        data: {
            item_id: itemId,
            rating: selectedRating > 0 ? selectedRating : '',
            comment: commentVal
        },
        success: function(response) {
            if (response.success) {
                $status.text('Salvo!').removeClass('text-muted text-danger').addClass('text-success');
                showToast('Avaliação salva com sucesso!', true);
                
                // Keep disabled after successful save
                $btn.prop('disabled', true);
                
                setTimeout(function() {
                    $status.text('');
                }, 3000);

                // Reload the details page contents instantly via SPA to show the new comment in the community list
                if (typeof loadPageContent === 'function') {
                    loadPageContent(window.location.pathname + window.location.search, false);
                }
            } else {
                $status.text('Erro').removeClass('text-muted text-success').addClass('text-danger');
                showToast(response.message || 'Erro ao salvar avaliação.', false);
                $btn.prop('disabled', false);
            }
        },
        error: function() {
            $status.text('Erro de rede').removeClass('text-muted text-success').addClass('text-danger');
            showToast('Erro de conexão ao salvar avaliação.', false);
            $btn.prop('disabled', false);
        },
        complete: function() {
            $btn.text('Salvar Avaliação');
        }
    });
};

window.showMoreComments = function(button) {
    const $container = $('#communityCommentsContainer');
    $container.find('.extra-comment.d-none').removeClass('d-none');
    $(button).closest('.text-center').remove();
};

window.rewatchEpisode = function(episodeId, button) {
    const $btn = $(button);
    if ($btn.hasClass('disabled')) return;
    $btn.addClass('disabled').css('opacity', '0.5');

    $.ajax({
        url: '/api/rewatch-episode',
        type: 'POST',
        data: { episode_id: episodeId },
        success: function(response) {
            if (response.success) {
                showToast('Episódio reassistido!', true);
                // Update badge count
                let $badge = $btn.find('.badge');
                if ($badge.length) {
                    $badge.text(response.rewatch_count + 'x');
                } else {
                    $btn.append('<span class="badge bg-warning text-black ms-0.5 px-1 py-0.5" style="font-size: 0.52rem; font-weight: 800; border-radius: 4px;">' + response.rewatch_count + 'x</span>');
                }
            } else {
                showToast(response.message || 'Erro ao reassistir.', false);
            }
        },
        error: function() {
            showToast('Erro de conexão ao reassistir.', false);
        },
        complete: function() {
            $btn.removeClass('disabled').css('opacity', '1');
        }
    });
};

$(document).on('click', '.btn-check-episode, .btn-check-season, .btn-check-all-show', function(e) {
    const upcoming = $(this).attr('data-upcoming') === '1' || $(this).hasClass('disabled');
    if (upcoming) {
        e.preventDefault();
        e.stopImmediatePropagation();
        showToast('Esse conteúdo ainda não foi lançado.', false);
        return false;
    }
});

$(document).on('click', '[data-locked="1"] #btnSaveReview, [data-locked="1"] .star-btn', function(e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    showToast('Avaliacao liberada somente apos o lancamento.', false);
    return false;
});
