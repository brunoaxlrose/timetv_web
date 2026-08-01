<?php

namespace Application\Controller\Api;

use Application\Model\TrackingModel;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class EpisodeController extends AbstractActionController {
    public function __construct(private TrackingModel $trackingModel) {
    }

    public function markWatchedAction(): JsonModel {
        if (!isset($_SESSION['user_id'])) {
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

        $episodeId = (int)($payload['episode_id'] ?? 0);
        if ($episodeId <= 0) {
            return $this->jsonError('Episodio invalido.', 422);
        }

        if (!$this->trackingModel->isEpisodeReleased((string)$episodeId)) {
            return $this->jsonError('Este episodio ainda nao foi lancado.', 409);
        }

        try {
            $this->trackingModel->watchSingleEpisode((int)$_SESSION['user_id'], (string)$episodeId);

            return new JsonModel([
                'success' => true,
                'data' => [
                    'episode_id' => $episodeId,
                    'watched' => true,
                ],
                'message' => 'Episodio marcado como visto.',
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError('Erro ao marcar episodio.', 500);
        }
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
