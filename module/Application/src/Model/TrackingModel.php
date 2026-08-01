<?php

namespace Application\Model;

use PDO;

class TrackingModel {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureFavoriteColumn();
    }

    private function ensureFavoriteColumn(): void {
        try {
            $stmt = $this->pdo->query("
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'usuario_item'
                  AND column_name = 'is_favorite'
                LIMIT 1
            ");
            if (!$stmt->fetchColumn()) {
                $this->pdo->exec("ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS is_favorite BOOLEAN NOT NULL DEFAULT FALSE");
            }
        } catch (\Exception $e) {
            // Ignore schema bootstrap failures; the app will still run read-only.
        }
    }

    private function dbBool($value): bool {
        return in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);
    }

    private function ensureCollectionRowsFromWatchedEpisodes(int $userId): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_item (id_usuario, id_item, status, ts_cancelamento)
                SELECT DISTINCT :insert_user_id, e.id_item, 'watching', NULL
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.id_usuario = :filter_user_id
                  AND ue.ts_cancelamento IS NULL
                ON CONFLICT (id_usuario, id_item) DO UPDATE SET
                    ts_cancelamento = NULL,
                    ts_atualizacao = CURRENT_TIMESTAMP,
                    status = CASE
                        WHEN usuario_item.ts_cancelamento IS NOT NULL THEN 'watching'
                        ELSE usuario_item.status
                    END
            ");
            $stmt->execute([':insert_user_id' => $userId, ':filter_user_id' => $userId]);
        } catch (\Throwable $e) {
            // The collection query below can still include watched episodes directly.
        }
    }

    public function getUserCollection(int $userId, array $types, string $sortBy, string $providerFilter = ''): array {
        $this->ensureCollectionRowsFromWatchedEpisodes($userId);
        try {
            $items = $this->getUserCollectionQuery($userId, $types, $sortBy, $providerFilter);
        } catch (\Throwable $e) {
            $items = [];
        }

        if (!empty($items)) {
            return $items;
        }

        return $this->getUserCollectionFallback($userId, $types);
    }

    private function getUserCollectionQuery(int $userId, array $types, string $sortBy, string $providerFilter = ''): array {
        $query = "
            SELECT
                COALESCE(ui.status, 'watching') as track_status,
                COALESCE(ui.ts_atualizacao, watched.last_watched_at, i.ts_inclusao) as ts_atualizacao,
                COALESCE(ui.ts_inclusao, watched.first_watched_at, i.ts_inclusao) as collection_created_at,
                i.*
            FROM (
                SELECT id_item
                FROM usuario_item
                WHERE id_usuario = :tracked_user_id AND ts_cancelamento IS NULL
                UNION
                SELECT DISTINCT e.id_item
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.id_usuario = :episode_user_id AND ue.ts_cancelamento IS NULL
            ) c
            JOIN item i ON i.id_item = c.id_item
            LEFT JOIN usuario_item ui ON ui.id_item = i.id_item
                AND ui.id_usuario = :joined_user_id
                AND ui.ts_cancelamento IS NULL
            LEFT JOIN (
                SELECT e.id_item, MIN(ue.ts_inclusao) as first_watched_at, MAX(ue.ts_inclusao) as last_watched_at
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.id_usuario = :watched_user_id AND ue.ts_cancelamento IS NULL
                GROUP BY e.id_item
            ) watched ON watched.id_item = i.id_item
            WHERE 1=1
        ";

        if (!empty($types)) {
            $safeTypes = array_map(function($t) { return $this->pdo->quote($t); }, $types);
            $query .= " AND i.type IN (" . implode(",", $safeTypes) . ")";
        } else {
            $query .= " AND 1=0";
        }

        $query .= " AND i.description IS NOT NULL AND i.description != 'Nenhuma sinopse disponível.' AND i.description != ''";

        $query = preg_replace("/ AND i\\.description IS NOT NULL AND i\\.description != '.*?' AND i\\.description != ''/", "", $query);

        if (!empty($providerFilter)) {
            $query .= " AND i.watch_providers LIKE :provider_pattern";
        }

        if ($sortBy === 'last_added') {
            $query .= " ORDER BY collection_created_at DESC";
        } elseif ($sortBy === 'last_premiered') {
            $query .= " ORDER BY i.release_year DESC";
        } else {
            $query .= " ORDER BY ts_atualizacao DESC";
        }

        $stmt = $this->pdo->prepare($query);
        $params = [
            ':tracked_user_id' => $userId,
            ':episode_user_id' => $userId,
            ':joined_user_id' => $userId,
            ':watched_user_id' => $userId,
        ];
        if (!empty($providerFilter)) {
            $params[':provider_pattern'] = '%"name":"' . $providerFilter . '"%';
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function getUserCollectionFallback(int $userId, array $types): array {
        try {
            $query = "
                SELECT DISTINCT
                    'watching' as track_status,
                    MAX(ue.ts_inclusao) OVER (PARTITION BY i.id_item) as ts_atualizacao,
                    i.*
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                JOIN item i ON i.id_item = e.id_item
                WHERE ue.id_usuario = :user_id
                  AND ue.ts_cancelamento IS NULL
            ";

            if (!empty($types)) {
                $safeTypes = array_map(function($t) { return $this->pdo->quote($t); }, $types);
                $query .= " AND i.type IN (" . implode(",", $safeTypes) . ")";
            } else {
                $query .= " AND 1=0";
            }

            $query .= " ORDER BY ts_atualizacao DESC";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getProgress(int $userId, string $itemId): array {
        $stmtTotal = $this->pdo->prepare("
            SELECT COUNT(id_episodio) 
            FROM episodio 
            WHERE id_item = :item_id 
              AND (air_date IS NULL OR air_date <= CURRENT_DATE)
        ");
        $stmtTotal->execute([':item_id' => $itemId]);
        $total = (int)$stmtTotal->fetchColumn();

        $stmtWatched = $this->pdo->prepare("
            SELECT COUNT(ue.id_episodio) 
            FROM usuario_episodio ue
            JOIN episodio e ON ue.id_episodio = e.id_episodio
            WHERE ue.id_usuario = :user_id AND e.id_item = :item_id AND ue.ts_cancelamento IS NULL
        ");
        $stmtWatched->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $watched = (int)$stmtWatched->fetchColumn();

        return [
            'total_count' => $total,
            'watched_count' => $watched
        ];
    }

    public function countReleasedUnwatchedEpisodes(int $userId, string $itemId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(e.id_episodio) 
            FROM episodio e
            WHERE e.id_item = :item_id 
              AND (e.air_date IS NULL OR e.air_date <= CURRENT_DATE)
              AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id AND ts_cancelamento IS NULL)
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return intval($stmt->fetchColumn());
    }

    public function countFutureEpisodes(string $itemId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(id_episodio)
            FROM episodio
            WHERE id_item = :item_id
              AND air_date IS NOT NULL
              AND air_date > CURRENT_DATE
        ");
        $stmt->execute([':item_id' => $itemId]);
        return intval($stmt->fetchColumn());
    }

    public function getNextUnwatchedEpisode(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT e.* 
            FROM episodio e
            WHERE e.id_item = :item_id 
              AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id AND ts_cancelamento IS NULL)
            ORDER BY e.season_number ASC, e.episode_number ASC
            LIMIT 1
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function addTrack(int $userId, string $itemId, string $status): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_item (id_usuario, id_item, status, ts_cancelamento)
            VALUES (:user_id, :item_id, :status, NULL)
            ON CONFLICT (id_usuario, id_item) DO UPDATE 
            SET status = EXCLUDED.status, ts_atualizacao = CURRENT_TIMESTAMP, ts_cancelamento = NULL
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':item_id' => $itemId,
            ':status' => $status
        ]);
    }

    public function startRewatching(int $userId, string $itemId): void {
        $stmtType = $this->pdo->prepare("SELECT type FROM item WHERE id_item = :item_id");
        $stmtType->execute([':item_id' => $itemId]);
        $type = $stmtType->fetchColumn();

        if ($type === 'movie') {
            $stmt = $this->pdo->prepare("
                UPDATE usuario_item 
                SET status = 'completed', 
                    rewatch_count = COALESCE(rewatch_count, 0) + 1,
                    ts_atualizacao = CURRENT_TIMESTAMP,
                    ts_cancelamento = NULL
                WHERE id_usuario = :user_id AND id_item = :item_id
            ");
            $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE usuario_item 
                SET status = 'watching', 
                    rewatch_count = COALESCE(rewatch_count, 0) + 1,
                    ts_atualizacao = CURRENT_TIMESTAMP,
                    ts_cancelamento = NULL
                WHERE id_usuario = :user_id AND id_item = :item_id
            ");
            $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
            
            $this->unwatchAllEpisodes($userId, $itemId);
        }
    }

    public function removeTrack(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("UPDATE usuario_item SET ts_cancelamento = CURRENT_TIMESTAMP WHERE id_usuario = :user_id AND id_item = :item_id AND ts_cancelamento IS NULL");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        
        $stmt = $this->pdo->prepare("
            UPDATE usuario_episodio 
            SET ts_cancelamento = CURRENT_TIMESTAMP 
            WHERE id_usuario = :user_id 
              AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id)
              AND ts_cancelamento IS NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
    }

    public function watchAllEpisodes(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio, ts_cancelamento)
            SELECT :user_id, id_episodio, NULL FROM episodio 
            WHERE id_item = :item_id AND (air_date IS NULL OR air_date <= :today)
            ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':today' => date('Y-m-d')]);

        $this->updateWatchlistStatus($userId, $itemId, 'completed');
    }

    public function unwatchAllEpisodes(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_episodio 
            SET ts_cancelamento = CURRENT_TIMESTAMP 
            WHERE id_usuario = :user_id 
              AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id)
              AND ts_cancelamento IS NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
    }

    public function watchSeasonEpisodes(int $userId, string $itemId, int $seasonNum): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio, ts_cancelamento)
            SELECT :user_id, id_episodio, NULL FROM episodio 
            WHERE id_item = :item_id AND season_number = :season AND (air_date IS NULL OR air_date <= :today)
            ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season' => $seasonNum, ':today' => date('Y-m-d')]);

        $this->syncItemStatusFromEpisodes($userId, $itemId);
    }

    public function unwatchSeasonEpisodes(int $userId, string $itemId, int $seasonNum): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_episodio 
            SET ts_cancelamento = CURRENT_TIMESTAMP 
            WHERE id_usuario = :user_id 
              AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id AND season_number = :season)
              AND ts_cancelamento IS NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season' => $seasonNum]);
    }

    public function watchPrecedingEpisodes(int $userId, string $itemId, string $episodeId): void {
        $stmt = $this->pdo->prepare("SELECT season_number, episode_number FROM episodio WHERE id_episodio = :ep_id LIMIT 1");
        $stmt->execute([':ep_id' => $episodeId]);
        $curr = $stmt->fetch();
        if ($curr) {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_episodio (id_usuario, id_episodio, ts_cancelamento)
                SELECT :user_id, id_episodio, NULL FROM episodio 
                WHERE id_item = :item_id 
                  AND (season_number < :season OR (season_number = :season AND episode_number <= :ep_num))
                  AND (air_date IS NULL OR air_date <= :today)
                ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':item_id' => $itemId,
                ':season' => $curr['season_number'],
                ':ep_num' => $curr['episode_number'],
                ':today' => date('Y-m-d')
            ]);

            $this->syncItemStatusFromEpisodes($userId, $itemId);
        }
    }

    public function watchSingleEpisode(int $userId, string $episodeId): void {
        // Check if episode is unreleased
        $stmt = $this->pdo->prepare("SELECT air_date FROM episodio WHERE id_episodio = :ep_id LIMIT 1");
        $stmt->execute([':ep_id' => $episodeId]);
        $ep = $stmt->fetch();
        if ($ep && !empty($ep['air_date'])) {
            $diff = strtotime($ep['air_date']) - strtotime(date('Y-m-d'));
            if ($diff > 0) {
                throw new \Exception('Este episódio ainda não foi lançado!');
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio, ts_cancelamento) 
            VALUES (:user_id, :ep_id, NULL) 
            ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':ep_id' => $episodeId]);

        $this->syncItemStatusFromEpisode($userId, $episodeId);
    }

    public function unwatchSingleEpisode(int $userId, string $episodeId): void {
        $stmt = $this->pdo->prepare("UPDATE usuario_episodio SET ts_cancelamento = CURRENT_TIMESTAMP WHERE id_usuario = :user_id AND id_episodio = :ep_id AND ts_cancelamento IS NULL");
        $stmt->execute([':user_id' => $userId, ':ep_id' => $episodeId]);
    }

    private function syncItemStatusFromEpisode(int $userId, string $episodeId): void {
        $stmt = $this->pdo->prepare("SELECT id_item FROM episodio WHERE id_episodio = :ep_id LIMIT 1");
        $stmt->execute([':ep_id' => $episodeId]);
        $itemId = (string)$stmt->fetchColumn();
        if ($itemId !== '') {
            $this->syncItemStatusFromEpisodes($userId, $itemId);
        }
    }

    private function syncItemStatusFromEpisodes(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM episodio
            WHERE id_item = :item_id
              AND (air_date IS NULL OR air_date <= CURRENT_DATE)
        ");
        $stmt->execute([':item_id' => $itemId]);
        $totalReleased = (int)$stmt->fetchColumn();

        if ($totalReleased <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM episodio e
            LEFT JOIN usuario_episodio ue ON ue.id_episodio = e.id_episodio AND ue.id_usuario = :user_id AND ue.ts_cancelamento IS NULL
            WHERE e.id_item = :item_id
              AND (e.air_date IS NULL OR e.air_date <= CURRENT_DATE)
              AND ue.id_usuario_episodio IS NOT NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $watchedReleased = (int)$stmt->fetchColumn();
        $futureEpisodes = $this->countFutureEpisodes($itemId);

        if ($watchedReleased >= $totalReleased && $futureEpisodes === 0) {
            $this->updateWatchlistStatus($userId, $itemId, 'completed');
        } elseif ($watchedReleased > 0) {
            $this->updateWatchlistStatus($userId, $itemId, 'watching');
        }
    }

    public function updateWatchlistStatus(int $userId, string $itemId, string $status): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_item (id_usuario, id_item, status, ts_cancelamento)
            VALUES (:user_id, :item_id, :status, NULL)
            ON CONFLICT (id_usuario, id_item) DO UPDATE SET
                status = EXCLUDED.status,
                ts_atualizacao = CURRENT_TIMESTAMP,
                ts_cancelamento = NULL
        ");
        $stmt->execute([':status' => $status, ':user_id' => $userId, ':item_id' => $itemId]);
    }

    public function isItemReleased(string $itemId): bool {
        $stmt = $this->pdo->prepare("SELECT type, status, release_date, release_year FROM item WHERE id_item = :item_id LIMIT 1");
        $stmt->execute([':item_id' => $itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            return false;
        }
        $today = strtotime(date('Y-m-d'));

        if (($item['type'] ?? '') !== 'movie') {
            $stmtReleasedEpisode = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM episodio
                WHERE id_item = :item_id
                  AND (air_date IS NULL OR air_date <= CURRENT_DATE)
            ");
            $stmtReleasedEpisode->execute([':item_id' => $itemId]);
            return (int)$stmtReleasedEpisode->fetchColumn() > 0;
        }

        if (($item['status'] ?? '') === 'Upcoming') {
            return false;
        }
        if (!empty($item['release_date'])) {
            return strtotime($item['release_date']) <= $today;
        }
        if (!empty($item['release_year'])) {
            return (int)$item['release_year'] < (int)date('Y');
        }
        return false;
    }

    public function isEpisodeReleased(string $episodeId): bool {
        $stmt = $this->pdo->prepare("SELECT air_date FROM episodio WHERE id_episodio = :ep_id LIMIT 1");
        $stmt->execute([':ep_id' => $episodeId]);
        $ep = $stmt->fetch();
        if (!$ep) {
            return false;
        }
        if (!empty($ep['air_date'])) {
            return strtotime($ep['air_date']) <= strtotime(date('Y-m-d'));
        }
        return true;
    }

    public function toggleFavorite(int $userId, int $itemId): bool {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item
            SET is_favorite = NOT COALESCE(is_favorite, FALSE),
                ts_atualizacao = CURRENT_TIMESTAMP,
                ts_cancelamento = NULL
            WHERE id_usuario = :user_id AND id_item = :item_id
            RETURNING is_favorite
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO usuario_item (id_usuario, id_item, status, is_favorite, ts_cancelamento)
                VALUES (:user_id, :item_id, 'watching', TRUE, NULL)
                ON CONFLICT (id_usuario, id_item) DO UPDATE SET is_favorite = TRUE, ts_cancelamento = NULL
                RETURNING is_favorite
            ");
            $stmtInsert->execute([':user_id' => $userId, ':item_id' => $itemId]);
            $value = $stmtInsert->fetchColumn();
        }
        return $this->dbBool($value);
    }

    public function setFavorite(int $userId, int $itemId, bool $isFavorite): bool {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item
            SET is_favorite = :is_favorite,
                ts_atualizacao = CURRENT_TIMESTAMP,
                ts_cancelamento = NULL
            WHERE id_usuario = :user_id AND id_item = :item_id
            RETURNING is_favorite
        ");
        $stmt->bindValue(':is_favorite', $isFavorite, \PDO::PARAM_BOOL);
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':item_id', $itemId, \PDO::PARAM_INT);
        $stmt->execute();
        $value = $stmt->fetchColumn();

        if ($value === false) {
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO usuario_item (id_usuario, id_item, status, is_favorite, ts_cancelamento)
                VALUES (:user_id, :item_id, 'watching', :is_favorite, NULL)
                ON CONFLICT (id_usuario, id_item) DO UPDATE
                SET is_favorite = EXCLUDED.is_favorite,
                    ts_cancelamento = NULL,
                    ts_atualizacao = CURRENT_TIMESTAMP
                RETURNING is_favorite
            ");
            $stmtInsert->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $stmtInsert->bindValue(':item_id', $itemId, \PDO::PARAM_INT);
            $stmtInsert->bindValue(':is_favorite', $isFavorite, \PDO::PARAM_BOOL);
            $stmtInsert->execute();
            $value = $stmtInsert->fetchColumn();
        }

        return $this->dbBool($value);
    }

    public function getFavorites(int $userId, int $limit = 10): array {
        $stmt = $this->pdo->prepare("
            SELECT i.*, ui.status as track_status, ui.is_favorite, ui.ts_atualizacao
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
              AND ui.ts_cancelamento IS NULL
              AND ui.is_favorite = TRUE
            ORDER BY ui.ts_atualizacao DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getStatsSummary(int $userId): array {
        $stats = [
            'totalEpisodes' => 0,
            'seriesCount' => 0,
            'animeCount' => 0,
            'moviesCount' => 0,
            'totalRewatched' => 0,
            'watchingCount' => 0,
            'upToDateCount' => 0,
            'completedCount' => 0,
            'pausedCount' => 0,
            'rewatchingCount' => 0,
            'totalMinutes' => 0
        ];

        // 1. Total episodes watched count
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM usuario_episodio WHERE id_usuario = :user_id AND ts_cancelamento IS NULL");
        $stmt->execute([':user_id' => $userId]);
        $stats['totalEpisodes'] = intval($stmt->fetchColumn());

        // 2. Counts by type
        $stmt = $this->pdo->prepare("
            SELECT i.type, COUNT(i.id_item) as count 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
            GROUP BY i.type
        ");
        $stmt->execute([':user_id' => $userId]);
        $counts = $stmt->fetchAll();
        foreach ($counts as $c) {
            if ($c['type'] === 'series') $stats['seriesCount'] = intval($c['count']);
            elseif ($c['type'] === 'anime') $stats['animeCount'] = intval($c['count']);
            elseif ($c['type'] === 'movie') $stats['moviesCount'] = intval($c['count']);
        }

        // 3. Total rewatched
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(rewatch_count), 0) FROM usuario_episodio WHERE id_usuario = :user_id AND ts_cancelamento IS NULL");
        $stmt->execute([':user_id' => $userId]);
        $stats['totalRewatched'] = intval($stmt->fetchColumn());

        // 4. Group status counts (watching, em dia, visto, etc.)
        $stmtItems = $this->pdo->prepare("
            SELECT ui.status as track_status, i.id_item, i.type, i.runtime_minutes 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
        ");
        $stmtItems->execute([':user_id' => $userId]);
        $userItems = $stmtItems->fetchAll();

        foreach ($userItems as $item) {
            if ($item['track_status'] === 'dropped') {
                $stats['pausedCount']++;
                continue;
            }
            if ($item['track_status'] === 'rewatching') {
                $stats['rewatchingCount']++;
            }

            if ($item['type'] !== 'movie') {
                // Count released unwatched episodes
                $stmtRem = $this->pdo->prepare("
                    SELECT COUNT(e.id_episodio) 
                    FROM episodio e
                    WHERE e.id_item = :item_id 
                      AND (e.air_date IS NULL OR e.air_date <= CURRENT_DATE)
                      AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id AND ts_cancelamento IS NULL)
                ");
                $stmtRem->execute([':item_id' => $item['id_item'], ':user_id' => $userId]);
                $remaining = intval($stmtRem->fetchColumn());

                if ($item['track_status'] === 'completed') {
                    $stats['completedCount']++;
                } elseif ($remaining === 0) {
                    $stats['upToDateCount']++;
                } else {
                    $stats['watchingCount']++;
                }
            } else {
                if ($item['track_status'] === 'completed') {
                    $stats['completedCount']++;
                } else {
                    $stats['watchingCount']++;
                }
            }
        }

        // 5. Total watched time in minutes
        $stmtEpTime = $this->pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(e.runtime_minutes, 45)), 0) 
            FROM usuario_episodio ue 
            JOIN episodio e ON ue.id_episodio = e.id_episodio 
            WHERE ue.id_usuario = :user_id AND ue.ts_cancelamento IS NULL
        ");
        $stmtEpTime->execute([':user_id' => $userId]);
        $epMinutes = intval($stmtEpTime->fetchColumn());

        $stmtMovieTime = $this->pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(i.runtime_minutes, 120)), 0) 
            FROM usuario_item ui 
            JOIN item i ON ui.id_item = i.id_item 
            WHERE ui.id_usuario = :user_id AND i.type = 'movie' AND ui.status = 'completed' AND ui.ts_cancelamento IS NULL
        ");
        $stmtMovieTime->execute([':user_id' => $userId]);
        $movieMinutes = intval($stmtMovieTime->fetchColumn());

        $stats['totalMinutes'] = $epMinutes + $movieMinutes;

        return $stats;
    }

    public function getStatsTimeline(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(i.release_year, 2026) as year, COUNT(ui.id_item) as count 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
            GROUP BY COALESCE(i.release_year, 2026)
            ORDER BY year ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getStatsGenres(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT ui.status, COUNT(ui.id_item) as count 
            FROM usuario_item ui
            WHERE ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
            GROUP BY ui.status
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getActivityHistory(int $userId): array {
        $stmt = $this->pdo->prepare("
            (
                SELECT ue.ts_inclusao as watched_at, i.id_item, i.title as show_title, i.type, i.poster_url, 
                       'episode' as media_type, e.season_number, e.episode_number
                FROM usuario_episodio ue
                JOIN episodio e ON ue.id_episodio = e.id_episodio
                JOIN item i ON e.id_item = i.id_item
                WHERE ue.id_usuario = :user_id AND ue.ts_cancelamento IS NULL
            )
            UNION ALL
            (
                SELECT ui.ts_atualizacao as watched_at, i.id_item, i.title as show_title, i.type, i.poster_url,
                       'movie' as media_type, NULL as season_number, NULL as episode_number
                FROM usuario_item ui
                JOIN item i ON ui.id_item = i.id_item
                WHERE ui.id_usuario = :user_id AND i.type = 'movie' AND ui.status = 'completed' AND ui.ts_cancelamento IS NULL
            )
            ORDER BY watched_at DESC
            LIMIT 1000
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getUserReviews(int $userId, int $limit = 10): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ui.id_item,
                i.title,
                i.type,
                i.poster_url,
                i.release_year,
                ui.rating,
                ui.comment,
                ui.ts_atualizacao as reviewed_at
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
              AND ui.ts_cancelamento IS NULL
              AND ui.rating IS NOT NULL
              AND ui.comment IS NOT NULL
              AND TRIM(ui.comment) <> ''
            ORDER BY ui.ts_atualizacao DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function rewatchEpisode(int $userId, int $episodeId): int {
        $stmt = $this->pdo->prepare("SELECT id_usuario_episodio, rewatch_count FROM usuario_episodio WHERE id_usuario = :uid AND id_episodio = :eid LIMIT 1");
        $stmt->execute([':uid' => $userId, ':eid' => $episodeId]);
        $row = $stmt->fetch();
        
        if ($row) {
            $newCount = (int)$row['rewatch_count'] + 1;
            $stmtUpdate = $this->pdo->prepare("UPDATE usuario_episodio SET rewatch_count = :count, ts_cancelamento = NULL WHERE id_usuario_episodio = :id");
            $stmtUpdate->execute([':count' => $newCount, ':id' => $row['id_usuario_episodio']]);
            return $newCount;
        } else {
            $stmtInsert = $this->pdo->prepare("INSERT INTO usuario_episodio (id_usuario, id_episodio, rewatch_count, ts_cancelamento) VALUES (:uid, :eid, 1, NULL)");
            $stmtInsert->execute([':uid' => $userId, ':eid' => $episodeId]);
            return 1;
        }
    }

    public function getContinueWatching(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT 
                ui.ts_atualizacao,
                i.*,
                next_ep.id_episodio AS next_id_episodio,
                next_ep.season_number AS next_season_number,
                next_ep.episode_number AS next_episode_number,
                next_ep.title AS next_title,
                next_ep.air_date AS next_air_date,
                next_ep.runtime_minutes AS next_runtime_minutes
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            LEFT JOIN LATERAL (
                SELECT e.id_episodio, e.season_number, e.episode_number, e.title, e.air_date, e.runtime_minutes
                FROM episodio e
                WHERE e.id_item = i.id_item
                  AND e.id_episodio NOT IN (
                      SELECT ue.id_episodio
                      FROM usuario_episodio ue
                      WHERE ue.id_usuario = :user_id
                        AND ue.ts_cancelamento IS NULL
                  )
                ORDER BY e.season_number ASC, e.episode_number ASC
                LIMIT 1
            ) next_ep ON TRUE
            WHERE ui.id_usuario = :user_id AND ui.status = 'watching' AND ui.ts_cancelamento IS NULL AND i.type != 'movie'
            ORDER BY ui.ts_atualizacao DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $shows = $stmt->fetchAll();

        $results = [];
        foreach ($shows as $show) {
            if (!empty($show['next_id_episodio'])) {
                $results[] = [
                    'item' => $show,
                    'next_episode' => [
                        'id_episodio' => $show['next_id_episodio'],
                        'season_number' => $show['next_season_number'],
                        'episode_number' => $show['next_episode_number'],
                        'title' => $show['next_title'],
                        'air_date' => $show['next_air_date'],
                        'runtime_minutes' => $show['next_runtime_minutes'],
                    ]
                ];
            }
        }
        return $results;
    }

    public function getPlanToWatch(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT ui.status as track_status, ui.ts_atualizacao, i.* 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id AND ui.status = 'plan_to_watch' AND ui.ts_cancelamento IS NULL
            ORDER BY ui.ts_atualizacao DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getUserLists(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM usuario_lista 
            WHERE id_usuario = :user_id 
            ORDER BY ts_inclusao DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $lists = $stmt->fetchAll();

        foreach ($lists as &$list) {
            $stmtItems = $this->pdo->prepare("
                SELECT i.* 
                FROM usuario_lista_item uli
                JOIN item i ON uli.id_item = i.id_item
                WHERE uli.id_lista = :id_lista
                ORDER BY uli.ts_inclusao DESC
            ");
            $stmtItems->execute([':id_lista' => $list['id_lista']]);
            $list['items'] = $stmtItems->fetchAll();
        }
        return $lists;
    }

    public function getUserListsSummary(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ul.id_lista,
                ul.nome,
                ul.ts_inclusao,
                COUNT(uli.id_item) AS item_count,
                (
                    SELECT i.poster_url
                    FROM usuario_lista_item uli2
                    JOIN item i ON i.id_item = uli2.id_item
                    WHERE uli2.id_lista = ul.id_lista
                    ORDER BY uli2.ts_inclusao DESC
                    LIMIT 1
                ) AS cover_poster_url
            FROM usuario_lista ul
            LEFT JOIN usuario_lista_item uli ON uli.id_lista = ul.id_lista
            WHERE ul.id_usuario = :user_id
            GROUP BY ul.id_lista, ul.nome, ul.ts_inclusao
            ORDER BY ul.ts_inclusao DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getListItems(int $userId, int $listId): array {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM usuario_lista
            WHERE id_usuario = :user_id AND id_lista = :list_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':list_id' => $listId]);
        if (!$stmt->fetchColumn()) {
            return [];
        }

        $stmtItems = $this->pdo->prepare("
            SELECT i.id_item, i.title, i.poster_url, i.type, i.release_year
            FROM usuario_lista_item uli
            JOIN item i ON i.id_item = uli.id_item
            WHERE uli.id_lista = :list_id
            ORDER BY uli.ts_inclusao DESC
        ");
        $stmtItems->execute([':list_id' => $listId]);
        return $stmtItems->fetchAll();
    }

    public function createList(int $userId, string $name): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_lista (id_usuario, nome)
            VALUES (:user_id, :nome)
            RETURNING id_lista
        ");
        $stmt->execute([':user_id' => $userId, ':nome' => $name]);
        return (int)$stmt->fetchColumn();
    }

    public function deleteList(int $userId, int $listId): void {
        $stmt = $this->pdo->prepare("
            DELETE FROM usuario_lista 
            WHERE id_usuario = :user_id AND id_lista = :list_id
        ");
        $stmt->execute([':user_id' => $userId, ':list_id' => $listId]);
    }

    public function renameList(int $userId, int $listId, string $name): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_lista
            SET nome = :nome
            WHERE id_usuario = :user_id AND id_lista = :list_id
        ");
        $stmt->execute([':nome' => $name, ':user_id' => $userId, ':list_id' => $listId]);
    }

    public function addToList(int $listId, int $itemId): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_lista_item (id_lista, id_item)
            VALUES (:list_id, :item_id)
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([':list_id' => $listId, ':item_id' => $itemId]);
    }

    public function removeFromList(int $listId, int $itemId): void {
        $stmt = $this->pdo->prepare("
            DELETE FROM usuario_lista_item 
            WHERE id_lista = :list_id AND id_item = :item_id
        ");
        $stmt->execute([':list_id' => $listId, ':item_id' => $itemId]);
    }

    public function getItemLists(int $userId, int $itemId): array {
        $stmt = $this->pdo->prepare("
            SELECT ul.id_lista, ul.nome, 
                   (uli.id_lista_item IS NOT NULL) as has_item
            FROM usuario_lista ul
            LEFT JOIN usuario_lista_item uli ON ul.id_lista = uli.id_lista AND uli.id_item = :item_id
            WHERE ul.id_usuario = :user_id
            ORDER BY ul.ts_inclusao DESC
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        return $stmt->fetchAll();
    }
}
