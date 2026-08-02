import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { Pressable, SafeAreaView, StyleSheet, View } from 'react-native';
import { User } from '../api/auth';
import { AppHeader } from '../components/AppHeader';
import { OfflineBanner } from '../components/OfflineBanner';
import { alpha, colors } from '../theme/colors';
import { CastMember, Item } from '../types';
import { CollectionScreen } from '../screens/CollectionScreen';
import { DashboardScreen } from '../screens/DashboardScreen';
import { DetailScreen } from '../screens/DetailScreen';
import { ListsScreen } from '../screens/ListsScreen';
import { ProfileScreen } from '../screens/ProfileScreen';
import { SearchScreen } from '../screens/SearchScreen';
import { CalendarScreen } from '../screens/CalendarScreen';
import { DiscoveryScreen } from '../screens/DiscoveryScreen';
import { PersonScreen } from '../screens/PersonScreen';
import { getOfflineSnapshot, subscribeOfflineState } from '../offline/manager';

type Tab = 'home' | 'lists' | 'collection' | 'profile' | 'search';

export function MainTabs({
  user,
  onLogout,
  onUserUpdated,
}: {
  user: User;
  onLogout: () => void;
  onUserUpdated: (user: User) => void;
}) {
  const [tab, setTab] = useState<Tab>('home');
  const [selectedItem, setSelectedItem] = useState<Item | null>(null);
  const [selectedPerson, setSelectedPerson] = useState<CastMember | null>(null);
  const [dataRefreshKey, setDataRefreshKey] = useState(0);
  const [visitedTabs, setVisitedTabs] = useState<Set<Tab>>(() => new Set(['home']));
  const [showCalendar, setShowCalendar] = useState(false);
  const [discovery, setDiscovery] = useState<'populares' | 'em_breve' | 'em_curso' | 'top_10_filmes' | 'top_10_series' | null>(null);
  const offlineState = useSyncExternalStore(subscribeOfflineState, getOfflineSnapshot, getOfflineSnapshot);
  const lastSyncedAt = useRef(offlineState.lastSyncedAt);

  useEffect(() => {
    if (!offlineState.lastSyncedAt || offlineState.lastSyncedAt === lastSyncedAt.current) return;
    lastSyncedAt.current = offlineState.lastSyncedAt;
    setDataRefreshKey((value) => value + 1);
  }, [offlineState.lastSyncedAt]);

  function openItem(item: Item) {
    setSelectedItem(item);
  }

  function openTab(nextTab: Tab) {
    setSelectedItem(null);
    setSelectedPerson(null);
    setShowCalendar(false);
    setDiscovery(null);
    setVisitedTabs((current) => new Set(current).add(nextTab));
    setTab(nextTab);
  }

  function invalidateUserData() {
    setDataRefreshKey((value) => value + 1);
  }

  const overlayOpen = !!selectedItem || !!selectedPerson || !!discovery || showCalendar;

  return (
    <View style={styles.root}>
      <SafeAreaView style={styles.headerSafe}><AppHeader user={user} onLogout={onLogout} onCalendar={() => { setSelectedItem(null); setSelectedPerson(null); setDiscovery(null); setShowCalendar(true); }} onProfile={() => openTab('profile')} /></SafeAreaView>
      <OfflineBanner />

      <View style={styles.content}>
        <View style={[styles.tabPage, (tab !== 'home' || overlayOpen) && styles.hidden]}><DashboardScreen onOpenItem={openItem} onOpenDiscovery={setDiscovery} refreshKey={dataRefreshKey} /></View>
        {visitedTabs.has('lists') ? <View style={[styles.tabPage, (tab !== 'lists' || overlayOpen) && styles.hidden]}><ListsScreen onOpenItem={openItem} refreshKey={dataRefreshKey} /></View> : null}
        {visitedTabs.has('collection') ? <View style={[styles.tabPage, (tab !== 'collection' || overlayOpen) && styles.hidden]}><CollectionScreen onOpenItem={openItem} refreshKey={dataRefreshKey} /></View> : null}
        {visitedTabs.has('search') ? <View style={[styles.tabPage, (tab !== 'search' || overlayOpen) && styles.hidden]}><SearchScreen onOpenItem={openItem} /></View> : null}
        {visitedTabs.has('profile') ? <View style={[styles.tabPage, (tab !== 'profile' || overlayOpen) && styles.hidden]}><ProfileScreen user={user} onOpenItem={openItem} onLogout={onLogout} onUserUpdated={onUserUpdated} refreshKey={dataRefreshKey} /></View> : null}
        {selectedPerson ? (
          <View style={styles.overlayPage}><PersonScreen castMember={selectedPerson} onBack={() => setSelectedPerson(null)} onOpenItem={(credit) => { setSelectedPerson(null); setSelectedItem(credit); }} /></View>
        ) : selectedItem ? (
          <View style={styles.overlayPage}><DetailScreen key={`${selectedItem.id_item || 0}-${selectedItem.tmdb_id || 0}-${selectedItem.tvmaze_id || 0}-${selectedItem.mal_id || 0}`} item={selectedItem} refreshKey={dataRefreshKey} onBack={() => setSelectedItem(null)} onSelectItem={(recItem) => setSelectedItem(recItem)} onSelectPerson={setSelectedPerson} onDataChanged={invalidateUserData} /></View>
        ) : discovery ? (
          <View style={styles.overlayPage}><DiscoveryScreen section={discovery} onBack={() => setDiscovery(null)} onOpenItem={openItem} /></View>
        ) : showCalendar ? (
          <View style={styles.overlayPage}><CalendarScreen onBack={() => setShowCalendar(false)} onOpenItem={openItem} /></View>
        ) : null}
      </View>

      <View style={styles.nav}>
        <TabButton type="home" active={!overlayOpen && tab === 'home'} onPress={() => openTab('home')} />
        <TabButton type="collection" active={!overlayOpen && tab === 'collection'} onPress={() => openTab('collection')} />
        <TabButton type="search" active={!overlayOpen && tab === 'search'} onPress={() => openTab('search')} />
        <TabButton type="heart" active={!overlayOpen && tab === 'lists'} onPress={() => openTab('lists')} />
        <TabButton type="user" active={!overlayOpen && tab === 'profile'} onPress={() => openTab('profile')} />
      </View>
    </View>
  );
}

function TabButton({ type, active, onPress }: { type: 'home' | 'heart' | 'play' | 'collection' | 'user' | 'search'; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.tab, active && styles.tabActive]}>
      <TabIcon type={type} active={active} />
    </Pressable>
  );
}

function TabIcon({ type, active }: { type: 'home' | 'heart' | 'play' | 'collection' | 'user' | 'search'; active: boolean }) {
  const color = active ? colors.text : colors.muted;
  if (type === 'home') {
    return <View style={styles.iconBox}><View style={[styles.homeRoof, { borderBottomColor: color }]} /><View style={[styles.homeBody, { borderColor: color }]} /></View>;
  }
  if (type === 'heart') {
    return <View style={styles.iconBox}><View style={[styles.heartDot, { backgroundColor: color, left: 11 }]} /><View style={[styles.heartDot, { backgroundColor: color, right: 11 }]} /><View style={[styles.heartBase, { backgroundColor: color }]} /></View>;
  }
  if (type === 'play') {
    return <View style={[styles.playBox, { borderColor: color }]}><View style={[styles.playTriangle, { borderLeftColor: color }]} /></View>;
  }
  if (type === 'collection') {
    return <View style={styles.iconBox}><View style={[styles.libraryShelf, { backgroundColor: color }]} /><View style={[styles.libraryShelf, { backgroundColor: color, top: 9 }]} /><View style={[styles.libraryShelf, { backgroundColor: color, top: 18 }]} /></View>;
  }
  if (type === 'user') {
    return <View style={styles.iconBox}><View style={[styles.userHead, { backgroundColor: color }]} /><View style={[styles.userBody, { backgroundColor: color }]} /></View>;
  }
  return <View style={styles.iconBox}><View style={[styles.searchCircle, { borderColor: color }]} /><View style={[styles.searchHandle, { backgroundColor: color }]} /></View>;
}

const styles = StyleSheet.create({
  root: { backgroundColor: colors.background, flex: 1 },
  headerSafe: { backgroundColor: colors.background },
  content: { flex: 1, position: 'relative' },
  tabPage: { flex: 1 },
  overlayPage: { ...StyleSheet.absoluteFillObject, backgroundColor: colors.background, zIndex: 5 },
  hidden: { display: 'none' },
  nav: {
    backgroundColor: alpha(colors.background, 0.96),
    borderColor: colors.surfaceRaised,
    borderRadius: 30,
    borderWidth: 1,
    bottom: 16,
    flexDirection: 'row',
    gap: 4,
    left: 16,
    padding: 7,
    position: 'absolute',
    right: 16,
  },
  tab: { alignItems: 'center', borderRadius: 24, flex: 1, height: 48, justifyContent: 'center' },
  tabActive: { backgroundColor: alpha(colors.accent, 0.25) },
  iconBox: { height: 28, position: 'relative', width: 28 },
  homeRoof: { borderBottomWidth: 12, borderLeftColor: 'transparent', borderLeftWidth: 10, borderRightColor: 'transparent', borderRightWidth: 10, height: 0, left: 4, position: 'absolute', top: 2, width: 0 },
  homeBody: { borderRadius: 3, borderWidth: 2, bottom: 3, height: 13, left: 7, position: 'absolute', width: 14 },
  heartDot: { borderRadius: 7, height: 13, position: 'absolute', top: 5, width: 13 },
  heartBase: { height: 13, left: 8, position: 'absolute', top: 11, transform: [{ rotate: '45deg' }], width: 13 },
  playBox: { alignItems: 'center', borderRadius: 6, borderWidth: 2, height: 24, justifyContent: 'center', width: 28 },
  playTriangle: { borderBottomColor: 'transparent', borderBottomWidth: 7, borderLeftWidth: 11, borderTopColor: 'transparent', borderTopWidth: 7, height: 0, marginLeft: 3, width: 0 },
  libraryShelf: { borderRadius: 2, height: 3, left: 5, position: 'absolute', top: 0, width: 18 },
  userHead: { borderRadius: 5, height: 10, left: 9, position: 'absolute', top: 4, width: 10 },
  userBody: { borderRadius: 8, bottom: 4, height: 10, left: 6, position: 'absolute', width: 16 },
  searchCircle: { borderRadius: 9, borderWidth: 2, height: 18, left: 3, position: 'absolute', top: 3, width: 18 },
  searchHandle: { borderRadius: 2, height: 9, position: 'absolute', right: 4, top: 18, transform: [{ rotate: '-45deg' }], width: 3 },
});
