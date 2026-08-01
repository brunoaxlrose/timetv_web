export type Item = {
  id_item: number | null;
  tvmaze_id?: number | null;
  tmdb_id?: number | null;
  mal_id?: number | null;
  titulo: string;
  tipo: 'movie' | 'series' | 'anime' | string;
  url_poster: string;
  url_banner?: string;
  descricao?: string;
  ano_lancamento?: number;
  data_lancamento?: string;
  status_acompanhamento?: string | null;
  eh_favorito?: boolean;
  progress_percent?: number;
  progress?: {
    total_count: number;
    watched_count: number;
  };
  next_episode?: Episode | null;
  nota?: number | null;
  comentario?: string | null;
  quantidade_reassistida?: number;
  generos?: string;
  provedores_streaming?: string;
  ts_atualizacao?: string;
  collection_created_at?: string;
  avaliacao_media?: number;
};

export type Episode = {
  id_episodio: number;
  numero_temporada: number;
  numero_episodio: number;
  titulo: string;
  data_exibicao?: string | null;
  duracao_minutos?: number | null;
  assistido?: boolean;
  quantidade_reassistida?: number;
};

export type UserList = {
  id_lista: number;
  nome: string;
  item_count: number;
  cover_poster_url?: string | null;
};

export type CastMember = {
  person_id?: number;
  source?: 'tmdb' | 'tvmaze' | 'jikan' | string;
  name: string;
  character?: string | null;
  image_url?: string | null;
};

export type Person = {
  person_id: number;
  source: string;
  name: string;
  image_url?: string | null;
  biography?: string;
  birthday?: string | null;
  place_of_birth?: string | null;
  department?: string | null;
};
