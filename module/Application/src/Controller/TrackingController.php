<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;

class TrackingController extends AbstractActionController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function dashboardAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];
        $currentType = $this->params()->fromQuery('type', 'series');

        // Fetch user's tracked items based on selected tab type (Movies or Series/Anime)
        if ($currentType === 'movie') {
            $stmt = $this->pdo->prepare("
                SELECT i.*, ui.status as track_status
                FROM usuario_item ui
                JOIN item i ON ui.id_item = i.id_item
                WHERE ui.id_usuario = :user_id AND i.type = 'movie'
                ORDER BY ui.ts_atualizacao DESC
            ");
        } else {
            $stmt = $this->pdo->prepare("
                SELECT i.*, ui.status as track_status
                FROM usuario_item ui
                JOIN item i ON ui.id_item = i.id_item
                WHERE ui.id_usuario = :user_id AND i.type IN ('series', 'anime')
                ORDER BY ui.ts_atualizacao DESC
            ");
        }
        
        $stmt->execute([':user_id' => $userId]);
        $trackedItems = $stmt->fetchAll();

        // Fetch remaining count and next episode details for each item
        foreach ($trackedItems as &$item) {
            if ($item['type'] !== 'movie') {
                $stmt = $this->pdo->prepare("
                    SELECT 
                        COUNT(e.id_episodio) as total_count,
                        COUNT(ue.id_usuario_episodio) as watched_count
                    FROM episodio e
                    LEFT JOIN usuario_episodio ue ON e.id_episodio = ue.id_episodio AND ue.id_usuario = :user_id
                    WHERE e.id_item = :item_id
                ");
                $stmt->execute([':item_id' => $item['id_item'], ':user_id' => $userId]);
                $item['progress'] = $stmt->fetch();

                $stmt = $this->pdo->prepare("
                    SELECT e.* 
                    FROM episodio e
                    WHERE e.id_item = :item_id 
                      AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id)
                    ORDER BY e.season_number ASC, e.episode_number ASC
                    LIMIT 1
                ");
                $stmt->execute([':item_id' => $item['id_item'], ':user_id' => $userId]);
                $item['next_episode'] = $stmt->fetch();

                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) 
                    FROM episodio e
                    WHERE e.id_item = :item_id 
                      AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id)
                ");
                $stmt->execute([':item_id' => $item['id_item'], ':user_id' => $userId]);
                $item['remaining_count'] = intval($stmt->fetchColumn());
            } else {
                $item['progress'] = [
                    'total_count' => 1,
                    'watched_count' => ($item['track_status'] === 'completed') ? 1 : 0
                ];
            }
        }
        unset($item);

        $view = new ViewModel([
            'trackedItems' => $trackedItems,
            'currentType' => $currentType
        ]);
        $this->layout()->title = "Dashboard - Time View";
        return $view;
    }

    public function statsAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM usuario_episodio WHERE id_usuario = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $totalEpisodesWatched = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT i.type, COUNT(ui.id_usuario_item) as count
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
            GROUP BY i.type
        ");
        $stmt->execute([':user_id' => $userId]);
        $counts = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        $seriesCount = $counts['series'] ?? 0;
        $animeCount = $counts['anime'] ?? 0;
        $moviesCount = $counts['movie'] ?? 0;

        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(e.runtime_minutes), 0)
            FROM usuario_episodio ue
            JOIN episodio e ON ue.id_episodio = e.id_episodio
            WHERE ue.id_usuario = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $minutesWatched = intval($stmt->fetchColumn());

        // Convert to days, hours, minutes
        $days = floor($minutesWatched / 1440);
        $hours = floor(($minutesWatched % 1440) / 60);
        $minutes = $minutesWatched % 60;

        $view = new ViewModel([
            'totalEpisodesWatched' => $totalEpisodesWatched,
            'seriesCount' => $seriesCount,
            'animeCount' => $animeCount,
            'moviesCount' => $moviesCount,
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes
        ]);
        $this->layout()->title = "Estatísticas - Time View";
        return $view;
    }

    public function diaryAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];

        // Chronological watched history (Episodes + Movies)
        $query = "
            SELECT 
                ue.ts_inclusao as watched_at, 
                e.title as episode_title, 
                e.episode_number, 
                e.season_number, 
                i.title as show_title, 
                i.type, 
                i.poster_url,
                'episode' as media_type
            FROM usuario_episodio ue
            JOIN episodio e ON ue.id_episodio = e.id_episodio
            JOIN item i ON e.id_item = i.id_item
            WHERE ue.id_usuario = :user_id

            UNION ALL

            SELECT 
                ui.ts_atualizacao as watched_at, 
                NULL as episode_title, 
                NULL as episode_number, 
                NULL as season_number, 
                i.title as show_title, 
                i.type, 
                i.poster_url,
                'movie' as media_type
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id AND i.type = 'movie' AND ui.status = 'completed'

            ORDER BY watched_at DESC
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        $history = $stmt->fetchAll();

        $view = new ViewModel([
            'history' => $history
        ]);
        $this->layout()->title = "O Meu Diário - Time View";
        return $view;
    }

    public function apiToggleTrackAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método não permitido.']);
        }

        $post = $request->getPost();
        $itemId = intval($post->get('item_id', 0));
        $tvmazeId = intval($post->get('tvmaze_id', 0));
        $action = $post->get('action', '');
        $status = $post->get('status', 'watching');
        $userId = $_SESSION['user_id'];

        if ($itemId <= 0 && $tvmazeId > 0) {
            $itemId = \Application\Helper\TvmazeHelper::importFromTVMaze($this->pdo, $tvmazeId);
        }

        if ($itemId <= 0) {
            return new JsonModel(['success' => false, 'message' => 'Item inválido.']);
        }

        try {
            if ($action === 'add') {
                $stmt = $this->pdo->prepare("
                    INSERT INTO usuario_item (id_usuario, id_item, status)
                    VALUES (:user_id, :item_id, :status)
                    ON CONFLICT(id_usuario, id_item) DO UPDATE SET status = EXCLUDED.status, ts_atualizacao = CURRENT_TIMESTAMP
                ");
                $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':status' => $status]);
                $msg = 'Item adicionado à sua lista!';
            } else {
                $stmt = $this->pdo->prepare("DELETE FROM usuario_item WHERE id_usuario = :user_id AND id_item = :item_id");
                $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
                
                $stmt = $this->pdo->prepare("
                    DELETE FROM usuario_episodio 
                    WHERE id_usuario = :user_id 
                      AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id)
                ");
                $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
                $msg = 'Item removido da sua lista.';
            }

            return new JsonModel(['success' => true, 'message' => $msg]);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro no banco de dados.']);
        }
    }

    public function apiToggleEpisodeAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método não permitido.']);
        }

        $post = $request->getPost();
        $itemId = intval($post->get('item_id', 0));
        $toggleType = $post->get('toggle_type', 'episode');
        $status = $post->get('status', '');
        $userId = $_SESSION['user_id'];

        if ($itemId <= 0) {
            return new JsonModel(['success' => false, 'message' => 'ID de item inválido.']);
        }

        try {
            if ($toggleType === 'episode') {
                $episodeId = intval($post->get('episode_id', 0));
                if ($episodeId <= 0) {
                    return new JsonModel(['success' => false, 'message' => 'ID do episódio inválido.']);
                }
                if ($status === 'watch') {
                    $stmt = $this->pdo->prepare("INSERT INTO usuario_episodio (id_usuario, id_episodio) VALUES (:user_id, :episode_id) ON CONFLICT(id_usuario, id_episodio) DO NOTHING");
                    $stmt->execute([':user_id' => $userId, ':episode_id' => $episodeId]);
                } else {
                    $stmt = $this->pdo->prepare("DELETE FROM usuario_episodio WHERE id_usuario = :user_id AND id_episodio = :episode_id");
                    $stmt->execute([':user_id' => $userId, ':episode_id' => $episodeId]);
                }
            } elseif ($toggleType === 'season') {
                $seasonNum = intval($post->get('season_number', 0));
                if ($status === 'watch') {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO usuario_episodio (id_usuario, id_episodio)
                        SELECT :user_id, id_episodio FROM episodio
                        WHERE id_item = :item_id AND season_number = :season_number
                        ON CONFLICT(id_usuario, id_episodio) DO NOTHING
                    ");
                } else {
                    $stmt = $this->pdo->prepare("
                        DELETE FROM usuario_episodio
                        WHERE id_usuario = :user_id
                          AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id AND season_number = :season_number)
                    ");
                }
                $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season_number' => $seasonNum]);
            } elseif ($toggleType === 'all') {
                if ($status === 'watch') {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO usuario_episodio (id_usuario, id_episodio)
                        SELECT :user_id, id_episodio FROM episodio
                        WHERE id_item = :item_id
                        ON CONFLICT(id_usuario, id_episodio) DO NOTHING
                    ");
                } else {
                    $stmt = $this->pdo->prepare("
                        DELETE FROM usuario_episodio
                        WHERE id_usuario = :user_id
                          AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id)
                    ");
                }
                $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
            } elseif ($toggleType === 'preceding') {
                $episodeId = intval($post->get('episode_id', 0));
                $stmt = $this->pdo->prepare("SELECT season_number, episode_number FROM episodio WHERE id_episodio = :ep_id LIMIT 1");
                $stmt->execute([':ep_id' => $episodeId]);
                $target = $stmt->fetch();
                
                if ($target) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO usuario_episodio (id_usuario, id_episodio)
                        SELECT :user_id, id_episodio FROM episodio
                        WHERE id_item = :item_id
                          AND (season_number < :season OR (season_number = :season AND episode_number <= :episode))
                        ON CONFLICT(id_usuario, id_episodio) DO NOTHING
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':item_id' => $itemId,
                        ':season' => $target['season_number'],
                        ':episode' => $target['episode_number']
                    ]);
                }
            }

            // Sync item completed status
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(e.id_episodio) as total_count,
                    COUNT(ue.id_usuario_episodio) as watched_count
                FROM episodio e
                LEFT JOIN usuario_episodio ue ON e.id_episodio = ue.id_episodio AND ue.id_usuario = :user_id
                WHERE e.id_item = :item_id
            ");
            $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
            $prog = $stmt->fetch();

            if ($prog && intval($prog['total_count']) > 0 && intval($prog['total_count']) === intval($prog['watched_count'])) {
                $stmt = $this->pdo->prepare("UPDATE usuario_item SET status = 'completed', ts_atualizacao = CURRENT_TIMESTAMP WHERE id_usuario = :user_id AND id_item = :item_id");
            } else {
                $stmt = $this->pdo->prepare("UPDATE usuario_item SET status = 'watching', ts_atualizacao = CURRENT_TIMESTAMP WHERE id_usuario = :user_id AND id_item = :item_id");
            }
            $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);

            return new JsonModel(['success' => true, 'message' => 'Progresso atualizado com sucesso!']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro no banco de dados.']);
        }
    }
}
