import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { User } from '../api/auth';
import { AppHeader } from '../components/AppHeader';
import { colors } from '../theme/colors';
import { Item } from '../types';
import { CollectionScreen } from '../screens/CollectionScreen';
import { DashboardScreen } from '../screens/DashboardScreen';
import { DetailScreen } from '../screens/DetailScreen';
import { ListsScreen } from '../screens/ListsScreen';
import { ProfileScreen } from '../screens/ProfileScreen';
import { SearchScreen } from '../screens/SearchScreen';

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
  const [collectionRefreshKey, setCollectionRefreshKey] = useState(0);

  function openItem(item: Item) {
    setSelectedItem(item);
  }

  return (
    <View style={styles.root}>
      <AppHeader user={user} onLogout={onLogout} onProfile={() => { setSelectedItem(null); setTab('profile'); }} />

      <View style={styles.content}>
        {selectedItem ? (
          <DetailScreen item={selectedItem} onBack={() => setSelectedItem(null)} onSelectItem={(recItem) => setSelectedItem(recItem)} />
        ) : tab === 'home' ? (
          <DashboardScreen onOpenItem={openItem} onOpenLists={() => setTab('lists')} />
        ) : tab === 'lists' ? (
          <ListsScreen onOpenItem={openItem} />
        ) : tab === 'collection' ? (
          <CollectionScreen onOpenItem={openItem} refreshKey={collectionRefreshKey} />
        ) : tab === 'search' ? (
          <SearchScreen onOpenItem={openItem} />
        ) : (
          <ProfileScreen user={user} onOpenItem={openItem} onLogout={onLogout} onUserUpdated={onUserUpdated} />
        )}
      </View>

      <View style={styles.nav}>
        <TabButton type="home" active={!selectedItem && tab === 'home'} onPress={() => { setSelectedItem(null); setTab('home'); }} />
        <TabButton type="collection" active={!selectedItem && tab === 'collection'} onPress={() => { setSelectedItem(null); setTab('collection'); setCollectionRefreshKey((value) => value + 1); }} />
        <TabButton type="search" active={!selectedItem && tab === 'search'} onPress={() => { setSelectedItem(null); setTab('search'); }} />
        <TabButton type="heart" active={!selectedItem && tab === 'lists'} onPress={() => { setSelectedItem(null); setTab('lists'); }} />
        <TabButton type="user" active={!selectedItem && tab === 'profile'} onPress={() => { setSelectedItem(null); setTab('profile'); }} />
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
  content: { flex: 1 },
  nav: {
    backgroundColor: 'rgba(17,17,24,0.96)',
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
  tabActive: { backgroundColor: 'rgba(139,92,246,0.25)' },
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
