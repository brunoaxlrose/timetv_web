<?php

namespace Application\Model;

use PDO;

class TrackingModel {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getUserCollection(int $userId, array $types, string $sortBy): array {
        $query = "
            SELECT ui.status as track_status, ui.ts_atualizacao, i.* 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
        ";

        if (!empty($types)) {
            $safeTypes = array_map(function($t) { return $this->pdo->quote($t); }, $types);
            $query .= " AND i.type IN (" . implode(",", $safeTypes) . ")";
        } else {
            $query .= " AND 1=0";
        }

        $query .= " AND i.description IS NOT NULL AND i.description != 'Nenhuma sinopse disponível.' AND i.description != ''";

        if ($sortBy === 'last_added') {
            $query .= " ORDER BY ui.ts_inclusao DESC";
        } elseif ($sortBy === 'last_premiered') {
            $query .= " ORDER BY i.release_year DESC";
        } else {
            $query .= " ORDER BY ui.ts_atualizacao DESC";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function countReleasedUnwatchedEpisodes(int $userId, string $itemId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(e.id_episodio) 
            FROM episodio e
            WHERE e.id_item = :item_id 
              AND (e.air_date IS NULL OR e.air_date = '' OR CAST(e.air_date AS DATE) <= CURRENT_DATE)
              AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id)
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return intval($stmt->fetchColumn());
    }

    public function getNextUnwatchedEpisode(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT e.* 
            FROM episodio e
            WHERE e.id_item = :item_id 
              AND e.id_episodio NOT IN (SELECT id_episodio FROM usuario_episodio WHERE id_usuario = :user_id)
            ORDER BY e.season_number ASC, e.episode_number ASC
            LIMIT 1
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function addTrack(int $userId, string $itemId, string $status): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_item (id_usuario, id_item, status)
            VALUES (:user_id, :item_id, :status)
            ON CONFLICT (id_usuario, id_item) DO UPDATE 
            SET status = EXCLUDED.status, ts_atualizacao = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':item_id' => $itemId,
            ':status' => $status
        ]);
    }

    public function startRewatching(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item 
            SET status = 'watching', 
                rewatch_count = COALESCE(rewatch_count, 0) + 1,
                ts_atualizacao = CURRENT_TIMESTAMP
            WHERE id_usuario = :user_id AND id_item = :item_id
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        
        $this->unwatchAllEpisodes($userId, $itemId);
    }

    public function removeTrack(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_item WHERE id_usuario = :user_id AND id_item = :item_id");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        
        // Also clear episodes progress when item is removed
        $stmt = $this->pdo->prepare("
            DELETE FROM usuario_episodio 
            WHERE id_usuario = :user_id 
              AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id)
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
    }

    public function watchAllEpisodes(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio)
            SELECT :user_id, id_episodio FROM episodio WHERE id_item = :item_id
            ON CONFLICT(id_usuario, id_episodio) DO NOTHING
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
    }

    public function unwatchAllEpisodes(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("
            DELETE FROM usuario_episodio 
            WHERE id_usuario = :user_id 
              AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id)
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
    }

    public function watchSeasonEpisodes(int $userId, string $itemId, int $seasonNum): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio)
            SELECT :user_id, id_episodio FROM episodio 
            WHERE id_item = :item_id AND season_number = :season
            ON CONFLICT(id_usuario, id_episodio) DO NOTHING
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season' => $seasonNum]);
    }

    public function unwatchSeasonEpisodes(int $userId, string $itemId, int $seasonNum): void {
        $stmt = $this->pdo->prepare("
            DELETE FROM usuario_episodio 
            WHERE id_usuario = :user_id 
              AND id_episodio IN (SELECT id_episodio FROM episodio WHERE id_item = :item_id AND season_number = :season)
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season' => $seasonNum]);
    }

    public function watchPrecedingEpisodes(int $userId, string $itemId, string $episodeId): void {
        $stmt = $this->pdo->prepare("SELECT season_number, episode_number FROM episodio WHERE id_episodio = :ep_id LIMIT 1");
        $stmt->execute([':ep_id' => $episodeId]);
        $curr = $stmt->fetch();
        if ($curr) {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_episodio (id_usuario, id_episodio)
                SELECT :user_id, id_episodio FROM episodio 
                WHERE id_item = :item_id 
                  AND (season_number < :season OR (season_number = :season AND episode_number <= :ep_num))
                ON CONFLICT(id_usuario, id_episodio) DO NOTHING
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
            INSERT INTO usuario_episodio (id_usuario, id_episodio) 
            VALUES (:user_id, :ep_id) 
            ON CONFLICT(id_usuario, id_episodio) DO NOTHING
        ");
        $stmt->execute([':user_id' => $userId, ':ep_id' => $episodeId]);
    }

    public function unwatchSingleEpisode(int $userId, string $episodeId): void {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_episodio WHERE id_usuario = :user_id AND id_episodio = :ep_id");
        $stmt->execute([':user_id' => $userId, ':ep_id' => $episodeId]);
    }

    public function updateWatchlistStatus(int $userId, string $itemId, string $status): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item 
            SET status = :status, ts_atualizacao = CURRENT_TIMESTAMP 
            WHERE id_usuario = :user_id AND id_item = :item_id
        ");
        $stmt->execute([':status' => $status, ':user_id' => $userId, ':item_id' => $itemId]);
    }

    public function getStatsSummary(int $userId): array {
        $stats = [];
        
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM usuario_episodio WHERE id_usuario = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $stats['totalEpisodes'] = intval($stmt->fetchColumn());

        $stmt = $this->pdo->prepare("
            SELECT i.type, COUNT(i.id_item) as count 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
            GROUP BY i.type
        ");
        $stmt->execute([':user_id' => $userId]);
        $counts = $stmt->fetchAll();
        
        $stats['seriesCount'] = 0;
        $stats['animeCount'] = 0;
        $stats['moviesCount'] = 0;
        foreach ($counts as $c) {
            if ($c['type'] === 'series') $stats['seriesCount'] = intval($c['count']);
            elseif ($c['type'] === 'anime') $stats['animeCount'] = intval($c['count']);
            elseif ($c['type'] === 'movie') $stats['moviesCount'] = intval($c['count']);
        }

        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(rewatch_count), 0) FROM usuario_item WHERE id_usuario = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $stats['totalRewatched'] = intval($stmt->fetchColumn());

        return $stats;
    }

    public function getStatsTimeline(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(i.release_year, 2026) as year, COUNT(ui.id_item) as count 
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
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
            WHERE ui.id_usuario = :user_id
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
                WHERE ue.id_usuario = :user_id
            )
            UNION ALL
            (
                SELECT ui.ts_atualizacao as watched_at, i.title as show_title, i.type, i.poster_url,
                       'movie' as media_type, NULL as season_number, NULL as episode_number
                FROM usuario_item ui
                JOIN item i ON ui.id_item = i.id_item
                WHERE ui.id_usuario = :user_id AND i.type = 'movie' AND ui.status = 'completed'
            )
            ORDER BY watched_at DESC
            LIMIT 50
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }
}
