<?php

namespace Application\Model;

use PDO;

class TrackingModel {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getUserCollection(int $userId, array $types, string $sortBy, string $providerFilter = ''): array {
        $query = "
            SELECT ui.status as track_status, ui.ts_atualizacao, i.* 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL
        ";

        if (!empty($types)) {
            $safeTypes = array_map(function($t) { return $this->pdo->quote($t); }, $types);
            $query .= " AND i.type IN (" . implode(",", $safeTypes) . ")";
        } else {
            $query .= " AND 1=0";
        }

        $query .= " AND i.description IS NOT NULL AND i.description != 'Nenhuma sinopse disponível.' AND i.description != ''";

        if (!empty($providerFilter)) {
            $query .= " AND i.watch_providers LIKE :provider_pattern";
        }

        if ($sortBy === 'last_added') {
            $query .= " ORDER BY ui.ts_inclusao DESC";
        } elseif ($sortBy === 'last_premiered') {
            $query .= " ORDER BY i.release_year DESC";
        } else {
            $query .= " ORDER BY ui.ts_atualizacao DESC";
        }

        $stmt = $this->pdo->prepare($query);
        $params = [':user_id' => $userId];
        if (!empty($providerFilter)) {
            $params[':provider_pattern'] = '%"name":"' . $providerFilter . '"%';
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getProgress(int $userId, string $itemId): array {
        $stmtTotal = $this->pdo->prepare("
            SELECT COUNT(id_episodio) 
            FROM episodio 
            WHERE id_item = :item_id 
              AND (air_date IS NULL OR air_date = '' OR CAST(air_date AS DATE) <= CURRENT_DATE)
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
              AND (e.air_date IS NULL OR e.air_date = '' OR CAST(e.air_date AS DATE) <= CURRENT_DATE)
              AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id AND ts_cancelamento IS NULL)
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
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
            SELECT :user_id, id_episodio, NULL FROM episodio WHERE id_item = :item_id
            ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
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
            WHERE id_item = :item_id AND season_number = :season
            ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season' => $seasonNum]);
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
                ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':item_id' => $itemId,
                ':season' => $curr['season_number'],
                ':ep_num' => $curr['episode_number']
            ]);
        }
    }

    public function watchSingleEpisode(int $userId, string $episodeId): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio, ts_cancelamento) 
            VALUES (:user_id, :ep_id, NULL) 
            ON CONFLICT(id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':ep_id' => $episodeId]);
    }

    public function unwatchSingleEpisode(int $userId, string $episodeId): void {
        $stmt = $this->pdo->prepare("UPDATE usuario_episodio SET ts_cancelamento = CURRENT_TIMESTAMP WHERE id_usuario = :user_id AND id_episodio = :ep_id AND ts_cancelamento IS NULL");
        $stmt->execute([':user_id' => $userId, ':ep_id' => $episodeId]);
    }

    public function updateWatchlistStatus(int $userId, string $itemId, string $status): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item 
            SET status = :status, ts_atualizacao = CURRENT_TIMESTAMP, ts_cancelamento = NULL 
            WHERE id_usuario = :user_id AND id_item = :item_id
        ");
        $stmt->execute([':status' => $status, ':user_id' => $userId, ':item_id' => $itemId]);
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
                      AND (e.air_date IS NULL OR e.air_date = '' OR CAST(e.air_date AS DATE) <= CURRENT_DATE)
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
                SELECT ue.ts_inclusao as watched_at, i.title as show_title, i.type, i.poster_url, 
                       'episode' as media_type, e.season_number, e.episode_number
                FROM usuario_episodio ue
                JOIN episodio e ON ue.id_episodio = e.id_episodio
                JOIN item i ON e.id_item = i.id_item
                WHERE ue.id_usuario = :user_id AND ue.ts_cancelamento IS NULL
            )
            UNION ALL
            (
                SELECT ui.ts_atualizacao as watched_at, i.title as show_title, i.type, i.poster_url,
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
}
