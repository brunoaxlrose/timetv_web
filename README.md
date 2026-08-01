# Time View

Time View e um app para acompanhar filmes, series, animes e novelas em um visual dark premium, com foco em catalogo, progresso, favoritos, listas, episodios e avaliacoes.

## Visao Geral

O projeto esta dividido em duas partes:

- `mobile/`: app React Native com Expo Go.
- `module/Application/`: backend Laminas que serve a API JSON e a aplicacao web existente.
- `module/data/sql/data01.sql`: script completo para recriar as tabelas do banco.

O app mobile consome a API REST e nao depende das views HTML.

## O Que O App Faz

- Buscar catalogo em filmes, series, animes e novelas.
- Abrir detalhe de titulos vindos do banco local, TVMaze, TMDB ou Jikan.
- Importar titulo automaticamente quando ele ainda nao existe na base.
- Mostrar sinopse, poster, banner, elenco, episodios e progresso.
- Marcar filme, episodio, temporada, anteriores ou tudo como visto.
- Criar entrada na colecao mesmo quando o usuario marca apenas episodios.
- Favoritar, remover favoritos e adicionar titulos em listas.
- Criar, renomear e excluir listas.
- Salvar avaliacoes e comentarios.
- Editar perfil, senha e nome de usuario.
- Enviar feedback do app.

## Requisitos

- Docker e Docker Compose para rodar o backend.
- Node.js e npm para rodar o app mobile.
- Expo Go atualizado para SDK 54.

O projeto mobile esta ajustado para Expo SDK 54, entao funciona com a versao atual do Expo Go.

## Como Rodar O Backend

```powershell
docker compose up -d --build
```

Depois de alterar PHP, factories, controllers ou helpers:

```powershell
docker compose restart web
```

Para ver logs do backend:

```powershell
docker logs --tail 120 tvtime_clone
```

## Como Rodar O Mobile

```powershell
cd mobile
npm install
$env:EXPO_PUBLIC_API_URL="http://SEU_IP_LOCAL:8080"
npx expo start -c
```

Exemplo:

```powershell
$env:EXPO_PUBLIC_API_URL="http://192.168.0.18:8080"
npx expo start -c
```

Se a porta `8081` estiver presa:

```powershell
Get-NetTCPConnection -LocalPort 8081 | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
```

## Banco De Dados

Para recriar tudo do zero, use o script:

```text
module/data/sql/data01.sql
```

Pontos importantes do schema atual:

- `usuario_lista` usa `ts_inclusao`.
- `usuario` possui `api_token_hash` para login mobile via Bearer token.
- `item` possui `genres`, `watch_providers`, `last_sync` e `release_date`.
- `episodio` possui `image_url` e chave unica por `id_item`, `season_number` e `episode_number`.
- `usuario_item` aceita os status `watching`, `completed`, `dropped`, `plan_to_watch`, `rewatching` e `abandoned`.

O backend tambem executa ajustes leves de schema ao iniciar, via `DatabaseSchemaHelper`.

## Autenticacao Mobile

O login e o cadastro retornam `api_token`. O app salva esse token e envia nas chamadas autenticadas:

```http
Authorization: Bearer SEU_TOKEN
```

Mensagens de login foram ajustadas para ficarem mais amigaveis:

- Email ou senha incorretos.
- Sessao expirada quando o token nao for mais valido.

## Catalogo, Detalhe E Episodios

O detalhe tenta resolver o titulo usando os identificadores disponiveis:

- `id`
- `tmdb_id`
- `tvmaze_id`
- `mal_id`
- `title`, `type`, `release_year`, `poster_url` e `banner_url`

Quando o item nao existe na base, o backend tenta importar:

- Filmes pelo TMDB.
- Series pelo TVMaze ou TMDB.
- Animes pelo Jikan/MyAnimeList.

Se a API externa falhar, o backend cria um item local minimo com os dados recebidos pelo app para evitar erro ao abrir o titulo.

## Colecao E Progresso

A colecao considera:

- Titulos marcados diretamente em `usuario_item`.
- Series e animes que possuem episodios marcados em `usuario_episodio`.

Ao marcar episodio, temporada ou serie como vista, o backend garante uma linha em `usuario_item`, assim o titulo aparece na aba Colecao/Catalogo do app.

A tela de colecao recarrega ao tocar novamente na aba, para refletir marcacoes feitas em outras telas.

## Endpoints Da API

### Autenticacao

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/register`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`

### Mobile

- `GET /api/v1/mobile/dashboard`
- `GET /api/v1/mobile/search`
- `GET /api/v1/mobile/collection`
- `GET /api/v1/mobile/detail`
- `POST /api/v1/mobile/favorite/toggle`
- `POST /api/v1/mobile/track`
- `POST /api/v1/mobile/episodes/mark`
- `POST /api/v1/mobile/episodes/rewatch`
- `POST /api/v1/mobile/review`
- `POST /api/v1/mobile/feedback`

### Listas

- `GET /api/v1/mobile/lists`
- `GET /api/v1/mobile/lists/items`
- `POST /api/v1/mobile/lists/create`
- `POST /api/v1/mobile/lists/rename`
- `POST /api/v1/mobile/lists/delete`
- `POST /api/v1/mobile/lists/add`

## Como Testar No Postman

### 1. Login

- Metodo: `POST`
- URL: `http://localhost:8080/api/v1/auth/login`
- Body: raw JSON

```json
{
  "email": "seuemail@exemplo.com",
  "password": "suasenha"
}
```

Guarde o `api_token` retornado e envie nas proximas chamadas:

```http
Authorization: Bearer SEU_TOKEN
```

### 2. Buscar Catalogo

- Metodo: `GET`
- URL: `http://localhost:8080/api/v1/mobile/search?search=supernatural`

### 3. Abrir Detalhe

- Metodo: `GET`
- URL com item local:

```text
http://localhost:8080/api/v1/mobile/detail?id=161
```

- URL com item externo:

```text
http://localhost:8080/api/v1/mobile/detail?tvmaze_id=92051&type=series&title=Quem+Ama+Cuida&release_year=2026
```

### 4. Marcar Episodio Como Visto

- Metodo: `POST`
- URL: `http://localhost:8080/api/v1/mobile/episodes/mark`
- Body: raw JSON

```json
{
  "item_id": 161,
  "mode": "single",
  "episode_id": 123
}
```

### 5. Marcar Filme Como Visto

- Metodo: `POST`
- URL: `http://localhost:8080/api/v1/mobile/track`
- Body: raw JSON

```json
{
  "item_id": 161,
  "type": "movie",
  "status": "completed"
}
```

### 6. Criar Lista

- Metodo: `POST`
- URL: `http://localhost:8080/api/v1/mobile/lists/create`
- Body: raw JSON

```json
{
  "name": "Para assistir"
}
```

### 7. Enviar Avaliacao

- Metodo: `POST`
- URL: `http://localhost:8080/api/v1/mobile/review`
- Body: raw JSON

```json
{
  "item_id": 161,
  "type": "movie",
  "rating": 5,
  "comment": "Otimo filme"
}
```

## Troubleshooting

### Expo Go incompativel

Se aparecer que o Expo Go esta em SDK 54 e o projeto em SDK antigo:

```powershell
cd mobile
npm install
npx expo start -c
```

### Colecao vazia ou erro 500

Reinicie o backend depois das alteracoes:

```powershell
docker compose restart web
```

Depois confira os logs:

```powershell
docker logs --tail 120 tvtime_clone
```

### Banco recriado

Depois de excluir e recriar as tabelas, faca login novamente no app para gerar um novo `api_token`.

## Estrutura Resumida

- `mobile/src/api/`
- `mobile/src/components/`
- `mobile/src/navigation/`
- `mobile/src/screens/`
- `module/Application/src/Controller/Api/`
- `module/Application/src/Controller/Factory/`
- `module/Application/src/Helper/`
- `module/Application/src/Model/`
- `module/data/sql/`
