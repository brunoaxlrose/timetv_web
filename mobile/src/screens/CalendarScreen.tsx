import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { EventoCalendario, getDashboard } from '../api/mobile';
import { colors } from '../theme/colors';
import { Item } from '../types';

const meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const calendarCache = new Map<string, EventoCalendario[]>();

export function CalendarScreen({ onBack, onOpenItem }: { onBack: () => void; onOpenItem: (item: Item) => void }) {
  const [cursor, setCursor] = useState(() => new Date());
  const [eventos, setEventos] = useState<EventoCalendario[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const requestId = useRef(0);
  const mes = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`;

  useEffect(() => {
    const currentRequest = ++requestId.current;
    const cached = calendarCache.get(mes);
    if (cached) setEventos(cached);
    setLoading(!cached);
    setSelectedDate(null);
    getDashboard(1, mes).then((response) => {
      if (currentRequest !== requestId.current) return;
      const nextEvents = response.data?.calendario || [];
      calendarCache.set(mes, nextEvents);
      setEventos(nextEvents);
    }).catch(() => {
      // Keep the selected month's cached events while offline.
    }).finally(() => {
      if (currentRequest === requestId.current) setLoading(false);
    });
  }, [mes]);

  const primeiroDia = new Date(cursor.getFullYear(), cursor.getMonth(), 1).getDay();
  const totalDias = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
  const dias = Array.from({ length: primeiroDia + totalDias }, (_, index) => index < primeiroDia ? null : index - primeiroDia + 1);
  const datasComEvento = new Set(eventos.map((evento) => Number(evento.data_evento.slice(8, 10))));
  const eventosVisiveis = selectedDate ? eventos.filter((evento) => evento.data_evento === selectedDate) : eventos;

  function mudarMes(delta: number) {
    setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + delta, 1));
  }

  function selecionarDia(dia: number) {
    const date = `${mes}-${String(dia).padStart(2, '0')}`;
    setSelectedDate((current) => current === date ? null : date);
  }

  return (
    <View style={styles.screen}>
      <View style={styles.header}><Pressable onPress={onBack}><Text style={styles.back}>‹</Text></Pressable><Text style={styles.title}>Calendário de lançamentos</Text></View>
      {loading ? <View style={styles.preparing}><ActivityIndicator color={colors.accent} size="large" /><Text style={styles.preparingTitle}>Preparando calendário</Text><Text style={styles.preparingText}>Buscando estreias e próximos episódios...</Text></View> : <>
        <View style={styles.monthRow}><Pressable onPress={() => mudarMes(-1)}><Text style={styles.arrow}>‹</Text></Pressable><Text style={styles.month}>{meses[cursor.getMonth()]} {cursor.getFullYear()}</Text><Pressable onPress={() => mudarMes(1)}><Text style={styles.arrow}>›</Text></Pressable></View>
        <View style={styles.week}>{['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].map((dia, index) => <Text key={`${dia}-${index}`} style={styles.weekDay}>{dia}</Text>)}</View>
        <View style={styles.grid}>{dias.map((dia, index) => dia ? <Pressable onPress={() => selecionarDia(dia)} key={index} style={[styles.day, selectedDate === `${mes}-${String(dia).padStart(2, '0')}` && styles.dayActive]}><Text style={[styles.dayText, selectedDate === `${mes}-${String(dia).padStart(2, '0')}` && styles.dayTextActive]}>{dia}</Text>{datasComEvento.has(dia) ? <View style={styles.dot} /> : null}</Pressable> : <View key={index} style={styles.day} />)}</View>
        <View style={styles.listHeader}><View><Text style={styles.listTitle}>{selectedDate ? 'Lançamentos do dia' : 'Todos os lançamentos'}</Text>{selectedDate ? <Text style={styles.listDate}>{formatarData(selectedDate)}</Text> : null}</View><Text style={styles.listCount}>{eventosVisiveis.length}</Text></View>
        <FlatList data={eventosVisiveis} keyExtractor={(item, index) => `${item.id_item || item.tmdb_id || item.tvmaze_id}-${item.id_episodio || 0}-${index}`} contentContainerStyle={styles.list} renderItem={({ item }) => (
          <Pressable style={styles.event} onPress={() => onOpenItem(item as unknown as Item)}>
            {item.url_poster ? <Image source={{ uri: item.url_poster }} style={styles.poster} /> : <View style={styles.poster} />}
            <View style={styles.copy}><Text style={styles.eventTitle}>{item.titulo}</Text><Text style={styles.meta}>{item.numero_temporada != null ? `T${item.numero_temporada} · E${item.numero_episodio}` : 'Estreia'} · {formatarData(item.data_evento)}</Text><Text numberOfLines={1} style={styles.episode}>{item.titulo_episodio || 'Novo lançamento'}</Text></View>
          </Pressable>
        )} ListEmptyComponent={<Text style={styles.empty}>{selectedDate ? 'Nenhum lançamento neste dia. Toque novamente no dia para ver o mês inteiro.' : 'Nenhum lançamento cadastrado neste mês.'}</Text>} />
      </>}
    </View>
  );
}

function formatarData(value: string) { return new Date(`${value}T12:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' }); }

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1, paddingHorizontal: 18, paddingTop: 12 },
  header: { alignItems: 'center', flexDirection: 'row', gap: 14, marginBottom: 22 }, back: { color: colors.text, fontSize: 40 }, title: { color: colors.text, fontSize: 21, fontWeight: '900' },
  monthRow: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: 16 }, month: { color: colors.text, fontSize: 20, fontWeight: '800' }, arrow: { color: colors.accent, fontSize: 34 },
  week: { flexDirection: 'row', marginTop: 15 }, weekDay: { color: colors.muted, flex: 1, textAlign: 'center' }, grid: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 7 }, day: { alignItems: 'center', borderRadius: 18, height: 43, justifyContent: 'center', width: '14.285%' }, dayActive: { backgroundColor: colors.accent }, dayText: { color: colors.text, fontSize: 16 }, dayTextActive: { fontWeight: '900' }, dot: { backgroundColor: colors.accent, borderRadius: 3, height: 5, marginTop: 3, width: 5 },
  preparing: { alignItems: 'center', flex: 1, justifyContent: 'center', paddingBottom: 90 }, preparingTitle: { color: colors.text, fontSize: 19, fontWeight: '900', marginTop: 18 }, preparingText: { color: colors.muted, marginTop: 7 }, listHeader: { alignItems: 'center', borderTopColor: colors.surfaceRaised, borderTopWidth: 1, flexDirection: 'row', justifyContent: 'space-between', marginTop: 14, paddingTop: 14 }, listTitle: { color: colors.text, fontSize: 16, fontWeight: '900' }, listDate: { color: colors.accent, fontSize: 12, fontWeight: '800', marginTop: 3 }, listCount: { backgroundColor: colors.surfaceRaised, borderRadius: 999, color: colors.text, fontWeight: '900', minWidth: 30, paddingHorizontal: 9, paddingVertical: 6, textAlign: 'center' }, list: { gap: 10, paddingBottom: 110, paddingTop: 12 }, event: { backgroundColor: colors.surfaceRaised, borderLeftColor: colors.accent, borderLeftWidth: 4, borderRadius: 14, flexDirection: 'row', minHeight: 100, padding: 10 }, poster: { backgroundColor: colors.surface, borderRadius: 9, height: 80, width: 56 }, copy: { flex: 1, justifyContent: 'center', paddingLeft: 12 }, eventTitle: { color: colors.text, fontSize: 16, fontWeight: '900' }, meta: { color: colors.accent, marginTop: 4 }, episode: { color: colors.muted, marginTop: 5 }, empty: { color: colors.muted, paddingVertical: 30, textAlign: 'center' },
});
