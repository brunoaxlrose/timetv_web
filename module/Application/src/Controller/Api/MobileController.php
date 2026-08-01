<?php

namespace Application\Controller\Api;

use Application\Helper\TmdbHelper;
use Application\Helper\TvmazeHelper;
use Application\Helper\JikanHelper;
use Application\Model\AuthModel;
use Application\Model\CatalogModel;
use Application\Model\TrackingModel;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class MobileController extends AbstractActionController {
    public function __construct(
        private TrackingModel $trackingModel,
        private CatalogModel $catalogModel,
        private AuthModel $authModel
    ) {
    }

    public function dashboardAction(): JsonModel {
        $userId = $this->userId();

        return $this->ok([
            'continue_watching' => $this->trackingModel->getContinueWatching($userId),
            'lists' => $this->trackingModel->getUserListsSummary($userId),
            'plan_to_watch' => $this->trackingModel->getPlanToWatch($userId),
            'popular' => TmdbHelper::getPopular(12),
            'upcoming' => TmdbHelper::getUpcoming(12),
        ]);
    }

    public function collectionAction(): JsonModel {
        $userId = $this->userId();
        $types = $this->params()->fromQuery('types', 'movie,series,anime');
        $types = array_values(array_filter(array_map('trim', explode(',', $types))));
        $statusFilter = $this->params()->fromQuery('status_filter', '');

        $items = $this->trackingModel->getUserCollection(
            $userId,
            $types,
            $this->params()->fromQuery('sort_by', 'last_watched'),
            $this->params()->fromQuery('provider', '')
        );

        $groups = [
            'watching' => [],
            'up_to_date' => [],
            'completed' => [],
            'plan_to_watch' => [],
            'paused' => [],
            'abandoned' => [],
            'rewatching' => [],
        ];

        foreach ($items as &$item) {
            $item['next_episode'] = null;
            if (($item['type'] ?? '') !== 'movie') {
                try {
                    $progress = $this->trackingModel->getProgress($userId, (string)$item['id_item']);
                    $remaining = $this->trackingModel->countReleasedUnwatchedEpisodes($userId, (string)$item['id_item']);
                    $futureEpisodes = $this->trackingModel->countFutureEpisodes((string)$item['id_item']);
                    $item['next_episode'] = $this->trackingModel->getNextUnwatchedEpisode($userId, (string)$item['id_item']);
                } catch (\Throwable $e) {
                    $progress = ['total_count' => 0, 'watched_count' => 0];
                    $remaining = 1;
                    $futureEpisodes = 0;
                    $item['next_episode'] = null;
                }

                $item['progress'] = $progress;
                $item['progress_percent'] = $progress['total_count'] > 0 ? round(($progress['watched_count'] / $progress['total_count']) * 100) : 0;

                if (($item['track_status'] ?? '') === 'plan_to_watch') {
                    $groups['plan_to_watch'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'dropped') {
                    $groups['paused'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'abandoned') {
                    $groups['abandoned'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'rewatching') {
                    $groups['rewatching'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'completed') {
                    $groups['completed'][] = $item;
                } elseif ($remaining === 0 && $futureEpisodes === 0) {
                    $item['track_status'] = 'completed';
                    $this->trackingModel->updateWatchlistStatus($userId, (string)$item['id_item'], 'completed');
                    $groups['completed'][] = $item;
                } elseif ($remaining === 0) {
                    $groups['up_to_date'][] = $item;
                } else {
                    $groups['watching'][] = $item;
                }
            } else {
                $item['progress'] = ['total_count' => 1, 'watched_count' => ($item['track_status'] ?? '') === 'completed' ? 1 : 0];
                $item['progress_percent'] = ($item['track_status'] ?? '') === 'completed' ? 100 : 0;
                if (($item['track_status'] ?? '') === 'plan_to_watch') {
                    $groups['plan_to_watch'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'dropped') {
                    $groups['paused'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'abandoned') {
                    $groups['abandoned'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'rewatching') {
                    $groups['rewatching'][] = $item;
                } elseif (($item['track_status'] ?? '') === 'completed') {
                    $groups['completed'][] = $item;
                } else {
                    $groups['watching'][] = $item;
                }
            }
        }
        unset($item);

        $filtered = match ($statusFilter) {
            'watching' => $groups['watching'],
            'em_dia' => $groups['up_to_date'],
            'visto' => $groups['completed'],
            'para_ver' => $groups['plan_to_watch'],
            'em_pausa' => $groups['paused'],
            'abandonado' => $groups['abandoned'],
            'reassistindo' => $groups['rewatching'],
            default => $items,
        };

        return $this->ok([
            'items' => $filtered,
            'groups' => $groups,
            'status_filter' => $statusFilter,
        ]);
    }

    public function searchAction(): JsonModel {
        $userId = $this->userId();
        $query = trim($this->params()->fromQuery('search', ''));

        return $this->ok([
            'query' => $query,
            'popular' => TmdbHelper::getPopular(12),
            'items' => $query !== '' ? $this->catalogModel->searchAllDatabases($query, $userId) : [],
            'recent_searches' => $this->recentSearches($query),
        ]);
    }

    public function listsAction(): JsonModel {
        return $this->ok([
            'lists' => $this->trackingModel->getUserListsSummary($this->userId()),
        ]);
    }

    public function listItemsAction(): JsonModel {
        $listId = (int)$this->params()->fromQuery('list_id', 0);
        if ($listId <= 0) {
            return $this->error('Lista invalida.', 422);
        }

        return $this->ok([
            'list_id' => $listId,
            'items' => $this->trackingModel->getListItems($this->userId(), $listId),
        ]);
    }

    public function createListAction(): JsonModel {
        $payload = $this->payload();
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            return $this->error('Nome da lista e obrigatorio.', 422);
        }

        $listId = $this->trackingModel->createList($this->userId(), $name);

        return $this->ok([
            'list_id' => $listId,
            'lists' => $this->trackingModel->getUserListsSummary($this->userId()),
        ]);
    }

    public function deleteListAction(): JsonModel {
        $payload = $this->payload();
        $listId = (int)($payload['list_id'] ?? 0);
        if ($listId <= 0) {
            return $this->error('Lista invalida.', 422);
        }

        $this->trackingModel->deleteList($this->userId(), $listId);

        return $this->ok([
            'lists' => $this->trackingModel->getUserListsSummary($this->userId()),
        ]);
    }

    public function renameListAction(): JsonModel {
        $payload = $this->payload();
        $listId = (int)($payload['list_id'] ?? 0);
        $name = trim((string)($payload['name'] ?? ''));
        if ($listId <= 0 || $name === '') {
            return $this->error('Dados da lista invalidos.', 422);
        }

        $this->trackingModel->renameList($this->userId(), $listId, $name);

        return $this->ok([
            'lists' => $this->trackingModel->getUserListsSummary($this->userId()),
        ]);
    }

    public function addToListAction(): JsonModel {
        $payload = $this->payload();
        $listId = (int)($payload['list_id'] ?? 0);
        if ($listId <= 0) {
            return $this->error('Lista invalida.', 422);
        }

        $itemId = $this->resolveItemId($payload);
        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }

        $this->trackingModel->addToList($listId, $itemId);

        return $this->ok([
            'list_id' => $listId,
            'item_id' => $itemId,
            'items' => $this->trackingModel->getListItems($this->userId(), $listId),
        ]);
    }

    public function toggleFavoriteAction(): JsonModel {
        $payload = $this->payload();
        $itemId = $this->resolveItemId($payload);
        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }

        $isFavorite = array_key_exists('is_favorite', $payload)
            ? $this->trackingModel->setFavorite($this->userId(), $itemId, filter_var($payload['is_favorite'], FILTER_VALIDATE_BOOLEAN))
            : $this->trackingModel->toggleFavorite($this->userId(), $itemId);

        return $this->ok([
            'item_id' => $itemId,
            'is_favorite' => $isFavorite,
        ]);
    }

    public function trackAction(): JsonModel {
        $payload = $this->payload();
        $itemId = $this->resolveItemId($payload);
        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }

        $action = (string)($payload['action'] ?? 'add');
        $status = (string)($payload['status'] ?? 'watching');

        if ($action === 'add' && $status === 'completed' && !$this->trackingModel->isItemReleased((string)$itemId)) {
            return $this->error('Este titulo ainda nao foi lancado.', 409);
        }

        if ($action === 'rewatch') {
            if (!$this->trackingModel->isItemReleased((string)$itemId)) {
                return $this->error('Este titulo ainda nao foi lancado.', 409);
            }
            $this->trackingModel->startRewatching($this->userId(), (string)$itemId);
        } elseif ($action === 'remove') {
            $this->trackingModel->removeTrack($this->userId(), (string)$itemId);
        } else {
            $this->trackingModel->addTrack($this->userId(), (string)$itemId, $status);
        }

        return $this->ok([
            'item_id' => $itemId,
            'status' => $action === 'rewatch' ? 'rewatching' : $status,
        ]);
    }

    public function rewatchEpisodeAction(): JsonModel {
        $payload = $this->payload();
        $episodeId = (int)($payload['episode_id'] ?? 0);
        if ($episodeId <= 0) {
            return $this->error('Episodio invalido.', 422);
        }
        if (!$this->trackingModel->isEpisodeReleased((string)$episodeId)) {
            return $this->error('Este episodio ainda nao foi lancado.', 409);
        }

        return $this->ok([
            'episode_id' => $episodeId,
            'rewatch_count' => $this->trackingModel->rewatchEpisode($this->userId(), $episodeId),
        ]);
    }

    public function markEpisodesAction(): JsonModel {
        $payload = $this->payload();
        $itemId = (int)($payload['item_id'] ?? 0);
        $episodeId = (int)($payload['episode_id'] ?? 0);
        $seasonNumber = (int)($payload['season_number'] ?? 0);
        $mode = (string)($payload['mode'] ?? 'single');

        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }

        if ($mode === 'single' && $episodeId <= 0) {
            return $this->error('Episodio invalido.', 422);
        }

        if ($mode === 'single' && !$this->trackingModel->isEpisodeReleased((string)$episodeId)) {
            return $this->error('Este episodio ainda nao foi lancado.', 409);
        }

        if ($mode === 'season' && $seasonNumber <= 0) {
            return $this->error('Temporada invalida.', 422);
        }

        if ($mode === 'preceding' && $episodeId <= 0) {
            return $this->error('Episodio invalido.', 422);
        }

        match ($mode) {
            'season' => $this->trackingModel->watchSeasonEpisodes($this->userId(), (string)$itemId, $seasonNumber),
            'preceding' => $this->trackingModel->watchPrecedingEpisodes($this->userId(), (string)$itemId, (string)$episodeId),
            'all' => $this->trackingModel->watchAllEpisodes($this->userId(), (string)$itemId),
            default => $this->trackingModel->watchSingleEpisode($this->userId(), (string)$episodeId),
        };

        return $this->ok([
            'item_id' => $itemId,
            'episodes' => $this->catalogModel->getEpisodesWithWatchedState($this->userId(), (string)$itemId),
            'progress' => $this->catalogModel->getProgress($this->userId(), (string)$itemId),
            'next_unwatched' => $this->catalogModel->getNextUnwatched($this->userId(), (string)$itemId),
        ]);
    }

    public function reviewAction(): JsonModel {
        $payload = $this->payload();
        $itemId = $this->resolveItemId($payload);
        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }
        if (!$this->trackingModel->isItemReleased((string)$itemId)) {
            return $this->error('Voce so pode avaliar apos o lancamento.', 409);
        }

        $rating = $payload['rating'] ?? null;
        $comment = trim((string)($payload['comment'] ?? ''));
        $rating = ($rating === null || $rating === '') ? null : (float)$rating;
        $comment = $comment === '' ? null : $comment;

        if ($rating === null || $rating <= 0 || $comment === null) {
            return $this->error('Escolha uma estrela e escreva um comentario.', 422);
        }

        $pdo = $this->catalogModel->getPdo();
        $stmtCheck = $pdo->prepare("SELECT id_usuario_item FROM usuario_item WHERE id_usuario = :uid AND id_item = :iid LIMIT 1");
        $stmtCheck->execute([':uid' => $this->userId(), ':iid' => $itemId]);
        if (!$stmtCheck->fetch()) {
            $stmtInsert = $pdo->prepare("INSERT INTO usuario_item (id_usuario, id_item, status) VALUES (:uid, :iid, 'plan_to_watch')");
            $stmtInsert->execute([':uid' => $this->userId(), ':iid' => $itemId]);
        }

        $stmt = $pdo->prepare("
            UPDATE usuario_item
            SET rating = :rating, comment = :comment, ts_atualizacao = CURRENT_TIMESTAMP
            WHERE id_usuario = :uid AND id_item = :iid
        ");
        $stmt->execute([
            ':rating' => $rating,
            ':comment' => $comment,
            ':uid' => $this->userId(),
            ':iid' => $itemId,
        ]);

        return $this->ok([
            'item_id' => $itemId,
            'rating' => $rating,
            'comment' => $comment,
        ]);
    }

    public function profileAction(): JsonModel {
        $userId = $this->userId();
        $stats = $this->trackingModel->getStatsSummary($userId);
        $minutes = (int)$stats['totalMinutes'];
        $limit = max(3, min(20, (int)$this->params()->fromQuery('limit', 10)));

        return $this->ok([
            'stats' => $stats,
            'time' => [
                'days' => floor($minutes / 1440),
                'hours' => floor(($minutes % 1440) / 60),
                'minutes' => $minutes % 60,
            ],
            'history' => array_slice($this->trackingModel->getActivityHistory($userId), 0, $limit),
            'favorites' => $this->trackingModel->getFavorites($userId, $limit),
            'reviews' => $this->trackingModel->getUserReviews($userId, $limit),
            'limit' => $limit,
        ]);
    }

    public function detailAction(): JsonModel {
        $userId = $this->userId();
        $itemId = $this->resolveItemId([
            'item_id' => (int)$this->params()->fromQuery('id', 0),
            'tvmaze_id' => (int)$this->params()->fromQuery('tvmaze_id', 0),
            'tmdb_id' => (int)$this->params()->fromQuery('tmdb_id', 0),
            'mal_id' => (int)$this->params()->fromQuery('mal_id', 0),
            'type' => $this->params()->fromQuery('type', ''),
            'title' => $this->params()->fromQuery('title', ''),
            'release_year' => (int)$this->params()->fromQuery('release_year', 0),
            'release_date' => $this->params()->fromQuery('release_date', ''),
            'poster_url' => $this->params()->fromQuery('poster_url', ''),
            'banner_url' => $this->params()->fromQuery('banner_url', ''),
        ]);
        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }

        $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
        if (!$item) {
            return $this->error('Item nao encontrado.', 404);
        }

        $pdo = $this->catalogModel->getPdo();

        try {
            if (($item['type'] ?? '') === 'movie' && !empty($item['tmdb_id'])) {
                \Application\Helper\TmdbHelper::syncMovieMetadata($pdo, (int)$item['id_item'], (int)$item['tmdb_id']);
                $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
            }

            if (($item['type'] ?? '') !== 'movie' && !empty($item['tvmaze_id'])) {
                $lastSync = $item['last_sync'] ?? null;
                if (empty($lastSync) || (time() - strtotime($lastSync)) > 3600) {
                    \Application\Helper\TvmazeHelper::syncEpisodes($pdo, (int)$itemId, (int)$item['tvmaze_id']);
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }

                if (empty($this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId)) && !empty($item['tmdb_id'])) {
                    \Application\Helper\TmdbHelper::syncTvMetadataAndEpisodes($pdo, (int)$itemId, (int)$item['tmdb_id'], $item['type']);
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }
            } elseif (($item['type'] ?? '') !== 'movie' && !empty($item['tmdb_id'])) {
                $lastSync = $item['last_sync'] ?? null;
                if (empty($lastSync) || (time() - strtotime($lastSync)) > 3600) {
                    \Application\Helper\TmdbHelper::syncTvMetadataAndEpisodes($pdo, (int)$itemId, (int)$item['tmdb_id'], $item['type']);
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }
            }

            if (($item['type'] ?? '') === 'anime' && !empty($item['mal_id'])) {
                $episodes = $this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId);
                $lastSync = $item['last_sync'] ?? null;
                if (empty($episodes) || empty($lastSync) || (time() - strtotime($lastSync)) > 3600) {
                    \Application\Helper\JikanHelper::syncEpisodes($pdo, (int)$itemId, (int)$item['mal_id'], (int)($item['total_episodes'] ?? 0));
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }
            }
        } catch (\Throwable $e) {
            $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId) ?: $item;
        }

        // Fetch/populate watch providers if null
        try {
            if (($item['watch_providers'] ?? null) === null) {
                $watchProviders = $this->fetchWatchProviders($item['title'], $item['type'], (int)($item['release_year'] ?? 0), (int)($item['tmdb_id'] ?? 0), (int)($item['tvmaze_id'] ?? 0));
                if ($watchProviders !== null) {
                    $stmt = $pdo->prepare("UPDATE item SET watch_providers = :wp WHERE id_item = :id");
                    $stmt->execute([':wp' => $watchProviders, ':id' => $itemId]);
                    $item['watch_providers'] = $watchProviders;
                } else {
                    $stmt = $pdo->prepare("UPDATE item SET watch_providers = '' WHERE id_item = :id");
                    $stmt->execute([':id' => $itemId]);
                    $item['watch_providers'] = '';
                }
            }
        } catch (\Throwable $e) {
            $item['watch_providers'] = $item['watch_providers'] ?? '';
        }

        // Fetch/populate genres if null/empty
        try {
            if (empty($item['genres'])) {
                $genresStr = null;
                if (($item['type'] ?? '') === 'anime' && !empty($item['mal_id'])) {
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
                } elseif (($item['type'] ?? '') !== 'movie' && !empty($item['tvmaze_id'])) {
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
                    $genresStr = $this->translateGenres($genresStr);
                    $stmt = $pdo->prepare("UPDATE item SET genres = :genres WHERE id_item = :id");
                    $stmt->execute([':genres' => $genresStr, ':id' => $itemId]);
                    $item['genres'] = $genresStr;
                }
            } else {
                $item['genres'] = $this->translateGenres($item['genres']);
            }
        } catch (\Throwable $e) {
            $item['genres'] = $item['genres'] ?? '';
        }

        // Recommendations
        $recommendations = [];
        try {
            if (($item['type'] ?? '') === 'anime' && !empty($item['mal_id'])) {
                $recommendations = \Application\Helper\JikanHelper::getRecommendations((int)$item['mal_id'], 8);
            } else {
                $tmdbIdForRecs = $item['tmdb_id'] ?? null;
                if (empty($tmdbIdForRecs)) {
                    $searchType = (($item['type'] ?? '') === 'movie') ? 'movie' : 'tv';
                    $url = "https://api.themoviedb.org/3/search/" . $searchType . "?api_key=1f54bd990f1cdfb230adb312546d765d&query=" . urlencode($item['title']) . "&language=pt-BR";
                    if (!empty($item['release_year'])) {
                        $url .= (($item['type'] ?? '') === 'movie') ? "&primary_release_year=" . $item['release_year'] : "&first_air_date_year=" . $item['release_year'];
                    }
                    $json = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: TimeView/1.0\r\n", 'timeout' => 5]]));
                    if ($json) {
                        $results = json_decode($json, true)['results'] ?? [];
                        if (!empty($results[0]['id'])) {
                            $tmdbIdForRecs = (int)$results[0]['id'];
                            try {
                                $stmt = $pdo->prepare("UPDATE item SET tmdb_id = :tmdb_id WHERE id_item = :id");
                                $stmt->execute([':tmdb_id' => $tmdbIdForRecs, ':id' => $itemId]);
                                $item['tmdb_id'] = $tmdbIdForRecs;
                            } catch (\Throwable $e) {
                                $tmdbIdForRecs = $item['tmdb_id'] ?? null;
                            }
                        }
                    }
                }
                if (!empty($tmdbIdForRecs)) {
                    $recommendations = \Application\Helper\TmdbHelper::getRecommendations($item['type'], (int)$tmdbIdForRecs, 8);
                }
            }
        } catch (\Throwable $e) {
            $recommendations = [];
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

        return $this->ok([
            'item' => $item,
            'episodes' => $this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId),
            'progress' => $this->catalogModel->getProgress($userId, (string)$itemId),
            'next_unwatched' => $this->catalogModel->getNextUnwatched($userId, (string)$itemId),
            'released' => $this->trackingModel->isItemReleased((string)$itemId),
            'lists' => $this->trackingModel->getItemLists($userId, (int)$itemId),
            'cast' => $this->getCast($item),
            'reviews' => $this->catalogModel->getItemComments((int)$itemId),
            'recommendations' => $recommendations
        ]);
    }

    private function userId(): int {
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        $header = (string)$this->getRequest()->getHeader('Authorization');
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            $token = trim($matches[1]);
            if ($token !== '') {
                $user = $this->authModel->getUserByToken($token);
                if ($user) {
                    return (int)$user['id_usuario'];
                }
            }
        }

        throw new \RuntimeException('Nao autorizado.');
    }

    private function payload(): array {
        $payload = json_decode($this->getRequest()->getContent(), true);
        if (!is_array($payload)) {
            $payload = $this->getRequest()->getPost()->toArray();
        }
        return $payload;
    }

    private function itemResolveErrorMessage(): string {
        return 'Nao consegui identificar este titulo. Abra pela busca novamente e tente outra vez.';
    }

    private function normalizeItemType(string $type): string {
        return match ($type) {
            'movie', 'filme', 'film' => 'movie',
            'anime' => 'anime',
            default => 'series',
        };
    }

    private function findLocalItemIdByExternalIds(\PDO $pdo, int $tvmazeId, int $tmdbId, int $malId): int {
        $lookups = [
            ['column' => 'tvmaze_id', 'value' => $tvmazeId],
            ['column' => 'tmdb_id', 'value' => $tmdbId],
            ['column' => 'mal_id', 'value' => $malId],
        ];

        foreach ($lookups as $lookup) {
            if ($lookup['value'] <= 0) {
                continue;
            }

            $stmt = $pdo->prepare("SELECT id_item FROM item WHERE {$lookup['column']} = :value LIMIT 1");
            $stmt->execute([':value' => $lookup['value']]);
            $itemId = (int)$stmt->fetchColumn();
            if ($itemId > 0) {
                return $itemId;
            }
        }

        return 0;
    }

    private function resolveItemId(array $payload): int {
        $itemId = (int)($payload['item_id'] ?? $payload['id_item'] ?? 0);
        if ($itemId > 0) {
            return $itemId;
        }

        $pdo = $this->catalogModel->getPdo();
        $tvmazeId = (int)($payload['tvmaze_id'] ?? 0);
        $tmdbId = (int)($payload['tmdb_id'] ?? 0);
        $malId = (int)($payload['mal_id'] ?? 0);
        $type = $this->normalizeItemType((string)($payload['type'] ?? 'series'));
        $payload['type'] = $type;

        $existingExternalId = $this->findLocalItemIdByExternalIds($pdo, $tvmazeId, $tmdbId, $malId);
        if ($existingExternalId > 0) {
            return $existingExternalId;
        }

        if ($tvmazeId > 0) {
            $resolvedId = (int)TvmazeHelper::importFromTVMaze($pdo, $tvmazeId);
            if ($resolvedId > 0) {
                return $resolvedId;
            }
        }
        if ($malId > 0) {
            $resolvedId = (int)JikanHelper::importAnimeFromMal($pdo, $malId);
            if ($resolvedId > 0) {
                return $resolvedId;
            }
        }
        if ($tmdbId > 0) {
            if ($type === 'movie') {
                $resolvedId = (int)TmdbHelper::importMovieFromTmdb($pdo, $tmdbId);
                if ($resolvedId > 0) {
                    return $resolvedId;
                }

                $movie = TmdbHelper::getMovieDetail($tmdbId);
                if ($movie) {
                    $fallbackPayload = array_merge($payload, [
                        'tmdb_id' => $tmdbId,
                        'type' => 'movie',
                        'title' => $payload['title'] ?? ($movie['title'] ?? $movie['original_title'] ?? ''),
                        'description' => $payload['description'] ?? ($movie['overview'] ?? ''),
                        'release_date' => $payload['release_date'] ?? ($movie['release_date'] ?? ''),
                        'poster_url' => $payload['poster_url'] ?? (!empty($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] : ''),
                        'banner_url' => $payload['banner_url'] ?? (!empty($movie['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'] : ''),
                        'runtime_minutes' => (int)($movie['runtime'] ?? 120),
                    ]);
                    $resolvedId = $this->createLocalItemFromPayload($fallbackPayload);
                    if ($resolvedId > 0) {
                        return $resolvedId;
                    }
                }
            } else {
                $tvShow = TmdbHelper::getTvDetail($tmdbId);
                $title = $tvShow['original_name'] ?? $tvShow['name'] ?? '';
                if ($title !== '') {
                    $resolvedTvmazeId = TvmazeHelper::getTvmazeIdByTitle($title);
                    if ($resolvedTvmazeId) {
                        $resolvedId = (int)TvmazeHelper::importFromTVMaze($pdo, $resolvedTvmazeId);
                        if ($resolvedId > 0) {
                            return $resolvedId;
                        }
                    }
                }

                $resolvedId = (int)TmdbHelper::importTvFromTmdb($pdo, $tmdbId, $type);
                if ($resolvedId > 0) {
                    return $resolvedId;
                }

                if ($tvShow) {
                    $fallbackPayload = array_merge($payload, [
                        'tmdb_id' => $tmdbId,
                        'type' => $type,
                        'title' => $payload['title'] ?? ($tvShow['name'] ?? $tvShow['original_name'] ?? ''),
                        'description' => $payload['description'] ?? ($tvShow['overview'] ?? ''),
                        'release_date' => $payload['release_date'] ?? ($tvShow['first_air_date'] ?? ''),
                        'release_year' => $payload['release_year'] ?? (!empty($tvShow['first_air_date']) ? (int)substr($tvShow['first_air_date'], 0, 4) : 0),
                        'poster_url' => $payload['poster_url'] ?? (!empty($tvShow['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $tvShow['poster_path'] : ''),
                        'banner_url' => $payload['banner_url'] ?? (!empty($tvShow['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $tvShow['backdrop_path'] : ''),
                        'total_episodes' => (int)($tvShow['number_of_episodes'] ?? 0),
                        'runtime_minutes' => (int)($tvShow['episode_run_time'][0] ?? 45),
                    ]);
                    $resolvedId = $this->createLocalItemFromPayload($fallbackPayload);
                    if ($resolvedId > 0) {
                        return $resolvedId;
                    }
                }
            }
        }

        $title = trim((string)($payload['title'] ?? ''));
        if ($title !== '') {
            $releaseYear = (int)($payload['release_year'] ?? 0);

            if ($type !== 'movie') {
                $resolvedTvmazeId = TvmazeHelper::getTvmazeIdByTitle($title);
                if ($resolvedTvmazeId) {
                    $resolvedId = (int)TvmazeHelper::importFromTVMaze($pdo, $resolvedTvmazeId);
                    if ($resolvedId > 0) {
                        return $resolvedId;
                    }
                }
            }

            $results = TmdbHelper::searchMulti($title, 8);
            foreach ($results as $result) {
                if (($result['type'] ?? '') !== $type || empty($result['tmdb_id'])) {
                    continue;
                }

                if ($releaseYear > 0 && !empty($result['release_year']) && abs((int)$result['release_year'] - $releaseYear) > 1) {
                    continue;
                }

                $resolvedId = $type === 'movie'
                    ? (int)TmdbHelper::importMovieFromTmdb($pdo, (int)$result['tmdb_id'])
                    : (int)TmdbHelper::importTvFromTmdb($pdo, (int)$result['tmdb_id'], $type);
                if ($resolvedId > 0) {
                    return $resolvedId;
                }
            }

            return $this->createLocalItemFromPayload($payload);
        }

        return 0;
    }

    private function createLocalItemFromPayload(array $payload): int {
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            return 0;
        }

        $pdo = $this->catalogModel->getPdo();
        $type = $this->normalizeItemType((string)($payload['type'] ?? 'series'));
        $tvmazeId = (int)($payload['tvmaze_id'] ?? 0);
        $tmdbId = (int)($payload['tmdb_id'] ?? 0);
        $malId = (int)($payload['mal_id'] ?? 0);

        $existingExternalId = $this->findLocalItemIdByExternalIds($pdo, $tvmazeId, $tmdbId, $malId);
        if ($existingExternalId > 0) {
            return $existingExternalId;
        }

        $releaseDate = trim((string)($payload['release_date'] ?? '')) ?: null;
        $releaseYear = (int)($payload['release_year'] ?? 0);
        if ($releaseYear <= 0 && $releaseDate) {
            $releaseYear = (int)substr($releaseDate, 0, 4);
        }
        if ($releaseYear <= 0) {
            $releaseYear = (int)date('Y');
        }

        $stmt = $pdo->prepare("
            SELECT id_item
            FROM item
            WHERE LOWER(title) = LOWER(:title)
              AND type = :type
              AND release_year = :release_year
            LIMIT 1
        ");
        $stmt->execute([
            ':title' => $title,
            ':type' => $type,
            ':release_year' => $releaseYear,
        ]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }

        $status = ($releaseDate && $releaseDate > date('Y-m-d')) || $releaseYear > (int)date('Y')
            ? 'Upcoming'
            : 'Running';

        try {
            $stmt = $pdo->prepare("
                INSERT INTO item (
                    tvmaze_id, tmdb_id, mal_id, title, type, poster_url, banner_url, description,
                    release_year, release_date, total_episodes, runtime_minutes, status, ts_inclusao
                )
                VALUES (
                    :tvmaze_id, :tmdb_id, :mal_id, :title, :type, :poster_url, :banner_url, :description,
                    :release_year, :release_date, :total_episodes, :runtime_minutes, :status, CURRENT_TIMESTAMP
                )
                RETURNING id_item
            ");
            $stmt->execute([
                ':tvmaze_id' => $tvmazeId > 0 ? $tvmazeId : null,
                ':tmdb_id' => $tmdbId > 0 ? $tmdbId : null,
                ':mal_id' => $malId > 0 ? $malId : null,
                ':title' => $title,
                ':type' => $type,
                ':poster_url' => $payload['poster_url'] ?? '',
                ':banner_url' => $payload['banner_url'] ?? ($payload['poster_url'] ?? ''),
                ':description' => $payload['description'] ?? 'Nenhuma sinopse disponivel.',
                ':release_year' => $releaseYear,
                ':release_date' => $releaseDate,
                ':total_episodes' => (int)($payload['total_episodes'] ?? ($type === 'movie' ? 1 : 0)),
                ':runtime_minutes' => (int)($payload['runtime_minutes'] ?? ($type === 'movie' ? 120 : 45)),
                ':status' => $status,
            ]);

            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $existingExternalId = $this->findLocalItemIdByExternalIds($pdo, $tvmazeId, $tmdbId, $malId);
            if ($existingExternalId > 0) {
                return $existingExternalId;
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO item (
                        tvmaze_id, tmdb_id, mal_id, title, type, poster_url, banner_url,
                        description, release_year, total_episodes, runtime_minutes
                    )
                    VALUES (
                        :tvmaze_id, :tmdb_id, :mal_id, :title, :type, :poster_url, :banner_url,
                        :description, :release_year, :total_episodes, :runtime_minutes
                    )
                    RETURNING id_item
                ");
                $stmt->execute([
                    ':tvmaze_id' => $tvmazeId > 0 ? $tvmazeId : null,
                    ':tmdb_id' => $tmdbId > 0 ? $tmdbId : null,
                    ':mal_id' => $malId > 0 ? $malId : null,
                    ':title' => $title,
                    ':type' => $type,
                    ':poster_url' => $payload['poster_url'] ?? '',
                    ':banner_url' => $payload['banner_url'] ?? ($payload['poster_url'] ?? ''),
                    ':description' => $payload['description'] ?? 'Nenhuma sinopse disponivel.',
                    ':release_year' => $releaseYear,
                    ':total_episodes' => (int)($payload['total_episodes'] ?? ($type === 'movie' ? 1 : 0)),
                    ':runtime_minutes' => (int)($payload['runtime_minutes'] ?? ($type === 'movie' ? 120 : 45)),
                ]);

                return (int)$stmt->fetchColumn();
            } catch (\Throwable $fallbackError) {
                return 0;
            }
        }
    }

    private function recentSearches(string $query): array {
        if (!isset($_SESSION['recent_searches'])) {
            $_SESSION['recent_searches'] = [];
        }

        if ($this->params()->fromQuery('clear_recent', '0') === '1') {
            $_SESSION['recent_searches'] = [];
            return [];
        }

        if ($query !== '') {
            $_SESSION['recent_searches'] = array_values(array_unique(array_merge([$query], $_SESSION['recent_searches'])));
            $_SESSION['recent_searches'] = array_slice($_SESSION['recent_searches'], 0, 4);
        }

        return $_SESSION['recent_searches'];
    }

    private function getCast(array $item): array {
        if (!empty($item['tmdb_id'])) {
            $cast = TmdbHelper::getCredits((string)$item['type'], (int)$item['tmdb_id'], 12);
            if (!empty($cast)) {
                return $cast;
            }
        }

        if (!empty($item['mal_id'])) {
            $cast = JikanHelper::getCharacters((int)$item['mal_id'], 12);
            if (!empty($cast)) {
                return $cast;
            }
        }

        if (empty($item['tvmaze_id'])) {
            return [];
        }

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n",
                'timeout' => 4,
            ],
        ]);
        $json = @file_get_contents('https://api.tvmaze.com/shows/' . (int)$item['tvmaze_id'] . '/cast', false, $context);
        $rows = $json ? json_decode($json, true) : [];
        if (!is_array($rows)) {
            return [];
        }

        $cast = [];
        foreach (array_slice($rows, 0, 12) as $row) {
            $person = $row['person'] ?? [];
            $character = $row['character'] ?? [];
            $cast[] = [
                'name' => $person['name'] ?? 'Sem nome',
                'character' => $character['name'] ?? '',
                'image_url' => $person['image']['medium'] ?? $person['image']['original'] ?? null,
            ];
        }

        return $cast;
    }

    private function ok(array $data): JsonModel {
        return new JsonModel([
            'success' => true,
            'data' => $data,
            'message' => 'OK',
        ]);
    }

    private function error(string $message, int $status): JsonModel {
        $this->getResponse()->setStatusCode($status);

        return new JsonModel([
            'success' => false,
            'data' => null,
            'message' => $message,
        ]);
    }

    private function fetchWatchProviders(string $title, string $type, ?int $year, ?int $tmdbId, ?int $tvmazeId): ?string {
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

    private function translateGenres(?string $genresStr): ?string {
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
