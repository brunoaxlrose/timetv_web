<?php

namespace Application\Controller\Api;

use Application\Model\AuthModel;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class FeedbackController extends AbstractActionController {
    public function __construct(private AuthModel $authModel) {
    }

    public function createAction(): JsonModel {
        $userId = $this->userId();
        if ($userId <= 0) {
            return $this->jsonError('Nao autorizado.', 401);
        }

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonError('Metodo nao permitido.', 405);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->getPost()->toArray();
        }

        $type = trim((string)($payload['type'] ?? 'bug'));
        $content = trim((string)($payload['content'] ?? ''));
        $screenshot = trim((string)($payload['screenshot_base64'] ?? ''));
        $idempotencyHeader = $request->getHeader('X-Idempotency-Key');
        $mutationId = $idempotencyHeader
            ? trim((string)$idempotencyHeader->getFieldValue())
            : trim((string)($payload['id_mutacao_cliente'] ?? ''));

        if ($content === '') {
            return $this->jsonError('Escreva a mensagem do feedback.', 422);
        }

        try {
            $this->authModel->saveFeedback(
                $userId,
                $type,
                $content,
                $screenshot !== '' ? $screenshot : null,
                $mutationId !== '' ? $mutationId : null
            );

            return new JsonModel([
                'success' => true,
                'data' => null,
                'message' => 'Feedback registrado com sucesso.',
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError('Erro ao salvar feedback.', 500);
        }
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

    private function jsonError(string $message, int $statusCode): JsonModel {
        $this->getResponse()->setStatusCode($statusCode);

        return new JsonModel([
            'success' => false,
            'data' => null,
            'message' => $message,
        ]);
    }
}
