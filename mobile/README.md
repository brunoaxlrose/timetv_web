# CineFio Mobile

Aplicativo React Native/Expo que consumira a API REST do backend Laminas.

## Rodando

```bash
cd mobile
npm install
npm run start
```

Por padrao, o app usa a API publicada no Render. As duas URLs ficam configuradas em `mobile/.env`:

```dotenv
EXPO_PUBLIC_API_ENV=production
EXPO_PUBLIC_LOCAL_API_URL=http://SEU_IP:8080
EXPO_PUBLIC_PRODUCTION_API_URL=https://cinefio-api.onrender.com
```

Para voltar ao Docker local, altere apenas `EXPO_PUBLIC_API_ENV` para `local` e reinicie o Expo com `npx expo start --clear`. No iPhone e no emulador Android, use o IP da maquina na rede em vez de `localhost`.

`EXPO_PUBLIC_API_URL` continua disponivel como sobrescrita temporaria e tem prioridade sobre os dois ambientes.

## Primeiro fluxo implementado

- `POST /api/v1/episodes/watched`
- componente de exemplo em `src/screens/EpisodeExampleScreen.tsx`
- cliente HTTP central em `src/api/client.ts`
