import type { ApiResponse } from '../api/client';

export type OfflineMutationKind =
  | 'favorite'
  | 'track'
  | 'episode_mark'
  | 'episode_rewatch'
  | 'review'
  | 'list_create'
  | 'list_rename'
  | 'list_delete'
  | 'list_add'
  | 'notification_read'
  | 'feedback';

export type ProjectionMutation = {
  kind: OfflineMutationKind;
  body: Record<string, unknown>;
  tempListId?: number;
};

export function projectOfflineMutations<T>(
  path: string,
  response: ApiResponse<T>,
  mutations: ProjectionMutation[],
): ApiResponse<T> {
  if (!response.data || !mutations.length) return response;
  const cloned = JSON.parse(JSON.stringify(response)) as ApiResponse<T>;

  for (const mutation of mutations) {
    applyMutation(path, cloned.data as unknown, mutation);
  }
  return cloned;
}

function applyMutation(path: string, root: unknown, mutation: ProjectionMutation) {
  const body = mutation.body;

  if (mutation.kind === 'favorite') {
    walk(root, (value) => {
      if (matchesItem(value, body)) value.eh_favorito = Boolean(body.eh_favorito);
    });
    return;
  }

  if (mutation.kind === 'track') {
    walk(root, (value) => {
      if (matchesItem(value, body)) {
        const action = String(body.action || 'add');
        value.status_acompanhamento = action === 'remove' ? null : body.status;
      }
    });
    return;
  }

  if (mutation.kind === 'review') {
    walk(root, (value) => {
      if (matchesItem(value, body)) {
        value.nota = body.nota;
        value.comentario = body.comentario;
      }
    });
    return;
  }

  if (mutation.kind === 'episode_mark') {
    walk(root, (value) => {
      if (!Array.isArray(value.episodes)) return;
      const episodes = value.episodes.filter(isRecord);
      const target = episodes.find((episode) => Number(episode.id_episodio) === Number(body.episode_id));
      for (const episode of episodes) {
        if (shouldMarkEpisode(episode, target, body)) episode.assistido = true;
      }
      const watched = episodes.filter((episode) => episode.assistido).length;
      value.progress = { total_count: episodes.length, watched_count: watched };
      value.next_unwatched = episodes.find((episode) => !episode.assistido) || null;
    });
    return;
  }

  if (mutation.kind === 'episode_rewatch') {
    walk(root, (value) => {
      if (Number(value.id_episodio) === Number(body.episode_id)) {
        value.quantidade_reassistida = Number(value.quantidade_reassistida || 0) + 1;
      }
    });
    return;
  }

  if (mutation.kind === 'notification_read') {
    walk(root, (value) => {
      if (Array.isArray(value.notifications)) value.notifications = [];
      if ('count' in value) value.count = 0;
    });
    return;
  }

  applyListMutation(path, root, mutation);
}

function applyListMutation(path: string, root: unknown, mutation: ProjectionMutation) {
  const body = mutation.body;
  const listId = Number(body.list_id ?? mutation.tempListId ?? 0);
  walk(root, (value) => {
    if (!Array.isArray(value.lists) && !Array.isArray(value.listas)) return;
    const key = Array.isArray(value.lists) ? 'lists' : 'listas';
    const lists = value[key] as Array<Record<string, unknown>>;

    if (mutation.kind === 'list_create' && !lists.some((list) => Number(list.id_lista) === listId)) {
      lists.unshift({ id_lista: listId, nome: String(body.name || 'Nova lista'), item_count: 0 });
    } else if (mutation.kind === 'list_rename') {
      const list = lists.find((candidate) => Number(candidate.id_lista) === listId);
      if (list) list.nome = String(body.name || list.nome);
    } else if (mutation.kind === 'list_delete') {
      value[key] = lists.filter((candidate) => Number(candidate.id_lista) !== listId);
    } else if (mutation.kind === 'list_add') {
      const list = lists.find((candidate) => Number(candidate.id_lista) === listId);
      if (list) {
        list.has_item = true;
        list.item_count = Number(list.item_count || 0) + 1;
      }
    }
  });

  if (mutation.kind === 'list_add' && path.includes('/lists/items')) {
    walk(root, (value) => {
      if (Number(value.list_id) !== listId || !Array.isArray(value.items)) return;
      if (!value.items.some((item) => isRecord(item) && matchesItem(item, body))) {
        value.items.unshift(itemFromBody(body));
      }
    });
  }
}

function shouldMarkEpisode(
  episode: Record<string, unknown>,
  target: Record<string, unknown> | undefined,
  body: Record<string, unknown>,
) {
  const mode = String(body.mode || 'single');
  if (mode === 'all') return true;
  if (mode === 'season') return Number(episode.numero_temporada) === Number(body.numero_temporada);
  if (mode === 'single') return Number(episode.id_episodio) === Number(body.episode_id);
  if (!target) return false;
  const season = Number(episode.numero_temporada);
  const targetSeason = Number(target.numero_temporada);
  return season < targetSeason
    || (season === targetSeason && Number(episode.numero_episodio) <= Number(target.numero_episodio));
}

function matchesItem(value: Record<string, unknown>, body: Record<string, unknown>) {
  const pairs = [
    ['id_item', body.item_id ?? body.id_item],
    ['tmdb_id', body.tmdb_id],
    ['tvmaze_id', body.tvmaze_id],
    ['mal_id', body.mal_id],
  ] as const;
  return pairs.some(([key, expected]) => Number(expected) > 0 && Number(value[key]) === Number(expected));
}

function itemFromBody(body: Record<string, unknown>) {
  return {
    id_item: body.item_id ?? body.id_item ?? null,
    tmdb_id: body.tmdb_id ?? null,
    tvmaze_id: body.tvmaze_id ?? null,
    mal_id: body.mal_id ?? null,
    titulo: body.titulo ?? '',
    tipo: body.tipo ?? 'series',
    url_poster: body.url_poster ?? '',
    url_banner: body.url_banner ?? '',
    ano_lancamento: body.ano_lancamento ?? null,
    data_lancamento: body.data_lancamento ?? null,
  };
}

function walk(value: unknown, visitor: (record: Record<string, unknown>) => void) {
  if (Array.isArray(value)) {
    value.forEach((entry) => walk(entry, visitor));
    return;
  }
  if (!isRecord(value)) return;
  visitor(value);
  Object.values(value).forEach((entry) => walk(entry, visitor));
}

function isRecord(value: unknown): value is Record<string, any> {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}
