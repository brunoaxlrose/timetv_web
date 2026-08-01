(function() {
    'use strict';

    const iconMap = {
        new_episode: 'bi-play-circle-fill',
        release_date: 'bi-calendar-event-fill',
        info: 'bi-info-circle-fill'
    };

    function timeAgo(dateStr) {
        const now = new Date();
        const then = new Date(dateStr);
        const diffMs = now - then;
        const diffMin = Math.floor(diffMs / 60000);
        if (diffMin < 1) return 'agora';
        if (diffMin < 60) return diffMin + 'm atrás';
        const diffH = Math.floor(diffMin / 60);
        if (diffH < 24) return diffH + 'h atrás';
        const diffD = Math.floor(diffH / 24);
        if (diffD < 7) return diffD + 'd atrás';
        return then.toLocaleDateString('pt-BR');
    }

    function renderNotifications(notifications) {
        const $list = $('#notifList');
        const $empty = $('#notifEmpty');
        const $badge = $('#notifBadge');
        $list.find('.notif-card').remove();
        if (!notifications || notifications.length === 0) {
            $empty.show();
            $badge.addClass('d-none');
            return;
        }
        $empty.hide();
        $badge.text(notifications.length > 99 ? '99+' : notifications.length).removeClass('d-none');
        notifications.forEach(function(n) {
            const iconClass = iconMap[n.tipo] || 'bi-bell-fill';
            const $card = $(`
                <div class="notif-card" data-id="${n.id_notificacao}" data-item="${n.id_item || ''}">
                    <div class="notif-card__icon notif-card__icon--${n.tipo}">
                        <i class="bi ${iconClass}"></i>
                    </div>
                    <div class="notif-card__body">
                        <div class="notif-card__title">${$('<span>').text(n.titulo).html()}</div>
                        <div class="notif-card__msg">${$('<span>').text(n.mensagem).html()}</div>
                        <div class="notif-card__time">${timeAgo(n.ts_criacao || n.ts_inclusao)}</div>
                    </div>
                    <div class="notif-card__dot"></div>
                </div>
            `);
            $card.on('click', function() {
                const itemId = $(this).data('item');
                if (itemId) window.location.href = '/detail?id=' + itemId;
            });
            $list.append($card);
        });
    }

    function fetchNotifications() {
        const $bell = $('#notifBellBtn');
        if (!$bell.length) return;
        $.ajax({
            url: '/api/notifications',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data && data.success) renderNotifications(data.notifications);
            }
        });
    }

    function closeAll() {
        $('#notifPanel').removeClass('open');
        $('#notifBellBtn').removeClass('active');
        $('#notifOverlay').addClass('d-none');
        $('#userDropdown').removeClass('show');
        $('#userMenuBtn').removeClass('active');
    }

    $(function() {
        fetchNotifications();
        $('#notifBellBtn').on('click', function(e) { e.stopPropagation(); closeAll(); $('#notifPanel').toggleClass('open'); $('#notifOverlay').toggleClass('d-none'); });
        $('#userMenuBtn').on('click', function(e) { e.stopPropagation(); closeAll(); $('#userDropdown').toggleClass('show'); $('#userMenuBtn').toggleClass('active'); });
        $('#markAllReadBtn').on('click', function(e) { e.stopPropagation(); $.post('/api/notifications/read', function(){ $('#notifList .notif-card').remove(); $('#notifEmpty').show(); $('#notifBadge').addClass('d-none'); }); });
        $('#notifOverlay').on('click', closeAll);
        $(document).on('click', function(e) { if (!$(e.target).closest('#notifPanel, #notifBellBtn, #userMenuWrap, #notifOverlay').length) closeAll(); });
        $('#userDropdown').on('click', function(e) { e.stopPropagation(); });
        $('#notifPanel').on('click', '.notif-card', closeAll);
        setInterval(fetchNotifications, 5 * 60 * 1000);
    });

    window.fetchNotifications = fetchNotifications;
    window.renderNotifications = renderNotifications;
})();
