<?php

namespace Application\Model;

use PDO;

class NotificationModel {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getUnread(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT n.*, i.url_poster, i.tipo AS tipo_item
            FROM notificacao n
            LEFT JOIN item i ON i.id_item = n.id_item
            WHERE n.id_usuario = :uid
              AND n.lida = FALSE
            ORDER BY n.ts_inclusao DESC
            LIMIT 50
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countUnread(int $userId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM notificacao
            WHERE id_usuario = :uid
              AND lida = FALSE
        ");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function markAllRead(int $userId): void {
        $stmt = $this->pdo->prepare("
            UPDATE notificacao
            SET lida = TRUE
            WHERE id_usuario = :uid
              AND lida = FALSE
        ");
        $stmt->execute([':uid' => $userId]);
    }

    public function createNotification(int $userId, string $tipo, ?int $itemId, string $titulo, string $mensagem): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO notificacao (id_usuario, tipo, id_item, titulo, mensagem)
            VALUES (:uid, :tipo, :id_item, :titulo, :mensagem)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':tipo' => $tipo,
            ':id_item' => $itemId,
            ':titulo' => $titulo,
            ':mensagem' => $mensagem,
        ]);
    }

    public function syncUserShows(int $userId): void {
        try {
            $stmt = $this->pdo->prepare("
                SELECT i.id_item, i.tvmaze_id
                FROM item i
                JOIN usuario_item ui ON ui.id_item = i.id_item AND ui.id_usuario = :uid
                WHERE i.tvmaze_id IS NOT NULL
                  AND i.tipo != 'movie'
                  AND ui.status IN ('assistindo', 'quero_ver', 'reassistindo')
                  AND (
                        i.ts_ultima_sincronizacao IS NULL
                     OR i.ts_ultima_sincronizacao < CURRENT_TIMESTAMP - INTERVAL '6 hours'
                  )
                ORDER BY i.ts_ultima_sincronizacao ASC NULLS FIRST
                LIMIT 3
            ");
            $stmt->execute([':uid' => $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $show) {
                \Application\Helper\TvmazeHelper::syncEpisodes($this->pdo, (int)$show['id_item'], (int)$show['tvmaze_id']);
            }
        } catch (\Throwable $e) {
            // Ignore sync failures.
        }
    }

    public function generateNotifications(int $userId): void {
        try {
            $this->syncUserShows($userId);

            $stmt = $this->pdo->prepare("
                SELECT
                    e.id_episodio,
                    e.id_item,
                    e.titulo AS titulo_episodio,
                    e.numero_temporada,
                    e.numero_episodio,
                    e.data_exibicao,
                    i.titulo AS titulo_item,
                    i.url_poster
                FROM episodio e
                JOIN item i ON i.id_item = e.id_item
                JOIN usuario_item ui ON ui.id_item = e.id_item AND ui.id_usuario = :uid
                WHERE e.data_exibicao IS NOT NULL
                  AND CAST(e.data_exibicao AS DATE) >= CURRENT_DATE - INTERVAL '3 days'
                  AND CAST(e.data_exibicao AS DATE) < CURRENT_DATE
                  AND ui.status IN ('assistindo', 'quero_ver', 'reassistindo')
                  AND NOT EXISTS (
                      SELECT 1
                      FROM notificacao n
                      WHERE n.id_usuario = :uid2
                        AND n.tipo = 'novo_episodio'
                        AND n.id_item = e.id_item
                        AND n.mensagem LIKE '%T' || LPAD(e.numero_temporada::text, 2, '0') || 'E' || LPAD(e.numero_episodio::text, 2, '0') || '%'
                  )
                ORDER BY CAST(e.data_exibicao AS DATE) DESC
                LIMIT 20
            ");
            $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
            $episodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $insertNotif = $this->pdo->prepare("
                INSERT INTO notificacao (id_usuario, tipo, id_item, titulo, mensagem)
                VALUES (:uid, :tipo, :id_item, :titulo, :mensagem)
            ");

            foreach ($episodes as $episode) {
                $codigo = 'T' . str_pad((string)$episode['numero_temporada'], 2, '0', STR_PAD_LEFT)
                    . 'E' . str_pad((string)$episode['numero_episodio'], 2, '0', STR_PAD_LEFT);
                $data = date('d/m/Y', strtotime((string)$episode['data_exibicao']));
                $dataExibicao = new \DateTime((string)$episode['data_exibicao']);
                $hoje = new \DateTime(date('Y-m-d'));
                $dias = (int)$hoje->diff($dataExibicao)->format('%a');
                $rotulo = $dias === 1 ? 'ontem' : $dias . ' dias atras';

                $insertNotif->execute([
                    ':uid' => $userId,
                    ':tipo' => 'novo_episodio',
                    ':id_item' => $episode['id_item'],
                    ':titulo' => 'Novo episodio: ' . $episode['titulo_item'],
                    ':mensagem' => $codigo . ' - ' . ($episode['titulo_episodio'] ?: 'Sem titulo') . ' - ' . $data . ' (' . $rotulo . ')',
                ]);
            }

            $stmt2 = $this->pdo->prepare("
                SELECT i.id_item, i.titulo, i.data_lancamento, i.url_poster, i.tipo
                FROM item i
                JOIN usuario_item ui ON ui.id_item = i.id_item AND ui.id_usuario = :uid
                WHERE i.data_lancamento >= CURRENT_DATE
                  AND i.data_lancamento <= CURRENT_DATE + INTERVAL '7 days'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM notificacao n
                      WHERE n.id_usuario = :uid2
                        AND n.tipo = 'data_lancamento'
                        AND n.id_item = i.id_item
                  )
                LIMIT 10
            ");
            $stmt2->execute([':uid' => $userId, ':uid2' => $userId]);
            $upcoming = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            foreach ($upcoming as $item) {
                $typeLabel = match ($item['tipo']) {
                    'movie' => 'Filme',
                    'anime' => 'Anime',
                    default => 'Serie',
                };
                $date = date('d/m/Y', strtotime((string)$item['data_lancamento']));
                $insertNotif->execute([
                    ':uid' => $userId,
                    ':tipo' => 'data_lancamento',
                    ':id_item' => $item['id_item'],
                    ':titulo' => $typeLabel . ' em breve: ' . $item['titulo'],
                    ':mensagem' => 'Lancamento previsto para ' . $date,
                ]);
            }
        } catch (\Throwable $e) {
            // Ignore notification generation failures.
        }
    }
}
