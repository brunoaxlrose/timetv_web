<?php

namespace Application\Model;

use PDO;

class NotificationModel {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Retorna notificações não lidas do usuário (máx 50)
     */
    public function getUnread(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT n.*, i.poster_url, i.type as item_type
            FROM notificacao n
            LEFT JOIN item i ON i.id_item = n.id_item
            WHERE n.id_usuario = :uid AND n.lida = FALSE
            ORDER BY n.ts_criacao DESC
            LIMIT 50
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Conta notificações não lidas
     */
    public function countUnread(int $userId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM notificacao WHERE id_usuario = :uid AND lida = FALSE
        ");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Marca todas as notificações do usuário como lidas
     */
    public function markAllRead(int $userId): void {
        $stmt = $this->pdo->prepare("
            UPDATE notificacao SET lida = TRUE WHERE id_usuario = :uid AND lida = FALSE
        ");
        $stmt->execute([':uid' => $userId]);
    }

    /**
     * Cria uma notificação genérica
     */
    public function createNotification(int $userId, string $tipo, ?int $itemId, string $titulo, string $mensagem): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO notificacao (id_usuario, tipo, id_item, titulo, mensagem)
            VALUES (:uid, :tipo, :id_item, :titulo, :mensagem)
        ");
        $stmt->execute([
            ':uid'      => $userId,
            ':tipo'     => $tipo,
            ':id_item'  => $itemId,
            ':titulo'   => $titulo,
            ':mensagem' => $mensagem
        ]);
    }

    /**
     * Sincroniza episódios de até 3 séries/animes acompanhados que não foram sincronizados nas últimas 6 horas
     */
    public function syncUserShows(int $userId): void {
        try {
            $stmt = $this->pdo->prepare("
                SELECT i.id_item, i.tvmaze_id 
                FROM item i
                JOIN usuario_item ui ON ui.id_item = i.id_item AND ui.id_usuario = :uid
                WHERE i.tvmaze_id IS NOT NULL 
                  AND i.type != 'movie'
                  AND ui.status IN ('watching', 'plan_to_watch')
                  AND (i.last_sync IS NULL OR i.last_sync < CURRENT_TIMESTAMP - INTERVAL '6 hours')
                ORDER BY i.last_sync ASC NULLS FIRST
                LIMIT 3
            ");
            $stmt->execute([':uid' => $userId]);
            $showsToSync = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($showsToSync as $show) {
                \Application\Helper\TvmazeHelper::syncEpisodes($this->pdo, (int)$show['id_item'], (int)$show['tvmaze_id']);
            }
        } catch (\Throwable $e) {
            // Silently ignore sync errors
        }
    }

    /**
     * Gera notificações automáticas para o usuário baseado em:
     * - Novos episódios (air_date nos últimos 3 dias) de séries acompanhadas
     * - Lançamentos "em breve" (release_date nos próximos 7 dias) de itens na watchlist
     */
    public function generateNotifications(int $userId): void {
        try {
            // Sincroniza shows acompanhados que precisam de atualização
            $this->syncUserShows($userId);

            // 1. Novos episódios (últimos 3 dias, excluindo o dia atual)
            $stmt = $this->pdo->prepare("
                SELECT e.id_episodio, e.id_item, e.title AS ep_title, e.season_number, e.episode_number,
                       e.air_date, i.title AS show_title, i.poster_url
                FROM episodio e
                JOIN item i ON i.id_item = e.id_item
                JOIN usuario_item ui ON ui.id_item = e.id_item AND ui.id_usuario = :uid
                WHERE e.air_date IS NOT NULL AND e.air_date <> ''
                  AND CAST(e.air_date AS DATE) >= CURRENT_DATE - INTERVAL '3 days'
                  AND CAST(e.air_date AS DATE) < CURRENT_DATE
                  AND ui.status IN ('watching', 'plan_to_watch')
                  AND NOT EXISTS (
                      SELECT 1 FROM notificacao n
                      WHERE n.id_usuario = :uid2
                        AND n.tipo = 'new_episode'
                        AND n.id_item = e.id_item
                        AND n.mensagem LIKE '%S' || LPAD(e.season_number::text, 2, '0') || 'E' || LPAD(e.episode_number::text, 2, '0') || '%'
                  )
                ORDER BY CAST(e.air_date AS DATE) DESC
                LIMIT 20
            ");
            $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
            $newEps = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $insertNotif = $this->pdo->prepare("
                INSERT INTO notificacao (id_usuario, tipo, id_item, titulo, mensagem)
                VALUES (:uid, :tipo, :id_item, :titulo, :mensagem)
            ");

            foreach ($newEps as $ep) {
                $epCode = 'S' . str_pad($ep['season_number'], 2, '0', STR_PAD_LEFT)
                        . 'E' . str_pad($ep['episode_number'], 2, '0', STR_PAD_LEFT);
                
                $dateFormatted = date('d/m/Y', strtotime($ep['air_date']));
                
                $airDateObj = new \DateTime($ep['air_date']);
                $todayObj = new \DateTime(date('Y-m-d'));
                $diff = $todayObj->diff($airDateObj);
                $days = (int)$diff->format('%a');
                
                $timeLabel = ($days === 1) ? 'ontem' : "$days dias atrás";

                $insertNotif->execute([
                    ':uid'      => $userId,
                    ':tipo'     => 'new_episode',
                    ':id_item'  => $ep['id_item'],
                    ':titulo'   => 'Novo episódio: ' . $ep['show_title'],
                    ':mensagem' => $epCode . ' — ' . ($ep['ep_title'] ?: 'Sem título') . ' · ' . $dateFormatted . ' (' . $timeLabel . ')',
                ]);
            }

            // 2. Lançamentos em breve (próximos 7 dias) — filmes/séries com release_date
            $stmt2 = $this->pdo->prepare("
                SELECT i.id_item, i.title, i.release_date, i.poster_url, i.type
                FROM item i
                JOIN usuario_item ui ON ui.id_item = i.id_item AND ui.id_usuario = :uid
                WHERE i.release_date >= CURRENT_DATE
                  AND i.release_date <= CURRENT_DATE + INTERVAL '7 days'
                  AND NOT EXISTS (
                      SELECT 1 FROM notificacao n
                      WHERE n.id_usuario = :uid2
                        AND n.tipo = 'release_date'
                        AND n.id_item = i.id_item
                  )
                LIMIT 10
            ");
            $stmt2->execute([':uid' => $userId, ':uid2' => $userId]);
            $upcoming = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            foreach ($upcoming as $it) {
                $typeLabel = match ($it['type']) {
                    'movie' => 'Filme',
                    'anime' => 'Anime',
                    default => 'Série',
                };
                $date = date('d/m/Y', strtotime($it['release_date']));
                $insertNotif->execute([
                    ':uid'      => $userId,
                    ':tipo'     => 'release_date',
                    ':id_item'  => $it['id_item'],
                    ':titulo'   => $typeLabel . ' em breve: ' . $it['title'],
                    ':mensagem' => 'Lançamento previsto para ' . $date,
                ]);
            }
        } catch (\Throwable $e) {
            // Silently ignore errors in notification generation
        }
    }
}
