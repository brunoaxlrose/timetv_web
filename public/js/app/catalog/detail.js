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
    const $filledStars = $('#ratingStarsContainer .bi-star-fill');
    if ($filledStars.length) {
        selectedRating = parseInt($filledStars.last().data('value')) || 0;
    }

    // Re-enable save button when user types in textarea
    $(document).off('input', '#userComment').on('input', '#userComment', function() {
        $('#btnSaveReview').prop('disabled', false);
    });
});

window.setStarRating = function(rating) {
    selectedRating = rating;
    $('#ratingStarsContainer .star-btn').each(function() {
        const val = parseInt($(this).data('value'));
        if (val <= rating) {
            $(this).removeClass('bi-star text-muted').addClass('bi-star-fill text-warning');
        } else {
            $(this).removeClass('bi-star-fill text-warning').addClass('bi-star text-muted');
        }
    });
    $('#btnSaveReview').prop('disabled', false); // Enable button on rating change
};

window.savePersonalReview = function(itemId) {
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
