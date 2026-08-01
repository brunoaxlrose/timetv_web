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
                ui.ts_inclusao as ts_inclusao,
                ui.ts_atualizacao as ts_atualizacao,
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
        fputcsv($output, ['titulo', 'tipo', 'ano', 'status', 'nota', 'rewatches', 'episodios_assistidos', 'total_episodios', 'ts_inclusao', 'ts_atualizacao']);
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
                $row['ts_inclusao'],
                $row['ts_atualizacao'],
            ]);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $filename = 'cinefio_export_' . $username . '_' . date('Y-m-d') . '.csv';

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
                $itemId = \Application\Helper\TmdbHelper::importMovieFromTmdb($this->pdo, $r['tmdb_id']);
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
                ON CONFLICT (id_usuario, id_item) 
                DO UPDATE SET status = EXCLUDED.status, ts_atualizacao = CURRENT_TIMESTAMP
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

    // =========================================================
    // PAGE ACTIONS
    // =========================================================

    public function tvtimeAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }
        return new \Laminas\View\Model\ViewModel();
    }

    public function imdbAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }
        return new \Laminas\View\Model\ViewModel();
    }

    public function traktAction() {
        if (!isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('login');
        }
        return new \Laminas\View\Model\ViewModel();
    }

    // =========================================================
    // API ACTIONS
    // =========================================================

    public function apiImportTvtimeAction(): JsonModel {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autenticado']);
        }
        $userId = (int)$_SESSION['user_id'];
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 8]]);
        set_time_limit(600);

        $importedMovies = 0;
        $importedShows = 0;

        // 1. Movies File
        if (!empty($_FILES['movies_file']['tmp_name'])) {
            $content = file_get_contents($_FILES['movies_file']['tmp_name']);
            $movies = json_decode($content, true);
            if (isset($movies['objects'])) {
                $movies = $movies['objects'];
            }
            if (is_array($movies)) {
                foreach ($movies as $movie) {
                    $title = $movie['name'] ?? $movie['title'] ?? ($movie['meta']['name'] ?? ($movie['meta']['title'] ?? ''));
                    if (empty($title)) continue;

                    $tmdbId = $movie['tmdb_id'] ?? ($movie['meta']['tmdb_id'] ?? null);
                    $imdbId = $movie['imdb_id'] ?? ($movie['meta']['imdb_id'] ?? null);

                    $status = 'completed';
                    if (isset($movie['status']) && ($movie['status'] === 'watchlist' || $movie['status'] === 'plan_to_watch')) {
                        $status = 'plan_to_watch';
                    }

                    $itemId = $this->importMovieByTmdbOrName($title, $tmdbId, $imdbId, $ctx);
                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, $status);
                        $importedMovies++;
                    }
                }
            }
        }

        // 2. Series File
        if (!empty($_FILES['series_file']['tmp_name'])) {
            $content = file_get_contents($_FILES['series_file']['tmp_name']);
            $shows = json_decode($content, true);
            if (isset($shows['objects'])) {
                $shows = $shows['objects'];
            }
            if (is_array($shows)) {
                foreach ($shows as $show) {
                    $showName = $show['name'] ?? $show['title'] ?? ($show['meta']['name'] ?? ($show['meta']['title'] ?? ''));
                    if (empty($showName)) continue;

                    $tvdbId = $show['tvdb_id'] ?? $show['id'] ?? ($show['meta']['tvdb_id'] ?? null);
                    $itemId = null;
                    if ($tvdbId) {
                        $itemId = $this->findOrImportByTvdb((int)$tvdbId, $showName, $ctx);
                    } else {
                        $itemId = $this->findOrImportByName($showName, 'series', $ctx);
                    }

                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'watching');
                        $importedShows++;

                        if (isset($show['seasons']) && is_array($show['seasons'])) {
                            foreach ($show['seasons'] as $season) {
                                $seasonNum = $season['season'] ?? $season['number'] ?? null;
                                if ($seasonNum === null) continue;

                                if (isset($season['episodes']) && is_array($season['episodes'])) {
                                    foreach ($season['episodes'] as $episode) {
                                        $epNum = $episode['episode'] ?? $episode['number'] ?? null;
                                        $watched = $episode['watched'] ?? false;
                                        if ($epNum === null || !$watched) continue;

                                        // Mark episode as watched
                                        $stmtEp = $this->pdo->prepare("
                                            SELECT id_episodio FROM episodio 
                                            WHERE id_item = :id_item AND season_number = :s AND episode_number = :e
                                            LIMIT 1
                                        ");
                                        $stmtEp->execute([':id_item' => $itemId, ':s' => $seasonNum, ':e' => $epNum]);
                                        $ep = $stmtEp->fetch();
                                        if ($ep) {
                                            try {
                                                $stmtInsert = $this->pdo->prepare("
                                                    INSERT INTO usuario_episodio (id_usuario, id_episodio)
                                                    VALUES (:uid, :eid)
                                                    ON CONFLICT (id_usuario, id_episodio) DO NOTHING
                                                ");
                                                $stmtInsert->execute([':uid' => $userId, ':eid' => $ep['id_episodio']]);
                                            } catch (\PDOException $e) {
                                                // ignore
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->updateShowStatuses($userId);

        $msg = "Importação do TV Time concluída: $importedMovies filmes e $importedShows séries processados.";
        $this->notificationModel->createNotification($userId, 'info', null, 'Importação do TV Time Finalizada', $msg);

        return new JsonModel(['success' => true, 'message' => $msg]);
    }

    public function apiImportImdbAction(): JsonModel {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autenticado']);
        }
        $userId = (int)$_SESSION['user_id'];
        if (empty($_FILES['zip_file']['tmp_name'])) {
            return new JsonModel(['success' => false, 'message' => 'Nenhum arquivo ZIP enviado.']);
        }

        $ctx = stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 8]]);
        set_time_limit(600);

        $zipPath = $_FILES['zip_file']['tmp_name'];
        $zip = new \ZipArchive();
        
        $ratingsCsv = '';
        $watchlistCsv = '';

        if ($zip->open($zipPath) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos(strtolower($filename), 'ratings.csv') !== false) {
                    $ratingsCsv = $zip->getFromIndex($i);
                }
                if (strpos(strtolower($filename), 'watchlist.csv') !== false) {
                    $watchlistCsv = $zip->getFromIndex($i);
                }
            }
            $zip->close();
        } else {
            return new JsonModel(['success' => false, 'message' => 'Não foi possível abrir o arquivo ZIP.']);
        }

        $imported = 0;

        // Process Ratings (Watched)
        if (!empty($ratingsCsv)) {
            $rows = $this->parseCsvString($ratingsCsv);
            foreach ($rows as $row) {
                $imdbId = $row['Const'] ?? null;
                $title = $row['Title'] ?? null;
                $type = $row['Title Type'] ?? 'movie';
                $rating = isset($row['Your Rating']) ? (float)$row['Your Rating'] : null;

                if (empty($title)) continue;

                if ($type === 'movie') {
                    $itemId = $this->importMovieByTmdbOrName($title, null, $imdbId, $ctx);
                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'completed');
                        if ($rating !== null) {
                            $stmtUpdate = $this->pdo->prepare("UPDATE usuario_item SET rating = :r WHERE id_usuario = :uid AND id_item = :iid");
                            $stmtUpdate->execute([':r' => $rating, ':uid' => $userId, ':iid' => $itemId]);
                        }
                        $imported++;
                    }
                } else {
                    // TV Series
                    $itemId = $this->findOrImportByName($title, 'series', $ctx);
                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'completed');
                        $imported++;
                    }
                }
            }
        }

        // Process Watchlist
        if (!empty($watchlistCsv)) {
            $rows = $this->parseCsvString($watchlistCsv);
            foreach ($rows as $row) {
                $imdbId = $row['Const'] ?? null;
                $title = $row['Title'] ?? null;
                $type = $row['Title Type'] ?? 'movie';

                if (empty($title)) continue;

                if ($type === 'movie') {
                    $itemId = $this->importMovieByTmdbOrName($title, null, $imdbId, $ctx);
                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'plan_to_watch');
                        $imported++;
                    }
                } else {
                    $itemId = $this->findOrImportByName($title, 'series', $ctx);
                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'plan_to_watch');
                        $imported++;
                    }
                }
            }
        }

        $msg = "Importação do IMDb concluída: $imported itens processados com sucesso.";
        $this->notificationModel->createNotification($userId, 'info', null, 'Importação do IMDb Finalizada', $msg);

        return new JsonModel(['success' => true, 'message' => $msg]);
    }

    public function apiImportTraktAction(): JsonModel {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autenticado']);
        }
        $userId = (int)$_SESSION['user_id'];
        if (empty($_FILES['zip_file']['tmp_name'])) {
            return new JsonModel(['success' => false, 'message' => 'Nenhum arquivo ZIP enviado.']);
        }

        $ctx = stream_context_create(['http' => ['header' => "User-Agent: TVTimeClone/1.0\r\n", 'timeout' => 8]]);
        set_time_limit(600);

        $zipPath = $_FILES['zip_file']['tmp_name'];
        $zip = new \ZipArchive();
        
        $watchedMoviesJson = '';
        $watchedShowsJson = '';
        $watchlistMoviesJson = '';
        $watchlistShowsJson = '';

        if ($zip->open($zipPath) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos(strtolower($filename), 'watched_movies.json') !== false || strpos(strtolower($filename), 'movies_watched.json') !== false) {
                    $watchedMoviesJson = $zip->getFromIndex($i);
                }
                if (strpos(strtolower($filename), 'watched_shows.json') !== false || strpos(strtolower($filename), 'shows_watched.json') !== false) {
                    $watchedShowsJson = $zip->getFromIndex($i);
                }
                if (strpos(strtolower($filename), 'watchlist_movies.json') !== false || strpos(strtolower($filename), 'movies_watchlist.json') !== false) {
                    $watchlistMoviesJson = $zip->getFromIndex($i);
                }
                if (strpos(strtolower($filename), 'watchlist_shows.json') !== false || strpos(strtolower($filename), 'shows_watchlist.json') !== false) {
                    $watchlistShowsJson = $zip->getFromIndex($i);
                }
            }
            $zip->close();
        } else {
            return new JsonModel(['success' => false, 'message' => 'Não foi possível abrir o arquivo ZIP.']);
        }

        $moviesCount = 0;
        $showsCount = 0;

        // Process Watched Movies
        if (!empty($watchedMoviesJson)) {
            $movies = json_decode($watchedMoviesJson, true);
            if (is_array($movies)) {
                foreach ($movies as $movie) {
                    $movieData = $movie['movie'] ?? $movie;
                    $title = $movieData['title'] ?? null;
                    if (empty($title)) continue;

                    $tmdbId = $movieData['ids']['tmdb'] ?? null;
                    $imdbId = $movieData['ids']['imdb'] ?? null;

                    $itemId = $this->importMovieByTmdbOrName($title, $tmdbId, $imdbId, $ctx);
                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'completed');
                        $moviesCount++;
                    }
                }
            }
        }

        // Process Watchlist Movies
        if (!empty($watchlistMoviesJson)) {
            $movies = json_decode($watchlistMoviesJson, true);
            if (is_array($movies)) {
                foreach ($movies as $movie) {
                    $movieData = $movie['movie'] ?? $movie;
                    $title = $movieData['title'] ?? null;
                    if (empty($title)) continue;

                    $tmdbId = $movieData['ids']['tmdb'] ?? null;
                    $imdbId = $movieData['ids']['imdb'] ?? null;

                    $itemId = $this->importMovieByTmdbOrName($title, $tmdbId, $imdbId, $ctx);
                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'plan_to_watch');
                        $moviesCount++;
                    }
                }
            }
        }

        // Process Watched Shows
        if (!empty($watchedShowsJson)) {
            $shows = json_decode($watchedShowsJson, true);
            if (is_array($shows)) {
                foreach ($shows as $show) {
                    $showData = $show['show'] ?? $show;
                    $title = $showData['title'] ?? null;
                    if (empty($title)) continue;

                    $tvdbId = $showData['ids']['tvdb'] ?? null;
                    $itemId = null;
                    if ($tvdbId) {
                        $itemId = $this->findOrImportByTvdb((int)$tvdbId, $title, $ctx);
                    } else {
                        $itemId = $this->findOrImportByName($title, 'series', $ctx);
                    }

                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'watching');
                        $showsCount++;

                        if (isset($show['seasons']) && is_array($show['seasons'])) {
                            foreach ($show['seasons'] as $season) {
                                $seasonNum = $season['number'] ?? null;
                                if ($seasonNum === null) continue;

                                if (isset($season['episodes']) && is_array($season['episodes'])) {
                                    foreach ($season['episodes'] as $episode) {
                                        $epNum = $episode['number'] ?? null;
                                        if ($epNum === null) continue;

                                        $stmtEp = $this->pdo->prepare("
                                            SELECT id_episodio FROM episodio 
                                            WHERE id_item = :id_item AND season_number = :s AND episode_number = :e
                                            LIMIT 1
                                        ");
                                        $stmtEp->execute([':id_item' => $itemId, ':s' => $seasonNum, ':e' => $epNum]);
                                        $ep = $stmtEp->fetch();
                                        if ($ep) {
                                            try {
                                                $stmtInsert = $this->pdo->prepare("
                                                    INSERT INTO usuario_episodio (id_usuario, id_episodio)
                                                    VALUES (:uid, :eid)
                                                    ON CONFLICT (id_usuario, id_episodio) DO NOTHING
                                                ");
                                                $stmtInsert->execute([':uid' => $userId, ':eid' => $ep['id_episodio']]);
                                            } catch (\PDOException $e) {
                                                // ignore
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Process Watchlist Shows
        if (!empty($watchlistShowsJson)) {
            $shows = json_decode($watchlistShowsJson, true);
            if (is_array($shows)) {
                foreach ($shows as $show) {
                    $showData = $show['show'] ?? $show;
                    $title = $showData['title'] ?? null;
                    if (empty($title)) continue;

                    $tvdbId = $showData['ids']['tvdb'] ?? null;
                    $itemId = null;
                    if ($tvdbId) {
                        $itemId = $this->findOrImportByTvdb((int)$tvdbId, $title, $ctx);
                    } else {
                        $itemId = $this->findOrImportByName($title, 'series', $ctx);
                    }

                    if ($itemId) {
                        $this->upsertUserItem($userId, $itemId, 'plan_to_watch');
                        $showsCount++;
                    }
                }
            }
        }

        $this->updateShowStatuses($userId);

        $msg = "Importação do Trakt concluída: $moviesCount filmes e $showsCount séries processados.";
        $this->notificationModel->createNotification($userId, 'info', null, 'Importação do Trakt Finalizada', $msg);

        return new JsonModel(['success' => true, 'message' => $msg]);
    }

    // =========================================================
    // PARSER HELPERS
    // =========================================================

    private function importMovieByTmdbOrName(string $title, ?int $tmdbId, ?string $imdbId, $ctx): ?int {
        if (empty($tmdbId) && !empty($imdbId)) {
            $url = "https://api.themoviedb.org/3/find/" . urlencode($imdbId) . "?api_key=1f54bd990f1cdfb230adb312546d765d&external_source=imdb_id&language=pt-BR";
            $json = @file_get_contents($url, false, $ctx);
            if ($json) {
                $data = json_decode($json, true);
                if (!empty($data['movie_results'][0]['id'])) {
                    $tmdbId = (int)$data['movie_results'][0]['id'];
                }
            }
        }

        if ($tmdbId) {
            $itemId = \Application\Helper\TmdbHelper::importMovieFromTmdb($this->pdo, $tmdbId);
            if ($itemId) {
                return $itemId;
            }
        }

        return $this->importTmdbMovie($title, $ctx);
    }

    private function parseCsvString(string $content): array {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $header = null;
        $rows = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (empty($row) || count($row) < 2) continue;
            if (!$header) {
                $header = $row;
                foreach ($header as &$h) {
                    $h = trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h));
                }
                continue;
            }
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            } elseif (count($row) > count($header)) {
                $row = array_slice($row, 0, count($header));
            }
            $rows[] = array_combine($header, $row);
        }
        return $rows;
    }
}
