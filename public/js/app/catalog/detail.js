function switchDetailTab(tab) {
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
