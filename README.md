# Time View

Uma aplicacao web premium de catalogacao e rastreamento de series, filmes e animes baseada no TV Time, desenvolvida em PHP (Laminas Project), Javascript/jQuery e CSS customizado.

## 🚀 Funcionalidades

- **Catalogo Dinamico**: Buscas em tempo real integrando TVmaze, The Movie Database (TMDB) e MyAnimeList (MAL) como fallbacks.
- **AJAX sem Reload**: Filtros avançados, ordenacao e paginação executadas de forma assincrona atualizando dinamicamente apenas a regiao de dados.
- **Acompanhamento de Episodios**: Marque episodios de temporadas de forma simples e intuitiva (com controle para manter a aba ativa no refresh).
- **Autenticacao Segura**: Controle de acesso, login simplificado e medidor de forca de senha dinamico no cadastro.
- **Reporte de Feedback**: Envio direto de bugs ou sugestoes com carregamento spinner e captura de screenshot.
- **Visual Clean**: Layout moderno e responsivo adaptado para dispositivos moveis com visual escuro.

## 🛠️ Tecnologias Utilizadas

- **PHP 8.x** (Laminas Project MVC)
- **PostgreSQL**
- **HTML5 & Vanilla CSS**
- **jQuery & Bootstrap 5**
- **Docker & Docker Compose**

## 💻 Como Executar

1. Certifique-se de possuir o Docker instalado.
2. Execute o comando para iniciar a aplicação:
   ```bash
   docker compose up -d
   ```
3. Acesse a aplicação em seu navegador no endereço: [http://localhost:8080](http://localhost:8080).

## ⚙️ Configurações e Variáveis de Ambiente

A aplicação está configurada para funcionar tanto diretamente via **Docker** quanto executada **localmente** no host (ex: `php -S localhost:8080 -t public`).

As variáveis de ambiente configuradas no [docker-compose.yml](file:///c:/Users/Bruno/Documents/projetos/project-tvtime/docker-compose.yml) são:

*   `DB_HOST`: Nome do host de banco de dados (`db` no Docker, padrão `localhost` no host local)
*   `DB_PORT`: Porta do banco de dados (`5432` no Docker, padrão `5433` exposta para o host local)
*   `DB_NAME`: Nome do banco de dados (`tvtime_db`)
*   `DB_USER`: Usuário do banco de dados (`tvtime`)
*   `DB_PASSWORD`: Senha do banco de dados (`tvtime_pass`)

A aplicação detecta automaticamente se está rodando dentro do container Docker ou diretamente no host do desenvolvedor e faz o fallback de conexão inteligente do PostgreSQL (usando `localhost:5433` localmente ou `db:5432` no container).

## 📱 Responsividade & Experiência de Usuário Premium

O Time View utiliza uma estratégia **Mobile-First** com adaptações sob medida para computadores/desktops:

*   **Celular (Mobile)**: Mantém o layout nativo inspirado no aplicativo mobile do TV Time, com barra de navegação flutuante inferior em estilo *glassmorphic*, navegação fácil por toque e listas horizontais deslizantes.
*   **Computador (Desktop/Tablet)**: 
    *   A barra inferior de navegação é convertida dinamicamente em um **menu sidebar premium na lateral esquerda** com os nomes das seções exibidos ao lado dos ícones.
    *   As listas horizontais de continuar assistindo e elenco passam a ser exibidas em **grids responsivos fluidos** otimizados para o mouse.
    *   A página de detalhes do item se reorganiza em **duas colunas estruturadas** (poster à esquerda, informações e lista de episódios em grid de 2 colunas à direita).
    *   Os overlays de modal e carregadores que cobriam a tela inteira de forma esticada no desktop são **centralizados em janelas cartão elegantes**.

## 📁 Estrutura de Diretórios do Projeto

*   `config/`: Arquivos de configuração global e módulos do Laminas MVC.
*   `module/Application/`: Código-fonte principal da aplicação (Controllers, Models, Views).
*   `public/`: Ponto de entrada web contendo assets CSS (`public/css/style.css`), JavaScript (`public/js/app.js`) e imagens.
*   `Dockerfile` & `docker-compose.yml`: Definições de containerização para ambiente de desenvolvimento isolado.
