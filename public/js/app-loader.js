(function() {
    var files = [
        '/js/app/core.js',
        '/js/app/navigation.js',
        '/js/app/tracking.js',
        '/js/app/modals.js'
    ];
    files.forEach(function(src) {
        document.write('<script src="' + src + '"><\/script>');
    });
})();
