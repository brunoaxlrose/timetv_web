import AsyncStorage from '@react-native-async-storage/async-storage';

export type PaletteKey = 'ambar' | 'atlantica' | 'cabernet';

export type ThemeColors = {
  background: string;
  surface: string;
  surfaceRaised: string;
  text: string;
  muted: string;
  accent: string;
  info: string;
  success: string;
  danger: string;
};

export const palettes: Array<{ key: PaletteKey; name: string; description: string; swatches: string[]; colors: ThemeColors }> = [
  {
    key: 'ambar',
    name: 'CineFio Âmbar',
    description: 'Azul profundo, marfim e âmbar',
    swatches: ['#273343', '#D0CCC3', '#A8A8A8', '#D99951', '#505052'],
    colors: {
      background: '#273343', surface: '#394452', surfaceRaised: '#505052', text: '#D0CCC3', muted: '#A8A8A8',
      accent: '#D99951', info: '#D99951', success: '#D99951', danger: '#C87562',
    },
  },
  {
    key: 'atlantica',
    name: 'Noite Atlântica',
    description: 'Petróleo, névoa e verde-água',
    swatches: ['#101B26', '#E8E3D8', '#A6B0B7', '#5EB6B0', '#314356'],
    colors: {
      background: '#101B26', surface: '#1B2A38', surfaceRaised: '#314356', text: '#E8E3D8', muted: '#A6B0B7',
      accent: '#5EB6B0', info: '#78C6C0', success: '#70BFA0', danger: '#D4776A',
    },
  },
  {
    key: 'cabernet',
    name: 'Cabernet',
    description: 'Vinho escuro, areia e cobre',
    swatches: ['#24181B', '#F0E6D8', '#B8A7A2', '#C9725D', '#594047'],
    colors: {
      background: '#24181B', surface: '#352429', surfaceRaised: '#594047', text: '#F0E6D8', muted: '#B8A7A2',
      accent: '#C9725D', info: '#D58B72', success: '#9BB58A', danger: '#DE6F67',
    },
  },
];

const PALETTE_KEY = 'cinefio:palette';
let activePaletteKey: PaletteKey = 'ambar';

export const colors: ThemeColors = {
  background: '#273343',
  surface: '#394452',
  surfaceRaised: '#505052',
  text: '#D0CCC3',
  muted: '#A8A8A8',
  accent: '#D99951',
  info: '#D99951',
  success: '#D99951',
  danger: '#C87562',
};

export function getActivePaletteKey() {
  return activePaletteKey;
}

export function applyPalette(key: PaletteKey) {
  const palette = palettes.find((item) => item.key === key) || palettes[0];
  activePaletteKey = palette.key;
  Object.assign(colors, palette.colors);
}

export async function hydratePalette() {
  const saved = await AsyncStorage.getItem(PALETTE_KEY);
  const key = palettes.some((item) => item.key === saved) ? saved as PaletteKey : 'ambar';
  applyPalette(key);
  return key;
}

export async function savePalette(key: PaletteKey) {
  await AsyncStorage.setItem(PALETTE_KEY, key);
  applyPalette(key);
}

export function alpha(color: string, opacity: number) {
  const value = color.replace('#', '');
  const normalized = value.length === 3 ? value.split('').map((part) => part + part).join('') : value.slice(0, 6);
  const alphaHex = Math.round(Math.max(0, Math.min(1, opacity)) * 255).toString(16).padStart(2, '0');
  return `#${normalized}${alphaHex}`;
}
