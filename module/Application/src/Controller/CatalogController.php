<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Application\Helper\TmdbHelper;
use Application\Helper\JikanHelper;

class CatalogController extends AbstractActionController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function indexAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }

        $userId = $_SESSION['user_id'];

        // Search can arrive via POST (jQuery AJAX) or GET (direct URL / filter)
        $searchPost = trim($this->params()->fromPost('search', ''));
        $searchGet  = trim($this->params()->fromQuery('search', ''));
        $search     = $searchPost !== '' ? $searchPost : $searchGet;
        
        // Advanced Filters (always from GET query string)
        $grouped      = $this->params()->fromQuery('grouped', '1') === '1';
        $statusFilter = $this->params()->fromQuery('status_filter', '');
        $sortBy       = $this->params()->fromQuery('sort_by', 'last_watched');
        
        $mediaMovies = $this->params()->fromQuery('media_movies', '1') === '1';
        $mediaSeries = $this->params()->fromQuery('media_series', '1') === '1';
        $mediaAnime  = $this->params()->fromQuery('media_anime', '1') === '1';

        $items = [];

        if (!empty($search)) {
            // Configure stream context to send a User-Agent header (required by TVmaze API)
            $options = [
                'http' => [
                    'header' => "User-Agent: TVTimeClone/1.0\r\n"
                ]
            ];
            $context = stream_context_create($options);

            // Fetch search results from TV Maze API
            $apiUrl = "https://api.tvmaze.com/search/shows?q=" . urlencode($search);
            $json = @file_get_contents($apiUrl, false, $context);
            $apiResults = $json ? json_decode($json, true) : [];

            foreach ($apiResults as $result) {
                $show = $result['show'] ?? null;
                if (!$show) continue;

                $summary = strip_tags($show['summary'] ?? '');
                // Omit shows without synopsis/description
                if (empty($summary) || $summary === 'Nenhuma sinopse disponível.') {
                    continue;
                }

                $tvmazeId = $show['id'];
                
                // Check if already exists locally
                $stmt = $this->pdo->prepare("
                    SELECT i.*, ui.status as track_status 
                    FROM item i 
                    LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id 
                    WHERE i.tvmaze_id = :tvmaze_id
                    LIMIT 1
                ");
                $stmt->execute([':user_id' => $userId, ':tvmaze_id' => $tvmazeId]);
                $localItem = $stmt->fetch();

                if ($localItem) {
                    // Check media type filter
                    if ($localItem['type'] === 'movie' && !$mediaMovies) continue;
                    if ($localItem['type'] === 'series' && !$mediaSeries) continue;
                    if ($localItem['type'] === 'anime' && !$mediaAnime) continue;

                    $items[] = $localItem;
                } else {
                    $showType = 'series';
                    $genres = $show['genres'] ?? [];
                    if (in_array('Anime', $genres) || 
                        (isset($show['network']['country']['code']) && $show['network']['country']['code'] === 'JP') ||
                        (isset($show['webChannel']['country']['code']) && $show['webChannel']['country']['code'] === 'JP')) {
                        $showType = 'anime';
                    }

                    // Check media type filter
                    if ($showType === 'movie' && !$mediaMovies) continue;
                    if ($showType === 'series' && !$mediaSeries) continue;
                    if ($showType === 'anime' && !$mediaAnime) continue;

                    $releaseYear = isset($show['premiered']) ? intval(substr($show['premiered'], 0, 4)) : date('Y');
                    $poster = $show['image']['medium'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400&auto=format&fit=crop';
                    $banner = $show['image']['original'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200&auto=format&fit=crop';

                    $items[] = [
                        'id_item' => null,
                        'tvmaze_id' => $tvmazeId,
                        'title' => $show['name'],
                        'type' => $showType,
                        'poster_url' => $poster,
                        'banner_url' => $banner,
                        'description' => $summary,
                        'release_year' => $releaseYear,
                        'track_status' => null
                    ];
                }
            }

            // -----------------------------------------------------------------------
            // FALLBACK: If TVmaze returned no results, search TMDB (movies + series)
            // TVmaze only indexes TV shows - movies won't be found there.
            // -----------------------------------------------------------------------
            if (empty($items)) {
                $tmdbResults = TmdbHelper::searchMulti($search, 20);
                foreach ($tmdbResults as $r) {
                    if ($r['type'] === 'movie' && !$mediaMovies) continue;
                    if ($r['type'] === 'series' && !$mediaSeries) continue;
                    if ($r['type'] === 'anime' && !$mediaAnime) continue;

                    // Check if tmdb_id already exists locally
                    if (!empty($r['tmdb_id'])) {
                        $stmt = $this->pdo->prepare("
                            SELECT i.*, ui.status as track_status
                            FROM item i
                            LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id
                            WHERE i.tmdb_id = :tmdb_id
                            LIMIT 1
                        ");
                        $stmt->execute([':user_id' => $userId, ':tmdb_id' => $r['tmdb_id']]);
                        $local = $stmt->fetch();
                        if ($local) {
                            $items[] = $local;
                            continue;
                        }
                    }

                    $items[] = $r;
                }
            }

            // -----------------------------------------------------------------------
            // FALLBACK 2: If both TVmaze & TMDB returned no results, search MyAnimeList (MAL / Jikan)
            // Useful for anime titles that aren't indexed well on other databases
            // -----------------------------------------------------------------------
            if (empty($items) && $mediaAnime) {
                $malResults = JikanHelper::searchAnime($search, 15);
                foreach ($malResults as $r) {
                    if (!empty($r['mal_id'])) {
                        $stmt = $this->pdo->prepare("
                            SELECT i.*, ui.status as track_status
                            FROM item i
                            LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id
                            WHERE i.mal_id = :mal_id
                            LIMIT 1
                        ");
                        $stmt->execute([':user_id' => $userId, ':mal_id' => $r['mal_id']]);
                        $local = $stmt->fetch();
                        if ($local) {
                            $items[] = $local;
                            continue;
                        }
                    }
                    $items[] = $r;
                }
            }
        } else {
            // Group user's items from DB
            $query = "
                SELECT i.*, ui.status as track_status, ui.rating, ui.ts_inclusao as added_at, ui.ts_atualizacao as updated_at
                FROM usuario_item ui
                JOIN item i ON ui.id_item = i.id_item
                WHERE ui.id_usuario = :user_id
            ";
            
            // Filter by media types
            $types = [];
            if ($mediaMovies) $types[] = "'movie'";
            if ($mediaSeries) $types[] = "'series'";
            if ($mediaAnime) $types[] = "'anime'";
            
            if (!empty($types)) {
                $query .= " AND i.type IN (" . implode(",", $types) . ")";
            } else {
                $query .= " AND 1=0"; // Exclude everything if all checkboxes unchecked
            }

            // Omit local shows without synopsis
            $query .= " AND i.description IS NOT NULL AND i.description != 'Nenhuma sinopse disponível.' AND i.description != ''";

            // Ordering options
            if ($sortBy === 'last_added') {
                $query .= " ORDER BY ui.ts_inclusao DESC";
            } elseif ($sortBy === 'last_premiered') {
                $query .= " ORDER BY i.release_year DESC";
            } else {
                // last_watched / last_updated
                $query .= " ORDER BY ui.ts_atualizacao DESC";
            }

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            $userItems = $stmt->fetchAll();

            if ($grouped) {
                $groupedList = [
                    'watching' => [],
                    'up_to_date' => [],
                    'completed' => []
                ];

                foreach ($userItems as &$item) {
                    if ($item['type'] !== 'movie') {
                        // Count only released episodes not yet watched (ignore future/upcoming)
                        $stmt = $this->pdo->prepare("
                            SELECT COUNT(e.id_episodio) 
                            FROM episodio e
                            WHERE e.id_item = :item_id 
                              AND (e.air_date IS NULL OR e.air_date = '' OR CAST(e.air_date AS DATE) <= CURRENT_DATE)
                              AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id)
                        ");
                        $stmt->execute([':item_id' => $item['id_item'], ':user_id' => $userId]);
                        $remaining = intval($stmt->fetchColumn());
                        
                        if ($item['track_status'] === 'completed') {
                            $groupedList['completed'][] = $item;
                        } elseif ($remaining === 0) {
                            $groupedList['up_to_date'][] = $item;
                        } else {
                            $groupedList['watching'][] = $item;
                        }
                    } else {
                        if ($item['track_status'] === 'completed') {
                            $groupedList['completed'][] = $item;
                        } else {
                            $groupedList['watching'][] = $item;
                        }
                    }
                }
                unset($item);

                // Filter groups by status pill if statusFilter is selected
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
                // Flat List view
                // Filter flat list by status if statusFilter is selected
                if (!empty($statusFilter)) {
                    $filteredFlat = [];
                    foreach ($userItems as &$item) {
                        if ($item['type'] !== 'movie') {
                            $stmt = $this->pdo->prepare("
                                SELECT COUNT(e.id_episodio) 
                                FROM episodio e
                                WHERE e.id_item = :item_id 
                                  AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id)
                            ");
                            $stmt->execute([':item_id' => $item['id_item'], ':user_id' => $userId]);
                            $remaining = intval($stmt->fetchColumn());
                            
                            if ($statusFilter === 'visto' && $item['track_status'] === 'completed') {
                                $filteredFlat[] = $item;
                            } elseif ($statusFilter === 'em_dia' && $remaining === 0 && $item['track_status'] !== 'completed') {
                                $filteredFlat[] = $item;
                            } elseif ($statusFilter === 'watching' && $remaining > 0 && $item['track_status'] !== 'completed') {
                                $filteredFlat[] = $item;
                            }
                        } else {
                            if ($statusFilter === 'visto' && $item['track_status'] === 'completed') {
                                $filteredFlat[] = $item;
                            } elseif ($statusFilter === 'watching' && $item['track_status'] !== 'completed') {
                                $filteredFlat[] = $item;
                            }
                        }
                    }
                    unset($item);
                    $items = $filteredFlat;
                } else {
                    $items = $userItems;
                }
            }
        }

        $view = new ViewModel([
            'items' => $items,
            'search' => $search,
            'grouped' => $grouped,
            'statusFilter' => $statusFilter,
            'sortBy' => $sortBy,
            'mediaMovies' => $mediaMovies,
            'mediaSeries' => $mediaSeries,
            'mediaAnime' => $mediaAnime
        ]);
        
        // Se for requisição AJAX, desativa o layout e renderiza apenas o template da view
        if ($this->getRequest()->isXmlHttpRequest()) {
            $view->setTerminal(true);
        } else {
            $this->layout()->title = "Explorar Catálogo - Time View";
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

        if ($tvmazeId > 0) {
            $localId = \Application\Helper\TvmazeHelper::importFromTVMaze($this->pdo, $tvmazeId);
            if ($localId) {
                return $this->redirect()->toUrl('/detail?id=' . $localId);
            } else {
                return $this->redirect()->toUrl('/catalog?error=import_failed');
            }
        }

        // --- TMDB Fallback ---
        if ($tmdbId > 0) {
            $localId = \Application\Helper\TmdbHelper::importMovieFromTmdb($this->pdo, $tmdbId);
            if ($localId) {
                return $this->redirect()->toUrl('/detail?id=' . $localId);
            } else {
                return $this->redirect()->toUrl('/catalog?error=import_failed');
            }
        }

        // --- MAL/Jikan Fallback ---
        if ($malId > 0) {
            $localId = \Application\Helper\JikanHelper::importAnimeFromMal($this->pdo, $malId);
            if ($localId) {
                return $this->redirect()->toUrl('/detail?id=' . $localId);
            } else {
                return $this->redirect()->toUrl('/catalog?error=import_failed');
            }
        }

        if ($id <= 0) {
            return $this->redirect()->toRoute('catalog');
        }

        // Fetch catalog item
        $stmt = $this->pdo->prepare("
            SELECT i.*, ui.status as track_status, ui.rating
            FROM item i
            LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id
            WHERE i.id_item = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            return $this->redirect()->toRoute('catalog');
        }

        // Fetch episodes
        $episodes = [];
        $progress = null;
        
        if ($item['type'] !== 'movie') {
            $stmt = $this->pdo->prepare("
                SELECT e.*, 
                       (SELECT 1 FROM usuario_episodio ue WHERE ue.id_episodio = e.id_episodio AND ue.id_usuario = :user_id) as watched
                FROM episodio e
                WHERE e.id_item = :item_id
                ORDER BY e.season_number ASC, e.episode_number ASC
            ");
            $stmt->execute([':item_id' => $id, ':user_id' => $userId]);
            $episodes = $stmt->fetchAll();

            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(e.id_episodio) as total_count,
                    COUNT(ue.id_usuario_episodio) as watched_count
                FROM episodio e
                LEFT JOIN usuario_episodio ue ON e.id_episodio = ue.id_episodio AND ue.id_usuario = :user_id
                WHERE e.id_item = :item_id
            ");
            $stmt->execute([':item_id' => $id, ':user_id' => $userId]);
            $progress = $stmt->fetch();
        } else {
            $isWatched = ($item['track_status'] === 'completed');
            $progress = [
                'total_count' => 1,
                'watched_count' => $isWatched ? 1 : 0
            ];
        }

        // Fetch cast members
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

        // Fetch next unwatched episode
        $nextUnwatched = null;
        if ($item['type'] !== 'movie') {
            $stmt = $this->pdo->prepare("
                SELECT season_number, episode_number FROM episodio 
                WHERE id_item = :item_id 
                  AND id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id)
                ORDER BY season_number ASC, episode_number ASC
                LIMIT 1
            ");
            $stmt->execute([':item_id' => $id, ':user_id' => $userId]);
            $nextUnwatched = $stmt->fetch();
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
