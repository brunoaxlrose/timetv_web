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

        $grouped        = $this->params()->fromQuery('grouped', '1') === '1';
        $statusFilter   = $this->params()->fromQuery('status_filter', '');
        $sortBy         = $this->params()->fromQuery('sort_by', 'last_watched');
        $providerFilter = $this->params()->fromQuery('provider', '');
        
        $mediaMovies = $this->params()->fromQuery('media_movies', '1') === '1';
        $mediaSeries = $this->params()->fromQuery('media_series', '1') === '1';
        $mediaAnime  = $this->params()->fromQuery('media_anime', '1') === '1';

        $types = [];
        if ($mediaMovies) $types[] = 'movie';
        if ($mediaSeries) $types[] = 'series';
        if ($mediaAnime) $types[] = 'anime';

        $userItems = $this->trackingModel->getUserCollection($userId, $types, $sortBy, $providerFilter);

        // Precalculate progress percent for all series and animes
        foreach ($userItems as &$item) {
            if ($item['type'] !== 'movie') {
                $prog = $this->trackingModel->getProgress($userId, $item['id_item']);
                $item['progress_percent'] = $prog['total_count'] > 0 ? round(($prog['watched_count'] / $prog['total_count']) * 100) : 0;
            }
        }

        $items = [];
        if ($grouped) {
            $groupedList = [
                'watching' => [],
                'up_to_date' => [],
                'completed' => []
            ];

            foreach ($userItems as &$item) {
                if ($item['type'] !== 'movie') {
                    $remaining = $this->trackingModel->countReleasedUnwatchedEpisodes($userId, $item['id_item']);
                    $item['next_episode'] = $this->trackingModel->getNextUnwatchedEpisode($userId, $item['id_item']);

                    if ($item['track_status'] === 'completed') {
                        $groupedList['completed'][] = $item;
                    } elseif ($remaining === 0) {
                        $groupedList['up_to_date'][] = $item;
                    } else {
                        $groupedList['watching'][] = $item;
                    }
                } else {
                    $item['next_episode'] = null;
                    if ($item['track_status'] === 'completed') {
                        $groupedList['completed'][] = $item;
                    } else {
                        $groupedList['watching'][] = $item;
                    }
                }
            }
            unset($item);

            if (!empty($statusFilter)) {
                if ($statusFilter === 'watching') {
                    $groupedList['up_to_date'] = [];
                    $groupedList['completed'] = [];
                } elseif ($statusFilter === 'visto') {
                    $groupedList['watching'] = [];
                    $groupedList['up_to_date'] = [];
                } elseif ($statusFilter === 'em_dia') {
                    $groupedList['watching'] = [];
                    $groupedList['completed'] = [];
                }
            }

            $items = $groupedList;
        } else {
            if (!empty($statusFilter)) {
                $filteredFlat = [];
                foreach ($userItems as &$item) {
                    if ($item['type'] !== 'movie') {
                        $remaining = $this->trackingModel->countReleasedUnwatchedEpisodes($userId, $item['id_item']);
                        $item['next_episode'] = $this->trackingModel->getNextUnwatchedEpisode($userId, $item['id_item']);

                        $status = 'watching';
                        if ($item['track_status'] === 'completed') {
                            $status = 'completed';
                        } elseif ($remaining === 0) {
                            $status = 'up_to_date';
                        }
                    } else {
                        $item['next_episode'] = null;
                        $status = ($item['track_status'] === 'completed') ? 'completed' : 'watching';
                    }

                    if ($statusFilter === 'watching' && $status === 'watching') {
                        $filteredFlat[] = $item;
                    } elseif ($statusFilter === 'visto' && $status === 'completed') {
                        $filteredFlat[] = $item;
                    } elseif ($statusFilter === 'em_dia' && $status === 'up_to_date') {
                        $filteredFlat[] = $item;
                    }
                }
                unset($item);
                $items = $filteredFlat;
            } else {
                foreach ($userItems as &$item) {
                    if ($item['type'] !== 'movie') {
                        $item['next_episode'] = $this->trackingModel->getNextUnwatchedEpisode($userId, $item['id_item']);
                    } else {
                        $item['next_episode'] = null;
                    }
                }
                unset($item);
                $items = $userItems;
            }
        }

        $view = new ViewModel([
            'items'          => $items,
            'grouped'        => $grouped,
            'statusFilter'   => $statusFilter,
            'sortBy'         => $sortBy,
            'mediaMovies'    => $mediaMovies,
            'mediaSeries'    => $mediaSeries,
            'mediaAnime'     => $mediaAnime,
            'providerFilter' => $providerFilter,
        ]);

        if ($this->getRequest()->isXmlHttpRequest()) {
            $view->setTerminal(true);
        } else {
            $this->layout()->title = "Início - Time View";
        }

        return $view;
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
                $remaining = $this->trackingModel->countReleasedUnwatchedEpisodes($userId, $itemId);
                if ($remaining === 0) {
                    $this->trackingModel->updateWatchlistStatus($userId, $itemId, 'completed');
                } else {
                    $this->trackingModel->updateWatchlistStatus($userId, $itemId, 'watching');
                }
            }

            return new JsonModel(['success' => true, 'message' => $msg]);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao salvar progresso: ' . $e->getMessage()]);
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
}
