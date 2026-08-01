import { apiRequest } from './client';

type MarkEpisodeWatchedResponse = {
  episode_id: number;
  watched: boolean;
};

export function markEpisodeWatched(episodeId: number) {
  return apiRequest<MarkEpisodeWatchedResponse>('/api/v1/episodes/watched', {
    method: 'POST',
    body: JSON.stringify({
      episode_id: episodeId,
    }),
  });
}
