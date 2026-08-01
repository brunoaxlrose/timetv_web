# CineFio Mobile

Aplicativo React Native/Expo que consumira a API REST do backend Laminas.

## Rodando

```bash
cd mobile
npm install
npm run start
```

Para apontar para outro backend:

```bash
EXPO_PUBLIC_API_URL=http://SEU_IP:8080 npm run start
```

Em emulador Android, normalmente use o IP da maquina na rede em vez de `localhost`.

## Primeiro fluxo implementado

- `POST /api/v1/episodes/watched`
- componente de exemplo em `src/screens/EpisodeExampleScreen.tsx`
- cliente HTTP central em `src/api/client.ts`
