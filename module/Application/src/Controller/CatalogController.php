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
    private $trackingModel;

    public function __construct(CatalogModel $catalogModel, \Application\Model\TrackingModel $trackingModel) {
        $this->catalogModel = $catalogModel;
        $this->trackingModel = $trackingModel;
    }

    public function indexAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];

        $viewMode       = $this->params()->fromQuery('view_mode', 'grid');
        $grouped        = ($viewMode === 'list') ? false : ($this->params()->fromQuery('grouped', '1') === '1');
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

        // Precalculate progress percent for all series, animes and movies
        foreach ($userItems as &$item) {
            if ($item['type'] !== 'movie') {
                $prog = $this->trackingModel->getProgress($userId, $item['id_item']);
                $item['progress_percent'] = $prog['total_count'] > 0 ? round(($prog['watched_count'] / $prog['total_count']) * 100) : 0;
            } else {
                $item['progress_percent'] = ($item['track_status'] === 'completed') ? 100 : 0;
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

        $page = (int)$this->params()->fromQuery('page', 1);
        if ($page < 1) $page = 1;

        $currentPage = 1;
        $totalPages = 1;
        $totalItems = count($items);
        if ($viewMode === 'list') {
            $grouped = false; // Force flat list for pagination
            $limit = 10;
            $currentPage = $page;
            $totalPages = (int)ceil($totalItems / $limit);
            if ($totalPages < 1) $totalPages = 1;
            if ($currentPage > $totalPages) $currentPage = $totalPages;
            $offset = ($currentPage - 1) * $limit;
            $items = array_slice($items, $offset, $limit);
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
            'viewMode'       => $viewMode,
            'currentPage'    => $currentPage,
            'totalPages'     => $totalPages,
            'totalItems'     => $totalItems,
        ]);

        if ($this->getRequest()->isXmlHttpRequest()) {
            $view->setTerminal(true);
        } else {
            $this->layout()->title = "Coleção - CineFio";
        }

        return $view;
    }

    public function searchAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];

        $searchPost = trim($this->params()->fromPost('search', ''));
        $searchGet  = trim($this->params()->fromQuery('search', ''));
        $search     = $searchPost !== '' ? $searchPost : $searchGet;

        // Trending / popular items (trending)
        $popularItems = TmdbHelper::getPopular(12);

        $items = [];
        if (!empty($search)) {
            $rawItems = $this->catalogModel->searchAllDatabases($search, $userId);
            foreach ($rawItems as $r) {
                $items[] = $r;
            }
        }

        // Add recent searches logic (save searches in session so the user can clear them)
        if (!isset($_SESSION['recent_searches'])) {
            $_SESSION['recent_searches'] = [];
        }
        if (!empty($search)) {
            // Add to top of list, prevent duplicates
            $_SESSION['recent_searches'] = array_values(array_unique(array_merge([$search], $_SESSION['recent_searches'])));
            // Keep last 4 searches
            $_SESSION['recent_searches'] = array_slice($_SESSION['recent_searches'], 0, 4);
        }

        $clearRecent = $this->params()->fromQuery('clear_recent', '0') === '1';
        if ($clearRecent) {
            $_SESSION['recent_searches'] = [];
            return new JsonModel(['success' => true]);
        }

        $view = new ViewModel([
            'items' => $items,
            'search' => $search,
            'popularItems' => $popularItems,
            'recentSearches' => $_SESSION['recent_searches']
        ]);

        if ($this->getRequest()->isXmlHttpRequest()) {
            $view->setTerminal(true);
        } else {
            $this->layout()->title = "Pesquisar - CineFio";
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
        $type = $this->params()->fromQuery('type', '');
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
            if ($type === 'series' || $type === 'anime') {
                $tvShow = \Application\Helper\TmdbHelper::getTvDetail($tmdbId);
                $searchTitle = $tvShow['original_name'] ?? $tvShow['name'] ?? '';
                if (!empty($searchTitle)) {
                    $tvmazeId = \Application\Helper\TvmazeHelper::getTvmazeIdByTitle($searchTitle);
                    if ($tvmazeId) {
                        return $this->redirect()->toUrl('/detail?tvmaze_id=' . $tvmazeId);
                    }
                }
                return $this->redirect()->toUrl('/catalog?error=tvshow_not_found');
            } else {
                $localId = \Application\Helper\TmdbHelper::importMovieFromTmdb($pdo, $tmdbId);
                if ($localId) {
                    return $this->redirect()->toUrl('/detail?id=' . $localId);
                } else {
                    return $this->redirect()->toUrl('/catalog?error=import_failed');
                }
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

        if ($item['type'] === 'movie' && !empty($item['tmdb_id'])) {
            \Application\Helper\TmdbHelper::syncMovieMetadata($pdo, (int)$item['id_item'], (int)$item['tmdb_id']);
            $item = $this->catalogModel->getLocalItemById($userId, $id);
        }

        if ($item['type'] !== 'movie' && !empty($item['tvmaze_id'])) {
            $lastSync = $item['last_sync'] ?? null;
            if (empty($lastSync) || (time() - strtotime($lastSync)) > 3600) {
                \Application\Helper\TvmazeHelper::syncEpisodes($pdo, $id, $item['tvmaze_id']);
                $item = $this->catalogModel->getLocalItemById($userId, $id);
            }
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

        // Fetch/populate genres if null/empty
        if (empty($item['genres'])) {
            $genresStr = null;
            if ($item['type'] === 'anime' && !empty($item['mal_id'])) {
                $animeData = \Application\Helper\JikanHelper::getAnimeDetail((int)$item['mal_id']);
                if ($animeData) {
                    $genres = [];
                    if (!empty($animeData['genres']) && is_array($animeData['genres'])) {
                        foreach ($animeData['genres'] as $g) {
                            $genres[] = $g['name'];
                        }
                    }
                    if (!empty($animeData['themes']) && is_array($animeData['themes'])) {
                        foreach ($animeData['themes'] as $t) {
                            $genres[] = $t['name'];
                        }
                    }
                    $genresStr = !empty($genres) ? implode(', ', $genres) : null;
                }
            } elseif ($item['type'] !== 'movie' && !empty($item['tvmaze_id'])) {
                $url = "https://api.tvmaze.com/shows/" . (int)$item['tvmaze_id'];
                $json = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n"]]));
                if ($json) {
                    $showData = json_decode($json, true);
                    if (!empty($showData['genres'])) {
                        $genresStr = implode(', ', $showData['genres']);
                    }
                }
            } else {
                $tmdbIdForGenres = $item['tmdb_id'] ?? null;
                if (!empty($tmdbIdForGenres)) {
                    $movieData = \Application\Helper\TmdbHelper::getMovieDetail((int)$tmdbIdForGenres);
                    if ($movieData && !empty($movieData['genres'])) {
                        $genres = [];
                        foreach ($movieData['genres'] as $g) {
                            $genres[] = $g['name'];
                        }
                        $genresStr = implode(', ', $genres);
                    }
                }
            }

            if ($genresStr !== null) {
                $genresStr = self::translateGenres($genresStr);
                $stmt = $pdo->prepare("UPDATE item SET genres = :genres WHERE id_item = :id");
                $stmt->execute([':genres' => $genresStr, ':id' => $id]);
                $item['genres'] = $genresStr;
            }
        } else {
            $item['genres'] = self::translateGenres($item['genres']);
        }

        $episodes = [];
        $progress = null;
        $hasReleasedContent = false;
        
        if ($item['type'] !== 'movie') {
            $episodes = $this->catalogModel->getEpisodesWithWatchedState($userId, $id);
            $progress = $this->catalogModel->getProgress($userId, $id);
            foreach ($episodes as $episode) {
                if (empty($episode['air_date']) || strtotime($episode['air_date']) <= strtotime(date('Y-m-d'))) {
                    $hasReleasedContent = true;
                    break;
                }
            }
        } else {
            $isWatched = ($item['track_status'] === 'completed');
            $progress = [
                'total_count' => 1,
                'watched_count' => $isWatched ? 1 : 0
            ];
            if (!empty($item['release_date'])) {
                $hasReleasedContent = strtotime($item['release_date']) <= strtotime(date('Y-m-d'));
            } elseif (!empty($item['release_year'])) {
                $hasReleasedContent = (int)$item['release_year'] < (int)date('Y');
            }
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

        // Recommendations
        $recommendations = [];
        if ($item['type'] === 'anime' && !empty($item['mal_id'])) {
            $recommendations = \Application\Helper\JikanHelper::getRecommendations((int)$item['mal_id'], 8);
        } else {
            $tmdbIdForRecs = $item['tmdb_id'] ?? null;
            if (empty($tmdbIdForRecs)) {
                $searchType = ($item['type'] === 'movie') ? 'movie' : 'tv';
                $url = "https://api.themoviedb.org/3/search/" . $searchType . "?api_key=1f54bd990f1cdfb230adb312546d765d&query=" . urlencode($item['title']) . "&language=pt-BR";
                if ($item['release_year']) {
                    $url .= ($item['type'] === 'movie') ? "&primary_release_year=" . $item['release_year'] : "&first_air_date_year=" . $item['release_year'];
                }
                $json = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: CineFio/1.0\r\n", 'timeout' => 5]]));
                if ($json) {
                    $results = json_decode($json, true)['results'] ?? [];
                    if (!empty($results[0]['id'])) {
                        $tmdbIdForRecs = (int)$results[0]['id'];
                        $stmt = $pdo->prepare("UPDATE item SET tmdb_id = :tmdb_id WHERE id_item = :id");
                        $stmt->execute([':tmdb_id' => $tmdbIdForRecs, ':id' => $id]);
                        $item['tmdb_id'] = $tmdbIdForRecs;
                    }
                }
            }
            if (!empty($tmdbIdForRecs)) {
                $recommendations = \Application\Helper\TmdbHelper::getRecommendations($item['type'], (int)$tmdbIdForRecs, 8);
            }
        }
        if (!empty($item['watch_providers'])) {
            $providers = json_decode($item['watch_providers'], true);
            if (is_array($providers)) {
                $cleaned = [];
                foreach ($providers as $prov) {
                    $name = $prov['name'] ?? '';
                    $targetName = $name;
                    if (stripos($name, 'Netflix') !== false) {
                        $targetName = 'Netflix';
                    } elseif (stripos($name, 'Paramount') !== false) {
                        $targetName = 'Paramount+';
                    } elseif (stripos($name, 'Prime Video') !== false || stripos($name, 'Amazon') !== false) {
                        $targetName = 'Prime Video';
                    } elseif (stripos($name, 'Apple TV') !== false) {
                        $targetName = 'Apple TV+';
                    } elseif (stripos($name, 'Disney') !== false) {
                        $targetName = 'Disney+';
                    } elseif (stripos($name, 'HBO') !== false || strcasecmp($name, 'Max') === 0) {
                        $targetName = 'Max';
                    } elseif (stripos($name, 'Star+') !== false || stripos($name, 'Star Plus') !== false) {
                        $targetName = 'Star+';
                    } elseif (stripos($name, 'Claro') !== false) {
                        $targetName = 'Claro tv+';
                    } elseif (stripos($name, 'Crunchyroll') !== false) {
                        $targetName = 'Crunchyroll';
                    }
                    $cleaned[$targetName] = [
                        'name' => $targetName,
                        'logo' => $prov['logo'] ?? ''
                    ];
                }
                $item['watch_providers'] = json_encode(array_values($cleaned), JSON_UNESCAPED_UNICODE);
            }
        }

        $view = new ViewModel([
            'item' => $item,
            'episodes' => $episodes,
            'progress' => $progress,
            'hasReleasedContent' => $hasReleasedContent,
            'cast' => $cast,
            'nextUnwatched' => $nextUnwatched,
            'userId' => $userId,
            'comments' => $comments,
            'recommendations' => $recommendations
        ]);
        $this->layout()->title = $item['title'] . " - CineFio";
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
            $stmtItem = $pdo->prepare("SELECT status, release_date, type FROM item WHERE id_item = :iid LIMIT 1");
            $stmtItem->execute([':iid' => $itemId]);
            $item = $stmtItem->fetch(\PDO::FETCH_ASSOC);
            if (!$item) {
                return new JsonModel(['success' => false, 'message' => 'Item inválido.']);
            }

            $isReleased = ($item['status'] ?? '') !== 'Upcoming';
            $today = strtotime(date('Y-m-d'));
            if (!empty($item['release_date'])) {
                $isReleased = $isReleased && strtotime($item['release_date']) <= $today;
            } elseif (!empty($item['release_year'])) {
                $isReleased = $isReleased && ((int)$item['release_year'] < (int)date('Y'));
            } else {
                $isReleased = false;
            }

            if (!$isReleased) {
                return new JsonModel(['success' => false, 'message' => 'Você só pode avaliar após o lançamento.']);
            }

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
                            $name = $prov['provider_name'];
                            $targetName = $name;
                            if (stripos($name, 'Netflix') !== false) {
                                $targetName = 'Netflix';
                            } elseif (stripos($name, 'Paramount') !== false) {
                                $targetName = 'Paramount+';
                            } elseif (stripos($name, 'Prime Video') !== false || stripos($name, 'Amazon') !== false) {
                                $targetName = 'Prime Video';
                            } elseif (stripos($name, 'Apple TV') !== false) {
                                $targetName = 'Apple TV+';
                            } elseif (stripos($name, 'Disney') !== false) {
                                $targetName = 'Disney+';
                            } elseif (stripos($name, 'HBO') !== false || strcasecmp($name, 'Max') === 0) {
                                $targetName = 'Max';
                            } elseif (stripos($name, 'Star+') !== false || stripos($name, 'Star Plus') !== false) {
                                $targetName = 'Star+';
                            } elseif (stripos($name, 'Claro') !== false) {
                                $targetName = 'Claro tv+';
                            } elseif (stripos($name, 'Crunchyroll') !== false) {
                                $targetName = 'Crunchyroll';
                            }
                            
                            $providers[$targetName] = [
                                'name' => $targetName,
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

    private static function translateGenres(?string $genresStr): ?string {
        if (empty($genresStr)) {
            return $genresStr;
        }
        $map = [
            'action' => 'Ação',
            'adventure' => 'Aventura',
            'anime' => 'Anime',
            'animation' => 'Animação',
            'comedy' => 'Comédia',
            'drama' => 'Drama',
            'fantasy' => 'Fantasia',
            'horror' => 'Terror',
            'mystery' => 'Mistério',
            'romance' => 'Romance',
            'sci-fi' => 'Ficção Científica',
            'science fiction' => 'Ficção Científica',
            'thriller' => 'Suspense',
            'crime' => 'Crime',
            'documentary' => 'Documentário',
            'family' => 'Família',
            'history' => 'História',
            'music' => 'Música',
            'supernatural' => 'Sobrenatural',
            'sports' => 'Esportes',
            'suspense' => 'Suspense',
            'slice of life' => 'Cotidiano',
            'war' => 'Guerra',
            'western' => 'Faroeste'
        ];
        $genres = explode(', ', $genresStr);
        $translated = [];
        foreach ($genres as $g) {
            $key = strtolower(trim($g));
            if (isset($map[$key])) {
                $translated[] = $map[$key];
            } else {
                $translated[] = $g;
            }
        }
        return implode(', ', array_unique($translated));
    }
}
