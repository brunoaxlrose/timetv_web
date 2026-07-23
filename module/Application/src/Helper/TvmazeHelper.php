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
        $releaseYear = isset($show['premiered']) ? intval(substr($show['premiered'], 0, 4)) : date('Y');
        $runtime = $show['runtime'] ?? 45;
        
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

            // Insert primary item record
            $insertItem = $pdo->prepare("
                INSERT INTO item (tvmaze_id, title, type, poster_url, banner_url, description, release_year, total_episodes, runtime_minutes)
                VALUES (:tvmaze_id, :title, :type, :poster, :banner, :description, :release_year, :total_episodes, :runtime)
                RETURNING id_item
            ");
            $insertItem->execute([
                ':tvmaze_id' => $tvmazeId,
                ':title' => $title,
                ':type' => $type,
                ':poster' => $poster,
                ':banner' => $banner,
                ':description' => $description,
                ':release_year' => $releaseYear,
                ':total_episodes' => $totalEpisodes,
                ':runtime' => $runtime
            ]);

            $itemId = $insertItem->fetchColumn();

            // Insert all episodes
            $insertEp = $pdo->prepare("
                INSERT INTO episodio (id_item, season_number, episode_number, title, air_date, image_url, description, runtime_minutes, rating)
                VALUES (:id_item, :season_number, :episode_number, :title, :air_date, :image_url, :description, :runtime_minutes, :rating)
            ");

            foreach ($filteredEpisodes as $ep) {
                $epImage = $ep['image']['medium'] ?? $ep['image']['original'] ?? '';
                $epDesc = strip_tags($ep['summary'] ?? '');
                if (empty($epDesc)) {
                    $epDesc = 'Nenhuma sinopse disponível.';
                }
                $epRuntime = $ep['runtime'] ?? 45;
                $epRating = $ep['rating']['average'] ?? null;

                $insertEp->execute([
                    ':id_item' => $itemId,
                    ':season_number' => $ep['season'],
                    ':episode_number' => $ep['number'],
                    ':title' => $ep['name'] ?? ('Episódio ' . $ep['number']),
                    ':air_date' => $ep['airdate'] ?? null,
                    ':image_url' => $epImage,
                    ':description' => $epDesc,
                    ':runtime_minutes' => $epRuntime,
                    ':rating' => $epRating
                ]);
            }

            $pdo->commit();
            return $itemId;
        } catch (\PDOException $e) {
            $pdo->rollBack();
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
}
