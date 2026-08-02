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
        } elseif (strpos($url, '/discover/') !== false) {
            $cacheTtl = 21600;
        } elseif (strpos($url, '/movie/') !== false || strpos($url, '/tv/') !== false) {
            $cacheTtl = 21600;
        }

        if ($cacheTtl > 0) {
            $cached = self::readCache($url, $cacheTtl);
            if ($cached !== null) {
                return $cached;
            }
        }

        $opts = ['http' => ['header' => "User-Agent: CineFio/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
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

    public static function getPopular(int $limit = 12, int $page = 1): array {
        $page = max(1, $page);
        $url = self::BASE_URL . '/trending/all/week?api_key=' . self::API_KEY . '&language=pt-BR&page=' . $page;
        $data = self::fetchJson($url);
        if (!$data) return [];

        $today = date('Y-m-d');
        $results = array_values(array_filter($data['results'] ?? [], static function(array $result) use ($today): bool {
            $releaseDate = $result['release_date'] ?? $result['first_air_date'] ?? '';
            return $releaseDate === '' || $releaseDate <= $today;
        }));
        return self::formatTmdbResults($results, $limit);
    }

    public static function getUpcoming(int $limit = 12, int $page = 1): array {
        // Adiciona region=BR para trazer datas de lançamento do Brasil
        $page = max(1, $page);
        $url = self::BASE_URL . '/movie/upcoming?api_key=' . self::API_KEY . '&language=pt-BR&page=' . $page . '&region=BR';
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

    public static function getPopularMovies(int $limit = 12, int $page = 1): array {
        $page = max(1, $page);
        $today = date('Y-m-d');
        $url = self::BASE_URL . '/discover/movie?api_key=' . self::API_KEY
            . '&language=pt-BR&region=BR&sort_by=popularity.desc&include_adult=false&page=' . $page
            . '&primary_release_date.lte=' . urlencode($today);
        $data = self::fetchJson($url);
        if (!$data || empty($data['results']) || !is_array($data['results'])) {
            return [];
        }
        return self::formatTmdbResults($data['results'], $limit);
    }

    public static function getPopularSeriesOnly(int $limit = 12, int $page = 1): array {
        $page = max(1, $page);
        $today = date('Y-m-d');
        $url = self::BASE_URL . '/discover/tv?api_key=' . self::API_KEY
            . '&language=pt-BR&sort_by=popularity.desc&include_adult=false&page=' . $page
            . '&first_air_date.lte=' . urlencode($today)
            . '&without_genres=16,10766';
        $data = self::fetchJson($url);
        if (!$data || empty($data['results']) || !is_array($data['results'])) {
            return [];
        }

        $items = [];
        foreach ($data['results'] as $result) {
            if (count($items) >= $limit) {
                break;
            }
            $result['media_type'] = 'tv';
            $genreIds = $result['genre_ids'] ?? [];
            if (in_array(10766, $genreIds, true) || in_array(16, $genreIds, true)) {
                continue;
            }
            $formatted = self::formatTmdbResults([$result], 1);
            if (!$formatted) {
                continue;
            }
            if (($formatted[0]['tipo'] ?? '') !== 'series') {
                continue;
            }
            $items[] = $formatted[0];
        }

        return $items;
    }

    public static function getCalendarReleases(string $startDate, string $endDate, int $limit = 80): array {
        $sources = [
            ['path' => 'movie', 'start' => 'primary_release_date.gte', 'end' => 'primary_release_date.lte'],
            ['path' => 'tv', 'start' => 'first_air_date.gte', 'end' => 'first_air_date.lte'],
        ];
        $events = [];

        foreach ($sources as $source) {
            $sourceEvents = [];
            $sourceLimit = max(20, (int)ceil($limit / count($sources)));
            for ($page = 1; $page <= 5 && count($sourceEvents) < $sourceLimit; $page++) {
                $url = self::BASE_URL . '/discover/' . $source['path'] . '?api_key=' . self::API_KEY
                    . '&language=pt-BR&region=BR&sort_by=popularity.desc&include_adult=false&page=' . $page
                    . '&' . $source['start'] . '=' . urlencode($startDate)
                    . '&' . $source['end'] . '=' . urlencode($endDate);
                $data = self::fetchJson($url);
                $results = $data['results'] ?? [];
                if (!$results) break;
                foreach ($results as $result) {
                    if (count($sourceEvents) >= $sourceLimit) break;
                    $date = $result['release_date'] ?? $result['first_air_date'] ?? '';
                    if ($date === '') continue;
                    $isMovie = $source['path'] === 'movie';
                    $origins = $result['origin_country'] ?? [];
                    $genres = $result['genre_ids'] ?? [];
                    $type = $isMovie ? 'movie' : ((in_array('JP', $origins, true) && in_array(16, $genres, true)) ? 'anime' : 'series');
                    $sourceEvents[] = [
                    'id_item' => null,
                    'tmdb_id' => (int)($result['id'] ?? 0),
                    'tvmaze_id' => null,
                    'mal_id' => null,
                    'titulo' => $result['title'] ?? $result['name'] ?? 'Sem título',
                    'tipo' => $type,
                    'url_poster' => !empty($result['poster_path']) ? self::IMG . 'w500' . $result['poster_path'] : '',
                    'url_banner' => !empty($result['backdrop_path']) ? self::IMG . 'original' . $result['backdrop_path'] : '',
                    'ano_lancamento' => (int)substr($date, 0, 4),
                    'data_lancamento' => $date,
                    'data_evento' => $date,
                    'id_episodio' => null,
                    'numero_temporada' => null,
                    'numero_episodio' => null,
                    'titulo_episodio' => null,
                    'status_acompanhamento' => null,
                    'assistido' => false,
                    'origem' => 'tmdb',
                    ];
                }
            }
            $events = array_merge($events, $sourceEvents);
        }

        usort($events, static fn(array $a, array $b): int => strcmp($a['data_evento'], $b['data_evento']));
        return array_slice($events, 0, $limit);
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
                'titulo' => $title,
                'tipo' => $type,
                'url_poster' => $poster,
                'url_banner' => $banner,
                'descricao' => $overview,
                'ano_lancamento' => $releaseYear,
                'data_lancamento' => $releaseDate,
                'status_acompanhamento' => null,
                'origem' => 'tmdb'
            ];
        }
        return $items;
    }

    public static function getMovieDetail(int $tmdbId): ?array {
        $url  = self::BASE_URL . '/movie/' . $tmdbId . '?api_key=' . self::API_KEY . '&language=pt-BR';
        return self::fetchJson($url);
    }

    public static function getCredits(string $type, int $tmdbId, int $limit = 12): array {
        $mediaType = $type === 'movie' ? 'movie' : 'tv';
        $url = self::BASE_URL . '/' . $mediaType . '/' . $tmdbId . '/credits?api_key=' . self::API_KEY . '&language=pt-BR';
        $data = self::fetchJson($url);
        if (!$data || empty($data['cast']) || !is_array($data['cast'])) {
            return [];
        }

        $cast = [];
        foreach (array_slice($data['cast'], 0, $limit) as $person) {
            $cast[] = [
                'person_id' => (int)($person['id'] ?? 0),
                'source' => 'tmdb',
                'name' => $person['name'] ?? 'Sem nome',
                'character' => $person['character'] ?? '',
                'image_url' => !empty($person['profile_path']) ? self::IMG . 'w185' . $person['profile_path'] : null,
            ];
        }

        return $cast;
    }

    public static function getPersonCredits(int $personId, int $limit = 60): ?array {
        $url = self::BASE_URL . '/person/' . $personId . '?api_key=' . self::API_KEY
            . '&language=pt-BR&append_to_response=combined_credits';
        $data = self::fetchJson($url);
        if (!$data || empty($data['id'])) return null;

        $credits = [];
        $seen = [];
        foreach (($data['combined_credits']['cast'] ?? []) as $credit) {
            $mediaType = $credit['media_type'] ?? '';
            if (!in_array($mediaType, ['movie', 'tv'], true) || empty($credit['id'])) continue;
            $character = strtolower(trim((string)($credit['character'] ?? '')));
            if ($character !== '' && preg_match('/\b(self|himself|herself|guest|ele mesmo|ela mesma)\b/i', $character)) continue;
            $key = $mediaType . ':' . $credit['id'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $date = $credit['release_date'] ?? $credit['first_air_date'] ?? '';
            $origins = $credit['origin_country'] ?? [];
            $genres = $credit['genre_ids'] ?? [];
            $type = $mediaType === 'movie' ? 'movie' : ((in_array('JP', $origins, true) && in_array(16, $genres, true)) ? 'anime' : 'series');
            $credits[] = [
                'id_item' => null,
                'tmdb_id' => (int)$credit['id'],
                'tvmaze_id' => null,
                'mal_id' => null,
                'titulo' => $credit['title'] ?? $credit['name'] ?? 'Sem título',
                'tipo' => $type,
                'url_poster' => !empty($credit['poster_path']) ? self::IMG . 'w500' . $credit['poster_path'] : '',
                'url_banner' => !empty($credit['backdrop_path']) ? self::IMG . 'original' . $credit['backdrop_path'] : '',
                'descricao' => trim($credit['overview'] ?? ''),
                'ano_lancamento' => $date ? (int)substr($date, 0, 4) : null,
                'data_lancamento' => $date ?: null,
                'personagem' => $credit['character'] ?? '',
                'popularidade' => (float)($credit['popularity'] ?? 0),
            ];
        }
        usort($credits, static fn(array $a, array $b): int => ($b['popularidade'] <=> $a['popularidade']) ?: (($b['ano_lancamento'] ?? 0) <=> ($a['ano_lancamento'] ?? 0)));

        return [
            'person' => [
                'person_id' => (int)$data['id'],
                'source' => 'tmdb',
                'name' => $data['name'] ?? 'Sem nome',
                'image_url' => !empty($data['profile_path']) ? self::IMG . 'h632' . $data['profile_path'] : null,
                'biography' => trim($data['biography'] ?? ''),
                'birthday' => $data['birthday'] ?? null,
                'place_of_birth' => $data['place_of_birth'] ?? null,
                'department' => $data['known_for_department'] ?? null,
            ],
            'credits' => array_slice($credits, 0, $limit),
        ];
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

        $genres = [];
        if (!empty($movie['genres']) && is_array($movie['genres'])) {
            foreach ($movie['genres'] as $g) {
                if (!empty($g['name'])) {
                    $genres[] = $g['name'];
                }
            }
        }
        $genresStr = !empty($genres) ? implode(', ', $genres) : null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO item (tmdb_id, titulo, tipo, url_poster, url_banner, descricao, ano_lancamento, data_lancamento, total_episodios, duracao_minutos, status, generos, ts_ultima_sincronizacao)
                VALUES (:tmdb_id, :titulo, 'movie', :poster, :banner, :descricao, :ano_lancamento, :data_lancamento, 1, :runtime, :status, :genres, CURRENT_TIMESTAMP)
                RETURNING id_item
            ");
            $stmt->execute([
                ':tmdb_id' => $tmdbId,
                ':titulo' => $title,
                ':poster' => $poster,
                ':banner' => $banner,
                ':descricao' => $description,
                ':ano_lancamento' => $releaseYear,
                ':data_lancamento' => $resolvedReleaseDate,
                ':runtime' => $runtime,
                ':status' => $status,
                ':genres' => $genresStr
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

        $genres = [];
        if (!empty($movie['genres']) && is_array($movie['genres'])) {
            foreach ($movie['genres'] as $g) {
                if (!empty($g['name'])) {
                    $genres[] = $g['name'];
                }
            }
        }
        $genresStr = !empty($genres) ? implode(', ', $genres) : null;

        try {
            $stmt = $pdo->prepare("
                UPDATE item
                SET titulo = :title,
                    url_poster = :poster,
                    url_banner = :banner,
                    descricao = :description,
                    ano_lancamento = :ano_lancamento,
                    data_lancamento = :data_lancamento,
                    duracao_minutos = :runtime,
                    status = :status,
                    generos = :genres
                WHERE id_item = :item_id
            ");
            $stmt->execute([
                ':title' => $title,
                ':poster' => $poster,
                ':banner' => $banner,
                ':description' => $description,
                ':ano_lancamento' => $releaseYear,
                ':data_lancamento' => $releaseDate,
                ':runtime' => $runtime,
                ':status' => $status,
                ':genres' => $genresStr,
                ':item_id' => $itemId
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function getTvDetail(int $tmdbId): ?array {
        $url  = self::BASE_URL . '/tv/' . $tmdbId . '?api_key=' . self::API_KEY . '&language=pt-BR';
        $opts = ['http' => ['header' => "User-Agent: CineFio/1.0\r\n", 'timeout' => 8]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        return $json ? json_decode($json, true) : null;
    }

    public static function getTvEpisodes(int $tmdbId): array {
        $apiKey = self::API_KEY;
        $opts = ['http' => ['header' => "User-Agent: CineFio/1.0\r\nAccept: application/json\r\n", 'timeout' => 8]];
        
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

    public static function importTvFromTmdb(\PDO $pdo, int $tmdbId, string $fallbackType = 'series'): int|false {
        $stmt = $pdo->prepare("SELECT id_item FROM item WHERE tmdb_id = :tmdb_id LIMIT 1");
        $stmt->execute([':tmdb_id' => $tmdbId]);
        $existing = $stmt->fetch();
        if ($existing) {
            self::syncTvMetadataAndEpisodes($pdo, (int)$existing['id_item'], $tmdbId, $fallbackType);
            return (int)$existing['id_item'];
        }

        $tv = self::getTvDetail($tmdbId);
        if (!$tv) return false;

        $title = $tv['name'] ?? $tv['original_name'] ?? 'Sem titulo';
        $description = trim($tv['overview'] ?? 'Nenhuma sinopse disponivel.');
        $releaseDate = $tv['first_air_date'] ?? null;
        $releaseYear = $releaseDate ? (int)substr($releaseDate, 0, 4) : (int)date('Y');
        $runtimeList = $tv['episode_run_time'] ?? [];
        $runtime = !empty($runtimeList[0]) ? (int)$runtimeList[0] : 45;
        $poster = !empty($tv['poster_path']) ? self::IMG . 'w500' . $tv['poster_path'] : 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
        $banner = !empty($tv['backdrop_path']) ? self::IMG . 'original' . $tv['backdrop_path'] : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';
        $genres = [];
        foreach (($tv['genres'] ?? []) as $g) {
            if (!empty($g['name'])) {
                $genres[] = $g['name'];
            }
        }
        $genresStr = !empty($genres) ? implode(', ', $genres) : null;
        $origins = $tv['origin_country'] ?? [];
        $type = (in_array('JP', $origins, true) && in_array('Animation', $genres, true)) ? 'anime' : $fallbackType;
        $type = $type === 'anime' ? 'anime' : 'series';

        try {
            $stmt = $pdo->prepare("
                INSERT INTO item (tmdb_id, titulo, tipo, url_poster, url_banner, descricao, ano_lancamento, data_lancamento, total_episodios, duracao_minutos, status, generos, ts_ultima_sincronizacao)
                VALUES (:tmdb_id, :titulo, :tipo, :poster, :banner, :descricao, :ano_lancamento, :data_lancamento, :total_episodios, :runtime, :status, :generos, CURRENT_TIMESTAMP)
                RETURNING id_item
            ");
            $stmt->execute([
                ':tmdb_id' => $tmdbId,
                ':titulo' => $title,
                ':tipo' => $type,
                ':poster' => $poster,
                ':banner' => $banner,
                ':descricao' => $description,
                ':ano_lancamento' => $releaseYear,
                ':data_lancamento' => $releaseDate,
                ':total_episodios' => (int)($tv['number_of_episodes'] ?? 0),
                ':runtime' => $runtime,
                ':status' => $tv['status'] ?? 'Running',
                ':generos' => $genresStr,
            ]);
            $row = $stmt->fetch();
            if (!$row) return false;

            $itemId = (int)$row['id_item'];
            self::syncTvMetadataAndEpisodes($pdo, $itemId, $tmdbId, $type);
            return $itemId;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function syncTvMetadataAndEpisodes(\PDO $pdo, int $itemId, int $tmdbId, string $fallbackType = 'series'): bool {
        $tv = self::getTvDetail($tmdbId);
        if (!$tv) return false;

        $episodes = self::getTvEpisodes($tmdbId);
        $title = $tv['name'] ?? $tv['original_name'] ?? 'Sem titulo';
        $description = trim($tv['overview'] ?? 'Nenhuma sinopse disponivel.');
        $releaseDate = $tv['first_air_date'] ?? null;
        $releaseYear = $releaseDate ? (int)substr($releaseDate, 0, 4) : (int)date('Y');
        $runtimeList = $tv['episode_run_time'] ?? [];
        $runtime = !empty($runtimeList[0]) ? (int)$runtimeList[0] : 45;
        $poster = !empty($tv['poster_path']) ? self::IMG . 'w500' . $tv['poster_path'] : 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?q=80&w=400';
        $banner = !empty($tv['backdrop_path']) ? self::IMG . 'original' . $tv['backdrop_path'] : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1200';
        $genres = [];
        foreach (($tv['genres'] ?? []) as $g) {
            if (!empty($g['name'])) {
                $genres[] = $g['name'];
            }
        }
        $genresStr = !empty($genres) ? implode(', ', $genres) : null;
        $origins = $tv['origin_country'] ?? [];
        $type = (in_array('JP', $origins, true) && in_array('Animation', $genres, true)) ? 'anime' : $fallbackType;
        $type = $type === 'anime' ? 'anime' : 'series';

        try {
            $stmt = $pdo->prepare("
                UPDATE item
                SET titulo = :titulo,
                    tipo = :tipo,
                    url_poster = :poster,
                    url_banner = :banner,
                    descricao = :descricao,
                    ano_lancamento = :ano_lancamento,
                    data_lancamento = :data_lancamento,
                    total_episodios = :total_episodios,
                    duracao_minutos = :runtime,
                    status = :status,
                    generos = :generos,
                    ts_ultima_sincronizacao = CURRENT_TIMESTAMP
                WHERE id_item = :item_id
            ");
            $stmt->execute([
                ':titulo' => $title,
                ':tipo' => $type,
                ':poster' => $poster,
                ':banner' => $banner,
                ':descricao' => $description,
                ':ano_lancamento' => $releaseYear,
                ':data_lancamento' => $releaseDate,
                ':total_episodios' => count($episodes) ?: (int)($tv['number_of_episodes'] ?? 0),
                ':runtime' => $runtime,
                ':status' => $tv['status'] ?? 'Running',
                ':generos' => $genresStr,
                ':item_id' => $itemId,
            ]);

            $stmtEpisode = $pdo->prepare("
                INSERT INTO episodio (id_item, numero_temporada, numero_episodio, titulo, data_exibicao, url_imagem, descricao, duracao_minutos, nota)
                VALUES (:id_item, :numero_temporada, :numero_episodio, :titulo, :data_exibicao, :url_imagem, :descricao, :duracao_minutos, :nota)
                ON CONFLICT (id_item, numero_temporada, numero_episodio) DO UPDATE SET
                    titulo = EXCLUDED.titulo,
                    data_exibicao = EXCLUDED.data_exibicao,
                    url_imagem = EXCLUDED.url_imagem,
                    descricao = EXCLUDED.descricao,
                    duracao_minutos = EXCLUDED.duracao_minutos,
                    nota = EXCLUDED.nota
            ");

            foreach ($episodes as $ep) {
                if (!isset($ep['season_number'], $ep['episode_number'])) {
                    continue;
                }
                $stmtEpisode->execute([
                    ':id_item' => $itemId,
                    ':numero_temporada' => (int)$ep['season_number'],
                    ':numero_episodio' => (int)$ep['episode_number'],
                    ':titulo' => $ep['name'] ?? ('Episodio ' . (int)$ep['episode_number']),
                    ':data_exibicao' => !empty($ep['air_date']) ? $ep['air_date'] : null,
                    ':url_imagem' => !empty($ep['still_path']) ? self::IMG . 'w500' . $ep['still_path'] : null,
                    ':descricao' => trim($ep['overview'] ?? ''),
                    ':duracao_minutos' => (int)($ep['runtime'] ?? $runtime),
                    ':nota' => isset($ep['vote_average']) ? (float)$ep['vote_average'] : null,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function getRecommendations(string $type, int $tmdbId, int $limit = 8): array {
        $mediaType = $type === 'movie' ? 'movie' : 'tv';
        $url = self::BASE_URL . '/' . $mediaType . '/' . $tmdbId . '/recommendations?api_key=' . self::API_KEY . '&language=pt-BR';
        $data = self::fetchJson($url);
        if (!$data || empty($data['results'])) {
            $url = self::BASE_URL . '/' . $mediaType . '/' . $tmdbId . '/similar?api_key=' . self::API_KEY . '&language=pt-BR';
            $data = self::fetchJson($url);
        }
        if (!$data || empty($data['results']) || !is_array($data['results'])) {
            return [];
        }
        return self::formatTmdbResults($data['results'], $limit);
    }
}
