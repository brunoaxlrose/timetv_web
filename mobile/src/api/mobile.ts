import { apiRequest } from './client';
import { CastMember, Episode, Item, Person, UserList } from '../types';

export type EventoCalendario = {
  id_item: number | null;
  tmdb_id?: number | null;
  tvmaze_id?: number | null;
  mal_id?: number | null;
  titulo: string;
  tipo: Item['tipo'];
  url_poster?: string;
  url_banner?: string;
  ano_lancamento?: number;
  data_lancamento?: string;
  provedores_streaming?: string;
  id_episodio?: number | null;
  numero_temporada?: number | null;
  numero_episodio?: number | null;
  titulo_episodio?: string | null;
  data_evento: string;
  status_acompanhamento?: string | null;
  assistido: boolean;
};

export function getDashboard(pagina = 1, mes?: string) {
  const params = new URLSearchParams({ pagina: String(pagina) });
  if (mes) params.set('mes', mes);
  return apiRequest<{
    continuar_assistindo: Array<{ item: Item; next_episode: Episode; progress?: { total_count: number; watched_count: number } }>;
    listas: UserList[];
    quero_ver: Item[];
    proximos: EventoCalendario[];
    calendario: EventoCalendario[];
    populares: Item[];
    em_breve: Item[];
    pagina: number;
    tem_mais_populares: boolean;
    tem_mais_em_breve: boolean;
  }>(`/api/v1/mobile/dashboard?${params.toString()}`);
}

export function getCollection(statusFilter = '', types = 'movie,series,anime', sortBy = 'last_watched') {
  const params = new URLSearchParams({
    types,
    status_filter: statusFilter,
    sort_by: sortBy,
  });
  return apiRequest<{
    items: Item[];
    groups: {
      assistindo: Item[];
      up_to_date: Item[];
      concluido: Item[];
      quero_ver: Item[];
      em_pausa: Item[];
      abandonado: Item[];
      reassistindo: Item[];
    };
    status_filter: string;
  }>(`/api/v1/mobile/collection?${params.toString()}`);
}

export function searchCatalog(query: string) {
  return apiRequest<{ query: string; popular: Item[]; items: Item[]; recent_searches: string[] }>(
    `/api/v1/mobile/search?search=${encodeURIComponent(query)}`,
  );
}

export function getLists() {
  return apiRequest<{ lists: UserList[] }>('/api/v1/mobile/lists');
}

export function getListItems(listId: number) {
  return apiRequest<{ list_id: number; items: Item[] }>(
    `/api/v1/mobile/lists/items?list_id=${listId}`,
  );
}

export function getProfile() {
  return apiRequest<{
    stats: Record<string, number>;
    time: { days: number; hours: number; minutes: number };
    history: Array<Record<string, unknown>>;
    favorites: Item[];
    reviews: Array<{
      id_item: number;
      titulo: string;
      tipo: Item['tipo'];
      url_poster: string;
      ano_lancamento?: number;
      nota: number;
      comentario: string;
      reviewed_at: string;
    }>;
  }>('/api/v1/mobile/profile');
}

export function getDetail(itemId: number) {
  return apiRequest<{
    item: Item;
    episodes: Episode[];
    progress: { total_count: number; watched_count: number };
    next_unwatched: Episode | null;
    released: boolean;
    lists: Array<UserList & { has_item: boolean }>;
    cast: CastMember[];
  }>(`/api/v1/mobile/detail?id=${itemId}`);
}

export function getDetailByItem(item: Item, rapido = false) {
  const params = new URLSearchParams();
  if (item.id_item) params.set('id', String(item.id_item));
  if (item.tvmaze_id) params.set('tvmaze_id', String(item.tvmaze_id));
  if (item.tmdb_id) params.set('tmdb_id', String(item.tmdb_id));
  if (item.mal_id) params.set('mal_id', String(item.mal_id));
  params.set('tipo', item.tipo || 'series');
  if (item.titulo) params.set('titulo', item.titulo);
  if (item.ano_lancamento) params.set('ano_lancamento', String(item.ano_lancamento));
  if (item.data_lancamento) params.set('data_lancamento', item.data_lancamento);
  if (item.url_poster) params.set('url_poster', item.url_poster);
  if (item.url_banner) params.set('url_banner', item.url_banner);
  if (rapido) params.set('rapido', '1');
  return apiRequest<{
    item: Item;
    episodes: Episode[];
    progress: { total_count: number; watched_count: number };
    next_unwatched: Episode | null;
    released: boolean;
    lists: Array<UserList & { has_item: boolean }>;
    cast: CastMember[];
    reviews: Array<{
      id_usuario: number;
      nome_usuario: string;
      url_avatar?: string;
      nota: number;
      comentario: string;
      reviewed_at: string;
    }>;
    recommendations: Item[];
  }>(`/api/v1/mobile/detail?${params.toString()}`);
}

export function getPersonCredits(personId: number, source: string) {
  const params = new URLSearchParams({ person_id: String(personId), source });
  return apiRequest<{ person: Person; credits: Item[] }>(`/api/v1/mobile/person?${params.toString()}`);
}

export function createList(name: string) {
  return apiRequest<{ list_id: number; lists: UserList[] }>('/api/v1/mobile/lists/create', {
    method: 'POST',
    body: JSON.stringify({ name }),
  });
}

export function deleteList(listId: number) {
  return apiRequest<{ lists: UserList[] }>('/api/v1/mobile/lists/delete', {
    method: 'POST',
    body: JSON.stringify({ list_id: listId }),
  });
}

export function renameList(listId: number, name: string) {
  return apiRequest<{ lists: UserList[] }>('/api/v1/mobile/lists/rename', {
    method: 'POST',
    body: JSON.stringify({ list_id: listId, name }),
  });
}

export function addItemToList(listId: number, item: Item) {
  return apiRequest<{ list_id: number; item_id: number; items: Item[] }>('/api/v1/mobile/lists/add', {
    method: 'POST',
    body: JSON.stringify({ list_id: listId, ...itemPayload(item) }),
  });
}

export function toggleFavorite(item: Item, isFavorite?: boolean) {
  return apiRequest<{ item_id: number; eh_favorito: boolean }>('/api/v1/mobile/favorite/toggle', {
    method: 'POST',
    body: JSON.stringify({ ...itemPayload(item), eh_favorito: isFavorite }),
  });
}

export function trackItem(item: Item, status = 'assistindo', action: 'add' | 'remove' | 'rewatch' = 'add') {
  return apiRequest<{ item_id: number; status: string }>('/api/v1/mobile/track', {
    method: 'POST',
    body: JSON.stringify({ ...itemPayload(item), status, action }),
  });
}

export function rewatchEpisode(episodeId: number) {
  return apiRequest<{ episode_id: number; quantidade_reassistida: number }>('/api/v1/mobile/episodes/rewatch', {
    method: 'POST',
    body: JSON.stringify({ episode_id: episodeId }),
  });
}

export function markEpisodes(payload: {
  item_id: number;
  episode_id?: number;
  numero_temporada?: number;
  mode: 'single' | 'preceding' | 'season' | 'all';
}) {
  return apiRequest<{
    item_id: number;
    episodes: Episode[];
    progress: { total_count: number; watched_count: number };
    next_unwatched: Episode | null;
  }>('/api/v1/mobile/episodes/mark', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function saveReview(item: Item, nota: number | null, comentario: string) {
  return apiRequest<{
    item_id: number;
    nota: number | null;
    comentario: string | null;
    avaliacao: { id_usuario: number; nome_usuario: string; url_avatar?: string; nota: number; comentario: string; reviewed_at: string } | null;
    total_avaliacoes: number;
  }>('/api/v1/mobile/review', {
    method: 'POST',
    body: JSON.stringify({ ...itemPayload(item), nota, comentario }),
  });
}

function itemPayload(item: Item) {
  return {
    item_id: item.id_item,
    tvmaze_id: item.tvmaze_id,
    tmdb_id: item.tmdb_id,
    mal_id: item.mal_id,
    tipo: item.tipo,
    titulo: item.titulo,
    ano_lancamento: item.ano_lancamento,
    data_lancamento: item.data_lancamento,
    url_poster: item.url_poster,
    url_banner: item.url_banner,
    descricao: item.descricao,
  };
}
