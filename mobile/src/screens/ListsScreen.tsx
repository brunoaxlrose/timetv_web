import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Image, KeyboardAvoidingView, Modal, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { createList, deleteList, getListItems, getLists, renameList } from '../api/mobile';
import { ConfirmModal } from '../components/ConfirmModal';
import { PosterCard } from '../components/PosterCard';
import { Skeleton } from '../components/Skeleton';
import { useToast } from '../components/Toast';
import { colors } from '../theme/colors';
import { Item, UserList } from '../types';

export function ListsScreen({ onOpenItem, refreshKey = 0 }: { onOpenItem: (item: Item) => void; refreshKey?: number }) {
  const { showToast } = useToast();
  const [lists, setLists] = useState<UserList[]>([]);
  const [items, setItems] = useState<Item[]>([]);
  const [selected, setSelected] = useState<UserList | null>(null);
  const [newListOpen, setNewListOpen] = useState(false);
  const [newListName, setNewListName] = useState('');
  const [editingList, setEditingList] = useState<UserList | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<UserList | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadingItems, setLoadingItems] = useState(false);
  const [savingList, setSavingList] = useState(false);

  async function load(refresh = false) {
    if (refresh) setRefreshing(true);
    try {
      const response = await getLists();
      setLists(response.data?.lists || []);
    } catch {
      // Preserve lists already shown when there is no cached response yet.
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }

  useEffect(() => { load(); }, [refreshKey]);

  async function openList(list: UserList) {
    setSelected(list);
    setItems([]);
    if (list.id_lista < 0) return;
    setLoadingItems(true);
    try {
      const response = await getListItems(list.id_lista);
      setItems(response.data?.items || []);
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Nao foi possivel carregar esta lista.', 'error');
    } finally {
      setLoadingItems(false);
    }
  }

  async function submitCreateList() {
    if (!newListName.trim() || savingList) return;
    setSavingList(true);
    try {
      const name = newListName.trim();
      const response = await createList(name);
      if (response.queued) {
        const created = response.data?.lists?.[0];
        if (created) setLists((current) => [created, ...current]);
      } else {
        setLists(response.data?.lists || []);
      }
      setNewListName('');
      setNewListOpen(false);
      showToast(response.queued ? 'Lista salva offline.' : 'Lista criada.', response.queued ? 'info' : 'success');
    } finally {
      setSavingList(false);
    }
  }

  async function submitRenameList() {
    if (!editingList || !newListName.trim() || savingList) return;
    setSavingList(true);
    try {
      const listId = editingList.id_lista;
      const name = newListName.trim();
      const response = await renameList(listId, name);
      if (response.queued) {
        setLists((current) => current.map((list) => list.id_lista === listId ? { ...list, nome: name } : list));
      } else {
        setLists(response.data?.lists || []);
      }
      setEditingList(null);
      setNewListName('');
      showToast(response.queued ? 'Alteracao salva offline.' : 'Lista renomeada.', response.queued ? 'info' : 'success');
    } finally {
      setSavingList(false);
    }
  }

  async function submitDeleteList() {
    if (!deleteTarget) return;
    const listId = deleteTarget.id_lista;
    const response = await deleteList(listId);
    if (response.queued) setLists((current) => current.filter((list) => list.id_lista !== listId));
    else setLists(response.data?.lists || []);
    if (selected?.id_lista === listId) setSelected(null);
    setDeleteTarget(null);
    showToast(response.queued ? 'Exclusao salva offline.' : 'Lista excluida.', response.queued ? 'info' : 'success');
  }

  return (
    <ScrollView
      style={styles.screen}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(true)} tintColor={colors.accent} />}
    >
      <View style={styles.header}>
        <Text style={styles.title}>As tuas listas</Text>
        <Pressable onPress={() => setNewListOpen(true)} style={styles.createButton}>
          <Text style={styles.createText}>+ Criar</Text>
        </Pressable>
      </View>

      {loading ? (
        <>
          <Skeleton height={96} />
          <View style={{ height: 12 }} />
          <Skeleton height={96} />
        </>
      ) : lists.length ? (
        lists.map((list) => (
          <Pressable key={list.id_lista} onPress={() => openList(list)} style={styles.listCard}>
            {list.cover_poster_url ? <Image source={{ uri: list.cover_poster_url }} style={styles.cover} /> : <View style={styles.cover} />}
            <View style={styles.listCopy}>
              <Text style={styles.listName}>{list.nome}</Text>
              <Text style={styles.count}>{list.item_count} itens</Text>
              <Text style={styles.hint}>Toque para abrir a lista</Text>
            </View>
            <View style={styles.cardActions}>
              <Pressable onPress={() => { setEditingList(list); setNewListName(list.nome); }} style={styles.smallAction}><EditIcon /></Pressable>
              <Pressable onPress={() => setDeleteTarget(list)} style={styles.smallDanger}><TrashIcon /></Pressable>
            </View>
          </Pressable>
        ))
      ) : (
        <Text style={styles.empty}>Crie sua primeira lista para organizar seus titulos.</Text>
      )}

      <Modal visible={!!selected} animationType="slide" transparent onRequestClose={() => setSelected(null)}>
        <View style={styles.modalOverlay}>
          <View style={styles.modalSheet}>
            <View style={styles.modalHeader}>
              <View>
                <Text style={styles.modalTitle}>{selected?.nome}</Text>
                <Text style={styles.count}>{items.length} itens</Text>
              </View>
              <Pressable onPress={() => setSelected(null)}><Text style={styles.close}>Fechar</Text></Pressable>
            </View>
            {loadingItems ? (
              <ActivityIndicator color={colors.accent} style={styles.modalLoader} />
            ) : (
              <FlatList
                data={items}
                keyExtractor={(item, index) => String(item.id_item || index)}
                numColumns={3}
                renderItem={({ item }) => <PosterCard item={item} onPress={onOpenItem} />}
                columnWrapperStyle={{ gap: 12 }}
                contentContainerStyle={{ gap: 14, paddingBottom: 30 }}
                ListEmptyComponent={<Text style={styles.empty}>Nenhum item nesta lista ainda.</Text>}
              />
            )}
          </View>
        </View>
      </Modal>

      <Modal visible={newListOpen} animationType="fade" transparent onRequestClose={() => setNewListOpen(false)}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={styles.modalOverlayCenter}>
          <View style={styles.createSheet}>
            <Text style={styles.modalTitle}>Criar Nova Lista</Text>
            <Text style={styles.hint}>Exemplo: Para assistir no fim de semana</Text>
            <TextInput
              onChangeText={setNewListName}
              placeholder="Nome da lista..."
              placeholderTextColor={colors.muted}
              style={styles.input}
              value={newListName}
            />
            <View style={styles.createActions}>
              <Pressable onPress={() => setNewListOpen(false)} style={styles.cancelButton}><Text style={styles.cancelText}>Cancelar</Text></Pressable>
              <Pressable disabled={savingList || !newListName.trim()} onPress={submitCreateList} style={[styles.saveButton, (savingList || !newListName.trim()) && styles.buttonDisabled]}>
                {savingList ? <ActivityIndicator color={colors.text} /> : <Text style={styles.saveText}>Criar</Text>}
              </Pressable>
            </View>
          </View>
        </KeyboardAvoidingView>
      </Modal>

      <Modal visible={!!editingList} animationType="fade" transparent onRequestClose={() => setEditingList(null)}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={styles.modalOverlayCenter}>
          <View style={styles.createSheet}>
            <Text style={styles.modalTitle}>Renomear lista</Text>
            <TextInput
              onChangeText={setNewListName}
              placeholder="Nome da lista..."
              placeholderTextColor={colors.muted}
              style={styles.input}
              value={newListName}
            />
            <View style={styles.createActions}>
              <Pressable onPress={() => setEditingList(null)} style={styles.cancelButton}><Text style={styles.cancelText}>Cancelar</Text></Pressable>
              <Pressable disabled={savingList || !newListName.trim()} onPress={submitRenameList} style={[styles.saveButton, (savingList || !newListName.trim()) && styles.buttonDisabled]}>
                {savingList ? <ActivityIndicator color={colors.text} /> : <Text style={styles.saveText}>Salvar</Text>}
              </Pressable>
            </View>
          </View>
        </KeyboardAvoidingView>
      </Modal>

      <ConfirmModal
        visible={!!deleteTarget}
        title="Excluir lista"
        message={`Deseja realmente excluir "${deleteTarget?.nome || ''}"? Os itens associados serao removidos desta lista.`}
        confirmLabel="Excluir"
        destructive
        onCancel={() => setDeleteTarget(null)}
        onConfirm={submitDeleteList}
      />

      <View style={{ height: 120 }} />
    </ScrollView>
  );
}

function EditIcon() {
  return (
    <View style={styles.iconDraw}>
      <View style={styles.editLine} />
      <View style={styles.editTip} />
    </View>
  );
}

function TrashIcon() {
  return (
    <View style={styles.iconDraw}>
      <View style={styles.trashLid} />
      <View style={styles.trashBody} />
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1, padding: 16 },
  header: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 16 },
  title: { color: colors.text, fontSize: 24, fontWeight: '900' },
  createButton: { backgroundColor: colors.accent, borderRadius: 999, paddingHorizontal: 16, paddingVertical: 10 },
  createText: { color: colors.text, fontWeight: '900' },
  listCard: {
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderColor: colors.surfaceRaised,
    borderRadius: 16,
    borderWidth: 1,
    flexDirection: 'row',
    gap: 12,
    marginBottom: 12,
    padding: 12,
  },
  cover: { backgroundColor: colors.surfaceRaised, borderRadius: 10, height: 76, width: 54 },
  listCopy: { flex: 1 },
  listName: { color: colors.text, fontSize: 16, fontWeight: '900' },
  count: { color: colors.muted, fontSize: 12, marginTop: 4 },
  hint: { color: colors.muted, fontSize: 11, marginTop: 4 },
  cardActions: { gap: 8 },
  smallAction: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderRadius: 10, paddingHorizontal: 10, paddingVertical: 8 },
  smallDanger: { alignItems: 'center', borderColor: colors.danger, borderRadius: 10, borderWidth: 1, paddingHorizontal: 10, paddingVertical: 8 },
  iconDraw: { height: 18, position: 'relative', width: 18 },
  editLine: { backgroundColor: colors.text, borderRadius: 2, height: 4, left: 2, position: 'absolute', top: 8, transform: [{ rotate: '-35deg' }], width: 14 },
  editTip: { borderBottomColor: colors.text, borderBottomWidth: 4, borderLeftColor: 'transparent', borderLeftWidth: 3, borderRightColor: 'transparent', borderRightWidth: 3, height: 0, position: 'absolute', right: 0, top: 5, transform: [{ rotate: '-35deg' }], width: 0 },
  trashLid: { backgroundColor: colors.danger, borderRadius: 2, height: 3, left: 3, position: 'absolute', top: 2, width: 12 },
  trashBody: { borderColor: colors.danger, borderRadius: 2, borderWidth: 2, height: 12, left: 4, position: 'absolute', top: 6, width: 10 },
  empty: { color: colors.muted, paddingTop: 16 },
  modalOverlay: { backgroundColor: 'rgba(0,0,0,0.7)', flex: 1, justifyContent: 'flex-end' },
  modalOverlayCenter: { alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.76)', flex: 1, justifyContent: 'center', padding: 18 },
  modalSheet: { backgroundColor: colors.background, borderTopLeftRadius: 24, borderTopRightRadius: 24, maxHeight: '82%', padding: 16 },
  createSheet: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 22, borderWidth: 1, padding: 18, width: '100%' },
  modalHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 16 },
  modalTitle: { color: colors.text, fontSize: 20, fontWeight: '900' },
  close: { color: colors.accent, fontWeight: '900' },
  modalLoader: { paddingVertical: 28 },
  input: { backgroundColor: colors.background, borderColor: colors.surfaceRaised, borderRadius: 14, borderWidth: 1, color: colors.text, marginTop: 14, minHeight: 52, paddingHorizontal: 14 },
  createActions: { flexDirection: 'row', gap: 10, marginTop: 14 },
  cancelButton: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderRadius: 16, flex: 1, padding: 14 },
  cancelText: { color: colors.text, fontWeight: '900' },
  saveButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, flex: 1, padding: 14 },
  buttonDisabled: { opacity: 0.55 },
  saveText: { color: colors.text, fontWeight: '900' },
});
