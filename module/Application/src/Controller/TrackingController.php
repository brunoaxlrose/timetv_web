<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Application\Model\TrackingModel;
use Application\Helper\MessageHelper;

class TrackingController extends AbstractActionController {
    private $trackingModel;

    public function __construct(TrackingModel $trackingModel) {
        $this->trackingModel = $trackingModel;
    }

    public function dashboardAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];

        $continueWatching = $this->trackingModel->getContinueWatching($userId);
        $userLists = $this->trackingModel->getUserLists($userId);
        $planToWatch = $this->trackingModel->getPlanToWatch($userId);

        $popularItems = \Application\Helper\TmdbHelper::getPopular(12);
        $upcomingItems = \Application\Helper\TmdbHelper::getUpcoming(12);

        $view = new ViewModel([
            'continueWatching' => $continueWatching,
            'userLists'        => $userLists,
            'planToWatch'      => $planToWatch,
            'popularItems'     => $popularItems,
            'upcomingItems'    => $upcomingItems
        ]);

        if ($this->getRequest()->isXmlHttpRequest()) {
            $view->setTerminal(true);
        } else {
            $this->layout()->title = "Início - Time View";
        }

        return $view;
    }

    public function listsAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];
        $userLists = $this->trackingModel->getUserLists($userId);

        $view = new ViewModel([
            'userLists' => $userLists
        ]);

        if ($this->getRequest()->isXmlHttpRequest()) {
            $view->setTerminal(true);
        } else {
            $this->layout()->title = "As minhas listas - Time View";
        }

        return $view;
    }

    public function apiCreateListAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método inválido.']);
        }

        $name = trim($request->getPost('name', ''));
        if (empty($name)) {
            return new JsonModel(['success' => false, 'message' => 'Nome da lista é obrigatório.']);
        }

        try {
            $listId = $this->trackingModel->createList($userId, $name);
            return new JsonModel(['success' => true, 'list_id' => $listId, 'message' => 'Lista criada com sucesso!']);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao criar lista.']);
        }
    }

    public function apiDeleteListAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método inválido.']);
        }

        $listId = intval($request->getPost('list_id', 0));
        if ($listId <= 0) {
            return new JsonModel(['success' => false, 'message' => 'Lista inválida.']);
        }

        try {
            $this->trackingModel->deleteList($userId, $listId);
            return new JsonModel(['success' => true, 'message' => 'Lista excluída com sucesso.']);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao excluir lista.']);
        }
    }

    public function apiAddToListAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método inválido.']);
        }

        $listId = intval($request->getPost('list_id', 0));
        $itemId = intval($request->getPost('item_id', 0));
        $tvmazeId = intval($request->getPost('tvmaze_id', 0));
        $tmdbId = intval($request->getPost('tmdb_id', 0));
        $type = $request->getPost('type', 'series');

        if ($listId <= 0) {
            return new JsonModel(['success' => false, 'message' => 'Lista inválida.']);
        }

        try {
            // Import item if not present in DB
            if ($itemId <= 0) {
                $dbConfig = $this->getEvent()->getApplication()->getServiceManager()->get('config')['db'] ?? [];
                $pdo = new \PDO($dbConfig['dsn'], $dbConfig['username'], $dbConfig['password']);
                if ($tvmazeId > 0) {
                    $itemId = \Application\Helper\TvmazeHelper::importFromTVMaze($pdo, $tvmazeId);
                } elseif ($tmdbId > 0) {
                    if ($type === 'movie') {
                        $itemId = \Application\Helper\TmdbHelper::importMovieFromTmdb($pdo, $tmdbId);
                    } else {
                        $itemId = \Application\Helper\TvmazeHelper::importFromTmdb($pdo, $tmdbId);
                    }
                }
            }

            if ($itemId <= 0) {
                return new JsonModel(['success' => false, 'message' => 'Item inválido.']);
            }

            $this->trackingModel->addToList($listId, $itemId);
            return new JsonModel(['success' => true, 'message' => 'Adicionado à lista com sucesso.']);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao adicionar à lista.']);
        }
    }

    public function apiRemoveFromListAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método inválido.']);
        }

        $listId = intval($request->getPost('list_id', 0));
        $itemId = intval($request->getPost('item_id', 0));

        if ($listId <= 0 || $itemId <= 0) {
            return new JsonModel(['success' => false, 'message' => 'Parâmetros inválidos.']);
        }

        try {
            $this->trackingModel->removeFromList($listId, $itemId);
            return new JsonModel(['success' => true, 'message' => 'Removido da lista.']);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao remover da lista.']);
        }
    }

    public function apiGetItemListsAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        $itemId = intval($this->params()->fromQuery('item_id', 0));

        try {
            $lists = $this->trackingModel->getItemLists($userId, $itemId);
            return new JsonModel(['success' => true, 'lists' => $lists]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao carregar listas.']);
        }
    }

    public function apiToggleTrackAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => MessageHelper::METHOD_INVALID]);
        }

        $post = $request->getPost();
        $itemId = $post->get('item_id');
        $tvmazeId = intval($post->get('tvmaze_id', 0));
        $action = $post->get('action', 'add');
        $status = $post->get('status', 'watching');

        if (!$itemId && $tvmazeId > 0) {
            $pdo = $this->trackingModel->getNextUnwatchedEpisode($userId, 'dummy'); // dummy query or fetch connection
            // We can resolve connection if needed
        }

        if (empty($itemId) && $tvmazeId > 0) {
            // Import show first
            $dbConfig = $this->getEvent()->getApplication()->getServiceManager()->get('config')['db'] ?? [];
            $pdo = new \PDO($dbConfig['dsn'], $dbConfig['username'], $dbConfig['password']);
            $itemId = \Application\Helper\TvmazeHelper::importFromTVMaze($pdo, $tvmazeId);
        }

        if (empty($itemId)) {
            return new JsonModel(['success' => false, 'message' => MessageHelper::ITEM_INVALID]);
        }

        try {
            if ($action === 'rewatch') {
                $this->trackingModel->startRewatching($userId, $itemId);
                return new JsonModel(['success' => true, 'message' => MessageHelper::REWATCH_START_SUCCESS]);
            } elseif ($action === 'add') {
                $this->trackingModel->addTrack($userId, $itemId, $status);
                return new JsonModel(['success' => true, 'message' => MessageHelper::TRACK_ADD_SUCCESS]);
            } else {
                $this->trackingModel->removeTrack($userId, $itemId);
                return new JsonModel(['success' => true, 'message' => MessageHelper::TRACK_REMOVE_SUCCESS]);
            }
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro de banco de dados: ' . $e->getMessage()]);
        }
    }

    public function apiToggleEpisodeAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => MessageHelper::AUTH_REQUIRED]);
        }

        $userId = $_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => MessageHelper::METHOD_INVALID]);
        }

        $post = $request->getPost();
        $episodeId = $post->get('episode_id');
        $itemId = $post->get('item_id');
        $status = $post->get('status', 'watch');
        $toggleType = $post->get('toggle_type');
        $seasonNum = $post->get('season_number');

        try {
            if ($toggleType === 'all') {
                if ($status === 'watch') {
                    $this->trackingModel->watchAllEpisodes($userId, $itemId);
                    $this->trackingModel->updateWatchlistStatus($userId, $itemId, 'completed');
                    $msg = MessageHelper::ALL_EPISODES_WATCHED;
                } else {
                    $this->trackingModel->unwatchAllEpisodes($userId, $itemId);
                    $this->trackingModel->updateWatchlistStatus($userId, $itemId, 'watching');
                    $msg = MessageHelper::ALL_EPISODES_RESET;
                }
            } elseif ($toggleType === 'season') {
                $seasonVal = intval($seasonNum);
                if ($status === 'watch') {
                    $this->trackingModel->watchSeasonEpisodes($userId, $itemId, $seasonVal);
                    $msg = MessageHelper::SEASON_WATCHED;
                } else {
                    $this->trackingModel->unwatchSeasonEpisodes($userId, $itemId, $seasonVal);
                    $msg = MessageHelper::SEASON_RESET;
                }
            } elseif ($toggleType === 'preceding') {
                $this->trackingModel->watchPrecedingEpisodes($userId, $itemId, $episodeId);
                $msg = 'Episódios anteriores marcados como vistos!';
            } else {
                // Single episode
                if ($status === 'watch') {
                    $this->trackingModel->watchSingleEpisode($userId, $episodeId);
                    $msg = MessageHelper::EPISODE_WATCHED;
                } else {
                    $this->trackingModel->unwatchSingleEpisode($userId, $episodeId);
                    $msg = MessageHelper::EPISODE_UNWATCHED;
                }
            }

            // Recalculate if show is fully watched to set status as completed
            if ($itemId) {
                $dbConfig = $this->getEvent()->getApplication()->getServiceManager()->get('config')['db'] ?? [];
                $pdo = new \PDO($dbConfig['dsn'], $dbConfig['username'], $dbConfig['password']);
                
                $stmtAll = $pdo->prepare("SELECT COUNT(*) FROM episodio WHERE id_item = :id_item");
                $stmtAll->execute([':id_item' => $itemId]);
                $totalEps = (int)$stmtAll->fetchColumn();

                $stmtWatched = $pdo->prepare("
                    SELECT COUNT(ue.id_episodio) 
                    FROM usuario_episodio ue
                    JOIN episodio e ON ue.id_episodio = e.id_episodio
                    WHERE ue.id_usuario = :user_id AND e.id_item = :item_id
                ");
                $stmtWatched->execute([':user_id' => $userId, ':item_id' => $itemId]);
                $watchedEps = (int)$stmtWatched->fetchColumn();

                if ($totalEps > 0 && $watchedEps >= $totalEps) {
                    $this->trackingModel->updateWatchlistStatus($userId, $itemId, 'completed');
                } else {
                    $this->trackingModel->updateWatchlistStatus($userId, $itemId, 'watching');
                }
            }

            return new JsonModel(['success' => true, 'message' => $msg]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function apiRewatchEpisodeAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autenticado.']);
        }
        $userId = $_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método inválido.']);
        }
        $episodeId = (int)$request->getPost()->get('episode_id');
        if ($episodeId <= 0) {
            return new JsonModel(['success' => false, 'message' => 'ID de episódio inválido.']);
        }
        
        try {
            $newCount = $this->trackingModel->rewatchEpisode($userId, $episodeId);
            return new JsonModel(['success' => true, 'rewatch_count' => $newCount]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function statsAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];
        
        $stats = $this->trackingModel->getStatsSummary($userId);
        $timeline = $this->trackingModel->getStatsTimeline($userId);

        $totalMinutes = $stats['totalMinutes'];
        $days = floor($totalMinutes / 1440);
        $hours = floor(($totalMinutes % 1440) / 60);
        $minutes = $totalMinutes % 60;

        $history = $this->trackingModel->getActivityHistory($userId);

        $view = new ViewModel([
            'totalEpisodesWatched' => $stats['totalEpisodes'],
            'seriesCount' => $stats['seriesCount'],
            'animeCount' => $stats['animeCount'],
            'moviesCount' => $stats['moviesCount'],
            'totalRewatched' => $stats['totalRewatched'],
            'watchingCount' => $stats['watchingCount'],
            'upToDateCount' => $stats['upToDateCount'],
            'completedCount' => $stats['completedCount'],
            'pausedCount' => $stats['pausedCount'],
            'rewatchingCount' => $stats['rewatchingCount'],
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'timeline' => $timeline,
            'history' => $history,
        ]);
        
        $this->layout()->title = "Perfil - Time View";
        return $view;
    }

    public function diaryAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];
        $history = $this->trackingModel->getActivityHistory($userId);

        $view = new ViewModel(['history' => $history]);
        $this->layout()->title = "Diário - Time View";
        return $view;
    }

    public function apiCreateEpisodeAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método inválido.']);
        }

        $post = $request->getPost();
        $itemId = intval($post->get('item_id'));
        $autoGenerate = (bool)$post->get('auto_generate', false);

        if ($itemId <= 0) {
            return new JsonModel(['success' => false, 'message' => 'Item inválido.']);
        }

        if ($autoGenerate) {
            try {
                $dbConfig = $this->getEvent()->getApplication()->getServiceManager()->get('config')['db'] ?? [];
                $pdo = new \PDO($dbConfig['dsn'], $dbConfig['username'], $dbConfig['password']);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                // Get last episode
                $stmt = $pdo->prepare("
                    SELECT season_number, episode_number, air_date 
                    FROM episodio 
                    WHERE id_item = :id_item 
                    ORDER BY season_number DESC, episode_number DESC 
                    LIMIT 1
                ");
                $stmt->execute([':id_item' => $itemId]);
                $lastEp = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($lastEp) {
                    $nextSeason = (int)$lastEp['season_number'];
                    $nextNumber = (int)$lastEp['episode_number'] + 1;
                    $lastDateStr = $lastEp['air_date'];
                    if (empty($lastDateStr)) {
                        $lastDateStr = date('Y-m-d');
                    }
                } else {
                    $nextSeason = 1;
                    $nextNumber = 1;
                    $lastDateStr = date('Y-m-d', strtotime('-1 day'));
                }

                $insertedCount = 0;
                $currentDate = new \DateTime($lastDateStr);

                $pdo->beginTransaction();
                for ($i = 0; $i < 10; $i++) {
                    $currentDate->modify('+1 day');
                    // Skip Sundays (telenovela pattern)
                    if ($currentDate->format('N') == 7) {
                        $currentDate->modify('+1 day');
                    }

                    $airDateStr = $currentDate->format('Y-m-d');
                    $title = 'Capítulo ' . $nextNumber;

                    $insert = $pdo->prepare("
                        INSERT INTO episodio (id_item, season_number, episode_number, title, air_date, description, runtime_minutes)
                        VALUES (:id_item, :season_number, :episode_number, :title, :air_date, 'Nenhuma sinopse disponível.', 45)
                        ON CONFLICT (id_item, season_number, episode_number) DO NOTHING
                    ");
                    $insert->execute([
                        ':id_item' => $itemId,
                        ':season_number' => $nextSeason,
                        ':episode_number' => $nextNumber,
                        ':title' => $title,
                        ':air_date' => $airDateStr
                    ]);
                    
                    if ($insert->rowCount() > 0) {
                        $insertedCount++;
                    }
                    $nextNumber++;
                }

                // Update total_episodes in item
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM episodio WHERE id_item = :id_item");
                $stmtCount->execute([':id_item' => $itemId]);
                $count = $stmtCount->fetchColumn();

                $updateItem = $pdo->prepare("UPDATE item SET total_episodes = :count WHERE id_item = :id_item");
                $updateItem->execute([':count' => $count, ':id_item' => $itemId]);

                $pdo->commit();

                return new JsonModel([
                    'success' => true, 
                    'message' => "Gerados {$insertedCount} novos capítulos automáticos!"
                ]);
            } catch (\Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return new JsonModel(['success' => false, 'message' => 'Erro ao auto-gerar episódios: ' . $e->getMessage()]);
            }
        }

        // Manual add
        $seasonNumber = intval($post->get('season_number', 1));
        $episodeNumber = intval($post->get('episode_number', 1));
        $title = trim($post->get('title', ''));
        $airDate = trim($post->get('air_date', ''));
        $description = trim($post->get('description', ''));

        if (empty($title)) {
            $title = 'Capítulo ' . $episodeNumber;
        }
        if (empty($description)) {
            $description = 'Nenhuma sinopse disponível.';
        }
        if (empty($airDate)) {
            $airDate = date('Y-m-d');
        }

        try {
            $dbConfig = $this->getEvent()->getApplication()->getServiceManager()->get('config')['db'] ?? [];
            $pdo = new \PDO($dbConfig['dsn'], $dbConfig['username'], $dbConfig['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT id_episodio FROM episodio WHERE id_item = :id_item AND season_number = :sn AND episode_number = :en LIMIT 1");
            $stmt->execute([':id_item' => $itemId, ':sn' => $seasonNumber, ':en' => $episodeNumber]);
            if ($stmt->fetch()) {
                return new JsonModel(['success' => false, 'message' => 'Este episódio já existe.']);
            }

            $insert = $pdo->prepare("
                INSERT INTO episodio (id_item, season_number, episode_number, title, air_date, description, runtime_minutes)
                VALUES (:id_item, :season_number, :episode_number, :title, :air_date, :description, 45)
            ");
            $insert->execute([
                ':id_item' => $itemId,
                ':season_number' => $seasonNumber,
                ':episode_number' => $episodeNumber,
                ':title' => $title,
                ':air_date' => $airDate,
                ':description' => $description
            ]);

            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM episodio WHERE id_item = :id_item");
            $stmtCount->execute([':id_item' => $itemId]);
            $count = $stmtCount->fetchColumn();

            $updateItem = $pdo->prepare("UPDATE item SET total_episodes = :count WHERE id_item = :id_item");
            $updateItem->execute([':count' => $count, ':id_item' => $itemId]);

            return new JsonModel(['success' => true, 'message' => 'Episódio adicionado com sucesso!']);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao criar episódio: ' . $e->getMessage()]);
        }
    }
}
