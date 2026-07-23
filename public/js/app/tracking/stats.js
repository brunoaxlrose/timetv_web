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
});

