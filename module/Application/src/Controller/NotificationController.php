<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Application\Model\NotificationModel;
use Application\Model\AuthModel;

class NotificationController extends AbstractActionController {
    private NotificationModel $notificationModel;

    public function __construct(NotificationModel $notificationModel, private AuthModel $authModel) {
        $this->notificationModel = $notificationModel;
    }

    /**
     * GET /api/notifications
     * Returns unread notifications + count for the logged-in user.
     */
    public function listAction(): JsonModel {
        $userId = $this->userId();
        if ($userId <= 0) {
            $this->getResponse()->setStatusCode(401);
            return new JsonModel(['success' => false, 'data' => null, 'message' => 'Nao autorizado.', 'count' => 0, 'notifications' => []]);
        }

        // Auto-generate before fetching (lightweight, runs fast due to NOT EXISTS guards)
        $this->notificationModel->generateNotifications($userId);

        $notifications = $this->notificationModel->getUnread($userId);
        $count = count($notifications);

        return new JsonModel([
            'success'       => true,
            'data'          => ['count' => $count, 'notifications' => $notifications],
            'message'       => 'OK',
            'count'         => $count,
            'notifications' => $notifications,
        ]);
    }

    /**
     * POST /api/notifications/read
     * Marks all notifications as read.
     */
    public function markReadAction(): JsonModel {
        $userId = $this->userId();
        if ($userId <= 0) {
            $this->getResponse()->setStatusCode(401);
            return new JsonModel(['success' => false, 'data' => null, 'message' => 'Nao autorizado.']);
        }
        $this->notificationModel->markAllRead($userId);

        return new JsonModel(['success' => true, 'data' => null, 'message' => 'OK']);
    }

    private function userId(): int {
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
        $header = (string)$this->getRequest()->getHeader('Authorization');
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            $user = $this->authModel->getUserByToken(trim($matches[1]));
            return $user ? (int)$user['id_usuario'] : 0;
        }
        return 0;
    }
}
