<?php

namespace Application\Helper;

/**
 * TmdbHelper
 * Fallback de busca via API do TMDB quando o TVmaze nao retorna filmes.
 */
class TmdbHelper {

    private const API_KEY  = '1f54bd990f1cdfb230adb312546d765d';
    private const BASE_URL = 'https://api.themoviedb.org/3';
    private const IMG      = 'https://image.tmdb.org/t/p/';

    private static function getCacheDir(): string {
        $dir = dirname(__DIR__, 3) . '/data/cache/tmdb';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private static function getCacheFile(string $key): string {
        return self::getCacheDir() . '/' . md5($key) . '.json';
    }

    private static function readCache(string $key, int $ttlSeconds): ?array {
        $file = self::getCacheFile($key);
        if (!is_file($file)) {
            return null;
        }
        if ((time() - filemtime($file)) > $ttlSeconds) {
            return null;
        }
        $json = @file_get_contents($file);
        return $json ? json_decode($json, true) : null;
    }

    private static function writeCache(string $key, array $data): void {
        $file = self::getCacheFile($key);
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function fetchJson(string $url): ?array {
        $cacheTtl = 0;
        if (strpos($url, '/trending/all/week') !== false) {
            $cacheTtl = 900;
        } elseif (strpos($url, '/movie/upcoming') !== false) {
            $cacheTtl = 1800;
        } elseif (strpos($url, '/movie/') !== false || strpos($url, '/tv/') !== false) {
            $cacheTtl = 21600;
        }

        if ($cacheTtl > 0) {
            $cached = self::readCache($url, $cacheTtl);
            if ($cached !== null) {
                return $cached;
            }
        }

        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        $decoded = $json ? json_decode($json, true) : null;

        if (is_array($decoded) && $cacheTtl > 0) {
            self::writeCache($url, $decoded);
        }

        return $decoded;
    }

    private static function resolveMovieReleaseDate(int $tmdbId, ?array $movie = null): ?string {
        $movie = $movie ?: self::getMovieDetail($tmdbId);
        $fallbackDate = !empty($movie['release_date']) ? $movie['release_date'] : null;

        $data = self::fetchJson(self::BASE_URL . '/movie/' . $tmdbId . '/release_dates?api_key=' . self::API_KEY);
        if (!$data || empty($data['results']) || !is_array($data['results'])) {
            return $fallbackDate;
        }

        $countryPriority = ['BR', 'PT', 'US'];
        $typePriority = [3, 2, 4, 5, 1, 6];

        foreach ($countryPriority as $country) {
            foreach ($data['results'] as $result) {
                if (($result['iso_3166_1'] ?? '') !== $country || empty($result['release_dates'])) {
                    continue;
                }

                usort($result['release_dates'], function($a, $b) use ($typePriority) {
                    $aRank = array_search((int)($a['type'] ?? 999), $typePriority, true);
                    $bRank = array_search((int)($b['type'] ?? 999), $typePriority, true);
                    $aRank = $aRank === false ? 999 : $aRank;
                    $bRank = $bRank === false ? 999 : $bRank;
                    return $aRank <=> $bRank;
                });

                foreach ($result['release_dates'] as $release) {
                    $value = $release['release_date'] ?? '';
                    if (!empty($value)) {
                        return substr($value, 0, 10);
                    }
                }
            }
        }

        return $fallbackDate;
    }

    public static function searchMulti(string $query, int $limit = 20): array {
        $url = self::BASE_URL . '/search/multi?api_key=' . self::API_KEY
            . '&query=' . urlencode($query)
            . '&language=pt-BR&include_adult=false&page=1';
        $data = self::fetchJson($url);
        if (!$data) return [];

        $results = $data['results'] ?? [];
        return self::formatTmdbResults($results, $limit);
    }

    public static function getPopular(int $limit = 12): array {
        $url = self::BASE_URL . '/trending/all/week?api_key=' . self::API_KEY . '&language=pt-BR&page=1';
        $data = self::fetchJson($url);
        if (!$data) return [];

        $results = $data['results'] ?? [];
        return self::formatTmdbResults($results, $limit);
    }

    public static function getUpcoming(int $limit = 12): array {
        // Adiciona region=BR para trazer datas de lançamento do Brasil
        $url = self::BASE_URL . '/movie/upcoming?api_key=' . self::API_KEY . '&language=pt-BR&page=1&region=BR';
        $data = self::fetchJson($url);
        if (!$data) return [];

        $results = $data['results'] ?? [];
        
        // Filtra para manter apenas filmes com data de lançamento futura (em relação a hoje)
        $today = date('Y-m-d');
        $upcomingResults = [];
        foreach ($results as $r) {
            $releaseDate = $r['release_date'] ?? '';
            if ($releaseDate && $releaseDate > $today) {
                $upcomingResults[] = $r;
            }
        }

        // Se o filtro de data futura remover muitos filmes, podemos afrouxar ou usar os resultados originais
        if (count($upcomingResults) < 4) {
            $upcomingResults = $results; // Fallback se a API retornar poucos futuros reais no BR
        }

        return self::formatTmdbResults($upcomingResults, $limit);
    }

    private static function formatTmdbResults(array $results, int $limit): array {
        $items   = [];
        foreach ($results as $r) {
            if (count($items) >= $limit) break;
            $mediaType = $r['media_type'] ?? '';
            if (empty($mediaType)) {
                $mediaType = isset($r['title']) ? 'movie' : 'tv';
            }
            if ($mediaType === 'person') continue;
            $overview = trim($r['overview'] ?? '');
            if (empty($overview)) {
                $overview = 'Nenhuma sinopse disponível.';
            }

            $poster = $r['poster_path']
                ? self::IMG . 'w500' . $r['poster_path']
                : 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
            $banner = $r['backdrop_path']
                ? self::IMG . 'original' . $r['backdrop_path']
                : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';

            $type = 'series';
            if ($mediaType === 'movie') {
                $type = 'movie';
            } elseif ($mediaType === 'tv') {
                $origins = $r['origin_country'] ?? [];
                $genres  = $r['genre_ids'] ?? [];
                $type = (in_array('JP', $origins) && in_array(16, $genres)) ? 'anime' : 'series';
            }

            $title = $r['title'] ?? $r['name'] ?? 'Sem titulo';
            $releaseDate = $r['release_date'] ?? $r['first_air_date'] ?? '';
            if ($type === 'movie' && !empty($r['id'])) {
                $resolvedReleaseDate = self::resolveMovieReleaseDate((int)$r['id']);
                if (!empty($resolvedReleaseDate)) {
                    $releaseDate = $resolvedReleaseDate;
                }
            }
            $releaseYear = $releaseDate ? (int)substr($releaseDate, 0, 4) : (int)date('Y');

            $items[] = [
                'id_item'      => null,
                'tvmaze_id'    => null,
                'tmdb_id'      => $r['id'],
                'title'        => $title,
                'type'         => $type,
                'poster_url'   => $poster,
                'banner_url'   => $banner,
                'description'  => $overview,
                'release_year' => $releaseYear,
                'release_date' => $releaseDate,
                'track_status' => null,
                'source'       => 'tmdb'
            ];
        }
        return $items;
    }

    public static function getMovieDetail(int $tmdbId): ?array {
        $url  = self::BASE_URL . '/movie/' . $tmdbId . '?api_key=' . self::API_KEY . '&language=pt-BR';
        return self::fetchJson($url);
    }

    public static function importMovieFromTmdb(\PDO $pdo, int $tmdbId): int|false {
        $stmt = $pdo->prepare("SELECT id_item FROM item WHERE tmdb_id = :tmdb_id LIMIT 1");
        $stmt->execute([':tmdb_id' => $tmdbId]);
        $existing = $stmt->fetch();
        if ($existing) {
            self::syncMovieMetadata($pdo, (int)$existing['id_item'], $tmdbId);
            return (int)$existing['id_item'];
        }

        $movie = self::getMovieDetail($tmdbId);
        if (!$movie) return false;

        $title        = $movie['title'] ?? 'Sem titulo';
        $description  = trim($movie['overview'] ?? 'Nenhuma sinopse disponivel.');
        $resolvedReleaseDate = self::resolveMovieReleaseDate($tmdbId, $movie);
        $releaseYear  = $resolvedReleaseDate ? (int)substr($resolvedReleaseDate, 0, 4) : (int)date('Y');
        $runtime      = (int)($movie['runtime'] ?? 120);
        $poster       = $movie['poster_path']   ? self::IMG . 'w500'    . $movie['poster_path']    : 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
        $banner       = $movie['backdrop_path'] ? self::IMG . 'original' . $movie['backdrop_path'] : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';

        $status = 'Running';
        if (!empty($resolvedReleaseDate) && $resolvedReleaseDate > date('Y-m-d')) {
            $status = 'Upcoming';
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO item (tmdb_id, title, type, poster_url, banner_url, description, release_year, release_date, total_episodes, runtime_minutes, status)
                VALUES (:tmdb_id, :title, 'movie', :poster, :banner, :description, :year, :release_date, 1, :runtime, :status)
                RETURNING id_item
            ");
            $stmt->execute([
                ':tmdb_id' => $tmdbId, 
                ':title' => $title, 
                ':poster' => $poster,
                ':banner' => $banner, 
                ':description' => $description, 
                ':year' => $releaseYear, 
                ':release_date' => $resolvedReleaseDate,
                ':runtime' => $runtime,
                ':status' => $status
            ]);
            $row = $stmt->fetch();
            return $row ? (int)$row['id_item'] : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function syncMovieMetadata(\PDO $pdo, int $itemId, int $tmdbId): bool {
        $movie = self::getMovieDetail($tmdbId);
        if (!$movie) {
            return false;
        }

        $title = $movie['title'] ?? 'Sem titulo';
        $description = trim($movie['overview'] ?? 'Nenhuma sinopse disponivel.');
        $releaseDate = self::resolveMovieReleaseDate($tmdbId, $movie);
        $releaseYear = $releaseDate ? (int)substr($releaseDate, 0, 4) : (int)date('Y');
        $runtime = (int)($movie['runtime'] ?? 120);
        $poster = $movie['poster_path'] ? self::IMG . 'w500' . $movie['poster_path'] : 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
        $banner = $movie['backdrop_path'] ? self::IMG . 'original' . $movie['backdrop_path'] : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';
        $status = (!empty($releaseDate) && $releaseDate > date('Y-m-d')) ? 'Upcoming' : 'Running';

        try {
            $stmt = $pdo->prepare("
                UPDATE item
                SET title = :title,
                    poster_url = :poster,
                    banner_url = :banner,
                    description = :description,
                    release_year = :year,
                    release_date = :release_date,
                    runtime_minutes = :runtime,
                    status = :status
                WHERE id_item = :item_id
            ");
            $stmt->execute([
                ':title' => $title,
                ':poster' => $poster,
                ':banner' => $banner,
                ':description' => $description,
                ':year' => $releaseYear,
                ':release_date' => $releaseDate,
                ':runtime' => $runtime,
                ':status' => $status,
                ':item_id' => $itemId
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function getTvDetail(int $tmdbId): ?array {
        $url  = self::BASE_URL . '/tv/' . $tmdbId . '?api_key=' . self::API_KEY . '&language=pt-BR';
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        return $json ? json_decode($json, true) : null;
    }

    public static function getTvEpisodes(int $tmdbId): array {
        $apiKey = self::API_KEY;
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        
        $detailUrl = self::BASE_URL . "/tv/{$tmdbId}?api_key={$apiKey}&language=pt-BR";
        $detailJson = @file_get_contents($detailUrl, false, stream_context_create($opts));
        if (!$detailJson) return [];
        $detail = json_decode($detailJson, true);
        
        $seasons = $detail['seasons'] ?? [];
        $allEpisodes = [];
        
        foreach ($seasons as $s) {
            $seasonNum = $s['season_number'] ?? null;
            if ($seasonNum === null || $seasonNum === 0) continue;
            
            $seasonUrl = self::BASE_URL . "/tv/{$tmdbId}/season/{$seasonNum}?api_key={$apiKey}&language=pt-BR";
            $seasonJson = @file_get_contents($seasonUrl, false, stream_context_create($opts));
            if (!$seasonJson) continue;
            
            $seasonData = json_decode($seasonJson, true);
            $episodes = $seasonData['episodes'] ?? [];
            foreach ($episodes as $ep) {
                $allEpisodes[] = $ep;
            }
        }
        return $allEpisodes;
    }
}
