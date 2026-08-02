<?php

namespace Application\Model;

use PDO;

class TrackingModel {
    private PDO $pdo;

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
                  AND column_name = 'eh_favorito'
                LIMIT 1
            ");
            if (!$stmt->fetchColumn()) {
                $this->pdo->exec("ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS eh_favorito BOOLEAN NOT NULL DEFAULT FALSE");
            }
        } catch (\Throwable $e) {
            // Ignore bootstrap failures.
        }
    }

    private function dbBool($value): bool {
        return in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);
    }

    private function ensureCollectionRowsFromWatchedEpisodes(int $userId): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_item (id_usuario, id_item, status, ts_cancelamento)
                SELECT DISTINCT :insert_user_id, e.id_item, 'assistindo', NULL
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.id_usuario = :filter_user_id
                  AND ue.ts_cancelamento IS NULL
                ON CONFLICT (id_usuario, id_item) DO UPDATE SET
                    ts_cancelamento = NULL,
                    ts_atualizacao = CURRENT_TIMESTAMP,
                    status = CASE
                        WHEN usuario_item.ts_cancelamento IS NOT NULL THEN 'assistindo'
                        ELSE usuario_item.status
                    END
            ");
            $stmt->execute([':insert_user_id' => $userId, ':filter_user_id' => $userId]);
        } catch (\Throwable $e) {
            // Fallback query below can still assemble the collection.
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
                COALESCE(ui.status, 'assistindo') AS status_acompanhamento,
                COALESCE(ui.ts_atualizacao, assistidos.ts_ultimo_consumo, i.ts_inclusao) AS ts_atualizacao,
                COALESCE(ui.ts_inclusao, assistidos.ts_primeiro_consumo, i.ts_inclusao) AS collection_created_at,
                COALESCE(ui.nota, 0) AS nota,
                COALESCE(ui.eh_favorito, FALSE) AS eh_favorito,
                COALESCE(episode_stats.total_count, 0) AS total_count,
                COALESCE(episode_stats.watched_count, 0) AS watched_count,
                COALESCE(episode_stats.remaining_count, 0) AS remaining_count,
                COALESCE(episode_stats.future_count, 0) AS future_count,
                next_episode.id_episodio AS next_episode_id,
                next_episode.numero_temporada AS next_season_number,
                next_episode.numero_episodio AS next_episode_number,
                next_episode.titulo AS next_episode_title,
                next_episode.data_exibicao AS next_air_date,
                next_episode.duracao_minutos AS next_runtime_minutes,
                i.id_item,
                i.tvmaze_id,
                i.tmdb_id,
                i.mal_id,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.url_banner,
                i.ano_lancamento,
                i.data_lancamento,
                i.total_episodios,
                i.duracao_minutos,
                i.ts_inclusao,
                0::numeric AS avaliacao_media
            FROM (
                SELECT id_item
                FROM usuario_item
                WHERE id_usuario = :tracked_user_id AND ts_cancelamento IS NULL AND status <> 'avaliado'
                UNION
                SELECT DISTINCT e.id_item
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.id_usuario = :episode_user_id AND ue.ts_cancelamento IS NULL
            ) colecao
            JOIN item i ON i.id_item = colecao.id_item
            LEFT JOIN usuario_item ui
                ON ui.id_item = i.id_item
               AND ui.id_usuario = :joined_user_id
               AND ui.ts_cancelamento IS NULL
            LEFT JOIN (
                SELECT
                    e.id_item,
                    MIN(ue.ts_inclusao) AS ts_primeiro_consumo,
                    MAX(ue.ts_inclusao) AS ts_ultimo_consumo
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.id_usuario = :watched_user_id
                  AND ue.ts_cancelamento IS NULL
                GROUP BY e.id_item
            ) assistidos ON assistidos.id_item = i.id_item
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(e.id_episodio) FILTER (
                        WHERE e.data_exibicao IS NULL OR e.data_exibicao <= CURRENT_DATE
                    ) AS total_count,
                    COUNT(ue.id_usuario_episodio) FILTER (
                        WHERE e.data_exibicao IS NULL OR e.data_exibicao <= CURRENT_DATE
                    ) AS watched_count,
                    COUNT(e.id_episodio) FILTER (
                        WHERE (e.data_exibicao IS NULL OR e.data_exibicao <= CURRENT_DATE)
                          AND ue.id_usuario_episodio IS NULL
                    ) AS remaining_count,
                    COUNT(e.id_episodio) FILTER (
                        WHERE e.data_exibicao > CURRENT_DATE
                    ) AS future_count
                FROM episodio e
                LEFT JOIN usuario_episodio ue
                    ON ue.id_episodio = e.id_episodio
                   AND ue.id_usuario = :stats_user_id
                   AND ue.ts_cancelamento IS NULL
                WHERE e.id_item = i.id_item
            ) episode_stats ON TRUE
            LEFT JOIN LATERAL (
                SELECT e.id_episodio, e.numero_temporada, e.numero_episodio, e.titulo, e.data_exibicao, e.duracao_minutos
                FROM episodio e
                WHERE e.id_item = i.id_item
                  AND NOT EXISTS (
                      SELECT 1
                      FROM usuario_episodio ue
                      WHERE ue.id_episodio = e.id_episodio
                        AND ue.id_usuario = :next_user_id
                        AND ue.ts_cancelamento IS NULL
                  )
                ORDER BY e.numero_temporada ASC, e.numero_episodio ASC
                LIMIT 1
            ) next_episode ON TRUE
            WHERE 1=1
        ";

        if (!empty($types)) {
            $safeTypes = array_map(fn($type) => $this->pdo->quote($type), $types);
            $query .= " AND i.tipo IN (" . implode(',', $safeTypes) . ")";
        } else {
            $query .= " AND 1=0";
        }

        if ($providerFilter !== '') {
            $query .= " AND i.provedores_streaming LIKE :provider_pattern";
        }

        if ($sortBy === 'last_added') {
            $query .= " ORDER BY collection_created_at DESC";
        } elseif ($sortBy === 'last_premiered') {
            $query .= " ORDER BY i.ano_lancamento DESC";
        } else {
            $query .= " ORDER BY ts_atualizacao DESC";
        }

        $stmt = $this->pdo->prepare($query);
        $params = [
            ':tracked_user_id' => $userId,
            ':episode_user_id' => $userId,
            ':joined_user_id' => $userId,
            ':watched_user_id' => $userId,
            ':stats_user_id' => $userId,
            ':next_user_id' => $userId,
        ];
        if ($providerFilter !== '') {
            $params[':provider_pattern'] = '%"name":"' . $providerFilter . '"%';
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function getUserCollectionFallback(int $userId, array $types): array {
        try {
            $query = "
                SELECT DISTINCT
                    'assistindo' AS status_acompanhamento,
                    MAX(ue.ts_inclusao) OVER (PARTITION BY i.id_item) AS ts_atualizacao,
                    i.*
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                JOIN item i ON i.id_item = e.id_item
                WHERE ue.id_usuario = :user_id
                  AND ue.ts_cancelamento IS NULL
            ";

            if (!empty($types)) {
                $safeTypes = array_map(fn($type) => $this->pdo->quote($type), $types);
                $query .= " AND i.tipo IN (" . implode(',', $safeTypes) . ")";
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
              AND (data_exibicao IS NULL OR data_exibicao <= CURRENT_DATE)
        ");
        $stmtTotal->execute([':item_id' => $itemId]);
        $total = (int)$stmtTotal->fetchColumn();

        $stmtWatched = $this->pdo->prepare("
            SELECT COUNT(ue.id_episodio)
            FROM usuario_episodio ue
            JOIN episodio e ON ue.id_episodio = e.id_episodio
            WHERE ue.id_usuario = :user_id
              AND e.id_item = :item_id
              AND ue.ts_cancelamento IS NULL
        ");
        $stmtWatched->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $watched = (int)$stmtWatched->fetchColumn();

        return [
            'total_count' => $total,
            'watched_count' => $watched,
        ];
    }

    public function countReleasedUnwatchedEpisodes(int $userId, string $itemId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(e.id_episodio)
            FROM episodio e
            WHERE e.id_item = :item_id
              AND (e.data_exibicao IS NULL OR e.data_exibicao <= CURRENT_DATE)
              AND e.id_episodio NOT IN (
                  SELECT id_episodio
                  FROM usuario_episodio
                  WHERE id_usuario = :user_id
                    AND ts_cancelamento IS NULL
              )
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function countFutureEpisodes(string $itemId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(id_episodio)
            FROM episodio
            WHERE id_item = :item_id
              AND data_exibicao IS NOT NULL
              AND data_exibicao > CURRENT_DATE
        ");
        $stmt->execute([':item_id' => $itemId]);
        return (int)$stmt->fetchColumn();
    }

    public function getNextUnwatchedEpisode(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT e.*
            FROM episodio e
            WHERE e.id_item = :item_id
              AND e.id_episodio NOT IN (
                  SELECT id_episodio
                  FROM usuario_episodio
                  WHERE id_usuario = :user_id
                    AND ts_cancelamento IS NULL
              )
            ORDER BY e.numero_temporada ASC, e.numero_episodio ASC
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
            SET status = EXCLUDED.status,
                ts_atualizacao = CURRENT_TIMESTAMP,
                ts_cancelamento = NULL
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':item_id' => $itemId,
            ':status' => $status,
        ]);
    }

    public function startRewatching(int $userId, string $itemId): void {
        $stmtType = $this->pdo->prepare("SELECT tipo FROM item WHERE id_item = :item_id");
        $stmtType->execute([':item_id' => $itemId]);
        $tipo = $stmtType->fetchColumn();

        if ($tipo === 'movie') {
            $stmt = $this->pdo->prepare("
                UPDATE usuario_item
                SET status = 'concluido',
                    quantidade_reassistida = COALESCE(quantidade_reassistida, 0) + 1,
                    ts_atualizacao = CURRENT_TIMESTAMP,
                    ts_cancelamento = NULL
                WHERE id_usuario = :user_id AND id_item = :item_id
            ");
            $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE usuario_item
            SET status = 'reassistindo',
                quantidade_reassistida = COALESCE(quantidade_reassistida, 0) + 1,
                ts_atualizacao = CURRENT_TIMESTAMP,
                ts_cancelamento = NULL
            WHERE id_usuario = :user_id AND id_item = :item_id
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $this->unwatchAllEpisodes($userId, $itemId);
    }

    public function removeTrack(int $userId, string $itemId): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item
            SET ts_cancelamento = CURRENT_TIMESTAMP
            WHERE id_usuario = :user_id
              AND id_item = :item_id
              AND ts_cancelamento IS NULL
        ");
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
            SELECT :user_id, id_episodio, NULL
            FROM episodio
            WHERE id_item = :item_id
              AND (data_exibicao IS NULL OR data_exibicao <= :today)
            ON CONFLICT (id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':today' => date('Y-m-d')]);
        $this->updateWatchlistStatus($userId, $itemId, 'concluido');
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
            SELECT :user_id, id_episodio, NULL
            FROM episodio
            WHERE id_item = :item_id
              AND numero_temporada = :season
              AND (data_exibicao IS NULL OR data_exibicao <= :today)
            ON CONFLICT (id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season' => $seasonNum, ':today' => date('Y-m-d')]);
        $this->syncItemStatusFromEpisodes($userId, $itemId);
    }

    public function unwatchSeasonEpisodes(int $userId, string $itemId, int $seasonNum): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_episodio
            SET ts_cancelamento = CURRENT_TIMESTAMP
            WHERE id_usuario = :user_id
              AND id_episodio IN (
                  SELECT id_episodio
                  FROM episodio
                  WHERE id_item = :item_id
                    AND numero_temporada = :season
              )
              AND ts_cancelamento IS NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId, ':season' => $seasonNum]);
    }

    public function watchPrecedingEpisodes(int $userId, string $itemId, string $episodeId): void {
        $stmt = $this->pdo->prepare("
            SELECT numero_temporada, numero_episodio
            FROM episodio
            WHERE id_episodio = :ep_id
            LIMIT 1
        ");
        $stmt->execute([':ep_id' => $episodeId]);
        $curr = $stmt->fetch();

        if (!$curr) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio, ts_cancelamento)
            SELECT :user_id, id_episodio, NULL
            FROM episodio
            WHERE id_item = :item_id
              AND (
                    numero_temporada < :season
                 OR (numero_temporada = :season AND numero_episodio <= :ep_num)
              )
              AND (data_exibicao IS NULL OR data_exibicao <= :today)
            ON CONFLICT (id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':item_id' => $itemId,
            ':season' => $curr['numero_temporada'],
            ':ep_num' => $curr['numero_episodio'],
            ':today' => date('Y-m-d'),
        ]);

        $this->syncItemStatusFromEpisodes($userId, $itemId);
    }

    public function watchSingleEpisode(int $userId, string $episodeId): void {
        $stmt = $this->pdo->prepare("
            SELECT data_exibicao
            FROM episodio
            WHERE id_episodio = :ep_id
            LIMIT 1
        ");
        $stmt->execute([':ep_id' => $episodeId]);
        $episode = $stmt->fetch();

        if ($episode && !empty($episode['data_exibicao'])) {
            $diff = strtotime($episode['data_exibicao']) - strtotime(date('Y-m-d'));
            if ($diff > 0) {
                throw new \Exception('Este episodio ainda nao foi lancado!');
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio, ts_cancelamento)
            VALUES (:user_id, :ep_id, NULL)
            ON CONFLICT (id_usuario, id_episodio) DO UPDATE SET ts_cancelamento = NULL
        ");
        $stmt->execute([':user_id' => $userId, ':ep_id' => $episodeId]);
        $this->syncItemStatusFromEpisode($userId, $episodeId);
    }

    public function unwatchSingleEpisode(int $userId, string $episodeId): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_episodio
            SET ts_cancelamento = CURRENT_TIMESTAMP
            WHERE id_usuario = :user_id
              AND id_episodio = :ep_id
              AND ts_cancelamento IS NULL
        ");
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
              AND (data_exibicao IS NULL OR data_exibicao <= CURRENT_DATE)
        ");
        $stmt->execute([':item_id' => $itemId]);
        $totalReleased = (int)$stmt->fetchColumn();

        if ($totalReleased <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM episodio e
            LEFT JOIN usuario_episodio ue
                ON ue.id_episodio = e.id_episodio
               AND ue.id_usuario = :user_id
               AND ue.ts_cancelamento IS NULL
            WHERE e.id_item = :item_id
              AND (e.data_exibicao IS NULL OR e.data_exibicao <= CURRENT_DATE)
              AND ue.id_usuario_episodio IS NOT NULL
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $watchedReleased = (int)$stmt->fetchColumn();
        $futureEpisodes = $this->countFutureEpisodes($itemId);

        if ($watchedReleased >= $totalReleased && $futureEpisodes === 0) {
            $this->updateWatchlistStatus($userId, $itemId, 'concluido');
        } elseif ($watchedReleased > 0) {
            $this->updateWatchlistStatus($userId, $itemId, 'assistindo');
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
        $stmt = $this->pdo->prepare("
            SELECT tipo, status, data_lancamento, ano_lancamento
            FROM item
            WHERE id_item = :item_id
            LIMIT 1
        ");
        $stmt->execute([':item_id' => $itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            return false;
        }

        $today = strtotime(date('Y-m-d'));

        if (($item['tipo'] ?? '') !== 'movie') {
            $stmtReleasedEpisode = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM episodio
                WHERE id_item = :item_id
                  AND (data_exibicao IS NULL OR data_exibicao <= CURRENT_DATE)
            ");
            $stmtReleasedEpisode->execute([':item_id' => $itemId]);
            return (int)$stmtReleasedEpisode->fetchColumn() > 0;
        }

        if (($item['status'] ?? '') === 'Upcoming') {
            return false;
        }
        if (!empty($item['data_lancamento'])) {
            return strtotime($item['data_lancamento']) <= $today;
        }
        if (!empty($item['ano_lancamento'])) {
            return (int)$item['ano_lancamento'] < (int)date('Y');
        }
        return false;
    }

    public function isEpisodeReleased(string $episodeId): bool {
        $stmt = $this->pdo->prepare("
            SELECT data_exibicao
            FROM episodio
            WHERE id_episodio = :ep_id
            LIMIT 1
        ");
        $stmt->execute([':ep_id' => $episodeId]);
        $ep = $stmt->fetch();
        if (!$ep) {
            return false;
        }
        if (!empty($ep['data_exibicao'])) {
            return strtotime($ep['data_exibicao']) <= strtotime(date('Y-m-d'));
        }
        return true;
    }

    public function toggleFavorite(int $userId, int $itemId): bool {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item
            SET eh_favorito = NOT COALESCE(eh_favorito, FALSE),
                ts_atualizacao = CURRENT_TIMESTAMP,
                ts_cancelamento = NULL
            WHERE id_usuario = :user_id AND id_item = :item_id
            RETURNING eh_favorito
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO usuario_item (id_usuario, id_item, status, eh_favorito, ts_cancelamento)
                VALUES (:user_id, :item_id, 'assistindo', TRUE, NULL)
                ON CONFLICT (id_usuario, id_item) DO UPDATE SET
                    eh_favorito = TRUE,
                    ts_cancelamento = NULL
                RETURNING eh_favorito
            ");
            $stmtInsert->execute([':user_id' => $userId, ':item_id' => $itemId]);
            $value = $stmtInsert->fetchColumn();
        }

        return $this->dbBool($value);
    }

    public function setFavorite(int $userId, int $itemId, bool $isFavorite): bool {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_item
            SET eh_favorito = :eh_favorito,
                ts_atualizacao = CURRENT_TIMESTAMP,
                ts_cancelamento = NULL
            WHERE id_usuario = :user_id AND id_item = :item_id
            RETURNING eh_favorito
        ");
        $stmt->bindValue(':eh_favorito', $isFavorite, PDO::PARAM_BOOL);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $value = $stmt->fetchColumn();

        if ($value === false) {
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO usuario_item (id_usuario, id_item, status, eh_favorito, ts_cancelamento)
                VALUES (:user_id, :item_id, 'assistindo', :eh_favorito, NULL)
                ON CONFLICT (id_usuario, id_item) DO UPDATE
                SET eh_favorito = EXCLUDED.eh_favorito,
                    ts_cancelamento = NULL,
                    ts_atualizacao = CURRENT_TIMESTAMP
                RETURNING eh_favorito
            ");
            $stmtInsert->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmtInsert->bindValue(':item_id', $itemId, PDO::PARAM_INT);
            $stmtInsert->bindValue(':eh_favorito', $isFavorite, PDO::PARAM_BOOL);
            $stmtInsert->execute();
            $value = $stmtInsert->fetchColumn();
        }

        return $this->dbBool($value);
    }

    public function getFavorites(int $userId, int $limit = 10): array {
        $stmt = $this->pdo->prepare("
            SELECT
                i.id_item,
                i.tvmaze_id,
                i.tmdb_id,
                i.mal_id,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.url_banner,
                i.ano_lancamento,
                i.data_lancamento,
                i.total_episodios,
                i.duracao_minutos,
                ui.status AS status_acompanhamento,
                ui.eh_favorito,
                ui.ts_atualizacao
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
              AND ui.ts_cancelamento IS NULL
              AND ui.eh_favorito = TRUE
            ORDER BY ui.ts_atualizacao DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getTopTitlesByType(string $type, int $limit = 10): array {
        $sql = "
            WITH item_engagement AS (
                SELECT
                    ui.id_item,
                    COUNT(DISTINCT ui.id_usuario) AS tracked_users,
                    COUNT(*) AS tracked_events
                FROM usuario_item ui
                WHERE ui.ts_cancelamento IS NULL
                  AND ui.status IN ('assistindo', 'concluido', 'em_pausa', 'reassistindo')
                GROUP BY ui.id_item
            ),
            episode_engagement AS (
                SELECT
                    e.id_item,
                    COUNT(DISTINCT ue.id_usuario) AS watched_users,
                    COUNT(*) AS watched_events
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.ts_cancelamento IS NULL
                GROUP BY e.id_item
            )
            SELECT
                i.id_item,
                i.tvmaze_id,
                i.tmdb_id,
                i.mal_id,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.url_banner,
                i.ano_lancamento,
                i.data_lancamento,
                i.total_episodios,
                i.duracao_minutos,
                i.generos,
                i.provedores_streaming,
                COALESCE(ie.tracked_users, 0) AS total_usuarios_lista,
                COALESCE(ie.tracked_events, 0) AS total_interacoes_lista,
                COALESCE(ee.watched_users, 0) AS total_usuarios_assistindo,
                COALESCE(ee.watched_events, 0) AS total_eventos_assistidos
            FROM item i
            LEFT JOIN item_engagement ie ON ie.id_item = i.id_item
            LEFT JOIN episode_engagement ee ON ee.id_item = i.id_item
            WHERE i.tipo = :type
              AND i.ts_cancelamento IS NULL
              AND (
                    COALESCE(ie.tracked_users, 0) > 0
                 OR COALESCE(ee.watched_users, 0) > 0
              )
            ORDER BY
                CASE WHEN :type = 'movie' THEN COALESCE(ie.tracked_users, 0) ELSE COALESCE(ee.watched_users, COALESCE(ie.tracked_users, 0)) END DESC,
                CASE WHEN :type = 'movie' THEN COALESCE(ie.tracked_events, 0) ELSE COALESCE(ee.watched_events, COALESCE(ie.tracked_events, 0)) END DESC,
                COALESCE(i.ano_lancamento, 0) DESC,
                i.id_item DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':type', $type);
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
            'totalMinutes' => 0,
            'evaluationCount' => 0,
        ];

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM usuario_episodio
            WHERE id_usuario = :user_id
              AND ts_cancelamento IS NULL
        ");
        $stmt->execute([':user_id' => $userId]);
        $stats['totalEpisodes'] = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT i.tipo, COUNT(i.id_item) AS count
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id AND ui.ts_cancelamento IS NULL AND ui.status <> 'avaliado'
            GROUP BY i.tipo
        ");
        $stmt->execute([':user_id' => $userId]);
        foreach ($stmt->fetchAll() as $count) {
            if ($count['tipo'] === 'series') {
                $stats['seriesCount'] = (int)$count['count'];
            } elseif ($count['tipo'] === 'anime') {
                $stats['animeCount'] = (int)$count['count'];
            } elseif ($count['tipo'] === 'movie') {
                $stats['moviesCount'] = (int)$count['count'];
            }
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM usuario_item WHERE id_usuario = :user_id AND nota IS NOT NULL AND comentario IS NOT NULL AND ts_cancelamento IS NULL");
        $stmt->execute([':user_id' => $userId]);
        $stats['evaluationCount'] = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(quantidade_reassistida), 0)
            FROM usuario_episodio
            WHERE id_usuario = :user_id
              AND ts_cancelamento IS NULL
        ");
        $stmt->execute([':user_id' => $userId]);
        $stats['totalRewatched'] = (int)$stmt->fetchColumn();

        $userItems = $this->getUserCollectionQuery($userId, ['movie', 'series', 'anime'], 'last_watched');

        foreach ($userItems as $item) {
            if ($item['status_acompanhamento'] === 'em_pausa') {
                $stats['pausedCount']++;
                continue;
            }

            if ($item['status_acompanhamento'] === 'reassistindo') {
                $stats['rewatchingCount']++;
            }

            if ($item['tipo'] !== 'movie') {
                $remaining = (int)($item['remaining_count'] ?? 0);
                $futureEpisodes = (int)($item['future_count'] ?? 0);

                if ($item['status_acompanhamento'] === 'concluido') {
                    $stats['completedCount']++;
                } elseif ($remaining === 0 && $futureEpisodes > 0) {
                    $stats['upToDateCount']++;
                } elseif ($remaining === 0 && (int)($item['watched_count'] ?? 0) > 0) {
                    $stats['completedCount']++;
                } else {
                    $stats['watchingCount']++;
                }
            } else {
                if ($item['status_acompanhamento'] === 'concluido') {
                    $stats['completedCount']++;
                } else {
                    $stats['watchingCount']++;
                }
            }
        }

        $stmtEpTime = $this->pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(e.duracao_minutos, 45)), 0)
            FROM usuario_episodio ue
            JOIN episodio e ON ue.id_episodio = e.id_episodio
            WHERE ue.id_usuario = :user_id
              AND ue.ts_cancelamento IS NULL
        ");
        $stmtEpTime->execute([':user_id' => $userId]);
        $epMinutes = (int)$stmtEpTime->fetchColumn();

        $stmtMovieTime = $this->pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(i.duracao_minutos, 120)), 0)
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
              AND i.tipo = 'movie'
              AND ui.status = 'concluido'
              AND ui.ts_cancelamento IS NULL
        ");
        $stmtMovieTime->execute([':user_id' => $userId]);
        $movieMinutes = (int)$stmtMovieTime->fetchColumn();

        $stats['totalMinutes'] = $epMinutes + $movieMinutes;
        return $stats;
    }

    public function getStatsTimeline(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(i.ano_lancamento, 2026) AS year, COUNT(ui.id_item) AS count
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
              AND ui.ts_cancelamento IS NULL
            GROUP BY COALESCE(i.ano_lancamento, 2026)
            ORDER BY year ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getStatsGenres(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT ui.status, COUNT(ui.id_item) AS count
            FROM usuario_item ui
            WHERE ui.id_usuario = :user_id
              AND ui.ts_cancelamento IS NULL
            GROUP BY ui.status
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getActivityHistory(int $userId, int $limit = 100): array {
        $stmt = $this->pdo->prepare("
            (
                SELECT
                    ue.ts_inclusao AS watched_at,
                    i.id_item,
                    i.titulo AS show_title,
                    i.tipo,
                    i.url_poster,
                    'episode' AS media_type,
                    e.numero_temporada,
                    e.numero_episodio,
                    (
                        SELECT COUNT(*)
                        FROM usuario_episodio ue_total
                        JOIN episodio e_total ON e_total.id_episodio = ue_total.id_episodio
                        WHERE ue_total.id_usuario = ue.id_usuario
                          AND ue_total.ts_cancelamento IS NULL
                          AND e_total.id_item = i.id_item
                    ) AS total_episodios_assistidos
                FROM usuario_episodio ue
                JOIN episodio e ON ue.id_episodio = e.id_episodio
                JOIN item i ON e.id_item = i.id_item
                WHERE ue.id_usuario = :user_id
                  AND ue.ts_cancelamento IS NULL
            )
            UNION ALL
            (
                SELECT
                    ui.ts_atualizacao AS watched_at,
                    i.id_item,
                    i.titulo AS show_title,
                    i.tipo,
                    i.url_poster,
                    'movie' AS media_type,
                    NULL AS numero_temporada,
                    NULL AS numero_episodio,
                    NULL AS total_episodios_assistidos
                FROM usuario_item ui
                JOIN item i ON ui.id_item = i.id_item
                WHERE ui.id_usuario = :user_id
                  AND i.tipo = 'movie'
                  AND ui.status = 'concluido'
                  AND ui.ts_cancelamento IS NULL
            )
            ORDER BY watched_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getUserReviews(int $userId, int $limit = 10): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ui.id_item,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.ano_lancamento,
                ui.nota,
                ui.comentario,
                ui.ts_atualizacao AS reviewed_at
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
              AND ui.ts_cancelamento IS NULL
              AND ui.nota IS NOT NULL
              AND ui.comentario IS NOT NULL
              AND TRIM(ui.comentario) <> ''
            ORDER BY ui.ts_atualizacao DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function rewatchEpisode(int $userId, int $episodeId): int {
        $stmt = $this->pdo->prepare("
            SELECT id_usuario_episodio, quantidade_reassistida
            FROM usuario_episodio
            WHERE id_usuario = :uid
              AND id_episodio = :eid
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':eid' => $episodeId]);
        $row = $stmt->fetch();

        if ($row) {
            $newCount = (int)$row['quantidade_reassistida'] + 1;
            $stmtUpdate = $this->pdo->prepare("
                UPDATE usuario_episodio
                SET quantidade_reassistida = :count,
                    ts_cancelamento = NULL
                WHERE id_usuario_episodio = :id
            ");
            $stmtUpdate->execute([':count' => $newCount, ':id' => $row['id_usuario_episodio']]);
            return $newCount;
        }

        $stmtInsert = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio, quantidade_reassistida, ts_cancelamento)
            VALUES (:uid, :eid, 1, NULL)
        ");
        $stmtInsert->execute([':uid' => $userId, ':eid' => $episodeId]);
        return 1;
    }

    public function getContinueWatching(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ui.ts_atualizacao,
                i.id_item,
                i.tvmaze_id,
                i.tmdb_id,
                i.mal_id,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.url_banner,
                i.ano_lancamento,
                i.data_lancamento,
                i.total_episodios,
                i.duracao_minutos,
                i.provedores_streaming,
                COALESCE(ui.nota, 0) AS nota,
                COALESCE(ui.eh_favorito, FALSE) AS eh_favorito,
                ui.status AS status_acompanhamento,
                next_ep.id_episodio AS next_id_episodio,
                next_ep.numero_temporada AS next_numero_temporada,
                next_ep.numero_episodio AS next_numero_episodio,
                next_ep.titulo AS next_titulo,
                next_ep.data_exibicao AS next_data_exibicao,
                next_ep.duracao_minutos AS next_duracao_minutos,
                (SELECT COUNT(*) FROM episodio e_total WHERE e_total.id_item = i.id_item AND e_total.ts_cancelamento IS NULL) AS total_episodios,
                (
                    SELECT COUNT(*)
                    FROM usuario_episodio ue_assistido
                    JOIN episodio e_assistido ON e_assistido.id_episodio = ue_assistido.id_episodio
                    WHERE ue_assistido.id_usuario = :user_id
                      AND ue_assistido.ts_cancelamento IS NULL
                      AND e_assistido.id_item = i.id_item
                ) AS episodios_assistidos
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            LEFT JOIN LATERAL (
                SELECT
                    e.id_episodio,
                    e.numero_temporada,
                    e.numero_episodio,
                    e.titulo,
                    e.data_exibicao,
                    e.duracao_minutos
                FROM episodio e
                WHERE e.id_item = i.id_item
                  AND e.id_episodio NOT IN (
                      SELECT ue.id_episodio
                      FROM usuario_episodio ue
                      WHERE ue.id_usuario = :user_id
                        AND ue.ts_cancelamento IS NULL
                  )
                ORDER BY e.numero_temporada ASC, e.numero_episodio ASC
                LIMIT 1
            ) next_ep ON TRUE
            WHERE ui.id_usuario = :user_id
              AND ui.status IN ('assistindo', 'reassistindo')
              AND ui.ts_cancelamento IS NULL
              AND i.tipo != 'movie'
            ORDER BY ui.ts_atualizacao DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $shows = $stmt->fetchAll();

        $results = [];
        foreach ($shows as $show) {
            if (!empty($show['next_id_episodio'])) {
                $results[] = [
                    'item' => $show,
                    'progress' => [
                        'total_count' => (int)($show['total_episodios'] ?? 0),
                        'watched_count' => (int)($show['episodios_assistidos'] ?? 0),
                    ],
                    'next_episode' => [
                        'id_episodio' => $show['next_id_episodio'],
                        'numero_temporada' => $show['next_numero_temporada'],
                        'numero_episodio' => $show['next_numero_episodio'],
                        'titulo' => $show['next_titulo'],
                        'data_exibicao' => $show['next_data_exibicao'],
                        'duracao_minutos' => $show['next_duracao_minutos'],
                    ],
                ];
            }
        }

        return $results;
    }

    public function getPlanToWatch(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ui.status AS status_acompanhamento,
                ui.ts_atualizacao,
                i.id_item,
                i.tvmaze_id,
                i.tmdb_id,
                i.mal_id,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.url_banner,
                i.ano_lancamento,
                i.data_lancamento,
                i.total_episodios,
                i.duracao_minutos,
                COALESCE(ui.nota, 0) AS nota,
                COALESCE(ui.eh_favorito, FALSE) AS eh_favorito
            FROM usuario_item ui
            JOIN item i ON ui.id_item = i.id_item
            WHERE ui.id_usuario = :user_id
              AND ui.status = 'quero_ver'
              AND ui.ts_cancelamento IS NULL
            ORDER BY ui.ts_atualizacao DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getUserLists(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM usuario_lista
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
                    SELECT i.url_poster
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
            WHERE id_usuario = :user_id
              AND id_lista = :list_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':list_id' => $listId]);
        if (!$stmt->fetchColumn()) {
            return [];
        }

        $stmtItems = $this->pdo->prepare("
            SELECT i.id_item, i.titulo, i.url_poster, i.tipo, i.ano_lancamento
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
            WHERE id_usuario = :user_id
              AND id_lista = :list_id
        ");
        $stmt->execute([':user_id' => $userId, ':list_id' => $listId]);
    }

    public function renameList(int $userId, int $listId, string $name): void {
        $stmt = $this->pdo->prepare("
            UPDATE usuario_lista
            SET nome = :nome
            WHERE id_usuario = :user_id
              AND id_lista = :list_id
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
            WHERE id_lista = :list_id
              AND id_item = :item_id
        ");
        $stmt->execute([':list_id' => $listId, ':item_id' => $itemId]);
    }

    public function getItemLists(int $userId, int $itemId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ul.id_lista,
                ul.nome,
                (uli.id_lista_item IS NOT NULL) AS has_item
            FROM usuario_lista ul
            LEFT JOIN usuario_lista_item uli
                ON ul.id_lista = uli.id_lista
               AND uli.id_item = :item_id
            WHERE ul.id_usuario = :user_id
            ORDER BY ul.ts_inclusao DESC
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        return $stmt->fetchAll();
    }
}
