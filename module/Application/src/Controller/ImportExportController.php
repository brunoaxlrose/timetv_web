<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use PDO;

class ImportExportController extends AbstractActionController {
    private PDO $pdo;
    private \Application\Model\NotificationModel $notificationModel;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->notificationModel = new \Application\Model\NotificationModel($pdo);
    }

    // =========================================================
    // IMPORT — POST /api/import
    // =========================================================
    public function importAction(): JsonModel {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autenticado']);
        }

        $userId = (int)$_SESSION['user_id'];

        if (empty($_FILES['csv_file']['tmp_name'])) {
            return new JsonModel(['success' => false, 'message' => 'Nenhum arquivo enviado.']);
        }

        $tmpPath      = $_FILES['csv_file']['tmp_name'];
        $originalName = strtolower($_FILES['csv_file']['name'] ?? '');

        set_time_limit(300); // up to 5 minutes

        // Detect file type by filename then by header
        if (strpos($originalName, 'tracking-prod-records-v2') !== false) {
            return $this->importEpisodes($userId, $tmpPath);
        }
        if (strpos($originalName, 'tracking-prod-records') !== false) {
            return $this->importMovies($userId, $tmpPath);
        }
        if (strpos($originalName, 'user_tv_show_data') !== false) {
            return $this->importShows($userId, $tmpPath);
        }

        // Auto-detect by CSV header
        $fh = fopen($tmpPath, 'r');
        $header = fgetcsv($fh);
        fclose($fh);
        $headerStr = implode(',', $header ?? []);

        if (strpos($headerStr, 'series_name') !== false && strpos($headerStr, 'season_number') !== false) {
            return $this->importEpisodes($userId, $tmpPath);
        }
        if (strpos($headerStr, 'movie_name') !== false) {
            return $this->importMovies($userId, $tmpPath);
        }
        if (strpos($headerStr, 'tv_show_name') !== false) {
            return $this->importShows($userId, $tmpPath);
        }

        return new JsonModel(['success' => false, 'message' => 'Formato de arquivo não reconhecido. Use tracking-prod-records-v2.csv, tracking-prod-records.csv ou user_tv_show_data.csv do TV Time.']);
    }

    // =========================================================
    // Import: user_tv_show_data.csv  →  follows shows
    // Columns: is_followed,is_favorited,nb_episodes_seen,tv_show_name,user_id,tv_show_id
    // tv_show_id is the TVDB ID
    // =========================================================
    private function importShows(int $userId, string $csvPath): JsonModel {
        $fh = fopen($csvPath, 'r');
        if (!$fh) {
            return new JsonModel(['success' => false, 'message' => 'Não foi possível abrir o arquivo.']);
        }

        $header = fgetcsv($fh);
        $colMap = array_flip($header);

        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;
        $processed = 0;

        $ctx = stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 8]]);

        while (($row = fgetcsv($fh)) !== false) {
            $processed++;
            $showName  = $row[$colMap['tv_show_name']] ?? '';
            $tvdbId    = (int)($row[$colMap['tv_show_id']] ?? 0);
            $isFollowed = (int)($row[$colMap['is_followed']] ?? 0);

            if (empty($showName)) {
                $skipped++;
                continue;
            }

            // Find or import the item
            $itemId = $this->findOrImportByTvdb($tvdbId, $showName, $ctx);
            if (!$itemId) {
                $errors++;
                continue;
            }

            // Create usuario_item if not exists
            $status = $isFollowed ? 'watching' : 'completed';
            $this->upsertUserItem($userId, $itemId, $status);
            $imported++;
        }
        fclose($fh);

        $msg = "$imported séries importadas, $skipped ignoradas, $errors erros.";
        $this->notificationModel->createNotification($userId, 'info', null, 'Importação de Séries Finalizada', $msg);

        return new JsonModel([
            'success'   => true,
            'message'   => "Importação concluída! " . $msg,
            'imported'  => $imported,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'processed' => $processed,
        ]);
    }

    // =========================================================
    // Import: tracking-prod-records-v2.csv  →  watched episodes
    // Columns: s_id,gsi,ep_no,episode_id,user_id,series_name,...,season_number,episode_number,...
    // =========================================================
    private function importEpisodes(int $userId, string $csvPath): JsonModel {
        $fh = fopen($csvPath, 'r');
        if (!$fh) {
            return new JsonModel(['success' => false, 'message' => 'Não foi possível abrir o arquivo.']);
        }

        $header = fgetcsv($fh);
        $colMap = array_flip($header);

        $ctx = stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 8]]);

        // Pre-cache: itemId by series_name
        $showCache = [];
        $epWatched = 0;
        $epSkipped = 0;
        $epErrors  = 0;
        $processed = 0;

        // Batch all rows first to unique show names
        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $rows[] = $row;
        }
        fclose($fh);

        // Import unique shows first
        foreach ($rows as $row) {
            $seriesName   = $row[$colMap['series_name']] ?? '';
            $seasonNum    = (int)($row[$colMap['season_number']] ?? 0);
            $episodeNum   = (int)($row[$colMap['episode_number']] ?? 0);

            if (empty($seriesName) || $seasonNum <= 0 || $episodeNum <= 0) {
                $epSkipped++;
                continue;
            }

            if (!isset($showCache[$seriesName])) {
                $itemId = $this->findOrImportByName($seriesName, 'series', $ctx);
                $showCache[$seriesName] = $itemId;
                if ($itemId) {
                    $this->upsertUserItem($userId, $itemId, 'watching');
                }
            }
        }

        // Now mark episodes as watched
        $stmtEp = $this->pdo->prepare("
            SELECT id_episodio FROM episodio 
            WHERE id_item = :id_item AND season_number = :s AND episode_number = :e
            LIMIT 1
        ");
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO usuario_episodio (id_usuario, id_episodio)
            VALUES (:uid, :eid)
            ON CONFLICT (id_usuario, id_episodio) DO NOTHING
        ");

        foreach ($rows as $row) {
            $processed++;
            $seriesName = $row[$colMap['series_name']] ?? '';
            $seasonNum  = (int)($row[$colMap['season_number']] ?? 0);
            $episodeNum = (int)($row[$colMap['episode_number']] ?? 0);

            if (empty($seriesName) || $seasonNum <= 0 || $episodeNum <= 0) {
                continue;
            }

            $itemId = $showCache[$seriesName] ?? null;
            if (!$itemId) {
                $epErrors++;
                continue;
            }

            $stmtEp->execute([':id_item' => $itemId, ':s' => $seasonNum, ':e' => $episodeNum]);
            $ep = $stmtEp->fetch();
            if (!$ep) {
                $epSkipped++;
                continue;
            }

            try {
                $stmtInsert->execute([':uid' => $userId, ':eid' => $ep['id_episodio']]);
                $epWatched++;
            } catch (\PDOException $e) {
                $epSkipped++;
            }
        }

        // Mark shows with all episodes watched as completed
        $this->updateShowStatuses($userId);

        $msg = "$epWatched episódios de " . count(array_filter($showCache)) . " séries marcados como assistidos.";
        $this->notificationModel->createNotification($userId, 'info', null, 'Importação de Episódios Finalizada', $msg);

        return new JsonModel([
            'success'   => true,
            'message'   => "Importação concluída! " . $msg,
            'shows'     => count(array_filter($showCache)),
            'watched'   => $epWatched,
            'skipped'   => $epSkipped,
            'errors'    => $epErrors,
            'processed' => $processed,
        ]);
    }

    // =========================================================
    // Import: tracking-prod-records.csv  →  movie watchlist
    // Columns: watch_count,watches,user_id,type-uuid-n,runtime,uuid,release_date,entity_type,...,movie_name,...,type,...
    // =========================================================
    private function importMovies(int $userId, string $csvPath): JsonModel {
        $fh = fopen($csvPath, 'r');
        if (!$fh) {
            return new JsonModel(['success' => false, 'message' => 'Não foi possível abrir o arquivo.']);
        }

        $header = fgetcsv($fh);
        $colMap = array_flip($header);

        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;
        $processed = 0;
        $seen      = [];

        $ctx = stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 8]]);

        while (($row = fgetcsv($fh)) !== false) {
            $processed++;
            $entityType = $row[$colMap['entity_type']] ?? '';
            $movieName  = $row[$colMap['movie_name']] ?? '';
            $watchCount = (int)($row[$colMap['watch_count']] ?? 0);

            if ($entityType !== 'movie' || empty($movieName)) {
                $skipped++;
                continue;
            }
            if (isset($seen[$movieName])) {
                continue;
            }
            $seen[$movieName] = true;

            $itemId = $this->findOrImportByName($movieName, 'movie', $ctx);
            if (!$itemId) {
                $errors++;
                continue;
            }

            $status = $watchCount > 0 ? 'completed' : 'plan_to_watch';
            $this->upsertUserItem($userId, $itemId, $status);
            $imported++;
        }
        fclose($fh);

        $msg = "$imported filmes importados com sucesso.";
        $this->notificationModel->createNotification($userId, 'info', null, 'Importação de Filmes Finalizada', $msg);

        return new JsonModel([
            'success'   => true,
            'message'   => "Importação concluída! " . $msg,
            'imported'  => $imported,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'processed' => $processed,
        ]);
    }

    // =========================================================
    // EXPORT — GET /api/export
    // =========================================================
    public function exportAction() {
        if (!isset($_SESSION['user_id'])) {
            $this->getResponse()->setStatusCode(401);
            return $this->getResponse();
        }

        $userId   = (int)$_SESSION['user_id'];
        $username = $_SESSION['username'] ?? 'user';

        $stmt = $this->pdo->prepare("
            SELECT 
                i.title,
                i.type,
                i.release_year,
                ui.status,
                ui.rating,
                ui.rewatch_count,
                ui.ts_inclusao as added_at,
                ui.ts_atualizacao as updated_at,
                COALESCE(ep_count.watched, 0) AS episodes_watched,
                i.total_episodes
            FROM usuario_item ui
            JOIN item i ON i.id_item = ui.id_item
            LEFT JOIN (
                SELECT ue.id_usuario, e.id_item, COUNT(*) AS watched
                FROM usuario_episodio ue
                JOIN episodio e ON e.id_episodio = ue.id_episodio
                WHERE ue.id_usuario = :uid
                GROUP BY ue.id_usuario, e.id_item
            ) ep_count ON ep_count.id_item = i.id_item AND ep_count.id_usuario = ui.id_usuario
            WHERE ui.id_usuario = :uid2
            ORDER BY i.type, i.title
        ");
        $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build CSV
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['titulo', 'tipo', 'ano', 'status', 'nota', 'rewatches', 'episodios_assistidos', 'total_episodios', 'adicionado_em', 'atualizado_em']);
        foreach ($items as $row) {
            fputcsv($output, [
                $row['title'],
                $row['type'],
                $row['release_year'],
                $row['status'],
                $row['rating'] ?? '',
                $row['rewatch_count'],
                $row['episodes_watched'],
                $row['total_episodes'],
                $row['added_at'],
                $row['updated_at'],
            ]);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $filename = 'timeview_export_' . $username . '_' . date('Y-m-d') . '.csv';

        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'text/csv; charset=utf-8')
            ->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->addHeaderLine('Content-Length', strlen($csv));
        $response->setContent($csv);
        return $response;
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /** Find item by TVDB ID (TVmaze lookup) or fall back to name search */
    private function findOrImportByTvdb(int $tvdbId, string $showName, $ctx): ?int {
        // Check local DB first
        if ($tvdbId > 0) {
            $stmt = $this->pdo->prepare("SELECT id_item FROM item WHERE tvmaze_id IN (SELECT id FROM (SELECT id FROM item WHERE tvmaze_id IS NOT NULL LIMIT 0) t) LIMIT 0");
            // Actually look up via TVmaze API by TVDB id
            $url  = "https://api.tvmaze.com/lookup/shows?thetvdb=$tvdbId";
            $json = @file_get_contents($url, false, $ctx);
            if ($json) {
                $show = json_decode($json, true);
                if (!empty($show['id'])) {
                    return $this->importTvmazeShow($show['id'], $ctx);
                }
            }
        }
        // Fallback to name search
        return $this->findOrImportByName($showName, 'series', $ctx);
    }

    /** Find item by name, import if not found */
    private function findOrImportByName(string $name, string $type, $ctx): ?int {
        // Check local DB by title
        $stmt = $this->pdo->prepare("SELECT id_item FROM item WHERE LOWER(title) = LOWER(:title) AND type = :type LIMIT 1");
        $stmt->execute([':title' => $name, ':type' => $type]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id_item'];
        }

        if ($type === 'movie') {
            return $this->importTmdbMovie($name, $ctx);
        }

        // Series/anime: TVmaze search
        $url  = "https://api.tvmaze.com/singlesearch/shows?q=" . urlencode($name);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) {
            return null;
        }
        $show = json_decode($json, true);
        if (empty($show['id'])) {
            return null;
        }
        return $this->importTvmazeShow($show['id'], $ctx);
    }

    /** Import a TVmaze show by ID (uses TvmazeHelper) */
    private function importTvmazeShow(int $tvmazeId, $ctx): ?int {
        // Check if already imported
        $stmt = $this->pdo->prepare("SELECT id_item FROM item WHERE tvmaze_id = :id LIMIT 1");
        $stmt->execute([':id' => $tvmazeId]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id_item'];
        }

        $result = \Application\Helper\TvmazeHelper::importFromTVMaze($this->pdo, $tvmazeId);
        return $result ? (int)$result : null;
    }

    /** Import a TMDB movie by search name */
    private function importTmdbMovie(string $name, $ctx): ?int {
        $results = \Application\Helper\TmdbHelper::searchMulti($name, 3);
        foreach ($results as $r) {
            if (($r['type'] ?? '') === 'movie' && !empty($r['tmdb_id'])) {
                // Check if already exists
                $stmt = $this->pdo->prepare("SELECT id_item FROM item WHERE tmdb_id = :id LIMIT 1");
                $stmt->execute([':id' => $r['tmdb_id']]);
                $row = $stmt->fetch();
                if ($row) {
                    return (int)$row['id_item'];
                }
                // Import via TmdbHelper
                $itemId = \Application\Helper\TmdbHelper::importMovie($this->pdo, $r['tmdb_id']);
                if ($itemId) {
                    return (int)$itemId;
                }
            }
        }
        return null;
    }

    /** Upsert user-item tracking row */
    private function upsertUserItem(int $userId, int $itemId, string $status): void {
        $validStatuses = ['watching', 'completed', 'dropped', 'plan_to_watch', 'rewatching'];
        if (!in_array($status, $validStatuses)) {
            $status = 'watching';
        }
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO usuario_item (id_usuario, id_item, status, ts_atualizacao)
                VALUES (:uid, :iid, :status, CURRENT_TIMESTAMP)
                ON CONFLICT (id_usuario, id_item) DO NOTHING
            ");
            $stmt->execute([':uid' => $userId, ':iid' => $itemId, ':status' => $status]);
        } catch (\PDOException $e) {
            // ignore
        }
    }

    /** Update shows to 'completed' if all episodes are watched */
    private function updateShowStatuses(int $userId): void {
        try {
            $this->pdo->prepare("
                UPDATE usuario_item ui
                SET status = 'completed'
                FROM item i
                WHERE i.id_item = ui.id_item
                  AND ui.id_usuario = :uid
                  AND ui.status = 'watching'
                  AND i.total_episodes > 0
                  AND i.status = 'Ended'
                  AND (
                      SELECT COUNT(*) FROM usuario_episodio ue
                      JOIN episodio e ON e.id_episodio = ue.id_episodio
                      WHERE ue.id_usuario = :uid2 AND e.id_item = i.id_item
                  ) >= i.total_episodes
            ")->execute([':uid' => $userId, ':uid2' => $userId]);
        } catch (\PDOException $e) {
            // ignore
        }
    }
}
