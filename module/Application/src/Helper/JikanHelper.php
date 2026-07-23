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

    public static function importAnimeFromMal(\PDO $pdo, int $malId): int|false {
        $stmt = $pdo->prepare("SELECT id_item FROM item WHERE mal_id = :mal_id LIMIT 1");
        $stmt->execute([':mal_id' => $malId]);
        $existing = $stmt->fetch();
        if ($existing) return (int)$existing['id_item'];

        $anime = self::getAnimeDetail($malId);
        if (!$anime) return false;

        $title       = $anime['title_english'] ?? $anime['title'] ?? 'Sem titulo';
        $description = trim($anime['synopsis'] ?? 'Nenhuma sinopse disponivel.');
        $releaseYear = isset($anime['aired']['prop']['from']['year']) ? (int)$anime['aired']['prop']['from']['year'] : (int)date('Y');
        $episodes    = isset($anime['episodes']) ? (int)$anime['episodes'] : 12;
        $images      = $anime['images']['jpg'] ?? [];
        $poster      = $images['large_image_url'] ?? $images['image_url'] ?? 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
        $banner      = $images['large_image_url'] ?? 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';

        try {
            $stmt = $pdo->prepare("
                INSERT INTO item (mal_id, title, type, poster_url, banner_url, description, release_year, total_episodes, runtime_minutes)
                VALUES (:mal_id, :title, 'anime', :poster, :banner, :description, :release_year, :episodes, 24)
                RETURNING id_item
            ");
            $stmt->execute([':mal_id' => $malId, ':title' => $title, ':poster' => $poster,
                ':banner' => $banner, ':description' => $description, ':release_year' => $releaseYear, ':episodes' => $episodes]);
            $row = $stmt->fetch();
            return $row ? (int)$row['id_item'] : false;
        } catch (\Exception $e) {
            return false;
        }
    }
}