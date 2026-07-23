<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Application\Model\NotificationModel;

class NotificationController extends AbstractActionController {
    private NotificationModel $notificationModel;

    public function __construct(NotificationModel $notificationModel) {
        $this->notificationModel = $notificationModel;
    }

    /**
     * GET /api/notifications
     * Returns unread notifications + count for the logged-in user.
     */
    public function listAction(): JsonModel {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'count' => 0, 'notifications' => []]);
        }

        $userId = (int)$_SESSION['user_id'];

        // Auto-generate before fetching (lightweight, runs fast due to NOT EXISTS guards)
        $this->notificationModel->generateNotifications($userId);

        $notifications = $this->notificationModel->getUnread($userId);
        $count = count($notifications);

        return new JsonModel([
            'success'       => true,
            'count'         => $count,
            'notifications' => $notifications,
        ]);
    }

    /**
     * POST /api/notifications/read
     * Marks all notifications as read.
     */
    public function markReadAction(): JsonModel {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false]);
        }

        $userId = (int)$_SESSION['user_id'];
        $this->notificationModel->markAllRead($userId);

        return new JsonModel(['success' => true]);
    }
}
