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

        // Fetch/populate watch providers if null
        if ($item['watch_providers'] === null) {
            $watchProviders = self::fetchWatchProviders($item['title'], $item['type'], $item['release_year'], $item['tmdb_id'], $item['tvmaze_id']);
            if ($watchProviders !== null) {
                $stmt = $pdo->prepare("UPDATE item SET watch_providers = :wp WHERE id_item = :id");
                $stmt->execute([':wp' => $watchProviders, ':id' => $id]);
                $item['watch_providers'] = $watchProviders;
            } else {
                $stmt = $pdo->prepare("UPDATE item SET watch_providers = '' WHERE id_item = :id");
                $stmt->execute([':id' => $id]);
                $item['watch_providers'] = '';
            }
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

        $comments = $this->catalogModel->getItemComments($id);

        $view = new ViewModel([
            'item' => $item,
            'episodes' => $episodes,
            'progress' => $progress,
            'cast' => $cast,
            'nextUnwatched' => $nextUnwatched,
            'userId' => $userId,
            'comments' => $comments
        ]);
        $this->layout()->title = $item['title'] . " - Time View";
        return $view;
    }

    public function apiSaveReviewAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autenticado']);
        }
        $userId = (int)$_SESSION['user_id'];
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método inválido']);
        }

        $post = $request->getPost();
        $itemId = (int)$post->get('item_id');
        $rating = $post->get('rating');
        $comment = $post->get('comment');

        if ($rating !== null && $rating !== '') {
            $rating = floatval($rating);
        } else {
            $rating = null;
        }

        if ($comment !== null) {
            $comment = trim($comment);
            if ($comment === '') {
                $comment = null;
            }
        }

        $pdo = $this->catalogModel->getPdo();
        try {
            // First, make sure the user tracks this item (default to plan_to_watch if not tracking yet)
            $stmtCheck = $pdo->prepare("SELECT id_usuario_item FROM usuario_item WHERE id_usuario = :uid AND id_item = :iid LIMIT 1");
            $stmtCheck->execute([':uid' => $userId, ':iid' => $itemId]);
            if (!$stmtCheck->fetch()) {
                $stmtInsert = $pdo->prepare("INSERT INTO usuario_item (id_usuario, id_item, status) VALUES (:uid, :iid, 'plan_to_watch')");
                $stmtInsert->execute([':uid' => $userId, ':iid' => $itemId]);
            }

            // Update rating and comment
            $stmtUpdate = $pdo->prepare("
                UPDATE usuario_item 
                SET rating = :rating, comment = :comment, ts_atualizacao = CURRENT_TIMESTAMP
                WHERE id_usuario = :uid AND id_item = :iid
            ");
            $stmtUpdate->execute([
                ':rating' => $rating,
                ':comment' => $comment,
                ':uid' => $userId,
                ':iid' => $itemId
            ]);

            return new JsonModel(['success' => true, 'message' => 'Avaliação salva com sucesso.']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro de banco de dados: ' . $e->getMessage()]);
        }
    }

    private static function fetchWatchProviders(string $title, string $type, ?int $year, ?int $tmdbId, ?int $tvmazeId): ?string {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 5]]);
        
        $imdbId = null;
        if (empty($tmdbId) && $tvmazeId) {
            $url = "https://api.tvmaze.com/shows/" . $tvmazeId;
            $json = @file_get_contents($url, false, $ctx);
            if ($json) {
                $data = json_decode($json, true);
                $imdbId = $data['externals']['imdb'] ?? null;
            }
        }

        if (empty($tmdbId) && !empty($imdbId)) {
            $url = "https://api.themoviedb.org/3/find/" . urlencode($imdbId) . "?api_key=1f54bd990f1cdfb230adb312546d765d&external_source=imdb_id";
            $json = @file_get_contents($url, false, $ctx);
            if ($json) {
                $data = json_decode($json, true);
                if ($type === 'movie' && !empty($data['movie_results'][0]['id'])) {
                    $tmdbId = (int)$data['movie_results'][0]['id'];
                } elseif (!empty($data['tv_results'][0]['id'])) {
                    $tmdbId = (int)$data['tv_results'][0]['id'];
                }
            }
        }

        if (empty($tmdbId)) {
            $searchType = ($type === 'movie') ? 'movie' : 'tv';
            $url = "https://api.themoviedb.org/3/search/" . $searchType . "?api_key=1f54bd990f1cdfb230adb312546d765d&query=" . urlencode($title) . "&language=pt-BR";
            if ($year) {
                $url .= ($type === 'movie') ? "&primary_release_year=" . $year : "&first_air_date_year=" . $year;
            }
            $json = @file_get_contents($url, false, $ctx);
            if ($json) {
                $results = json_decode($json, true)['results'] ?? [];
                if (!empty($results[0]['id'])) {
                    $tmdbId = (int)$results[0]['id'];
                }
            }
        }

        if ($tmdbId) {
            $providerType = ($type === 'movie') ? 'movie' : 'tv';
            $url = "https://api.themoviedb.org/3/" . $providerType . "/" . $tmdbId . "/watch/providers?api_key=1f54bd990f1cdfb230adb312546d765d";
            $json = @file_get_contents($url, false, $ctx);
            if ($json) {
                $data = json_decode($json, true);
                $providers = [];
                foreach (['BR', 'PT', 'US'] as $country) {
                    if (isset($data['results'][$country]['flatrate'])) {
                        foreach ($data['results'][$country]['flatrate'] as $prov) {
                            $providers[$prov['provider_name']] = [
                                'name' => $prov['provider_name'],
                                'logo' => 'https://image.tmdb.org/t/p/original' . ($prov['logo_path'] ?? '')
                            ];
                        }
                    }
                }
                if (!empty($providers)) {
                    return json_encode(array_values($providers), JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return null;
    }
}
