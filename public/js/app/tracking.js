$(function() {
    function reloadKeepEpisodesTab() {
        const activeTab = localStorage.getItem('activeTab_' + window.location.search) || 'sobre';
        showGlobalLoader(true);
        $.get(window.location.href, function(data) {
            const html = $.parseHTML(data);
            const $newContent = $(html).find('.flex-grow-1');
            if ($newContent.length) $('.flex-grow-1').html($newContent.html());
            const $newFloatingBar = $(html).find('.bottom-floating-bar');
            if ($newFloatingBar.length) $('.bottom-floating-bar').replaceWith($newFloatingBar);
            if (window.location.pathname.indexOf('/detail') !== -1) {
                localStorage.setItem('activeTab_' + window.location.search, activeTab);
                const tabBtnId = `#tab${activeTab.charAt(0).toUpperCase() + activeTab.slice(1)}Btn`;
                $(tabBtnId).click();
            }
        }).always(function() { showGlobalLoader(false); });
    }

    $(document).on('click', '.btn-track-toggle', function(e) {
        e.preventDefault();
        const $btnTrack = $(this);
        $btnTrack.attr('disabled', 'disabled');
        showGlobalLoader(true);
        $.post('/api/track', {
            item_id: $btnTrack.attr('data-item-id'),
            tvmaze_id: $btnTrack.attr('data-tvmaze-id') || '',
            action: $btnTrack.attr('data-action'),
            status: $btnTrack.attr('data-status') || 'watching'
        }, function(data) {
            if (data.success) { showToast(data.message, true); reloadKeepEpisodesTab(); }
            else showToast(data.message, false);
        }, 'json').always(function() { $btnTrack.removeAttr('disabled'); showGlobalLoader(false); });
    });

    $(document).on('click', '.btn-favorite-toggle', function(e) {
        e.preventDefault();
        const $btn = $(this);
        $btn.attr('disabled', 'disabled');
        $.post('/api/favorite/toggle', { item_id: $btn.attr('data-item-id') }, function(data) {
            if (data.success) {
                showToast(data.message, true);
                const active = !!data.is_favorite;
                $btn.toggleClass('active', active);
                $btn.find('i').attr('class', active ? 'bi bi-heart-fill' : 'bi bi-heart');
            } else showToast(data.message, false);
        }, 'json').always(function() { $btn.removeAttr('disabled'); });
    });

    $(document).on('click', '.btn-check-episode, .btn-check-season, .btn-check-all-show', function(e) {
        // keep existing detail.js handlers; this file only holds shared loader for track/favorite.
    });
});
