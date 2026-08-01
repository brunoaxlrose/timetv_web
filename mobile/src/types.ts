export type Item = {
  id_item: number | null;
  tvmaze_id?: number | null;
  tmdb_id?: number | null;
  mal_id?: number | null;
  title: string;
  type: 'movie' | 'series' | 'anime' | string;
  poster_url: string;
  banner_url?: string;
  description?: string;
  release_year?: number;
  release_date?: string;
  track_status?: string | null;
  is_favorite?: boolean;
  progress_percent?: number;
  progress?: {
    total_count: number;
    watched_count: number;
  };
  next_episode?: Episode | null;
  rating?: number | null;
  comment?: string | null;
  rewatch_count?: number;
  genres?: string;
  watch_providers?: string;
};

export type Episode = {
  id_episodio: number;
  season_number: number;
  episode_number: number;
  title: string;
  air_date?: string | null;
  runtime_minutes?: number | null;
  watched?: boolean;
  rewatch_count?: number;
};

export type UserList = {
  id_lista: number;
  nome: string;
  item_count: number;
  cover_poster_url?: string | null;
};

export type CastMember = {
  name: string;
  character?: string | null;
  image_url?: string | null;
};
