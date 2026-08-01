import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Image, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { addItemToList, getDetailByItem, markEpisodes, saveReview, toggleFavorite, trackItem } from '../api/mobile';
import { ConfirmModal } from '../components/ConfirmModal';
import { Skeleton } from '../components/Skeleton';
import { useToast } from '../components/Toast';
import { colors } from '../theme/colors';
import { CastMember, Episode, Item, UserList } from '../types';

type SelectableList = UserList & { has_item?: boolean };
type DetailTab = 'about' | 'episodes';

export function DetailScreen({ item, onBack, onSelectItem }: { item: Item; onBack: () => void; onSelectItem?: (item: Item) => void }) {
  const [detail, setDetail] = useState<Awaited<ReturnType<typeof getDetailByItem>>['data'] | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<DetailTab>('about');
  const [favorite, setFavorite] = useState(!!item.is_favorite);
  const [rating, setRating] = useState(Number(item.rating || 0));
  const [comment, setComment] = useState(item.comment || '');
  const [listOpen, setListOpen] = useState(false);
  const [confirmRemoveFavorite, setConfirmRemoveFavorite] = useState(false);
  const [saving, setSaving] = useState(false);
  const [favoriteSaving, setFavoriteSaving] = useState(false);
  const [detailError, setDetailError] = useState('');
  const { showToast } = useToast();

  async function load() {
    setLoading(true);
    setDetailError('');
    try {
      const response = await getDetailByItem(item);
      setDetail(response.data);
      setActiveTab(response.data?.item?.type !== 'movie' && response.data?.episodes?.length ? 'episodes' : 'about');
      if (response.data?.item) {
        setFavorite(!!response.data.item.is_favorite);
        setRating(Number(response.data.item.rating || 0));
        setComment(response.data.item.comment || '');
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel carregar este titulo.';
      setDetail(null);
      setDetailError(message);
      showToast(message, 'error');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    setActiveTab('about');
    load();
  }, [item.id_item, item.tmdb_id, item.tvmaze_id, item.mal_id]);

  const displayItem = detail?.item || item;
  const released = detail?.released ?? isReleased(displayItem.release_date);
  const isMovie = displayItem.type === 'movie';
  const nextEpisode = detail?.next_unwatched || null;
  const hasEpisodes = !!detail?.episodes?.length && !!displayItem.id_item && !isMovie;
  const canUseItemActions = !!(displayItem.id_item || displayItem.tmdb_id || displayItem.tvmaze_id || displayItem.mal_id);
  const watchedCount = Number(detail?.progress?.watched_count || 0);
  const totalCount = Number(detail?.progress?.total_count || 0);
  const isWatched = isMovie ? displayItem.track_status === 'completed' : totalCount > 0 && watchedCount >= totalCount;
  const canRewatch = isMovie && released && isWatched;
  const hasExistingReview = !!Number(displayItem.rating || 0) && !!String(displayItem.comment || '').trim();
  const mainButtonLabel = !released
    ? availableLabel(displayItem.release_date)
    : isMovie && isWatched
      ? 'Filme assistido'
      : isMovie
        ? 'Marcar filme como assistido'
        : nextEpisode
          ? `Marcar T${nextEpisode.season_number}E${nextEpisode.episode_number} como visto`
          : 'Tudo assistido';

  async function doFavorite() {
    if (favoriteSaving) return;
    if (favorite) {
      setConfirmRemoveFavorite(true);
      return;
    }

    setFavoriteSaving(true);
    try {
      const response = await toggleFavorite(displayItem, true);
      const nextFavorite = !!response.data?.is_favorite;
      const itemId = response.data?.item_id ?? displayItem.id_item;
      setFavorite(nextFavorite);
      setDetail((current) => current ? {
        ...current,
        item: { ...current.item, id_item: itemId, is_favorite: nextFavorite },
      } : current);
      showToast(nextFavorite ? 'Adicionado aos favoritos.' : 'Removido dos favoritos.', 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao favoritar.', 'error');
    } finally {
      setFavoriteSaving(false);
    }
  }

  async function removeFavorite() {
    setConfirmRemoveFavorite(false);
    setFavoriteSaving(true);
    try {
      const response = await toggleFavorite(displayItem, false);
      const nextFavorite = !!response.data?.is_favorite;
      const itemId = response.data?.item_id ?? displayItem.id_item;
      setFavorite(nextFavorite);
      setDetail((current) => current ? {
        ...current,
        item: { ...current.item, id_item: itemId, is_favorite: nextFavorite },
      } : current);
      showToast('Removido dos favoritos.', 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao remover favorito.', 'error');
    } finally {
      setFavoriteSaving(false);
    }
  }

  async function markMainWatched() {
    if (!released || isWatched || saving) return;
    setSaving(true);
    try {
      if (isMovie) {
        const response = await trackItem(displayItem, 'completed');
        const itemId = response.data?.item_id ?? displayItem.id_item;
        setDetail((current) => current ? {
          ...current,
          item: { ...current.item, id_item: itemId, track_status: 'completed' },
          progress: { total_count: 1, watched_count: 1 },
        } : current);
      } else if (nextEpisode) {
        const response = await markEpisodes({
          item_id: Number(displayItem.id_item),
          episode_id: nextEpisode.id_episodio,
          mode: 'single',
        });
        applyEpisodePayload(response.data);
      }
      showToast('Marcado como assistido.', 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao marcar como visto.', 'error');
    } finally {
      setSaving(false);
    }
  }

  async function doRewatchItem() {
    if (!canRewatch || saving) return;
    setSaving(true);
    try {
      const response = await trackItem(displayItem, 'rewatching', 'rewatch');
      const itemId = response.data?.item_id ?? displayItem.id_item;
      setDetail((current) => current ? {
        ...current,
        item: { ...current.item, id_item: itemId, track_status: isMovie ? 'completed' : 'watching', rewatch_count: Number(current.item.rewatch_count || 0) + 1 },
      } : current);
      showToast('Reassistir iniciado.', 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao iniciar reassistir.', 'error');
    } finally {
      setSaving(false);
    }
  }

  async function doSaveReview(nextRating = rating) {
    if (!released || saving) return;
    if (!nextRating || !comment.trim()) {
      showToast('Escolha uma estrela e escreva um comentario.', 'info');
      return;
    }
    setSaving(true);
    try {
      await saveReview(displayItem, nextRating, comment.trim());
      setDetail((current) => current ? {
        ...current,
        item: { ...current.item, rating: nextRating, comment: comment.trim() },
      } : current);
      showToast('Avaliacao salva.', 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao salvar avaliacao.', 'error');
    } finally {
      setSaving(false);
    }
  }

  function applyEpisodePayload(payload?: { episodes: Episode[]; progress: { total_count: number; watched_count: number }; next_unwatched: Episode | null } | null) {
    if (!payload) return;
    setDetail((current) => current ? {
      ...current,
      episodes: payload.episodes,
      progress: payload.progress,
      next_unwatched: payload.next_unwatched,
    } : current);
  }

  return (
    <ScrollView style={styles.screen}>
      {loading ? (
        <View style={{ padding: 16 }}>
          <Pressable onPress={onBack} style={styles.backButton}><View style={styles.backChevron} /></Pressable>
          <Skeleton height={240} width={160} radius={16} />
          <View style={{ height: 18 }} />
          <Skeleton height={30} />
        </View>
      ) : detailError && !detail ? (
        <View style={styles.detailErrorScreen}>
          <Pressable onPress={onBack} style={styles.backButton}><View style={styles.backChevron} /></Pressable>
          <Text style={styles.detailErrorTitle}>Nao foi possivel abrir este titulo.</Text>
          <Text style={styles.detailErrorText}>{detailError}</Text>
          <Pressable onPress={load} style={styles.detailRetryButton}>
            <Text style={styles.detailRetryText}>Tentar novamente</Text>
          </Pressable>
        </View>
      ) : (
        <>
          {/* Immersive Header Banner (Option 3) */}
          <View style={styles.bannerContainer}>
            <Image source={{ uri: displayItem.banner_url || displayItem.poster_url }} style={styles.bannerImage} />
            <View style={styles.bannerOverlay} />
            
            {/* Navigation buttons absolutely positioned on top of the banner */}
            <View style={styles.bannerNavRow}>
              <Pressable onPress={onBack} style={styles.bannerBackButton}>
                <View style={styles.backChevron} />
              </Pressable>
              
              <Pressable disabled={favoriteSaving} onPress={doFavorite} style={[styles.bannerHeartButton, favorite && styles.bannerHeartButtonActive]}>
                <HeartIcon filled={favorite} />
              </Pressable>
            </View>

            {/* Poster and Title Info Floating over Banner Bottom */}
            <View style={styles.bannerContentRow}>
              <Image source={{ uri: displayItem.poster_url }} style={styles.floatPoster} />
              <View style={styles.bannerMetaBlock}>
                <Text style={styles.bannerTitle} numberOfLines={2}>{displayItem.title}</Text>
                <View style={styles.bannerMetaInfo}>
                  <Text style={styles.bannerYearText}>{displayItem.release_year || 'Sem ano'}</Text>
                  <Text style={styles.bannerDot}>•</Text>
                  <Text style={styles.bannerTypeText}>{labelType(displayItem.type)}</Text>
                </View>
                {displayItem.genres ? (
                  <View style={styles.bannerGenresRow}>
                    {displayItem.genres.split(', ').slice(0, 3).map((genre, idx) => (
                      <View key={idx} style={styles.bannerGenreBadge}>
                        <Text style={styles.bannerGenreText}>{genre}</Text>
                      </View>
                    ))}
                  </View>
                ) : null}
              </View>
            </View>
          </View>

          {/* Inner padded content container */}
          <View style={styles.contentContainer}>
            {!released && displayItem.release_date ? (
              <Text style={styles.release}>Lancamento: {formatDate(displayItem.release_date)}</Text>
            ) : null}

            {canRewatch ? (
              <View style={styles.actionRow}>
                <Pressable disabled={saving} onPress={doRewatchItem} style={[styles.pill, saving && styles.actionDisabled]}>
                  <Text style={styles.pillText}>Reassistir</Text>
                </Pressable>
              </View>
            ) : null}

            {hasEpisodes ? (
              <View style={styles.tabBar}>
                <Pressable onPress={() => setActiveTab('about')} style={[styles.tabButton, activeTab === 'about' && styles.tabButtonActive]}>
                  <Text style={[styles.tabText, activeTab === 'about' && styles.tabTextActive]}>Sobre</Text>
                </Pressable>
                <Pressable onPress={() => setActiveTab('episodes')} style={[styles.tabButton, activeTab === 'episodes' && styles.tabButtonActive]}>
                  <Text style={[styles.tabText, activeTab === 'episodes' && styles.tabTextActive]}>Episódios</Text>
                </Pressable>
              </View>
            ) : (
              <Text style={styles.sectionTitle}>Sobre</Text>
            )}

            {activeTab === 'about' || !hasEpisodes ? (
              <>
                <Text style={styles.description}>{displayItem.description || 'Nenhuma sinopse disponivel.'}</Text>

                <View style={styles.inlineActionRow}>
                  <Pressable disabled={!canUseItemActions} onPress={() => setListOpen(true)} style={[styles.listFab, !canUseItemActions && styles.actionDisabled]}>
                    <ListPlusIcon />
                  </Pressable>
                  {isMovie ? (
                    <Pressable disabled={!canUseItemActions || !released || isWatched || saving} onPress={markMainWatched} style={[styles.watchButton, (!canUseItemActions || !released || isWatched) && styles.watchButtonDisabled]}>
                      {saving ? <ActivityIndicator color={colors.text} /> : <Text style={styles.watchButtonText}>{canUseItemActions ? mainButtonLabel : 'Item indisponivel'}</Text>}
                    </Pressable>
                  ) : (
                    <Pressable disabled={!hasEpisodes} onPress={() => setActiveTab('episodes')} style={[styles.watchButton, !hasEpisodes && styles.watchButtonDisabled]}>
                      <Text style={styles.watchButtonText}>{hasEpisodes ? 'Ver episodios' : 'Sem episodios'}</Text>
                    </Pressable>
                  )}
                </View>

                <CastSection cast={detail?.cast || []} />

                {displayItem.watch_providers ? (
                  <View style={styles.providersBlock}>
                  <Text style={styles.providersTitle}>Onde assistir</Text>
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.providersList}>
                    {(() => {
                      try {
                        const providers: Array<{ name: string; logo: string }> = JSON.parse(displayItem.watch_providers);
                        if (!providers || !providers.length) return null;
                        return providers.map((p, idx) => (
                          <View key={idx} style={styles.providerBadge}>
                            {p.logo ? (
                              <Image source={{ uri: p.logo }} style={styles.providerLogo} />
                            ) : null}
                          </View>
                        ));
                      } catch {
                        return null;
                      }
                    })()}
                  </ScrollView>
                </View>
              ) : null}

              {detail?.recommendations && detail.recommendations.length > 0 ? (
                <View style={styles.recsBlock}>
                  <Text style={styles.recsTitle}>Recomendações</Text>
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.recsList}>
                    {detail.recommendations.map((recItem, idx) => (
                      <Pressable key={idx} onPress={() => onSelectItem && onSelectItem(recItem)} style={styles.recCard}>
                        <Image source={{ uri: recItem.poster_url }} style={styles.recPhoto} />
                        <Text numberOfLines={1} style={styles.recName}>{recItem.title}</Text>
                        <Text numberOfLines={1} style={styles.recYear}>{recItem.release_year}</Text>
                      </Pressable>
                    ))}
                  </ScrollView>
                </View>
              ) : null}

              <View style={styles.reviewBox}>
                <Text style={styles.reviewTitle}>A minha avaliacao</Text>
                <View style={styles.stars}>
                  {[1, 2, 3, 4, 5].map((star) => (
                    <Text
                      key={star}
                      onPress={() => released && !hasExistingReview && setRating(star)}
                      style={[styles.star, rating >= star && styles.starActive, (!released || hasExistingReview) && styles.starDisabled]}
                    >
                      {rating >= star ? '\u2605' : '\u2606'}
                    </Text>
                  ))}
                </View>
                <TextInput
                  editable={released && !hasExistingReview}
                  multiline
                  onChangeText={setComment}
                  placeholder={released ? 'Deixe um comentario sobre este titulo...' : 'Disponivel apos o lancamento.'}
                  placeholderTextColor={colors.muted}
                  style={[styles.commentInput, (!released || hasExistingReview) && styles.commentDisabled]}
                  value={comment}
                />
                {released && !hasExistingReview ? (
                  <Pressable disabled={!rating || !comment.trim() || saving} onPress={() => doSaveReview()} style={[styles.saveReviewButton, (!rating || !comment.trim() || saving) && styles.saveReviewDisabled]}>
                    <Text style={styles.saveReviewText}>Salvar avaliacao</Text>
                  </Pressable>
                ) : hasExistingReview ? (
                  <View style={styles.reviewSavedBadge}>
                    <Text style={styles.reviewSavedText}>Avaliação já salva</Text>
                  </View>
                ) : null}
              </View>

              <View style={styles.communityBox}>
                <View style={styles.communityHeader}>
                  <Text style={styles.communityTitle}>Avaliações</Text>
                  <Text style={styles.communityCount}>{detail?.reviews?.length || 0}</Text>
                </View>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.communityList}>
                  {(detail?.reviews || []).map((review, index) => (
                    <View key={`${review.user_name || 'user'}-${index}`} style={styles.communityCard}>
                      <View style={styles.communityTop}>
                        <View style={styles.communityAvatar}>
                          <Text style={styles.communityAvatarText}>{initials(review.user_name || 'U')}</Text>
                        </View>
                        <View style={{ flex: 1 }}>
                          <Text style={styles.communityUser}>{review.user_name || 'Usuário'}</Text>
                          <Text style={styles.communityMeta}>{formatDateTime(review.reviewed_at)}</Text>
                        </View>
                        <Text style={styles.communityRating}>★ {Number(review.rating || 0).toFixed(1)}</Text>
                      </View>
                      <Text numberOfLines={4} style={styles.communityComment}>{review.comment}</Text>
                    </View>
                  ))}
                  {!(detail?.reviews || []).length ? (
                    <View style={styles.communityEmpty}>
                      <Text style={styles.communityEmptyTitle}>Sem avaliações ainda.</Text>
                      <Text style={styles.communityEmptyText}>Quando alguém avaliar este título, elas aparecem aqui.</Text>
                    </View>
                  ) : null}
                </ScrollView>
              </View>
            </>
          ) : null}

          {activeTab === 'episodes' && hasEpisodes ? (
            <EpisodeGroups
              episodes={detail.episodes}
              itemId={Number(displayItem.id_item)}
              onEpisodesChanged={applyEpisodePayload}
            />
          ) : null}
        </View>

          <View style={styles.bottomActionRow}>
            <Pressable onPress={() => setListOpen(true)} style={styles.listFab}>
              <ListPlusIcon />
            </Pressable>
            {isMovie ? (
              <Pressable disabled={!released || isWatched || saving} onPress={markMainWatched} style={[styles.watchButton, (!released || isWatched) && styles.watchButtonDisabled]}>
                {saving ? <ActivityIndicator color={colors.text} /> : <Text style={styles.watchButtonText}>{mainButtonLabel}</Text>}
              </Pressable>
            ) : (
              <Pressable disabled={!hasEpisodes} onPress={() => setActiveTab('episodes')} style={[styles.watchButton, !hasEpisodes && styles.watchButtonDisabled]}>
                <Text style={styles.watchButtonText}>Ver episódios</Text>
              </Pressable>
            )}
          </View>
        </>
      )}

      <ListModal
        visible={listOpen}
        lists={detail?.lists || []}
        item={displayItem}
        onClose={() => setListOpen(false)}
        onAdded={(listId, itemId) => {
          setDetail((current) => current ? {
            ...current,
            item: { ...current.item, id_item: itemId ?? current.item.id_item },
            lists: current.lists.map((list) => list.id_lista === listId ? { ...list, has_item: true } : list),
          } : current);
        }}
      />

      <ConfirmModal
        visible={confirmRemoveFavorite}
        title="Remover favorito"
        message="Deseja remover este titulo dos favoritos?"
        confirmLabel="Remover"
        destructive
        onCancel={() => setConfirmRemoveFavorite(false)}
        onConfirm={removeFavorite}
      />

      <View style={{ height: 110 }} />
    </ScrollView>
  );
}

function EpisodeGroups({
  episodes,
  itemId,
  onEpisodesChanged,
}: {
  episodes: Episode[];
  itemId: number;
  onEpisodesChanged: (payload?: { episodes: Episode[]; progress: { total_count: number; watched_count: number }; next_unwatched: Episode | null } | null) => void;
}) {
  const { showToast } = useToast();
  const [savingSeason, setSavingSeason] = useState<number | null>(null);
  const seasons = episodes.reduce<Record<string, Episode[]>>((acc, episode) => {
    const key = String(episode.season_number || 1);
    acc[key] = acc[key] || [];
    acc[key].push(episode);
    return acc;
  }, {});
  const seasonNumbers = Object.keys(seasons).map(Number).sort((a, b) => a - b);
  const [activeSeason, setActiveSeason] = useState(seasonNumbers[0] || 1);
  const activeRows = seasons[String(activeSeason)] || [];

  useEffect(() => {
    if (!seasons[String(activeSeason)] && seasonNumbers.length) {
      setActiveSeason(seasonNumbers[0]);
    }
  }, [episodes.length]);

  async function markSeason(season: number) {
    if (savingSeason) return;
    setSavingSeason(season);
    try {
      const response = await markEpisodes({ item_id: itemId, season_number: season, mode: 'season' });
      onEpisodesChanged(response.data);
      showToast(`Temporada ${season} marcada como assistida.`, 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao marcar temporada.', 'error');
    } finally {
      setSavingSeason(null);
    }
  }

  return (
    <>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.seasonPicker} contentContainerStyle={styles.seasonPickerContent}>
        {seasonNumbers.map((season) => {
          const watched = seasons[String(season)].filter((episode) => episode.watched).length;
          const total = seasons[String(season)].length;
          return (
            <Pressable key={season} onPress={() => setActiveSeason(season)} style={[styles.seasonChip, activeSeason === season && styles.seasonChipActive]}>
              <Text style={[styles.seasonChipTitle, activeSeason === season && styles.seasonChipTitleActive]}>T{season}</Text>
              <Text style={styles.seasonChipMeta}>{watched}/{total}</Text>
            </Pressable>
          );
        })}
      </ScrollView>

      <View style={styles.seasonBox}>
        <View style={styles.seasonHeader}>
          <View style={{ flex: 1 }}>
            <Text style={styles.seasonTitle}>Temporada {activeSeason}</Text>
            <Text style={styles.seasonMeta}>{activeRows.filter((episode) => episode.watched).length} de {activeRows.length} episódios vistos</Text>
          </View>
          <Pressable disabled={savingSeason === activeSeason} onPress={() => markSeason(activeSeason)} style={styles.seasonButton}>
            {savingSeason === activeSeason ? <ActivityIndicator color={colors.text} size="small" /> : <Text style={styles.seasonButtonText}>Marcar temporada completa</Text>}
          </Pressable>
        </View>
        {activeRows.map((episode) => (
          <EpisodeRow
            key={episode.id_episodio}
            episode={episode}
            itemId={itemId}
            onEpisodesChanged={onEpisodesChanged}
          />
        ))}
      </View>
    </>
  );
}

function CastSection({ cast }: { cast: CastMember[] }) {
  if (!cast.length) return null;

  return (
    <View style={styles.castBlock}>
      <Text style={styles.castTitle}>Elenco</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.castList}>
        {cast.map((person, index) => (
          <View key={`${person.name}-${index}`} style={styles.castCard}>
            {person.image_url ? (
              <Image source={{ uri: person.image_url }} style={styles.castPhoto} />
            ) : (
              <View style={styles.castPhotoFallback}>
                <Text style={styles.castInitials}>{initials(person.name)}</Text>
              </View>
            )}
            <Text numberOfLines={1} style={styles.castName}>{person.name}</Text>
            {person.character ? <Text numberOfLines={1} style={styles.castCharacter}>{person.character}</Text> : null}
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

function EpisodeRow({
  episode,
  itemId,
  onEpisodesChanged,
}: {
  episode: Episode;
  itemId: number;
  onEpisodesChanged: (payload?: { episodes: Episode[]; progress: { total_count: number; watched_count: number }; next_unwatched: Episode | null } | null) => void;
}) {
  const [watched, setWatched] = useState(!!episode.watched);
  const [choiceOpen, setChoiceOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const upcoming = !isReleased(episode.air_date || undefined);
  const { showToast } = useToast();

  useEffect(() => {
    setWatched(!!episode.watched);
  }, [episode.watched]);

  async function mark() {
    if (loading || watched || upcoming) return;
    setChoiceOpen(false);
    setLoading(true);
    try {
      const response = await markEpisodes({ item_id: itemId, episode_id: episode.id_episodio, mode: 'single' });
      onEpisodesChanged(response.data);
      setWatched(true);
      showToast('Episodio marcado como assistido.', 'success');
    } finally {
      setLoading(false);
    }
  }

  async function markPrevious() {
    if (loading || watched || upcoming) return;
    setChoiceOpen(false);
    setLoading(true);
    try {
      const response = await markEpisodes({ item_id: itemId, episode_id: episode.id_episodio, mode: 'preceding' });
      onEpisodesChanged(response.data);
      setWatched(true);
      showToast('Episodios anteriores marcados.', 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao marcar anteriores.', 'error');
    } finally {
      setLoading(false);
    }
  }

  return (
    <View style={[styles.episodeRow, upcoming && styles.episodeLocked]}>
      <Pressable onPress={() => !watched && !upcoming && setChoiceOpen(true)} disabled={loading || watched || upcoming} style={styles.episodeContent}>
        <Text style={styles.episodeTitle}>{episode.episode_number}. {episode.title}</Text>
        <Text style={styles.meta}>{episode.air_date ? formatDate(episode.air_date) : 'Sem data'} - {episode.runtime_minutes || 45}m</Text>
        {upcoming ? <Text style={styles.upcomingText}>Ainda nao lancado</Text> : null}
      </Pressable>
      <View style={styles.episodeActions}>
        {watched ? (
          <View style={styles.episodeCheckWatched}>
            <View style={styles.checkMark} />
          </View>
        ) : upcoming ? (
          <Text style={styles.lockedText}>Em breve</Text>
        ) : (
          <Pressable onPress={() => setChoiceOpen(true)} disabled={loading} style={styles.episodeCheckButton}>
            {loading ? <ActivityIndicator color={colors.text} size="small" /> : <View style={styles.checkMarkMuted} />}
          </Pressable>
        )}
      </View>
      <EpisodeMarkModal
        episode={episode}
        loading={loading}
        onCancel={() => setChoiceOpen(false)}
        onMarkOnly={mark}
        onMarkUntil={markPrevious}
        visible={choiceOpen}
      />
    </View>
  );
}

function EpisodeMarkModal({
  visible,
  episode,
  loading,
  onMarkUntil,
  onMarkOnly,
  onCancel,
}: {
  visible: boolean;
  episode: Episode;
  loading: boolean;
  onMarkUntil: () => void;
  onMarkOnly: () => void;
  onCancel: () => void;
}) {
  const episodeLabel = `T${episode.season_number}E${episode.episode_number}`;

  return (
    <Modal visible={visible} animationType="fade" transparent onRequestClose={onCancel}>
      <View style={styles.choiceOverlay}>
        <View style={styles.choiceBox}>
          <View style={styles.choiceIconWrap}>
            <View style={styles.choiceCheck} />
          </View>
          <Text style={styles.choiceTitle}>Marcar episódio</Text>
          <Text style={styles.choiceMessage}>Como quer marcar {episodeLabel} como assistido?</Text>

          <Pressable disabled={loading} onPress={onMarkUntil} style={styles.choicePrimary}>
            <Text style={styles.choicePrimaryText}>Marcar até {episodeLabel}</Text>
          </Pressable>
          <Pressable disabled={loading} onPress={onMarkOnly} style={styles.choiceSecondary}>
            <Text style={styles.choiceSecondaryText}>Marcar apenas este episódio</Text>
          </Pressable>
          <Pressable disabled={loading} onPress={onCancel} style={styles.choiceCancel}>
            <Text style={styles.choiceCancelText}>Cancelar</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

function ListModal({
  visible,
  lists,
  item,
  onClose,
  onAdded,
}: {
  visible: boolean;
  lists: SelectableList[];
  item: Item;
  onClose: () => void;
  onAdded: (listId: number, itemId?: number | null) => void;
}) {
  const [savingList, setSavingList] = useState<number | null>(null);
  const { showToast } = useToast();

  async function add(list: SelectableList) {
    if (savingList || list.has_item) return;
    setSavingList(list.id_lista);
    try {
      const response = await addItemToList(list.id_lista, item);
      onAdded(list.id_lista, response.data?.item_id);
      showToast(`Adicionado em ${list.nome}.`, 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao adicionar na lista.', 'error');
    } finally {
      setSavingList(null);
    }
  }

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <View style={styles.modalOverlay}>
        <View style={styles.modalSheet}>
          <View style={styles.modalHeader}>
            <Text style={styles.modalTitle}>Adicionar a lista</Text>
            <Pressable onPress={onClose}><Text style={styles.close}>Fechar</Text></Pressable>
          </View>
          <FlatList
            data={lists}
            keyExtractor={(list) => String(list.id_lista)}
            renderItem={({ item: list }) => (
              <Pressable disabled={!!list.has_item || savingList === list.id_lista} onPress={() => add(list)} style={styles.listRow}>
                <Text style={styles.listName}>{list.nome}</Text>
                <View style={[styles.checkbox, list.has_item && styles.checkboxChecked]}>
                  {savingList === list.id_lista ? <ActivityIndicator color={colors.text} size="small" /> : list.has_item ? <View style={styles.checkboxInner} /> : null}
                </View>
              </Pressable>
            )}
            ListEmptyComponent={<Text style={styles.emptyModal}>Crie uma lista na aba Listas primeiro.</Text>}
          />
        </View>
      </View>
    </Modal>
  );
}

function HeartIcon({ filled }: { filled: boolean }) {
  return <Text style={[styles.heartGlyph, filled && styles.heartGlyphFilled]}>{filled ? '\u2665' : '\u2661'}</Text>;
}

function ListPlusIcon() {
  return (
    <View style={styles.listIcon}>
      <View style={styles.listLine} />
      <View style={[styles.listLine, { top: 12 }]} />
      <View style={styles.plusH} />
      <View style={styles.plusV} />
    </View>
  );
}

function isReleased(date?: string | null) {
  if (!date) return true;
  return date.slice(0, 10) <= new Date().toISOString().slice(0, 10);
}

function availableLabel(date?: string | null) {
  return date ? `Disponivel em ${formatDate(date)}` : 'Ainda nao lancado';
}

function formatDate(date: string) {
  const [year, month, day] = date.slice(0, 10).split('-');
  return `${day}/${month}/${year}`;
}

function labelType(type: string) {
  if (type === 'movie') return 'Filme';
  if (type === 'anime') return 'Anime';
  return 'Serie';
}

function initials(name: string) {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');
}

function formatDateTime(value?: string) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('pt-BR', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1 },
  backButton: { alignItems: 'center', backgroundColor: colors.surface, borderRadius: 18, height: 36, justifyContent: 'center', marginBottom: 14, width: 36 },
  backChevron: { borderBottomColor: colors.accent, borderBottomWidth: 2, borderLeftColor: colors.accent, borderLeftWidth: 2, height: 11, transform: [{ rotate: '45deg' }], width: 11 },
  poster: { alignSelf: 'center', borderRadius: 16, height: 240, marginBottom: 18, width: 160 },
  titleRow: { alignItems: 'center', flexDirection: 'row', flexWrap: 'wrap', gap: 8, justifyContent: 'center' },
  title: { color: colors.text, fontSize: 26, fontWeight: '900', textAlign: 'center' },
  titleBadge: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 999, borderWidth: 1, color: colors.muted, fontSize: 11, fontWeight: '900', overflow: 'hidden', paddingHorizontal: 10, paddingVertical: 5 },
  meta: { color: colors.muted, fontSize: 12, marginTop: 5 },
  release: { color: colors.info, fontSize: 13, fontWeight: '900', marginTop: 8, textAlign: 'center' },
  actionRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, justifyContent: 'center', marginTop: 16 },
  heartButton: { alignItems: 'center', backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 999, borderWidth: 1, height: 42, justifyContent: 'center', width: 52 },
  heartButtonActive: { backgroundColor: 'rgba(255,59,85,0.16)', borderColor: colors.danger },
  actionDisabled: { opacity: 0.65 },
  heartGlyph: { color: colors.text, fontSize: 28, fontWeight: '900', lineHeight: 31 },
  heartGlyphFilled: { color: colors.danger },
  pill: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 999, borderWidth: 1, paddingHorizontal: 15, paddingVertical: 10 },
  pillText: { color: colors.text, fontWeight: '900' },
  tabBar: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 18, borderWidth: 1, flexDirection: 'row', gap: 6, marginTop: 26, padding: 5 },
  tabButton: { alignItems: 'center', borderRadius: 14, flex: 1, paddingVertical: 12 },
  tabButtonActive: { backgroundColor: 'rgba(139,92,246,0.18)' },
  tabText: { color: colors.muted, fontSize: 13, fontWeight: '900', textTransform: 'uppercase' },
  tabTextActive: { color: colors.accent },
  description: { color: colors.text, fontSize: 14, lineHeight: 22, marginTop: 14 },
  detailErrorScreen: { flex: 1, minHeight: 480, padding: 20 },
  detailErrorTitle: { color: colors.text, fontSize: 22, fontWeight: '900', marginTop: 40, textAlign: 'center' },
  detailErrorText: { color: colors.muted, fontSize: 14, lineHeight: 22, marginTop: 12, textAlign: 'center' },
  detailRetryButton: { alignItems: 'center', alignSelf: 'center', backgroundColor: colors.accent, borderRadius: 999, marginTop: 22, paddingHorizontal: 20, paddingVertical: 13 },
  detailRetryText: { color: colors.text, fontSize: 14, fontWeight: '900' },
  castBlock: { marginTop: 22 },
  castTitle: { color: colors.text, fontSize: 14, fontWeight: '900', marginBottom: 12, textTransform: 'uppercase' },
  castList: { gap: 14, paddingRight: 8 },
  castCard: { width: 86 },
  castPhoto: { backgroundColor: colors.surfaceRaised, borderRadius: 32, height: 64, width: 64 },
  castPhotoFallback: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderRadius: 32, height: 64, justifyContent: 'center', width: 64 },
  castInitials: { color: colors.text, fontSize: 16, fontWeight: '900' },
  castName: { color: colors.text, fontSize: 12, fontWeight: '900', marginTop: 8 },
  castCharacter: { color: colors.muted, fontSize: 11, marginTop: 2 },
  sectionTitle: { color: colors.accent, fontSize: 13, fontWeight: '900', marginBottom: 8, marginTop: 28, textAlign: 'center', textTransform: 'uppercase' },
  reviewBox: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 16, borderWidth: 1, marginTop: 24, padding: 16 },
  reviewTitle: { color: colors.text, fontSize: 13, fontWeight: '900', textTransform: 'uppercase' },
  communityBox: { marginTop: 24 },
  communityHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 12 },
  communityTitle: { color: colors.text, fontSize: 13, fontWeight: '900', textTransform: 'uppercase' },
  communityCount: { color: colors.muted, fontSize: 12, fontWeight: '900' },
  communityList: { gap: 12, paddingRight: 12 },
  communityCard: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 16, borderWidth: 1, padding: 14, width: 280 },
  communityTop: { alignItems: 'center', flexDirection: 'row', gap: 10, marginBottom: 10 },
  communityAvatar: { alignItems: 'center', backgroundColor: 'rgba(139,92,246,0.18)', borderColor: 'rgba(139,92,246,0.35)', borderRadius: 18, borderWidth: 1, height: 36, justifyContent: 'center', width: 36 },
  communityAvatarText: { color: colors.text, fontSize: 12, fontWeight: '900' },
  communityUser: { color: colors.text, fontSize: 13, fontWeight: '900' },
  communityMeta: { color: colors.muted, fontSize: 10, marginTop: 2 },
  communityRating: { color: '#f6c45f', fontSize: 12, fontWeight: '900' },
  communityComment: { color: colors.text, fontSize: 12, lineHeight: 18 },
  communityEmpty: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 16, borderWidth: 1, padding: 14, width: 260 },
  communityEmptyTitle: { color: colors.text, fontSize: 13, fontWeight: '900' },
  communityEmptyText: { color: colors.muted, fontSize: 12, lineHeight: 18, marginTop: 6 },
  stars: { flexDirection: 'row', gap: 10, justifyContent: 'center', marginTop: 12 },
  star: { color: colors.surfaceRaised, fontSize: 34, fontWeight: '900' },
  starActive: { color: '#ffc400' },
  starDisabled: { opacity: 0.35 },
  commentInput: { backgroundColor: colors.background, borderRadius: 12, color: colors.text, marginTop: 12, minHeight: 78, padding: 12, textAlignVertical: 'top' },
  commentDisabled: { opacity: 0.6 },
  saveReviewButton: { alignSelf: 'flex-end', backgroundColor: colors.accent, borderRadius: 999, marginTop: 10, paddingHorizontal: 16, paddingVertical: 10 },
  saveReviewDisabled: { opacity: 0.5 },
  saveReviewText: { color: colors.text, fontWeight: '900' },
  reviewSavedBadge: { alignSelf: 'flex-end', backgroundColor: 'rgba(56,239,125,0.14)', borderColor: colors.success, borderRadius: 999, borderWidth: 1, marginTop: 10, paddingHorizontal: 14, paddingVertical: 9 },
  reviewSavedText: { color: colors.success, fontSize: 12, fontWeight: '900' },
  seasonPicker: { marginTop: 16 },
  seasonPickerContent: { gap: 8, paddingRight: 8 },
  seasonChip: { alignItems: 'center', backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 14, borderWidth: 1, minWidth: 62, paddingHorizontal: 12, paddingVertical: 10 },
  seasonChipActive: { backgroundColor: 'rgba(139,92,246,0.2)', borderColor: colors.accent },
  seasonChipTitle: { color: colors.muted, fontSize: 13, fontWeight: '900' },
  seasonChipTitleActive: { color: colors.text },
  seasonChipMeta: { color: colors.muted, fontSize: 10, fontWeight: '800', marginTop: 3 },
  seasonBox: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 18, borderWidth: 1, marginBottom: 12, overflow: 'hidden' },
  seasonHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', padding: 14 },
  seasonTitle: { color: colors.text, flex: 1, fontSize: 16, fontWeight: '900' },
  seasonMeta: { color: colors.muted, fontSize: 11, fontWeight: '800', marginTop: 4 },
  seasonButton: { backgroundColor: 'rgba(139,92,246,0.2)', borderColor: colors.accent, borderRadius: 999, borderWidth: 1, maxWidth: 142, paddingHorizontal: 10, paddingVertical: 7 },
  seasonButtonText: { color: colors.text, fontSize: 10, fontWeight: '900' },
  episodeRow: {
    borderTopColor: colors.surfaceRaised,
    borderTopWidth: 1,
    flexDirection: 'row',
    gap: 12,
    padding: 12,
  },
  episodeContent: { flex: 1, paddingRight: 4 },
  episodeLocked: { opacity: 0.58 },
  episodeTitle: { color: colors.text, fontSize: 14, fontWeight: '900' },
  upcomingText: { color: colors.muted, fontSize: 11, marginTop: 5 },
  episodeActions: {
    alignItems: 'center',
    alignSelf: 'center',
    flexDirection: 'row',
    justifyContent: 'flex-end',
    minWidth: 34,
  },
  episodeCheckButton: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderColor: 'rgba(255,255,255,0.12)', borderRadius: 999, borderWidth: 1, height: 26, justifyContent: 'center', marginLeft: 8, width: 26 },
  episodeCheckWatched: { alignItems: 'center', backgroundColor: colors.success, borderRadius: 999, height: 26, justifyContent: 'center', marginLeft: 8, width: 26 },
  checkMark: { borderBottomColor: colors.background, borderBottomWidth: 2, borderLeftColor: colors.background, borderLeftWidth: 2, height: 6, transform: [{ rotate: '-45deg' }], width: 10 },
  checkMarkMuted: { borderBottomColor: colors.muted, borderBottomWidth: 2, borderLeftColor: colors.muted, borderLeftWidth: 2, height: 6, transform: [{ rotate: '-45deg' }], width: 10 },
  watchedBadge: { backgroundColor: 'rgba(56,239,125,0.16)', borderColor: colors.success, borderRadius: 999, borderWidth: 1, paddingHorizontal: 10, paddingVertical: 7 },
  watchedBadgeText: { color: colors.success, fontSize: 11, fontWeight: '900' },
  rewatchButton: { borderColor: colors.info, borderRadius: 999, borderWidth: 1, paddingHorizontal: 10, paddingVertical: 8 },
  rewatchText: { color: colors.info, fontSize: 11, fontWeight: '900' },
  previousButton: { backgroundColor: colors.surfaceRaised, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 8 },
  previousText: { color: colors.text, fontSize: 11, fontWeight: '900' },
  markButton: { backgroundColor: colors.accent, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8 },
  markText: { color: colors.text, fontSize: 11, fontWeight: '900' },
  lockedText: { color: colors.muted, fontSize: 11, fontWeight: '900' },
  bottomActionRow: { display: 'none' },
  inlineActionRow: { alignItems: 'center', flexDirection: 'row', gap: 10, marginTop: 18 },
  listFab: { alignItems: 'center', borderColor: colors.surfaceRaised, borderRadius: 999, borderWidth: 1, height: 54, justifyContent: 'center', width: 54 },
  listIcon: { height: 24, position: 'relative', width: 24 },
  listLine: { backgroundColor: colors.text, borderRadius: 2, height: 3, left: 2, position: 'absolute', top: 5, width: 13 },
  plusH: { backgroundColor: colors.text, borderRadius: 2, height: 3, position: 'absolute', right: 1, top: 11, width: 11 },
  plusV: { backgroundColor: colors.text, borderRadius: 2, height: 11, position: 'absolute', right: 5, top: 7, width: 3 },
  watchButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 999, flex: 1, justifyContent: 'center', minHeight: 54 },
  watchButtonDisabled: { backgroundColor: colors.surfaceRaised },
  watchButtonText: { color: colors.text, fontSize: 14, fontWeight: '900', textAlign: 'center', textTransform: 'uppercase' },
  choiceOverlay: { alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.72)', flex: 1, justifyContent: 'center', padding: 22 },
  choiceBox: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 24, borderWidth: 1, padding: 20, width: '100%' },
  choiceIconWrap: { alignItems: 'center', alignSelf: 'center', backgroundColor: 'rgba(56,239,125,0.14)', borderColor: colors.success, borderRadius: 999, borderWidth: 1, height: 54, justifyContent: 'center', marginBottom: 14, width: 54 },
  choiceCheck: { borderBottomColor: colors.success, borderBottomWidth: 3, borderLeftColor: colors.success, borderLeftWidth: 3, height: 12, transform: [{ rotate: '-45deg' }], width: 22 },
  choiceTitle: { color: colors.text, fontSize: 20, fontWeight: '900', textAlign: 'center' },
  choiceMessage: { color: colors.muted, fontSize: 14, lineHeight: 20, marginBottom: 18, marginTop: 8, textAlign: 'center' },
  choicePrimary: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, padding: 15 },
  choicePrimaryText: { color: colors.text, fontSize: 15, fontWeight: '900' },
  choiceSecondary: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderRadius: 16, marginTop: 10, padding: 15 },
  choiceSecondaryText: { color: colors.text, fontSize: 15, fontWeight: '900' },
  choiceCancel: { alignItems: 'center', marginTop: 12, padding: 12 },
  choiceCancelText: { color: colors.muted, fontSize: 14, fontWeight: '900' },
  modalOverlay: { backgroundColor: 'rgba(0,0,0,0.72)', flex: 1, justifyContent: 'flex-end' },
  modalSheet: { backgroundColor: colors.surface, borderTopLeftRadius: 24, borderTopRightRadius: 24, maxHeight: '70%', padding: 16 },
  modalHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 12 },
  modalTitle: { color: colors.text, fontSize: 20, fontWeight: '900' },
  close: { color: colors.muted, fontWeight: '900' },
  listRow: { alignItems: 'center', backgroundColor: colors.background, borderRadius: 14, flexDirection: 'row', justifyContent: 'space-between', marginBottom: 10, padding: 14 },
  listName: { color: colors.text, fontWeight: '900' },
  checkbox: { alignItems: 'center', borderColor: colors.surfaceRaised, borderRadius: 8, borderWidth: 2, height: 26, justifyContent: 'center', width: 26 },
  checkboxChecked: { backgroundColor: colors.accent, borderColor: colors.accent },
  checkboxInner: { backgroundColor: colors.text, borderRadius: 4, height: 10, width: 10 },
  emptyModal: { color: colors.muted, padding: 16 },
  genresRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, justifyContent: 'center', marginTop: 10 },
  genreBadge: { backgroundColor: 'rgba(255,255,255,0.06)', borderColor: 'rgba(255,255,255,0.1)', borderRadius: 12, borderWidth: 1, paddingHorizontal: 10, paddingVertical: 4 },
  genreText: { color: '#c8c8e3', fontSize: 11, fontWeight: '600' },
  providersBlock: { marginTop: 22 },
  providersTitle: { color: colors.text, fontSize: 14, fontWeight: '900', marginBottom: 12, textTransform: 'uppercase' },
  providersList: { gap: 12, paddingRight: 8 },
  providerBadge: { alignItems: 'center', justifyContent: 'center' },
  providerLogo: { borderRadius: 12, height: 48, width: 48 },
  recsBlock: { marginTop: 22 },
  recsTitle: { color: colors.text, fontSize: 14, fontWeight: '900', marginBottom: 12, textTransform: 'uppercase' },
  recsList: { gap: 14, paddingRight: 8 },
  recCard: { width: 86 },
  recPhoto: { backgroundColor: colors.surfaceRaised, borderRadius: 8, height: 114, width: 80 },
  recName: { color: colors.text, fontSize: 12, fontWeight: '900', marginTop: 8 },
  recYear: { color: colors.muted, fontSize: 11, marginTop: 2 },
  contentContainer: { paddingHorizontal: 16, paddingBottom: 16 },
  bannerContainer: { height: 260, position: 'relative', width: '100%' },
  bannerImage: { height: '100%', resizeMode: 'cover', width: '100%' },
  bannerOverlay: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(0,0,0,0.58)' },
  bannerNavRow: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', left: 16, position: 'absolute', right: 16, top: 48, zIndex: 10 },
  bannerBackButton: { alignItems: 'center', backgroundColor: 'rgba(17,17,24,0.6)', borderRadius: 18, height: 36, justifyContent: 'center', width: 36 },
  bannerHeartButton: { alignItems: 'center', backgroundColor: 'rgba(17,17,24,0.6)', borderRadius: 18, height: 36, justifyContent: 'center', width: 36 },
  bannerHeartButtonActive: { backgroundColor: 'rgba(255,59,85,0.7)' },
  bannerContentRow: { alignItems: 'flex-end', bottom: 16, flexDirection: 'row', gap: 14, left: 16, position: 'absolute', right: 16 },
  floatPoster: { borderRadius: 10, height: 110, width: 75, borderColor: 'rgba(255,255,255,0.15)', borderWidth: 1 },
  bannerMetaBlock: { flex: 1, justifyContent: 'flex-end', paddingBottom: 2 },
  bannerTitle: { color: colors.text, fontSize: 18, fontWeight: '900', textShadowColor: 'rgba(0,0,0,0.85)', textShadowOffset: { width: 0, height: 1 }, textShadowRadius: 3 },
  bannerMetaInfo: { alignItems: 'center', flexDirection: 'row', gap: 6, marginTop: 4 },
  bannerYearText: { color: '#c8c8e3', fontSize: 12, fontWeight: '700' },
  bannerDot: { color: '#c8c8e3', fontSize: 10 },
  bannerTypeText: { color: '#c8c8e3', fontSize: 12, fontWeight: '700' },
  bannerGenresRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 4, marginTop: 6 },
  bannerGenreBadge: { backgroundColor: 'rgba(255,255,255,0.12)', borderRadius: 6, paddingHorizontal: 6, paddingVertical: 2 },
  bannerGenreText: { color: '#ffffff', fontSize: 9, fontWeight: '700' },
});
