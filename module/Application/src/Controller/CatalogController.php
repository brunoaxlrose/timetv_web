<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Application\Helper\TmdbHelper;
use Application\Helper\JikanHelper;
use Application\Model\CatalogModel;

class CatalogController extends AbstractActionController {
    private $catalogModel;

    public function __construct(CatalogModel $catalogModel) {
        $this->catalogModel = $catalogModel;
    }

    public function indexAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];

        $searchPost = trim($this->params()->fromPost('search', ''));
        $searchGet  = trim($this->params()->fromQuery('search', ''));
        $search     = $searchPost !== '' ? $searchPost : $searchGet;
        
        $grouped      = $this->params()->fromQuery('grouped', '1') === '1';
        $statusFilter = $this->params()->fromQuery('status_filter', '');
        $sortBy       = $this->params()->fromQuery('sort_by', 'last_watched');
        
        $mediaMovies = $this->params()->fromQuery('media_movies', '1') === '1';
        $mediaSeries = $this->params()->fromQuery('media_series', '1') === '1';
        $mediaAnime  = $this->params()->fromQuery('media_anime', '1') === '1';

        $items = [];

        if (!empty($search)) {
            $rawItems = $this->catalogModel->searchAllDatabases($search, $userId);
            foreach ($rawItems as $r) {
                if ($r['type'] === 'movie' && !$mediaMovies) continue;
                if ($r['type'] === 'series' && !$mediaSeries) continue;
                if ($r['type'] === 'anime' && !$mediaAnime) continue;
                $items[] = $r;
            }
        } else {
            $popularItems = TmdbHelper::getPopular(12);
            $upcomingItems = TmdbHelper::getUpcoming(12);
        }

        $view = new ViewModel([
            'items' => $items,
            'search' => $search,
            'mediaMovies' => $mediaMovies,
            'mediaSeries' => $mediaSeries,
            'mediaAnime' => $mediaAnime,
            'popularItems' => $popularItems ?? [],
            'upcomingItems' => $upcomingItems ?? []
        ]);
        
        if ($this->getRequest()->isXmlHttpRequest()) {
            $view->setTerminal(true);
        } else {
            $this->layout()->title = "Pesquisar - Time View";
        }
        
        return $view;
    }

    public function detailAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $id = intval($this->params()->fromQuery('id', 0));
        $tvmazeId = intval($this->params()->fromQuery('tvmaze_id', 0));
        $tmdbId = intval($this->params()->fromQuery('tmdb_id', 0));
        $malId = intval($this->params()->fromQuery('mal_id', 0));
        $userId = $_SESSION['user_id'];
        $pdo = $this->catalogModel->getPdo();

        if ($tvmazeId > 0) {
            $localId = \Application\Helper\TvmazeHelper::importFromTVMaze($pdo, $tvmazeId);
            if ($localId) {
                return $this->redirect()->toUrl('/detail?id=' . $localId);
            } else {
                return $this->redirect()->toUrl('/catalog?error=import_failed');
            }
        }

        if ($tmdbId > 0) {
            $localId = \Application\Helper\TmdbHelper::importMovieFromTmdb($pdo, $tmdbId);
            if ($localId) {
                return $this->redirect()->toUrl('/detail?id=' . $localId);
            } else {
                return $this->redirect()->toUrl('/catalog?error=import_failed');
            }
        }

        if ($malId > 0) {
            $localId = \Application\Helper\JikanHelper::importAnimeFromMal($pdo, $malId);
            if ($localId) {
                return $this->redirect()->toUrl('/detail?id=' . $localId);
            } else {
                return $this->redirect()->toUrl('/catalog?error=import_failed');
            }
        }

        if ($id <= 0) {
            return $this->redirect()->toRoute('catalog');
        }

        $item = $this->catalogModel->getLocalItemById($userId, $id);

        if (!$item) {
            return $this->redirect()->toRoute('catalog');
        }

        $episodes = [];
        $progress = null;
        
        if ($item['type'] !== 'movie') {
            $episodes = $this->catalogModel->getEpisodesWithWatchedState($userId, $id);
            $progress = $this->catalogModel->getProgress($userId, $id);
        } else {
            $isWatched = ($item['track_status'] === 'completed');
            $progress = [
                'total_count' => 1,
                'watched_count' => $isWatched ? 1 : 0
            ];
        }

        $cast = [];
        if ($item['tvmaze_id'] > 0) {
            $castUrl = "https://api.tvmaze.com/shows/" . $item['tvmaze_id'] . "/cast";
            $options = [
                'http' => [
                    'header' => "User-Agent: TVTimeClone/1.0\r\n"
                ]
            ];
            $context = stream_context_create($options);
            $castJson = @file_get_contents($castUrl, false, $context);
            $cast = $castJson ? json_decode($castJson, true) : [];
        }

        $nextUnwatched = null;
        if ($item['type'] !== 'movie') {
            $nextUnwatched = $this->catalogModel->getNextUnwatched($userId, $id);
        }

        $view = new ViewModel([
            'item' => $item,
            'episodes' => $episodes,
            'progress' => $progress,
            'cast' => $cast,
            'nextUnwatched' => $nextUnwatched,
            'userId' => $userId
        ]);
        $this->layout()->title = $item['title'] . " - Time View";
        return $view;
    }
}
