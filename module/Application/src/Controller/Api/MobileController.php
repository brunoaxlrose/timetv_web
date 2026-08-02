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
        $page = max(1, (int)$this->params()->fromQuery('pagina', 1));
        $month = $this->params()->fromQuery('mes', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $calendar = $this->catalogModel->getReleaseCalendar($userId, $startDate, $endDate);
        if ($this->params()->fromQuery('mes', '') !== '') {
            $calendar = array_merge(
                $calendar,
                TmdbHelper::getCalendarReleases($startDate, $endDate, 120),
                TvmazeHelper::getFutureSchedule($startDate, $endDate)
            );
            $seenCalendar = [];
            $calendar = array_values(array_filter($calendar, static function(array $event) use (&$seenCalendar): bool {
                $key = strtolower((string)($event['titulo'] ?? '')) . '|' . ($event['data_evento'] ?? '') . '|' . ($event['numero_temporada'] ?? '') . '|' . ($event['numero_episodio'] ?? '');
                if (isset($seenCalendar[$key])) return false;
                $seenCalendar[$key] = true;
                return true;
            }));
            usort($calendar, static fn(array $a, array $b): int => strcmp((string)$a['data_evento'], (string)$b['data_evento']));
        }

        return $this->ok([
            'continuar_assistindo' => $this->trackingModel->getContinueWatching($userId),
            'listas' => $this->trackingModel->getUserListsSummary($userId),
            'quero_ver' => $this->trackingModel->getPlanToWatch($userId),
            'proximos' => $this->catalogModel->getReleaseCalendar($userId, date('Y-m-d'), date('Y-m-d', strtotime('+90 days')), 20),
            'calendario' => $calendar,
            'populares' => TmdbHelper::getPopular(20, $page),
            'top_10_filmes' => TmdbHelper::getPopularMovies(10, $page),
            'top_10_series' => TmdbHelper::getPopularSeriesOnly(10, $page),
            'em_breve' => TmdbHelper::getUpcoming(10, $page),
            'pagina' => $page,
            'tem_mais_populares' => true,
            'tem_mais_em_breve' => true,
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
            'assistindo' => [],
            'up_to_date' => [],
            'concluido' => [],
            'quero_ver' => [],
            'em_pausa' => [],
            'abandonado' => [],
            'reassistindo' => [],
        ];

        foreach ($items as &$item) {
            $item['next_episode'] = null;
            if (($item['tipo'] ?? '') !== 'movie') {
                if (array_key_exists('remaining_count', $item)) {
                    $progress = [
                        'total_count' => (int)($item['total_count'] ?? 0),
                        'watched_count' => (int)($item['watched_count'] ?? 0),
                    ];
                    $remaining = (int)$item['remaining_count'];
                    $futureEpisodes = (int)($item['future_count'] ?? 0);
                    if (!empty($item['next_episode_id'])) {
                        $item['next_episode'] = [
                            'id_episodio' => (int)$item['next_episode_id'],
                            'numero_temporada' => (int)$item['next_season_number'],
                            'numero_episodio' => (int)$item['next_episode_number'],
                            'titulo' => $item['next_episode_title'] ?? '',
                            'data_exibicao' => $item['next_air_date'] ?? null,
                            'duracao_minutos' => isset($item['next_runtime_minutes']) ? (int)$item['next_runtime_minutes'] : null,
                        ];
                    }
                } else {
                    $progress = $this->trackingModel->getProgress($userId, (string)$item['id_item']);
                    $remaining = $this->trackingModel->countReleasedUnwatchedEpisodes($userId, (string)$item['id_item']);
                    $futureEpisodes = $this->trackingModel->countFutureEpisodes((string)$item['id_item']);
                    $item['next_episode'] = $this->trackingModel->getNextUnwatchedEpisode($userId, (string)$item['id_item']);
                }

                $item['progress'] = $progress;
                $item['progress_percent'] = $progress['total_count'] > 0 ? round(($progress['watched_count'] / $progress['total_count']) * 100) : 0;

                if (($item['status_acompanhamento'] ?? '') === 'quero_ver') {
                    $groups['quero_ver'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'em_pausa') {
                    $groups['em_pausa'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'abandonado') {
                    $groups['abandonado'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'reassistindo') {
                    $groups['reassistindo'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'concluido') {
                    $groups['concluido'][] = $item;
                } elseif ($progress['total_count'] > 0 && $remaining === 0 && $futureEpisodes === 0) {
                    $item['status_acompanhamento'] = 'concluido';
                    $this->trackingModel->updateWatchlistStatus($userId, (string)$item['id_item'], 'concluido');
                    $groups['concluido'][] = $item;
                } elseif ($progress['total_count'] > 0 && $remaining === 0) {
                    $groups['up_to_date'][] = $item;
                } else {
                    $groups['assistindo'][] = $item;
                }
            } else {
                $item['progress'] = ['total_count' => 1, 'watched_count' => ($item['status_acompanhamento'] ?? '') === 'concluido' ? 1 : 0];
                $item['progress_percent'] = ($item['status_acompanhamento'] ?? '') === 'concluido' ? 100 : 0;
                if (($item['status_acompanhamento'] ?? '') === 'quero_ver') {
                    $groups['quero_ver'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'em_pausa') {
                    $groups['em_pausa'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'abandonado') {
                    $groups['abandonado'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'reassistindo') {
                    $groups['reassistindo'][] = $item;
                } elseif (($item['status_acompanhamento'] ?? '') === 'concluido') {
                    $groups['concluido'][] = $item;
                } else {
                    $groups['assistindo'][] = $item;
                }
            }
            unset(
                $item['total_count'],
                $item['watched_count'],
                $item['remaining_count'],
                $item['future_count'],
                $item['next_episode_id'],
                $item['next_season_number'],
                $item['next_episode_number'],
                $item['next_episode_title'],
                $item['next_air_date'],
                $item['next_runtime_minutes']
            );
        }
        unset($item);

        $filtered = match ($statusFilter) {
            'watching' => $groups['assistindo'],
            'em_dia' => $groups['up_to_date'],
            'visto' => $groups['concluido'],
            'para_ver' => $groups['quero_ver'],
            'em_pausa' => $groups['em_pausa'],
            'abandonado' => $groups['abandonado'],
            'reassistindo' => $groups['reassistindo'],
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

        $data = $this->idempotent($payload, function() use ($name): array {
            $listId = $this->trackingModel->createList($this->userId(), $name);
            return [
                'list_id' => $listId,
                'lists' => $this->trackingModel->getUserListsSummary($this->userId()),
            ];
        });

        return $this->ok($data);
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

        $isFavorite = array_key_exists('eh_favorito', $payload)
            ? $this->trackingModel->setFavorite($this->userId(), $itemId, filter_var($payload['eh_favorito'], FILTER_VALIDATE_BOOLEAN))
            : $this->trackingModel->toggleFavorite($this->userId(), $itemId);

        return $this->ok([
            'item_id' => $itemId,
            'eh_favorito' => $isFavorite,
        ]);
    }

    public function trackAction(): JsonModel {
        $payload = $this->payload();
        $itemId = $this->resolveItemId($payload);
        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }

        $action = (string)($payload['action'] ?? 'add');
        $status = (string)($payload['status'] ?? 'assistindo');

        if ($action === 'add' && $status === 'concluido' && !$this->trackingModel->isItemReleased((string)$itemId)) {
            return $this->error('Este titulo ainda nao foi lancado.', 409);
        }
        if ($action === 'rewatch' && !$this->trackingModel->isItemReleased((string)$itemId)) {
            return $this->error('Este titulo ainda nao foi lancado.', 409);
        }

        $data = $this->idempotent($payload, function() use ($action, $itemId, $status): array {
            if ($action === 'rewatch') {
                $this->trackingModel->startRewatching($this->userId(), (string)$itemId);
            } elseif ($action === 'remove') {
                $this->trackingModel->removeTrack($this->userId(), (string)$itemId);
            } else {
                $this->trackingModel->addTrack($this->userId(), (string)$itemId, $status);
            }

            return [
                'item_id' => $itemId,
                'status' => $action === 'rewatch' ? 'reassistindo' : $status,
            ];
        });

        return $this->ok($data);
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

        $data = $this->idempotent($payload, fn(): array => [
            'episode_id' => $episodeId,
            'quantidade_reassistida' => $this->trackingModel->rewatchEpisode($this->userId(), $episodeId),
        ]);

        return $this->ok($data);
    }

    public function markEpisodesAction(): JsonModel {
        $payload = $this->payload();
        $itemId = (int)($payload['item_id'] ?? 0);
        $episodeId = (int)($payload['episode_id'] ?? 0);
        $seasonNumber = (int)($payload['numero_temporada'] ?? 0);
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

        $rating = $payload['nota'] ?? null;
        $comment = trim((string)($payload['comentario'] ?? ''));
        $rating = ($rating === null || $rating === '') ? null : (float)$rating;
        $comment = $comment === '' ? null : $comment;

        if ($rating === null || $rating <= 0) {
            return $this->error('Escolha uma avaliacao.', 422);
        }

        $pdo = $this->catalogModel->getPdo();
        $stmtCheck = $pdo->prepare("SELECT id_usuario_item FROM usuario_item WHERE id_usuario = :uid AND id_item = :iid LIMIT 1");
        $stmtCheck->execute([':uid' => $this->userId(), ':iid' => $itemId]);
        if (!$stmtCheck->fetch()) {
            $stmtInsert = $pdo->prepare("INSERT INTO usuario_item (id_usuario, id_item, status) VALUES (:uid, :iid, 'avaliado')");
            $stmtInsert->execute([':uid' => $this->userId(), ':iid' => $itemId]);
        }

        $stmt = $pdo->prepare("
            UPDATE usuario_item
            SET nota = :rating, comentario = :comment, ts_atualizacao = CURRENT_TIMESTAMP
            WHERE id_usuario = :uid AND id_item = :iid
        ");
        $stmt->execute([
            ':rating' => $rating,
            ':comment' => $comment,
            ':uid' => $this->userId(),
            ':iid' => $itemId,
        ]);

        $reviews = $this->catalogModel->getItemComments((int)$itemId);
        $currentReview = null;
        foreach ($reviews as $review) {
            if ((int)$review['id_usuario'] === $this->userId()) {
                $currentReview = $review;
                break;
            }
        }

        return $this->ok([
            'item_id' => $itemId,
            'nota' => $rating,
            'comentario' => $comment,
            'avaliacao' => $currentReview,
            'total_avaliacoes' => count($reviews),
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
            'history' => $this->trackingModel->getActivityHistory($userId, $limit),
            'favorites' => $this->trackingModel->getFavorites($userId, $limit),
            'reviews' => $this->trackingModel->getUserReviews($userId, $limit),
            'limit' => $limit,
        ]);
    }

    public function detailAction(): JsonModel {
        $userId = $this->userId();
        $fastResolve = $this->params()->fromQuery('rapido', '0') === '1' || $this->params()->fromQuery('somente_essencial', '0') === '1';
        $itemId = $this->resolveItemId([
            'item_id' => (int)$this->params()->fromQuery('id', 0),
            'tvmaze_id' => (int)$this->params()->fromQuery('tvmaze_id', 0),
            'tmdb_id' => (int)$this->params()->fromQuery('tmdb_id', 0),
            'mal_id' => (int)$this->params()->fromQuery('mal_id', 0),
            'tipo' => $this->params()->fromQuery('tipo', ''),
            'titulo' => $this->params()->fromQuery('titulo', ''),
            'ano_lancamento' => (int)$this->params()->fromQuery('ano_lancamento', 0),
            'data_lancamento' => $this->params()->fromQuery('data_lancamento', ''),
            'url_poster' => $this->params()->fromQuery('url_poster', ''),
            'url_banner' => $this->params()->fromQuery('url_banner', ''),
            'rapido' => $this->params()->fromQuery('rapido', '0') === '1',
        ], $fastResolve);
        if ($itemId <= 0) {
            return $this->error($this->itemResolveErrorMessage(), 422);
        }

        $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
        if (!$item) {
            return $this->error('Item nao encontrado.', 404);
        }

        $pdo = $this->catalogModel->getPdo();

        if ($this->params()->fromQuery('rapido', '0') === '1') {
            return $this->ok($this->localDetailData($userId, (int)$itemId, $item));
        }

        if ($this->params()->fromQuery('pular_sincronizacao', '0') !== '1') {
            try {
            if (($item['tipo'] ?? '') === 'movie' && !empty($item['tmdb_id']) && (empty($item['ts_ultima_sincronizacao']) || time() - strtotime($item['ts_ultima_sincronizacao']) > 86400)) {
                \Application\Helper\TmdbHelper::syncMovieMetadata($pdo, (int)$item['id_item'], (int)$item['tmdb_id']);
                $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
            }

            if (($item['tipo'] ?? '') !== 'movie' && !empty($item['tvmaze_id'])) {
                $lastSync = $item['ts_ultima_sincronizacao'] ?? null;
                $existingEpisodes = $this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId);
                if (empty($existingEpisodes) || empty($lastSync) || (time() - strtotime($lastSync)) > 86400) {
                    \Application\Helper\TvmazeHelper::syncEpisodes($pdo, (int)$itemId, (int)$item['tvmaze_id']);
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }

                if (empty($this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId)) && !empty($item['tmdb_id'])) {
                    \Application\Helper\TmdbHelper::syncTvMetadataAndEpisodes($pdo, (int)$itemId, (int)$item['tmdb_id'], $item['tipo']);
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }
            } elseif (($item['tipo'] ?? '') !== 'movie' && !empty($item['tmdb_id'])) {
                $lastSync = $item['ts_ultima_sincronizacao'] ?? null;
                if (empty($lastSync) || (time() - strtotime($lastSync)) > 86400) {
                    \Application\Helper\TmdbHelper::syncTvMetadataAndEpisodes($pdo, (int)$itemId, (int)$item['tmdb_id'], $item['tipo']);
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }
            }

            if (($item['tipo'] ?? '') === 'anime' && !empty($item['mal_id'])) {
                $episodes = $this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId);
                $lastSync = $item['ts_ultima_sincronizacao'] ?? null;
                if (empty($episodes) || empty($lastSync) || (time() - strtotime($lastSync)) > 86400) {
                    \Application\Helper\JikanHelper::syncEpisodes($pdo, (int)$itemId, (int)$item['mal_id'], (int)($item['total_episodios'] ?? 0));
                    $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId);
                }
            }
            } catch (\Throwable $e) {
                $item = $this->catalogModel->getLocalItemById($userId, (string)$itemId) ?: $item;
            }
        }

        if ($this->params()->fromQuery('somente_essencial', '0') === '1') {
            return $this->ok($this->localDetailData($userId, (int)$itemId, $item));
        }

        // Fetch/populate watch providers if null
        try {
            if (($item['provedores_streaming'] ?? null) === null) {
                $watchProviders = $this->fetchWatchProviders($item['titulo'], $item['tipo'], (int)($item['ano_lancamento'] ?? 0), (int)($item['tmdb_id'] ?? 0), (int)($item['tvmaze_id'] ?? 0));
                if ($watchProviders !== null) {
                    $stmt = $pdo->prepare("UPDATE item SET provedores_streaming = :wp WHERE id_item = :id");
                    $stmt->execute([':wp' => $watchProviders, ':id' => $itemId]);
                    $item['provedores_streaming'] = $watchProviders;
                } else {
                    $stmt = $pdo->prepare("UPDATE item SET provedores_streaming = '' WHERE id_item = :id");
                    $stmt->execute([':id' => $itemId]);
                    $item['provedores_streaming'] = '';
                }
            }
        } catch (\Throwable $e) {
            $item['provedores_streaming'] = $item['provedores_streaming'] ?? '';
        }

        // Fetch/populate genres if null/empty
        try {
            if (empty($item['generos'])) {
                $genresStr = null;
                if (($item['tipo'] ?? '') === 'anime' && !empty($item['mal_id'])) {
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
                } elseif (($item['tipo'] ?? '') !== 'movie' && !empty($item['tvmaze_id'])) {
                    $url = "https://api.tvmaze.com/shows/" . (int)$item['tvmaze_id'];
                    $json = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: CineFio/1.0\r\n", 'timeout' => 4]]));
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
                    $stmt = $pdo->prepare("UPDATE item SET generos = :genres WHERE id_item = :id");
                    $stmt->execute([':genres' => $genresStr, ':id' => $itemId]);
                    $item['generos'] = $genresStr;
                }
            } else {
                $item['generos'] = $this->translateGenres($item['generos']);
            }
        } catch (\Throwable $e) {
            $item['generos'] = $item['generos'] ?? '';
        }

        // Recommendations
        $recommendations = [];
        try {
            if (($item['tipo'] ?? '') === 'anime' && !empty($item['mal_id'])) {
                $recommendations = \Application\Helper\JikanHelper::getRecommendations((int)$item['mal_id'], 8);
            } else {
                $tmdbIdForRecs = $item['tmdb_id'] ?? null;
                if (empty($tmdbIdForRecs)) {
                    $searchType = (($item['tipo'] ?? '') === 'movie') ? 'movie' : 'tv';
                    $url = "https://api.themoviedb.org/3/search/" . $searchType . "?api_key=1f54bd990f1cdfb230adb312546d765d&query=" . urlencode($item['titulo']) . "&language=pt-BR";
                    if (!empty($item['ano_lancamento'])) {
                        $url .= (($item['tipo'] ?? '') === 'movie') ? "&primary_release_year=" . $item['ano_lancamento'] : "&first_air_date_year=" . $item['ano_lancamento'];
                    }
                    $json = @file_get_contents($url, false, stream_context_create(['http' => ['header' => "User-Agent: CineFio/1.0\r\n", 'timeout' => 5]]));
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
                    $recommendations = \Application\Helper\TmdbHelper::getRecommendations($item['tipo'], (int)$tmdbIdForRecs, 8);
                }
            }
        } catch (\Throwable $e) {
            $recommendations = [];
        }

        if (!empty($item['provedores_streaming'])) {
            $providers = json_decode($item['provedores_streaming'], true);
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
                $item['provedores_streaming'] = json_encode(array_values($cleaned), JSON_UNESCAPED_UNICODE);
            }
        }

        return $this->ok([
            'item' => $item,
            'episodes' => $this->episodesForDetail($userId, (int)$itemId, $item),
            'progress' => $this->catalogModel->getProgress($userId, (string)$itemId),
            'next_unwatched' => $this->catalogModel->getNextUnwatched($userId, (string)$itemId),
            'released' => $this->trackingModel->isItemReleased((string)$itemId),
            'lists' => $this->trackingModel->getItemLists($userId, (int)$itemId),
            'cast' => $this->getCast($item),
            'reviews' => $this->catalogModel->getItemComments((int)$itemId),
            'recommendations' => $recommendations
        ]);
    }

    public function personAction(): JsonModel {
        $source = strtolower(trim((string)$this->params()->fromQuery('source', 'tmdb')));
        $personId = (int)$this->params()->fromQuery('person_id', 0);
        if ($personId <= 0) return $this->error('Pessoa invalida.', 422);

        $result = match ($source) {
            'jikan' => JikanHelper::getCharacterCredits($personId),
            'tvmaze' => $this->getTvmazePersonCredits($personId),
            default => TmdbHelper::getPersonCredits($personId),
        };
        if (!$result) return $this->error('Filmografia nao encontrada.', 404);
        return $this->ok($result);
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

    private function idempotent(array $payload, callable $operation): array {
        $header = $this->getRequest()->getHeader('X-Idempotency-Key');
        $mutationId = $header ? trim((string)$header->getFieldValue()) : trim((string)($payload['id_mutacao_cliente'] ?? ''));
        if ($mutationId === '') {
            return $operation();
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $mutationId)) {
            throw new \InvalidArgumentException('Chave de idempotencia invalida.');
        }

        $pdo = $this->catalogModel->getPdo();
        $userId = $this->userId();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmtInsert = $pdo->prepare("
                INSERT INTO requisicao_idempotente (id_usuario, id_mutacao_cliente)
                VALUES (:id_usuario, :id_mutacao_cliente)
                ON CONFLICT (id_usuario, id_mutacao_cliente) DO NOTHING
            ");
            $stmtInsert->execute([
                ':id_usuario' => $userId,
                ':id_mutacao_cliente' => $mutationId,
            ]);

            $stmtExisting = $pdo->prepare("
                SELECT resposta
                FROM requisicao_idempotente
                WHERE id_usuario = :id_usuario
                  AND id_mutacao_cliente = :id_mutacao_cliente
                FOR UPDATE
            ");
            $stmtExisting->execute([
                ':id_usuario' => $userId,
                ':id_mutacao_cliente' => $mutationId,
            ]);
            $stored = $stmtExisting->fetchColumn();
            if ($stored !== false && $stored !== null) {
                $data = json_decode((string)$stored, true, 512, JSON_THROW_ON_ERROR);
                if ($startedTransaction) $pdo->commit();
                return is_array($data) ? $data : [];
            }

            $data = $operation();
            $stmtUpdate = $pdo->prepare("
                UPDATE requisicao_idempotente
                SET resposta = CAST(:resposta AS JSONB)
                WHERE id_usuario = :id_usuario
                  AND id_mutacao_cliente = :id_mutacao_cliente
            ");
            $stmtUpdate->execute([
                ':resposta' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':id_usuario' => $userId,
                ':id_mutacao_cliente' => $mutationId,
            ]);
            if ($startedTransaction) $pdo->commit();
            return $data;
        } catch (\Throwable $error) {
            if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
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

    private function resolveItemId(array $payload, bool $preferLocalPlaceholder = false): int {
        $itemId = (int)($payload['item_id'] ?? $payload['id_item'] ?? 0);
        if ($itemId > 0) {
            return $itemId;
        }

        $pdo = $this->catalogModel->getPdo();
        $tvmazeId = (int)($payload['tvmaze_id'] ?? 0);
        $tmdbId = (int)($payload['tmdb_id'] ?? 0);
        $malId = (int)($payload['mal_id'] ?? 0);
        $type = $this->normalizeItemType((string)($payload['tipo'] ?? 'series'));
        $payload['tipo'] = $type;

        $existingExternalId = $this->findLocalItemIdByExternalIds($pdo, $tvmazeId, $tmdbId, $malId);
        if ($existingExternalId > 0) {
            return $existingExternalId;
        }

        if (($preferLocalPlaceholder || !empty($payload['rapido'])) && trim((string)($payload['titulo'] ?? '')) !== '') {
            $quickItemId = $this->createLocalItemFromPayload($payload);
            if ($quickItemId > 0) {
                return $quickItemId;
            }
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
                        'tipo' => 'movie',
                        'titulo' => $payload['titulo'] ?? ($movie['title'] ?? $movie['original_title'] ?? ''),
                        'descricao' => $payload['descricao'] ?? ($movie['overview'] ?? ''),
                        'data_lancamento' => $payload['data_lancamento'] ?? ($movie['release_date'] ?? ''),
                        'url_poster' => $payload['url_poster'] ?? (!empty($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] : ''),
                        'url_banner' => $payload['url_banner'] ?? (!empty($movie['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'] : ''),
                        'duracao_minutos' => (int)($movie['runtime'] ?? 120),
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
                        'tipo' => $type,
                        'titulo' => $payload['titulo'] ?? ($tvShow['name'] ?? $tvShow['original_name'] ?? ''),
                        'descricao' => $payload['descricao'] ?? ($tvShow['overview'] ?? ''),
                        'data_lancamento' => $payload['data_lancamento'] ?? ($tvShow['first_air_date'] ?? ''),
                        'ano_lancamento' => $payload['ano_lancamento'] ?? (!empty($tvShow['first_air_date']) ? (int)substr($tvShow['first_air_date'], 0, 4) : 0),
                        'url_poster' => $payload['url_poster'] ?? (!empty($tvShow['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $tvShow['poster_path'] : ''),
                        'url_banner' => $payload['url_banner'] ?? (!empty($tvShow['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $tvShow['backdrop_path'] : ''),
                        'total_episodios' => (int)($tvShow['number_of_episodes'] ?? 0),
                        'duracao_minutos' => (int)($tvShow['episode_run_time'][0] ?? 45),
                    ]);
                    $resolvedId = $this->createLocalItemFromPayload($fallbackPayload);
                    if ($resolvedId > 0) {
                        return $resolvedId;
                    }
                }
            }
        }

        $title = trim((string)($payload['titulo'] ?? ''));
        if ($title !== '') {
            $releaseYear = (int)($payload['ano_lancamento'] ?? 0);

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
                if (($result['tipo'] ?? '') !== $type || empty($result['tmdb_id'])) {
                    continue;
                }

                if ($releaseYear > 0 && !empty($result['ano_lancamento']) && abs((int)$result['ano_lancamento'] - $releaseYear) > 1) {
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
        $title = trim((string)($payload['titulo'] ?? ''));
        if ($title === '') {
            return 0;
        }

        $pdo = $this->catalogModel->getPdo();
        $type = $this->normalizeItemType((string)($payload['tipo'] ?? 'series'));
        $tvmazeId = (int)($payload['tvmaze_id'] ?? 0);
        $tmdbId = (int)($payload['tmdb_id'] ?? 0);
        $malId = (int)($payload['mal_id'] ?? 0);

        $existingExternalId = $this->findLocalItemIdByExternalIds($pdo, $tvmazeId, $tmdbId, $malId);
        if ($existingExternalId > 0) {
            return $existingExternalId;
        }

        $releaseDate = trim((string)($payload['data_lancamento'] ?? '')) ?: null;
        $releaseYear = (int)($payload['ano_lancamento'] ?? 0);
        if ($releaseYear <= 0 && $releaseDate) {
            $releaseYear = (int)substr($releaseDate, 0, 4);
        }
        if ($releaseYear <= 0) {
            $releaseYear = (int)date('Y');
        }

        $stmt = $pdo->prepare("
            SELECT id_item
            FROM item
            WHERE LOWER(titulo) = LOWER(:titulo)
              AND tipo = :tipo
              AND ano_lancamento = :ano_lancamento
            LIMIT 1
        ");
        $stmt->execute([
            ':titulo' => $title,
            ':tipo' => $type,
            ':ano_lancamento' => $releaseYear,
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
                    tvmaze_id, tmdb_id, mal_id, titulo, tipo, url_poster, url_banner, descricao,
                    ano_lancamento, data_lancamento, total_episodios, duracao_minutos, status, ts_inclusao
                )
                VALUES (
                    :tvmaze_id, :tmdb_id, :mal_id, :titulo, :tipo, :url_poster, :url_banner, :descricao,
                    :ano_lancamento, :data_lancamento, :total_episodios, :duracao_minutos, :status, CURRENT_TIMESTAMP
                )
                RETURNING id_item
            ");
            $stmt->execute([
                ':tvmaze_id' => $tvmazeId > 0 ? $tvmazeId : null,
                ':tmdb_id' => $tmdbId > 0 ? $tmdbId : null,
                ':mal_id' => $malId > 0 ? $malId : null,
                ':titulo' => $title,
                ':tipo' => $type,
                ':url_poster' => $payload['url_poster'] ?? '',
                ':url_banner' => $payload['url_banner'] ?? ($payload['url_poster'] ?? ''),
                ':descricao' => $payload['descricao'] ?? 'Nenhuma sinopse disponivel.',
                ':ano_lancamento' => $releaseYear,
                ':data_lancamento' => $releaseDate,
                ':total_episodios' => (int)($payload['total_episodios'] ?? ($type === 'movie' ? 1 : 0)),
                ':duracao_minutos' => (int)($payload['duracao_minutos'] ?? ($type === 'movie' ? 120 : 45)),
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
            $cast = TmdbHelper::getCredits((string)$item['tipo'], (int)$item['tmdb_id'], 12);
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

        $cacheDir = dirname(__DIR__, 4) . '/data/cache/tvmaze';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
        $url = 'https://api.tvmaze.com/shows/' . (int)$item['tvmaze_id'] . '/cast';
        $cacheFile = $cacheDir . '/' . md5($url) . '.json';
        $json = is_file($cacheFile) && time() - filemtime($cacheFile) < 21600
            ? @file_get_contents($cacheFile)
            : false;
        if (!$json) {
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: CineFio/1.0\r\nAccept: application/json\r\n",
                    'timeout' => 4,
                ],
            ]);
            $json = @file_get_contents($url, false, $context);
            if ($json) @file_put_contents($cacheFile, $json);
        }
        $rows = $json ? json_decode($json, true) : [];
        if (!is_array($rows)) {
            return [];
        }

        $cast = [];
        foreach (array_slice($rows, 0, 12) as $row) {
            $person = $row['person'] ?? [];
            $character = $row['character'] ?? [];
            $cast[] = [
                'person_id' => (int)($person['id'] ?? 0),
                'source' => 'tvmaze',
                'name' => $person['name'] ?? 'Sem nome',
                'character' => $character['name'] ?? '',
                'image_url' => $person['image']['medium'] ?? $person['image']['original'] ?? null,
            ];
        }

        return $cast;
    }

    private function localDetailData(int $userId, int $itemId, array $item): array {
        $episodes = $this->episodesForDetail($userId, $itemId, $item);
        return [
            'item' => $item,
            'episodes' => $episodes,
            'progress' => $this->catalogModel->getProgress($userId, (string)$itemId),
            'next_unwatched' => $this->catalogModel->getNextUnwatched($userId, (string)$itemId),
            'released' => $this->trackingModel->isItemReleased((string)$itemId),
            'lists' => $this->trackingModel->getItemLists($userId, $itemId),
            'cast' => [],
            'reviews' => $this->catalogModel->getItemComments($itemId),
            'recommendations' => [],
        ];
    }

    private function episodesForDetail(int $userId, int $itemId, array $item): array {
        $episodes = $this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId);
        if (!empty($episodes) || ($item['tipo'] ?? '') === 'movie') {
            return $episodes;
        }

        $totalEpisodes = (int)($item['total_episodios'] ?? 0);
        if ($totalEpisodes <= 0) {
            return [];
        }

        return $this->seedPlaceholderEpisodes($userId, $itemId, $totalEpisodes, (string)($item['tipo'] ?? 'series'));
    }

    private function seedPlaceholderEpisodes(int $userId, int $itemId, int $totalEpisodes, string $type): array {
        $totalEpisodes = max(0, min($totalEpisodes, 1500));
        if ($totalEpisodes === 0 || $type === 'movie') {
            return [];
        }

        $pdo = $this->catalogModel->getPdo();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO episodio (id_item, numero_temporada, numero_episodio, titulo, data_exibicao, descricao, duracao_minutos)
                VALUES (:id_item, 1, :numero_episodio, :titulo, NULL, '', :duracao_minutos)
                ON CONFLICT (id_item, numero_temporada, numero_episodio) DO NOTHING
            ");

            $runtime = $type === 'anime' ? 24 : 45;
            for ($episodeNumber = 1; $episodeNumber <= $totalEpisodes; $episodeNumber++) {
                $stmt->execute([
                    ':id_item' => $itemId,
                    ':numero_episodio' => $episodeNumber,
                    ':titulo' => 'Episodio ' . $episodeNumber,
                    ':duracao_minutos' => $runtime,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        return $this->catalogModel->getEpisodesWithWatchedState($userId, (string)$itemId);
    }

    private function getTvmazePersonCredits(int $personId): ?array {
        $context = stream_context_create(['http' => ['header' => "User-Agent: CineFio/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]]);
        $personJson = @file_get_contents('https://api.tvmaze.com/people/' . $personId, false, $context);
        $creditsJson = @file_get_contents('https://api.tvmaze.com/people/' . $personId . '/castcredits?embed=show', false, $context);
        $person = $personJson ? json_decode($personJson, true) : null;
        $rows = $creditsJson ? json_decode($creditsJson, true) : [];
        if (!$person || !is_array($rows)) return null;

        $credits = [];
        foreach ($rows as $row) {
            $show = $row['_embedded']['show'] ?? [];
            if (empty($show['id'])) continue;
            $premiered = $show['premiered'] ?? '';
            $credits[] = [
                'id_item' => null,
                'tmdb_id' => null,
                'tvmaze_id' => (int)$show['id'],
                'mal_id' => null,
                'titulo' => $show['name'] ?? 'Sem título',
                'tipo' => 'series',
                'url_poster' => $show['image']['medium'] ?? '',
                'url_banner' => $show['image']['original'] ?? '',
                'descricao' => trim(strip_tags($show['summary'] ?? '')),
                'ano_lancamento' => $premiered ? (int)substr($premiered, 0, 4) : null,
                'data_lancamento' => $premiered ?: null,
                'personagem' => '',
                'popularidade' => (float)($show['weight'] ?? 0),
            ];
        }
        usort($credits, static fn(array $a, array $b): int => ($b['popularidade'] <=> $a['popularidade']));
        return [
            'person' => [
                'person_id' => (int)$person['id'],
                'source' => 'tvmaze',
                'name' => $person['name'] ?? 'Sem nome',
                'image_url' => $person['image']['original'] ?? $person['image']['medium'] ?? null,
                'biography' => '',
                'birthday' => $person['birthday'] ?? null,
                'place_of_birth' => $person['country']['name'] ?? null,
                'department' => 'Atuação',
            ],
            'credits' => array_slice($credits, 0, 60),
        ];
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
