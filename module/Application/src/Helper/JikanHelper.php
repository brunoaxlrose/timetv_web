<?php

namespace Application\Helper;

class JikanHelper {
    private const BASE_URL = 'https://api.jikan.moe/v4';

    public static function searchAnime(string $query, int $limit = 10): array {
        $url = self::BASE_URL . '/anime?q=' . urlencode($query) . '&limit=' . $limit . '&sfw=true';
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return [];

        $data = json_decode($json, true);
        $results = $data['data'] ?? [];
        $items = [];

        foreach ($results as $r) {
            $overview = trim($r['synopsis'] ?? '');
            if (empty($overview)) continue;

            $images = $r['images']['jpg'] ?? [];
            $poster = $images['large_image_url'] ?? $images['image_url'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
            $banner = $images['large_image_url'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';
            $title = $r['title_english'] ?? $r['title'] ?? 'Anime sem titulo';
            $releaseYear = isset($r['aired']['prop']['from']['year']) ? (int)$r['aired']['prop']['from']['year'] : (int)date('Y');

            $items[] = [
                'id_item'       => null,
                'tvmaze_id'     => null,
                'tmdb_id'       => null,
                'mal_id'        => $r['mal_id'],
                'title'         => $title,
                'type'          => 'anime',
                'poster_url'    => $poster,
                'banner_url'    => $banner,
                'description'   => $overview,
                'release_year'  => $releaseYear,
                'track_status'  => null,
                'source'        => 'mal'
            ];
        }
        return $items;
    }

    public static function getAnimeDetail(int $malId): ?array {
        $url = self::BASE_URL . '/anime/' . $malId;
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return null;
        $res = json_decode($json, true);
        return $res['data'] ?? null;
    }

    public static function getCharacters(int $malId, int $limit = 12): array {
        $url = self::BASE_URL . '/anime/' . $malId . '/characters';
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 6]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return [];

        $res = json_decode($json, true);
        $rows = $res['data'] ?? [];
        if (!is_array($rows)) return [];

        $cast = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $character = $row['character'] ?? [];
            $images = $character['images']['jpg'] ?? [];
            $cast[] = [
                'name' => $character['name'] ?? 'Sem nome',
                'character' => $row['role'] ?? '',
                'image_url' => $images['image_url'] ?? null,
            ];
        }

        return $cast;
    }

    public static function getEpisodes(int $malId, int $maxPages = 8): array {
        $episodes = [];
        $page = 1;
        $hasNextPage = true;
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];

        while ($hasNextPage && $page <= $maxPages) {
            $url = self::BASE_URL . '/anime/' . $malId . '/episodes?page=' . $page;
            $json = @file_get_contents($url, false, stream_context_create($opts));
            if (!$json) break;

            $res = json_decode($json, true);
            $rows = $res['data'] ?? [];
            if (!is_array($rows) || empty($rows)) break;

            foreach ($rows as $row) {
                $episodeNumber = (int)($row['mal_id'] ?? 0);
                if ($episodeNumber <= 0) {
                    continue;
                }

                $episodes[] = [
                    'season_number' => 1,
                    'episode_number' => $episodeNumber,
                    'title' => $row['title'] ?? ('Episodio ' . $episodeNumber),
                    'air_date' => self::dateOnly($row['aired'] ?? null),
                    'runtime_minutes' => 24,
                    'rating' => isset($row['score']) ? (float)$row['score'] : null,
                    'description' => '',
                ];
            }

            $pagination = $res['pagination'] ?? [];
            $hasNextPage = !empty($pagination['has_next_page']);
            $page++;
        }

        return $episodes;
    }

    public static function syncEpisodes(\PDO $pdo, int $itemId, int $malId, int $totalEpisodes = 0): bool {
        $episodes = self::getEpisodes($malId);

        if ($totalEpisodes > count($episodes)) {
            $existingNumbers = [];
            foreach ($episodes as $episode) {
                $existingNumbers[(int)$episode['episode_number']] = true;
            }

            for ($i = 1; $i <= $totalEpisodes; $i++) {
                if (isset($existingNumbers[$i])) {
                    continue;
                }

                $episodes[] = [
                    'season_number' => 1,
                    'episode_number' => $i,
                    'title' => 'Episodio ' . $i,
                    'air_date' => null,
                    'runtime_minutes' => 24,
                    'rating' => null,
                    'description' => '',
                ];
            }

            usort($episodes, fn($a, $b) => (int)$a['episode_number'] <=> (int)$b['episode_number']);
        }

        if (empty($episodes)) {
            return false;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO episodio (id_item, season_number, episode_number, title, air_date, description, runtime_minutes, rating)
                VALUES (:id_item, :season_number, :episode_number, :title, :air_date, :description, :runtime_minutes, :rating)
                ON CONFLICT (id_item, season_number, episode_number) DO UPDATE SET
                    title = EXCLUDED.title,
                    air_date = COALESCE(EXCLUDED.air_date, episodio.air_date),
                    description = COALESCE(NULLIF(EXCLUDED.description, ''), episodio.description),
                    runtime_minutes = EXCLUDED.runtime_minutes,
                    rating = EXCLUDED.rating
            ");

            foreach ($episodes as $episode) {
                $stmt->execute([
                    ':id_item' => $itemId,
                    ':season_number' => (int)$episode['season_number'],
                    ':episode_number' => (int)$episode['episode_number'],
                    ':title' => $episode['title'],
                    ':air_date' => $episode['air_date'],
                    ':description' => $episode['description'],
                    ':runtime_minutes' => (int)$episode['runtime_minutes'],
                    ':rating' => $episode['rating'],
                ]);
            }

            $update = $pdo->prepare("UPDATE item SET total_episodes = :total, last_sync = CURRENT_TIMESTAMP WHERE id_item = :item_id");
            $update->execute([':total' => max($totalEpisodes, count($episodes)), ':item_id' => $itemId]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    public static function importAnimeFromMal(\PDO $pdo, int $malId): int|false {
        $stmt = $pdo->prepare("SELECT id_item FROM item WHERE mal_id = :mal_id LIMIT 1");
        $stmt->execute([':mal_id' => $malId]);
        $existing = $stmt->fetch();
        if ($existing) {
            self::syncEpisodes($pdo, (int)$existing['id_item'], $malId);
            return (int)$existing['id_item'];
        }

        $anime = self::getAnimeDetail($malId);
        if (!$anime) return false;

        $title       = $anime['title_english'] ?? $anime['title'] ?? 'Sem titulo';
        $description = trim($anime['synopsis'] ?? 'Nenhuma sinopse disponivel.');
        $releaseYear = isset($anime['aired']['prop']['from']['year']) ? (int)$anime['aired']['prop']['from']['year'] : (int)date('Y');
        $episodes    = isset($anime['episodes']) ? (int)$anime['episodes'] : 12;
        $images      = $anime['images']['jpg'] ?? [];
        $poster      = $images['large_image_url'] ?? $images['image_url'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
        $banner      = $images['large_image_url'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';

        $genres = [];
        if (!empty($anime['genres']) && is_array($anime['genres'])) {
            foreach ($anime['genres'] as $g) {
                if (!empty($g['name'])) {
                    $genres[] = $g['name'];
                }
            }
        }
        if (!empty($anime['themes']) && is_array($anime['themes'])) {
            foreach ($anime['themes'] as $t) {
                if (!empty($t['name'])) {
                    $genres[] = $t['name'];
                }
            }
        }
        $genresStr = !empty($genres) ? implode(', ', $genres) : null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO item (mal_id, title, type, poster_url, banner_url, description, release_year, total_episodes, runtime_minutes, genres)
                VALUES (:mal_id, :title, 'anime', :poster, :banner, :description, :release_year, :episodes, 24, :genres)
                RETURNING id_item
            ");
            $stmt->execute([
                ':mal_id' => $malId,
                ':title' => $title,
                ':poster' => $poster,
                ':banner' => $banner,
                ':description' => $description,
                ':release_year' => $releaseYear,
                ':episodes' => $episodes,
                ':genres' => $genresStr
            ]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }

            $itemId = (int)$row['id_item'];
            self::syncEpisodes($pdo, $itemId, $malId, $episodes);
            return $itemId;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function dateOnly(?string $value): ?string {
        if (empty($value)) {
            return null;
        }

        $date = substr($value, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    public static function getRecommendations(int $malId, int $limit = 8): array {
        $url = self::BASE_URL . '/anime/' . $malId . '/recommendations';
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return [];

        $data = json_decode($json, true);
        $results = $data['data'] ?? [];
        $items = [];

        foreach (array_slice($results, 0, $limit) as $r) {
            $entry = $r['entry'] ?? null;
            if (!$entry) continue;

            $images = $entry['images']['jpg'] ?? [];
            $poster = $images['large_image_url'] ?? $images['image_url'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
            $banner = $images['large_image_url'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';

            $items[] = [
                'id_item'       => null,
                'tvmaze_id'     => null,
                'tmdb_id'       => null,
                'mal_id'        => $entry['mal_id'],
                'title'         => $entry['title'] ?? 'Anime sem titulo',
                'type'          => 'anime',
                'poster_url'    => $poster,
                'banner_url'    => $banner,
                'description'   => '',
                'release_year'  => (int)date('Y'),
                'track_status'  => null,
                'source'        => 'mal'
            ];
        }
        return $items;
    }
}
