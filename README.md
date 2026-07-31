# Time View

Aplicacao web de catalogacao e rastreamento de filmes, series, animes e novelas com experiencia mobile-first inspirada em apps como TV Time.

## Visao Geral

O projeto usa:

- PHP com Laminas MVC
- PostgreSQL
- jQuery + Bootstrap 5
- CSS customizado
- Docker para ambiente local

O frontend funciona como uma SPA leve:

- links internos carregam o conteudo via AJAX
- o layout principal permanece na tela
- scripts por pagina sao reinjetados dinamicamente
- existe cache em memoria para acelerar navegacao entre rotas

## Funcionalidades

- Busca de catalogo com TVmaze, TMDB e fallbacks
- Dashboard com continuar assistindo, listas, populares e em breve
- Pagina de detalhe com:
  - abas de sobre e episodios
  - marcar como visto
  - bloquear conteudo ainda nao lancado
  - favoritos
  - avaliacao pessoal liberada apenas apos lancamento
- Listas personalizadas com:
  - criacao e exclusao
  - adicionar e remover itens
  - abertura em modal com carregamento sob demanda
- Perfil com edicao via AJAX
- Feedback com screenshot
- Confirmacoes customizadas em modal

## Otimizacoes Recentes

- `public/js/app.js` foi dividido em modulos:
  - `public/js/app/core.js`
  - `public/js/app/navigation.js`
  - `public/js/app/tracking.js`
  - `public/js/app/modals.js`
  - `public/js/app/catalog/detail.js`
- `/dashboard` foi otimizado com:
  - cache local das respostas do TMDB
  - remocao de consulta N+1 no bloco `Continuar`
- `/lists` agora carrega apenas resumo das listas na rota principal
- itens de cada lista sao carregados sob demanda em modal
- o menu do coracao (`/lists`) foi configurado para forcar refresh e rerender dos itens

## Como Executar

1. Tenha Docker e Docker Compose instalados.
2. Suba os containers:

```bash
docker compose up -d
```

3. Acesse:

```txt
http://localhost:8080
```

## Variaveis de Ambiente

Configuradas principalmente via `docker-compose.yml`:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`

O projeto suporta execucao em container e tambem ambiente local, com fallback de conexao para PostgreSQL.

## Estrutura do Projeto

- `config/`: configuracoes globais e modulos
- `module/Application/src/`: controllers, models e helpers
- `module/Application/view/`: views `.phtml`
- `public/css/`: estilos
- `public/js/`: scripts da aplicacao
- `data/cache/tmdb/`: cache local das respostas do TMDB

## Observacoes de Manutencao

- Ao mexer em regras de lancamento, revisar:
  - `module/Application/src/Controller/TrackingController.php`
  - `module/Application/src/Controller/CatalogController.php`
  - `module/Application/src/Model/TrackingModel.php`
  - `public/js/app/catalog/detail.js`
- Ao mexer em navegacao SPA, revisar:
  - `public/js/app/core.js`
  - `public/js/app/modals.js`
- Ao mexer em listas, revisar:
  - `module/Application/view/tracking/lists.phtml`
  - `module/Application/src/Model/TrackingModel.php`
  - `module/Application/src/Controller/TrackingController.php`
