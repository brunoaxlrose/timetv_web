import { apiRequest } from './client';
import { CastMember, Episode, Item, UserList } from '../types';

export function getDashboard() {
  return apiRequest<{
    continue_watching: Array<{ item: Item; next_episode: Episode }>;
    lists: UserList[];
    plan_to_watch: Item[];
    popular: Item[];
    upcoming: Item[];
  }>('/api/v1/mobile/dashboard');
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
      watching: Item[];
      up_to_date: Item[];
      completed: Item[];
      plan_to_watch: Item[];
      paused: Item[];
      abandoned: Item[];
      rewatching: Item[];
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
      title: string;
      type: Item['type'];
      poster_url: string;
      release_year?: number;
      rating: number;
      comment: string;
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

export function getDetailByItem(item: Item) {
  const params = new URLSearchParams();
  if (item.id_item) params.set('id', String(item.id_item));
  if (item.tvmaze_id) params.set('tvmaze_id', String(item.tvmaze_id));
  if (item.tmdb_id) params.set('tmdb_id', String(item.tmdb_id));
  if (item.mal_id) params.set('mal_id', String(item.mal_id));
  params.set('type', item.type || 'series');
  if (item.title) params.set('title', item.title);
  if (item.release_year) params.set('release_year', String(item.release_year));
  if (item.release_date) params.set('release_date', item.release_date);
  if (item.poster_url) params.set('poster_url', item.poster_url);
  if (item.banner_url) params.set('banner_url', item.banner_url);
  return apiRequest<{
    item: Item;
    episodes: Episode[];
    progress: { total_count: number; watched_count: number };
    next_unwatched: Episode | null;
    released: boolean;
    lists: Array<UserList & { has_item: boolean }>;
    cast: CastMember[];
    reviews: Array<{
      user_name: string;
      avatar_url?: string;
      rating: number;
      comment: string;
      reviewed_at: string;
    }>;
    recommendations: Item[];
  }>(`/api/v1/mobile/detail?${params.toString()}`);
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
  return apiRequest<{ item_id: number; is_favorite: boolean }>('/api/v1/mobile/favorite/toggle', {
    method: 'POST',
    body: JSON.stringify({ ...itemPayload(item), is_favorite: isFavorite }),
  });
}

export function trackItem(item: Item, status = 'watching', action: 'add' | 'remove' | 'rewatch' = 'add') {
  return apiRequest<{ item_id: number; status: string }>('/api/v1/mobile/track', {
    method: 'POST',
    body: JSON.stringify({ ...itemPayload(item), status, action }),
  });
}

export function rewatchEpisode(episodeId: number) {
  return apiRequest<{ episode_id: number; rewatch_count: number }>('/api/v1/mobile/episodes/rewatch', {
    method: 'POST',
    body: JSON.stringify({ episode_id: episodeId }),
  });
}

export function markEpisodes(payload: {
  item_id: number;
  episode_id?: number;
  season_number?: number;
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

export function saveReview(item: Item, rating: number | null, comment: string) {
  return apiRequest<{ item_id: number; rating: number | null; comment: string | null }>('/api/v1/mobile/review', {
    method: 'POST',
    body: JSON.stringify({ ...itemPayload(item), rating, comment }),
  });
}

function itemPayload(item: Item) {
  return {
    item_id: item.id_item,
    tvmaze_id: item.tvmaze_id,
    tmdb_id: item.tmdb_id,
    mal_id: item.mal_id,
    type: item.type,
    title: item.title,
    release_year: item.release_year,
    release_date: item.release_date,
    poster_url: item.poster_url,
    banner_url: item.banner_url,
    description: item.description,
  };
}
