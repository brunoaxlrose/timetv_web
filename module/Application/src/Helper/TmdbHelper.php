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

    public static function searchMulti(string $query, int $limit = 20): array {
        $url = self::BASE_URL . '/search/multi?api_key=' . self::API_KEY
            . '&query=' . urlencode($query)
            . '&language=pt-BR&include_adult=false&page=1';

        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return [];

        $results = json_decode($json, true)['results'] ?? [];
        return self::formatTmdbResults($results, $limit);
    }

    public static function getPopular(int $limit = 12): array {
        $url = self::BASE_URL . '/trending/all/week?api_key=' . self::API_KEY . '&language=pt-BR&page=1';
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return [];

        $results = json_decode($json, true)['results'] ?? [];
        return self::formatTmdbResults($results, $limit);
    }

    public static function getUpcoming(int $limit = 12): array {
        // Adiciona region=BR para trazer datas de lançamento do Brasil
        $url = self::BASE_URL . '/movie/upcoming?api_key=' . self::API_KEY . '&language=pt-BR&page=1&region=BR';
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if (!$json) return [];

        $results = json_decode($json, true)['results'] ?? [];
        
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

            $title       = $r['title'] ?? $r['name'] ?? 'Sem titulo';
            $releaseDate = $r['release_date'] ?? $r['first_air_date'] ?? '';
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
        $opts = ['http' => ['header' => "User-Agent: TimeView/1.0\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        return $json ? json_decode($json, true) : null;
    }

    public static function importMovieFromTmdb(\PDO $pdo, int $tmdbId): int|false {
        $stmt = $pdo->prepare("SELECT id_item FROM item WHERE tmdb_id = :tmdb_id LIMIT 1");
        $stmt->execute([':tmdb_id' => $tmdbId]);
        $existing = $stmt->fetch();
        if ($existing) return (int)$existing['id_item'];

        $movie = self::getMovieDetail($tmdbId);
        if (!$movie) return false;

        $title        = $movie['title'] ?? 'Sem titulo';
        $description  = trim($movie['overview'] ?? 'Nenhuma sinopse disponivel.');
        $releaseYear  = $movie['release_date'] ? (int)substr($movie['release_date'], 0, 4) : (int)date('Y');
        $runtime      = (int)($movie['runtime'] ?? 120);
        $poster       = $movie['poster_path']   ? self::IMG . 'w500'    . $movie['poster_path']    : 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
        $banner       = $movie['backdrop_path'] ? self::IMG . 'original' . $movie['backdrop_path'] : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';

        $status = 'Running';
        if (!empty($movie['release_date']) && $movie['release_date'] > date('Y-m-d')) {
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
                ':release_date' => $movie['release_date'] ?? null,
                ':runtime' => $runtime,
                ':status' => $status
            ]);
            $row = $stmt->fetch();
            return $row ? (int)$row['id_item'] : false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
