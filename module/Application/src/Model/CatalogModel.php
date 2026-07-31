<?php

namespace Application\Model;

use PDO;

class CatalogModel {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function getLocalItemByTvmazeId(int $userId, string $tvmazeId) {
        $stmt = $this->pdo->prepare("
            SELECT i.*, ui.status as track_status 
            FROM item i 
            LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
            WHERE i.tvmaze_id = :tvmaze_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':tvmaze_id' => $tvmazeId]);
        return $stmt->fetch();
    }

    public function getLocalItemByTmdbId(int $userId, string $tmdbId) {
        $stmt = $this->pdo->prepare("
            SELECT i.*, ui.status as track_status
            FROM item i
            LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
            WHERE i.tmdb_id = :tmdb_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':tmdb_id' => $tmdbId]);
        return $stmt->fetch();
    }

    public function getLocalItemByMalId(int $userId, string $malId) {
        $stmt = $this->pdo->prepare("
            SELECT i.*, ui.status as track_status
            FROM item i
            LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
            WHERE i.mal_id = :mal_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':mal_id' => $malId]);
        return $stmt->fetch();
    }

    public function getLocalItemById(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT i.*, ui.status as track_status, ui.rating, ui.comment, COALESCE(ui.rewatch_count, 0) as rewatch_count
            FROM item i
            LEFT JOIN usuario_item ui ON i.id_item = ui.id_item AND ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
            WHERE i.id_item = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function getEpisodesWithWatchedState(int $userId, string $itemId): array {
        $stmt = $this->pdo->prepare("
            SELECT e.*, 
                   (ue.id_usuario_episodio IS NOT NULL) as watched,
                   COALESCE(ue.rewatch_count, 0) as rewatch_count
            FROM episodio e
            LEFT JOIN usuario_episodio ue ON e.id_episodio = ue.id_episodio AND ue.id_usuario = :user_id AND ue.ts_cancelamento IS NULL
            WHERE e.id_item = :item_id
            ORDER BY e.season_number ASC, e.episode_number ASC
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getProgress(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(e.id_episodio) as total_count,
                COUNT(ue.id_usuario_episodio) as watched_count
            FROM episodio e
            LEFT JOIN usuario_episodio ue ON e.id_episodio = ue.id_episodio AND ue.id_usuario = :user_id AND ue.ts_cancelamento IS NULL
            WHERE e.id_item = :item_id
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function getNextUnwatched(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT season_number, episode_number FROM episodio 
            WHERE id_item = :item_id 
              AND id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id AND ts_cancelamento IS NULL)
            ORDER BY season_number ASC, episode_number ASC
            LIMIT 1
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function getItemByTvmazeId(string $tvmazeId) {
        $stmt = $this->pdo->prepare("SELECT * FROM item WHERE tvmaze_id = :tvmaze_id LIMIT 1");
        $stmt->execute([':tvmaze_id' => $tvmazeId]);
        return $stmt->fetch();
    }

    public function saveItem(array $itemData): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO item (id_item, title, type, poster_url, banner_url, tvmaze_id)
            VALUES (:id, :title, :type, :poster, :banner, :tvmaze_id)
            ON CONFLICT (id_item) DO UPDATE 
            SET title = EXCLUDED.title, 
                poster_url = EXCLUDED.poster_url, 
                banner_url = EXCLUDED.banner_url,
                tvmaze_id = EXCLUDED.tvmaze_id
        ");
        $stmt->execute([
            ':id' => $itemData['id'],
            ':title' => $itemData['title'],
            ':type' => $itemData['type'],
            ':poster' => $itemData['poster'],
            ':banner' => $itemData['banner'],
            ':tvmaze_id' => $itemData['tvmaze_id'] ?? null
        ]);
    }

    public function getWatchlistStatus(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT status FROM usuario_item 
            WHERE id_usuario = :user_id AND id_item = :item_id AND ts_cancelamento IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':item_id' => $itemId
        ]);
        $res = $stmt->fetch();
        return $res ? $res['status'] : null;
    }

    public function getEpisodesByItemId(string $itemId): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM episodio 
            WHERE id_item = :item_id 
            ORDER BY season_number ASC, episode_number ASC
        ");
        $stmt->execute([':item_id' => $itemId]);
        return $stmt->fetchAll();
    }

    public function saveEpisode(array $epData): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO episodio (id_item, season_number, episode_number, title, air_date, runtime_minutes, rating, description)
            VALUES (:item_id, :season, :episode, :title, :air_date, :runtime, :rating, :description)
            ON CONFLICT (id_item, season_number, episode_number) DO UPDATE
            SET title = EXCLUDED.title,
                air_date = EXCLUDED.air_date,
                runtime_minutes = EXCLUDED.runtime_minutes,
                rating = EXCLUDED.rating,
                description = EXCLUDED.description
        ");
        $stmt->execute([
            ':item_id' => $epData['item_id'],
            ':season' => $epData['season'],
            ':episode' => $epData['episode'],
            ':title' => $epData['title'],
            ':air_date' => $epData['air_date'] ?: null,
            ':runtime' => $epData['runtime'] ?: null,
            ':rating' => $epData['rating'] ?: null,
            ':description' => $epData['description'] ?: null
        ]);
    }

    public function getWatchedEpisodeIds(int $userId, array $episodeIds): array {
        if (empty($episodeIds)) return [];
        $inQuery = implode(',', array_fill(0, count($episodeIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id_episodio FROM usuario_episodio 
            WHERE id_usuario = ? AND id_episodio IN ($inQuery)
        ");
        $params = array_merge([$userId], $episodeIds);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function getCachedSearchResults(string $query) {
        $stmt = $this->pdo->prepare("
            SELECT results FROM search_cache 
            WHERE query = :query AND ts_created > CURRENT_TIMESTAMP - INTERVAL '1 day'
            LIMIT 1
        ");
        $stmt->execute([':query' => strtolower(trim($query))]);
        $res = $stmt->fetch();
        return $res ? json_decode($res['results'], true) : null;
    }

    public function cacheSearchResults(string $query, array $results): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO search_cache (query, results, ts_created)
            VALUES (:query, :results, CURRENT_TIMESTAMP)
            ON CONFLICT (query) DO UPDATE 
            SET results = EXCLUDED.results, ts_created = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':query' => strtolower(trim($query)),
            ':results' => json_encode($results)
        ]);
    }

    public function searchAllDatabases(string $search, int $userId): array {
        // Check cache first
        $cached = $this->getCachedSearchResults($search);
        if ($cached !== null) {
            // Re-bind watched/track statuses from DB for cached items so they stay dynamically up-to-date
            foreach ($cached as &$item) {
                if (!empty($item['tvmaze_id'])) {
                    $local = $this->getLocalItemByTvmazeId($userId, $item['tvmaze_id']);
                    if ($local) {
                        $item['id_item'] = $local['id_item'];
                        $item['track_status'] = $local['track_status'];
                    }
                } elseif (!empty($item['tmdb_id'])) {
                    $local = $this->getLocalItemByTmdbId($userId, $item['tmdb_id']);
                    if ($local) {
                        $item['id_item'] = $local['id_item'];
                        $item['track_status'] = $local['track_status'];
                    }
                } elseif (!empty($item['mal_id'])) {
                    $local = $this->getLocalItemByMalId($userId, $item['mal_id']);
                    if ($local) {
                        $item['id_item'] = $local['id_item'];
                        $item['track_status'] = $local['track_status'];
                    }
                }
            }
            unset($item);
            return $cached;
        }

        // TVmaze Search
        $options = ['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 5]];
        $context = stream_context_create($options);
        $apiUrl = "https://api.tvmaze.com/search/shows?q=" . urlencode($search);
        $json = @file_get_contents($apiUrl, false, $context);
        $tvmazeResults = $json ? json_decode($json, true) : [];

        $merged = [];
        $seenKeys = [];

        $getDedupeKey = function($title, $type, $year) {
            $cleanTitle = preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title)));
            return $cleanTitle . '_' . $type . '_' . $year;
        };

        // 1. Process TVmaze results
        foreach ($tvmazeResults as $result) {
            $show = $result['show'] ?? null;
            if (!$show) continue;
            $summary = strip_tags($show['summary'] ?? '');
            if (empty($summary)) {
                $summary = 'Nenhuma sinopse disponível.';
            }

            $tvmazeId = $show['id'];
            $localItem = $this->getLocalItemByTvmazeId($userId, $tvmazeId);
            if ($localItem) {
                $item = $localItem;
            } else {
                $showType = 'series';
                $genres = $show['genres'] ?? [];
                if (in_array('Anime', $genres) || 
                    (isset($show['network']['country']['code']) && $show['network']['country']['code'] === 'JP') ||
                    (isset($show['webChannel']['country']['code']) && $show['webChannel']['country']['code'] === 'JP')) {
                    $showType = 'anime';
                }
                $releaseYear = isset($show['premiered']) ? intval(substr($show['premiered'], 0, 4)) : date('Y');
                $poster = $show['image']['medium'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
                $banner = $show['image']['original'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';

                $item = [
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

            $key = $getDedupeKey($item['title'], $item['type'], $item['release_year']);
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $merged[] = $item;
            }
        }

        // 2. Process TMDB results
        $tmdbResults = \Application\Helper\TmdbHelper::searchMulti($search, 20);
        foreach ($tmdbResults as $r) {
            if (!empty($r['tmdb_id'])) {
                $local = $this->getLocalItemByTmdbId($userId, $r['tmdb_id']);
                $item = $local ? $local : $r;
            } else {
                $item = $r;
            }

            $key = $getDedupeKey($item['title'], $item['type'], $item['release_year'] ?? date('Y'));
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $merged[] = $item;
            }
        }

        // 3. Process Jikan/MAL results
        $malResults = \Application\Helper\JikanHelper::searchAnime($search, 15);
        foreach ($malResults as $r) {
            if (!empty($r['mal_id'])) {
                $local = $this->getLocalItemByMalId($userId, $r['mal_id']);
                $item = $local ? $local : $r;
            } else {
                $item = $r;
            }

            $key = $getDedupeKey($item['title'], $item['type'], $item['release_year'] ?? date('Y'));
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $merged[] = $item;
            }
        }

        $this->cacheSearchResults($search, $merged);

        return $merged;
    }

    public function getItemComments(int $itemId): array {
        $stmt = $this->pdo->prepare("
            SELECT ui.comment, ui.rating, ui.ts_atualizacao, u.user_name, u.avatar_url, ui.id_usuario
            FROM usuario_item ui
            JOIN usuario u ON ui.id_usuario = u.id_usuario
            WHERE ui.id_item = :item_id AND ui.comment IS NOT NULL AND ui.comment != ''
            ORDER BY ui.ts_atualizacao DESC
        ");
        $stmt->execute([':item_id' => $itemId]);
        return $stmt->fetchAll();
    }
}
