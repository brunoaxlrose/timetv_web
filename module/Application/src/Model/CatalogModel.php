<?php

namespace Application\Model;

use PDO;

class CatalogModel {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    private function hasColumn(string $table, string $column): bool {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    }

    private function getLocalItemByField(int $userId, string $field, string $value) {
        $stmt = $this->pdo->prepare("
            SELECT
                i.*,
                ui.status AS status_acompanhamento,
                ui.nota,
                ui.comentario,
                COALESCE(ui.quantidade_reassistida, 0) AS quantidade_reassistida,
                COALESCE(ui.eh_favorito, FALSE) AS eh_favorito
            FROM item i
            LEFT JOIN usuario_item ui
                ON i.id_item = ui.id_item
               AND ui.id_usuario = :user_id
               AND ui.ts_cancelamento IS NULL
            WHERE i.{$field} = :value
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':value' => $value]);
        return $stmt->fetch();
    }

    public function getLocalItemByTvmazeId(int $userId, string $tvmazeId) {
        return $this->getLocalItemByField($userId, 'tvmaze_id', $tvmazeId);
    }

    public function getLocalItemByTmdbId(int $userId, string $tmdbId) {
        return $this->getLocalItemByField($userId, 'tmdb_id', $tmdbId);
    }

    public function getLocalItemByMalId(int $userId, string $malId) {
        return $this->getLocalItemByField($userId, 'mal_id', $malId);
    }

    public function getLocalItemById(int $userId, string $itemId) {
        return $this->getLocalItemByField($userId, 'id_item', $itemId);
    }

    public function getEpisodesWithWatchedState(int $userId, string $itemId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                e.*,
                (ue.id_usuario_episodio IS NOT NULL) AS assistido,
                COALESCE(ue.quantidade_reassistida, 0) AS quantidade_reassistida
            FROM episodio e
            LEFT JOIN usuario_episodio ue
                ON e.id_episodio = ue.id_episodio
               AND ue.id_usuario = :user_id
               AND ue.ts_cancelamento IS NULL
            WHERE e.id_item = :item_id
            ORDER BY e.numero_temporada ASC, e.numero_episodio ASC
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getProgress(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(e.id_episodio) AS total_count,
                COUNT(ue.id_usuario_episodio) AS watched_count,
                COUNT(e.id_episodio) AS total_episodios,
                COUNT(ue.id_usuario_episodio) AS episodios_assistidos
            FROM episodio e
            LEFT JOIN usuario_episodio ue
                ON e.id_episodio = ue.id_episodio
               AND ue.id_usuario = :user_id
               AND ue.ts_cancelamento IS NULL
            WHERE e.id_item = :item_id
        ");
        $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function getNextUnwatched(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT id_episodio, numero_temporada, numero_episodio, titulo, data_exibicao, duracao_minutos
            FROM episodio
            WHERE id_item = :item_id
              AND id_episodio NOT IN (
                  SELECT id_episodio
                  FROM usuario_episodio
                  WHERE id_usuario = :user_id
                    AND ts_cancelamento IS NULL
              )
            ORDER BY numero_temporada ASC, numero_episodio ASC
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
            INSERT INTO item (id_item, titulo, tipo, url_poster, url_banner, tvmaze_id)
            VALUES (:id, :titulo, :tipo, :poster, :banner, :tvmaze_id)
            ON CONFLICT (id_item) DO UPDATE
            SET titulo = EXCLUDED.titulo,
                tipo = EXCLUDED.tipo,
                url_poster = EXCLUDED.url_poster,
                url_banner = EXCLUDED.url_banner,
                tvmaze_id = EXCLUDED.tvmaze_id
        ");
        $stmt->execute([
            ':id' => $itemData['id'],
            ':titulo' => $itemData['titulo'],
            ':tipo' => $itemData['tipo'],
            ':poster' => $itemData['poster'],
            ':banner' => $itemData['banner'],
            ':tvmaze_id' => $itemData['tvmaze_id'] ?? null,
        ]);
    }

    public function getWatchlistStatus(int $userId, string $itemId) {
        $stmt = $this->pdo->prepare("
            SELECT status
            FROM usuario_item
            WHERE id_usuario = :user_id
              AND id_item = :item_id
              AND ts_cancelamento IS NULL
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':item_id' => $itemId]);
        $res = $stmt->fetch();
        return $res ? $res['status'] : null;
    }

    public function getEpisodesByItemId(string $itemId): array {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM episodio
            WHERE id_item = :item_id
            ORDER BY numero_temporada ASC, numero_episodio ASC
        ");
        $stmt->execute([':item_id' => $itemId]);
        return $stmt->fetchAll();
    }

    public function saveEpisode(array $epData): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO episodio (id_item, numero_temporada, numero_episodio, titulo, data_exibicao, duracao_minutos, nota, descricao)
            VALUES (:item_id, :season, :episode, :titulo, :data_exibicao, :duracao_minutos, :nota, :descricao)
            ON CONFLICT (id_item, numero_temporada, numero_episodio) DO UPDATE
            SET titulo = EXCLUDED.titulo,
                data_exibicao = EXCLUDED.data_exibicao,
                duracao_minutos = EXCLUDED.duracao_minutos,
                nota = EXCLUDED.nota,
                descricao = EXCLUDED.descricao
        ");
        $stmt->execute([
            ':item_id' => $epData['item_id'],
            ':season' => $epData['season'],
            ':episode' => $epData['episode'],
            ':titulo' => $epData['titulo'],
            ':data_exibicao' => $epData['data_exibicao'] ?: null,
            ':duracao_minutos' => $epData['duracao_minutos'] ?: null,
            ':nota' => $epData['nota'] ?? null,
            ':descricao' => $epData['descricao'] ?: null,
        ]);
    }

    public function getWatchedEpisodeIds(int $userId, array $episodeIds): array {
        if (empty($episodeIds)) {
            return [];
        }
        $inQuery = implode(',', array_fill(0, count($episodeIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id_episodio
            FROM usuario_episodio
            WHERE id_usuario = ? AND id_episodio IN ($inQuery)
        ");
        $params = array_merge([$userId], $episodeIds);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function getCachedSearchResults(string $query) {
        $stmt = $this->pdo->prepare("
            SELECT results
            FROM search_cache
            WHERE query = :query
              AND ts_created > CURRENT_TIMESTAMP - INTERVAL '1 day'
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
            ':results' => json_encode($results),
        ]);
    }

    public function searchAllDatabases(string $search, int $userId): array {
        $cached = $this->getCachedSearchResults($search);
        if ($cached !== null) {
            foreach ($cached as &$item) {
                if (!empty($item['tvmaze_id'])) {
                    $local = $this->getLocalItemByTvmazeId($userId, (string)$item['tvmaze_id']);
                } elseif (!empty($item['tmdb_id'])) {
                    $local = $this->getLocalItemByTmdbId($userId, (string)$item['tmdb_id']);
                } elseif (!empty($item['mal_id'])) {
                    $local = $this->getLocalItemByMalId($userId, (string)$item['mal_id']);
                } else {
                    $local = null;
                }

                if ($local) {
                    $item = array_merge($item, $local);
                }
            }
            unset($item);
            return $cached;
        }

        $options = ['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 5]];
        $context = stream_context_create($options);
        $apiUrl = "https://api.tvmaze.com/search/shows?q=" . urlencode($search);
        $json = @file_get_contents($apiUrl, false, $context);
        $tvmazeResults = $json ? json_decode($json, true) : [];

        $merged = [];
        $seenKeys = [];
        $getDedupeKey = function($titulo, $tipo, $ano) {
            $cleanTitle = preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $titulo)));
            return $cleanTitle . '_' . $tipo . '_' . $ano;
        };

        foreach ($tvmazeResults as $result) {
            $show = $result['show'] ?? null;
            if (!$show) {
                continue;
            }

            $localItem = $this->getLocalItemByTvmazeId($userId, (string)$show['id']);
            if ($localItem) {
                $item = $localItem;
            } else {
                $summary = trim(strip_tags($show['summary'] ?? '')) ?: 'Nenhuma sinopse disponivel.';
                $genres = $show['genres'] ?? [];
                $tipo = 'series';
                if (
                    in_array('Anime', $genres, true)
                    || (($show['network']['country']['code'] ?? null) === 'JP')
                    || (($show['webChannel']['country']['code'] ?? null) === 'JP')
                ) {
                    $tipo = 'anime';
                }

                $ano = isset($show['premiered']) ? (int)substr($show['premiered'], 0, 4) : (int)date('Y');
                $item = [
                    'id_item' => null,
                    'tvmaze_id' => $show['id'],
                    'tmdb_id' => null,
                    'mal_id' => null,
                    'titulo' => $show['name'],
                    'tipo' => $tipo,
                    'url_poster' => $show['image']['medium'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400',
                    'url_banner' => $show['image']['original'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200',
                    'descricao' => $summary,
                    'ano_lancamento' => $ano,
                    'status_acompanhamento' => null,
                ];
            }

            $key = $getDedupeKey($item['titulo'], $item['tipo'], $item['ano_lancamento'] ?? date('Y'));
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $merged[] = $item;
            }
        }

        foreach (\Application\Helper\TmdbHelper::searchMulti($search, 20) as $result) {
            $item = $result;
            if (!empty($result['tmdb_id'])) {
                $local = $this->getLocalItemByTmdbId($userId, (string)$result['tmdb_id']);
                if ($local) {
                    $item = array_merge($item, $local);
                }
            }

            $key = $getDedupeKey($item['titulo'], $item['tipo'], $item['ano_lancamento'] ?? date('Y'));
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $merged[] = $item;
            }
        }

        foreach (\Application\Helper\JikanHelper::searchAnime($search, 15) as $result) {
            $item = $result;
            if (!empty($result['mal_id'])) {
                $local = $this->getLocalItemByMalId($userId, (string)$result['mal_id']);
                if ($local) {
                    $item = array_merge($item, $local);
                }
            }

            $key = $getDedupeKey($item['titulo'], $item['tipo'], $item['ano_lancamento'] ?? date('Y'));
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
            SELECT
                ui.comentario,
                ui.nota,
                ui.ts_atualizacao AS reviewed_at,
                u.nome_usuario,
                u.url_avatar,
                ui.id_usuario
            FROM usuario_item ui
            JOIN usuario u ON ui.id_usuario = u.id_usuario
            WHERE ui.id_item = :item_id
              AND ui.nota IS NOT NULL
            ORDER BY ui.ts_atualizacao DESC
        ");
        $stmt->execute([':item_id' => $itemId]);
        return $stmt->fetchAll();
    }

    public function getReleaseCalendar(int $userId, string $startDate, string $endDate, ?int $limit = null): array {
        $query = "
            SELECT
                i.id_item,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.provedores_streaming,
                e.id_episodio,
                e.numero_temporada,
                e.numero_episodio,
                e.titulo AS titulo_episodio,
                e.data_exibicao AS data_evento,
                ui.status AS status_acompanhamento,
                EXISTS (
                    SELECT 1 FROM usuario_episodio ue
                    WHERE ue.id_usuario = :id_usuario_episodio
                      AND ue.id_episodio = e.id_episodio
                      AND ue.ts_cancelamento IS NULL
                ) AS assistido
            FROM episodio e
            JOIN item i ON i.id_item = e.id_item AND i.ts_cancelamento IS NULL
            LEFT JOIN usuario_item ui
              ON ui.id_item = i.id_item
             AND ui.id_usuario = :id_usuario_acompanhamento_episodio
             AND ui.ts_cancelamento IS NULL
            WHERE e.data_exibicao BETWEEN :data_inicio_episodio AND :data_fim_episodio
              AND e.ts_cancelamento IS NULL

            UNION ALL

            SELECT
                i.id_item,
                i.titulo,
                i.tipo,
                i.url_poster,
                i.provedores_streaming,
                NULL AS id_episodio,
                NULL AS numero_temporada,
                NULL AS numero_episodio,
                NULL AS titulo_episodio,
                i.data_lancamento AS data_evento,
                ui.status AS status_acompanhamento,
                FALSE AS assistido
            FROM item i
            LEFT JOIN usuario_item ui
              ON ui.id_item = i.id_item
             AND ui.id_usuario = :id_usuario_acompanhamento_item
             AND ui.ts_cancelamento IS NULL
            WHERE i.data_lancamento BETWEEN :data_inicio_item AND :data_fim_item
              AND i.ts_cancelamento IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM episodio e
                  WHERE e.id_item = i.id_item
                    AND e.data_exibicao = i.data_lancamento
                    AND e.ts_cancelamento IS NULL
              )
            ORDER BY data_evento, titulo
        ";
        if ($limit !== null && $limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':id_usuario_episodio', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':id_usuario_acompanhamento_episodio', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':id_usuario_acompanhamento_item', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':data_inicio_episodio', $startDate);
        $stmt->bindValue(':data_fim_episodio', $endDate);
        $stmt->bindValue(':data_inicio_item', $startDate);
        $stmt->bindValue(':data_fim_item', $endDate);
        if ($limit !== null && $limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
