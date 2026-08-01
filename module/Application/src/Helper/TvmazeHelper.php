<?php

namespace Application\Helper;

class TvmazeHelper {
    public static function importFromTVMaze($pdo, $tvmazeId) {
        // Check if already exists in the database
        $stmt = $pdo->prepare("SELECT id_item FROM item WHERE tvmaze_id = :tvmaze_id LIMIT 1");
        $stmt->execute([':tvmaze_id' => $tvmazeId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing['id_item'];
        }

        // Configure stream context to send a User-Agent header (required by TVmaze API)
        $options = [
            'http' => [
                'header' => "User-Agent: TVTimeClone/1.0\r\n"
            ]
        ];
        $context = stream_context_create($options);

        // Fetch Show details from TV Maze API
        $showUrl = "https://api.tvmaze.com/shows/" . intval($tvmazeId);
        $showJson = @file_get_contents($showUrl, false, $context);
        if (!$showJson) {
            return false;
        }
        
        $show = json_decode($showJson, true);
        if (!$show) {
            return false;
        }

        // Determine content type (anime or series)
        $type = 'series';
        $genres = $show['genres'] ?? [];
        
        // Simple heuristic to detect Anime
        if (in_array('Anime', $genres)) {
            $type = 'anime';
        } elseif (isset($show['network']['country']['code']) && $show['network']['country']['code'] === 'JP') {
            $type = 'anime';
        } elseif (isset($show['webChannel']['country']['code']) && $show['webChannel']['country']['code'] === 'JP') {
            $type = 'anime';
        }

        $title = $show['name'] ?? 'Sem título';
        $description = strip_tags($show['summary'] ?? 'Nenhuma sinopse disponível.');
        $description = self::translateToPortuguese($description);
        $releaseYear = !empty($show['premiered']) ? intval(substr($show['premiered'], 0, 4)) : date('Y');
        $runtime = !empty($show['runtime']) ? (int)$show['runtime'] : 45;
        
        // Fallback images if TV Maze doesn't provide them
        $poster = $show['image']['medium'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400&auto=format&fit=crop';
        $banner = $show['image']['original'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200&auto=format&fit=crop';

        // Fetch episode list
        $episodesUrl = "https://api.tvmaze.com/shows/" . intval($tvmazeId) . "/episodes";
        $episodesJson = @file_get_contents($episodesUrl, false, $context);
        $episodesList = $episodesJson ? json_decode($episodesJson, true) : [];
        
        // Filter out episodes without season or number values
        $filteredEpisodes = array_filter($episodesList, function($ep) {
            return isset($ep['season']) && isset($ep['number']);
        });
        
        $totalEpisodes = count($filteredEpisodes);

        try {
            $pdo->beginTransaction();

            $genresStr = !empty($genres) ? implode(', ', $genres) : null;

            // Insert primary item record
            $insertItem = $pdo->prepare("
                INSERT INTO item (tvmaze_id, titulo, tipo, url_poster, url_banner, descricao, ano_lancamento, data_lancamento, total_episodios, duracao_minutos, ts_ultima_sincronizacao, generos)
                VALUES (:tvmaze_id, :titulo, :tipo, :poster, :banner, :descricao, :ano_lancamento, :data_lancamento, :total_episodios, :duracao_minutos, CURRENT_TIMESTAMP, :generos)
                RETURNING id_item
            ");
            $insertItem->execute([
                ':tvmaze_id' => $tvmazeId,
                ':titulo' => $title,
                ':tipo' => $type,
                ':poster' => $poster,
                ':banner' => $banner,
                ':descricao' => $description,
                ':ano_lancamento' => $releaseYear,
                ':data_lancamento' => $show['premiered'] ?? null,
                ':total_episodios' => $totalEpisodes,
                ':duracao_minutos' => $runtime,
                ':generos' => $genresStr
            ]);

            $itemId = $insertItem->fetchColumn();

            // Insert all episodes
            $insertEp = $pdo->prepare("
                INSERT INTO episodio (id_item, numero_temporada, numero_episodio, titulo, data_exibicao, url_imagem, descricao, duracao_minutos, nota)
                VALUES (:id_item, :numero_temporada, :numero_episodio, :titulo, :data_exibicao, :url_imagem, :descricao, :duracao_minutos, :nota)
            ");

            foreach ($filteredEpisodes as $ep) {
                $epImage = $ep['image']['medium'] ?? $ep['image']['original'] ?? '';
                $epDesc = strip_tags($ep['summary'] ?? '');
                if (empty($epDesc)) {
                    $epDesc = 'Nenhuma sinopse disponível.';
                }
                $epRuntime = !empty($ep['runtime']) ? (int)$ep['runtime'] : 45;
                $epRating = $ep['rating']['average'] ?? null;
                $airDate = !empty($ep['airdate']) ? $ep['airdate'] : null;

                $insertEp->execute([
                    ':id_item' => $itemId,
                    ':numero_temporada' => $ep['season'],
                    ':numero_episodio' => $ep['number'],
                    ':titulo' => $ep['name'] ?? ('Episódio ' . $ep['number']),
                    ':data_exibicao' => $airDate,
                    ':url_imagem' => $epImage,
                    ':descricao' => $epDesc,
                    ':duracao_minutos' => $epRuntime,
                    ':nota' => $epRating
                ]);
            }

            $pdo->commit();
            return $itemId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    public static function syncEpisodes($pdo, $itemId, $tvmazeId) {
        $options = [
            'http' => [
                'header' => "User-Agent: TVTimeClone/1.0\r\n"
            ]
        ];
        $context = stream_context_create($options);

        // Fetch show's title, type, and current tmdb_id from local DB
        $stmtLocal = $pdo->prepare("SELECT titulo, tipo, tmdb_id FROM item WHERE id_item = :id_item LIMIT 1");
        $stmtLocal->execute([':id_item' => $itemId]);
        $localItem = $stmtLocal->fetch(\PDO::FETCH_ASSOC);

        $title = $localItem['titulo'] ?? '';
        $type = $localItem['tipo'] ?? 'series';
        $tmdbId = $localItem['tmdb_id'] ?? null;

        // Fetch episodes list from TVMaze
        $episodesUrl = "https://api.tvmaze.com/shows/" . intval($tvmazeId) . "/episodes";
        $episodesJson = @file_get_contents($episodesUrl, false, $context);
        $episodesList = $episodesJson ? json_decode($episodesJson, true) : [];

        $filteredEpisodes = array_filter($episodesList, function($ep) {
            return isset($ep['season']) && isset($ep['number']);
        });

        // Fetch episodes from TMDB as fallback/enrichment source
        $tmdbEpisodes = [];
        if ($type !== 'movie') {
            if (empty($tmdbId)) {
                $searchUrl = "https://api.themoviedb.org/3/search/tv?api_key=1f54bd990f1cdfb230adb312546d765d&query=" . urlencode($title) . "&language=pt-BR";
                $searchJson = @file_get_contents($searchUrl, false, stream_context_create(['http' => ['header' => "User-Agent: CineFio/1.0\r\n"]]));
                if ($searchJson) {
                    $searchResults = json_decode($searchJson, true)['results'] ?? [];
                    if (!empty($searchResults)) {
                        $tmdbId = $searchResults[0]['id'];
                        // Save tmdb_id to item
                        $updateTmdb = $pdo->prepare("UPDATE item SET tmdb_id = :tmdb_id WHERE id_item = :id_item");
                        $updateTmdb->execute([':tmdb_id' => $tmdbId, ':id_item' => $itemId]);
                    }
                }
            }

            if (!empty($tmdbId)) {
                $tmdbEpisodes = \Application\Helper\TmdbHelper::getTvEpisodes((int)$tmdbId);
            }
        }

        // Merge TVMaze and TMDB episodes
        $mergedEpisodes = [];
        foreach ($filteredEpisodes as $ep) {
            $key = $ep['season'] . '_' . $ep['number'];
            $epImage = $ep['image']['medium'] ?? $ep['image']['original'] ?? '';
            $epDesc = strip_tags($ep['summary'] ?? '');
            if (empty($epDesc)) {
                $epDesc = 'Nenhuma sinopse disponível.';
            }
            $epRuntime = !empty($ep['runtime']) ? (int)$ep['runtime'] : 45;
            $epRating = $ep['rating']['average'] ?? null;

            $mergedEpisodes[$key] = [
                'season' => $ep['season'],
                'number' => $ep['number'],
                'title' => $ep['name'] ?? ('Episódio ' . $ep['number']),
                'air_date' => !empty($ep['airdate']) ? $ep['airdate'] : null,
                'image_url' => $epImage,
                'description' => $epDesc,
                'runtime' => $epRuntime,
                'rating' => $epRating
            ];
        }

        foreach ($tmdbEpisodes as $ep) {
            $key = $ep['season_number'] . '_' . $ep['episode_number'];
            $epImage = $ep['still_path'] ? ('https://image.tmdb.org/t/p/w500' . $ep['still_path']) : '';
            $epDesc = trim($ep['overview'] ?? '');
            if (empty($epDesc)) {
                $epDesc = 'Nenhuma sinopse disponível.';
            }
            $epRuntime = !empty($ep['runtime']) ? (int)$ep['runtime'] : 45;
            $epRating = $ep['vote_average'] ?? null;

            if (isset($mergedEpisodes[$key])) {
                if ($mergedEpisodes[$key]['description'] === 'Nenhuma sinopse disponível.' && $epDesc !== 'Nenhuma sinopse disponível.') {
                    $mergedEpisodes[$key]['description'] = $epDesc;
                }
                if (empty($mergedEpisodes[$key]['air_date']) && !empty($ep['air_date'])) {
                    $mergedEpisodes[$key]['air_date'] = $ep['air_date'];
                }
                if ((strpos($mergedEpisodes[$key]['title'], 'Episode') === 0 || strpos($mergedEpisodes[$key]['title'], 'Episódio') === 0) && !empty($ep['name']) && strpos($ep['name'], 'Episode') === false) {
                    $mergedEpisodes[$key]['title'] = $ep['name'];
                }
                if (empty($mergedEpisodes[$key]['image_url']) && !empty($epImage)) {
                    $mergedEpisodes[$key]['image_url'] = $epImage;
                }
            } else {
                $mergedEpisodes[$key] = [
                    'season' => $ep['season_number'],
                    'number' => $ep['episode_number'],
                    'title' => $ep['name'] ?? ('Episódio ' . $ep['episode_number']),
                    'air_date' => !empty($ep['air_date']) ? $ep['air_date'] : null,
                    'image_url' => $epImage,
                    'description' => $epDesc,
                    'runtime' => $epRuntime,
                    'rating' => $epRating
                ];
            }
        }

        $totalEpisodes = count($mergedEpisodes);

        try {
            $pdo->beginTransaction();

            // Fetch show details to update status
            $showUrl = "https://api.tvmaze.com/shows/" . intval($tvmazeId);
            $showJson = @file_get_contents($showUrl, false, $context);
            $status = 'Running';
            if ($showJson) {
                $show = json_decode($showJson, true);
                $status = $show['status'] ?? 'Running';
            }

            $updateItem = $pdo->prepare("
                UPDATE item 
                SET total_episodios = :total_episodios,
                    status = :status,
                    ts_ultima_sincronizacao = CURRENT_TIMESTAMP
                WHERE id_item = :id_item
            ");
            $updateItem->execute([
                ':total_episodios' => $totalEpisodes,
                ':status' => $status,
                ':id_item' => $itemId
            ]);

            // Insert or update all episodes
            $insertEp = $pdo->prepare("
                INSERT INTO episodio (id_item, numero_temporada, numero_episodio, titulo, data_exibicao, url_imagem, descricao, duracao_minutos, nota)
                VALUES (:id_item, :numero_temporada, :numero_episodio, :titulo, :data_exibicao, :url_imagem, :descricao, :duracao_minutos, :nota)
                ON CONFLICT (id_item, numero_temporada, numero_episodio) DO UPDATE SET
                    titulo = EXCLUDED.titulo,
                    data_exibicao = EXCLUDED.data_exibicao,
                    url_imagem = CASE WHEN EXCLUDED.url_imagem <> '' THEN EXCLUDED.url_imagem ELSE episodio.url_imagem END,
                    descricao = CASE WHEN EXCLUDED.descricao <> 'Nenhuma sinopse disponível.' AND EXCLUDED.descricao <> '' THEN EXCLUDED.descricao ELSE episodio.descricao END,
                    duracao_minutos = EXCLUDED.duracao_minutos,
                    nota = EXCLUDED.nota
            ");

            foreach ($mergedEpisodes as $ep) {
                $insertEp->execute([
                    ':id_item' => $itemId,
                    ':numero_temporada' => $ep['season'],
                    ':numero_episodio' => $ep['number'],
                    ':titulo' => $ep['title'],
                    ':data_exibicao' => $ep['air_date'] ?? null,
                    ':url_imagem' => $ep['image_url'],
                    ':descricao' => $ep['description'],
                    ':duracao_minutos' => $ep['runtime'],
                    ':nota' => $ep['rating']
                ]);
            }

            $pdo->commit();
            return true;
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    public static function translateToPortuguese($text) {
        if (empty($text)) return $text;
        
        $englishWords = [' the ', ' of ', ' and ', ' is ', ' that ', ' it ', ' with ', ' as ', ' for ', ' was ', ' on ', ' are '];
        $hasEnglish = false;
        foreach ($englishWords as $word) {
            if (stripos($text, $word) !== false) {
                $hasEnglish = true;
                break;
            }
        }
        
        if (!$hasEnglish) {
            return $text;
        }
        
        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=pt&dt=t&q=" . urlencode($text);
        $json = @file_get_contents($url);
        if (!$json) return $text;
        $result = json_decode($json, true);
        if (!isset($result[0])) return $text;
        
        $translated = "";
        foreach ($result[0] as $sentence) {
            $translated .= $sentence[0] ?? "";
        }
        return $translated;
    }

    public static function getTvmazeIdByTitle(string $title): ?int {
        $url = 'https://api.tvmaze.com/singlesearch/shows?q=' . urlencode($title);
        $opts = ['http' => ['header' => "User-Agent: CineFio/1.0\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return null;
        $show = json_decode($json, true);
        return $show['id'] ?? null;
    }

    public static function getFutureSchedule(string $startDate, string $endDate, int $limit = 160): array {
        $cacheFile = sys_get_temp_dir() . '/cinefio-tvmaze-full-schedule.json';
        $json = is_file($cacheFile) && time() - filemtime($cacheFile) < 21600 ? @file_get_contents($cacheFile) : false;
        if (!$json) {
            $context = stream_context_create(['http' => ['header' => "User-Agent: CineFio/1.0\r\nAccept: application/json\r\n", 'timeout' => 15]]);
            $json = @file_get_contents('https://api.tvmaze.com/schedule/full', false, $context);
            if ($json) @file_put_contents($cacheFile, $json);
        }
        $rows = $json ? json_decode($json, true) : [];
        if (!is_array($rows)) return [];

        $events = [];
        foreach ($rows as $episode) {
            $date = substr((string)($episode['airdate'] ?? $episode['airstamp'] ?? ''), 0, 10);
            if ($date < $startDate || $date > $endDate) continue;
            $show = $episode['_embedded']['show'] ?? $episode['show'] ?? [];
            if (empty($show['id'])) continue;
            $genres = $show['genres'] ?? [];
            $country = $show['network']['country']['code'] ?? $show['webChannel']['country']['code'] ?? '';
            $type = ($country === 'JP' || in_array('Anime', $genres, true)) ? 'anime' : 'series';
            $events[] = [
                'id_item' => null,
                'tvmaze_id' => (int)$show['id'],
                'tmdb_id' => null,
                'mal_id' => null,
                'titulo' => $show['name'] ?? 'Sem título',
                'tipo' => $type,
                'url_poster' => $show['image']['medium'] ?? '',
                'url_banner' => $show['image']['original'] ?? '',
                'ano_lancamento' => !empty($show['premiered']) ? (int)substr($show['premiered'], 0, 4) : (int)substr($date, 0, 4),
                'data_evento' => $date,
                'id_episodio' => null,
                'numero_temporada' => isset($episode['season']) ? (int)$episode['season'] : null,
                'numero_episodio' => isset($episode['number']) ? (int)$episode['number'] : null,
                'titulo_episodio' => $episode['name'] ?? null,
                'status_acompanhamento' => null,
                'assistido' => false,
                'origem' => 'tvmaze',
            ];
            if (count($events) >= $limit) break;
        }
        return $events;
    }
}
