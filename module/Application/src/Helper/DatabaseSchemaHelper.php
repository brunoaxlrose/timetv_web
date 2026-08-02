<?php

namespace Application\Helper;

class DatabaseSchemaHelper {
    public static function ensureTablesExist(\PDO $pdo) {
        $queries = [
            // 1. Usuario
            "CREATE TABLE IF NOT EXISTS usuario (
                id_usuario SERIAL PRIMARY KEY,
                nome_usuario VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                hash_senha VARCHAR(255) NOT NULL,
                nome VARCHAR(100) NOT NULL DEFAULT '',
                sobrenome VARCHAR(100) NOT NULL DEFAULT '',
                url_avatar TEXT NULL,
                hash_token_api TEXT NULL,
                hash_token_api_ts_inclusao TIMESTAMP NULL,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL
            );",

            // 2. Item (Movies, Series & Anime)
            "CREATE TABLE IF NOT EXISTS item (
                id_item SERIAL PRIMARY KEY,
                tvmaze_id INT UNIQUE NULL,
                tmdb_id INT UNIQUE NULL,
                mal_id INT UNIQUE NULL,
                titulo VARCHAR(255) NOT NULL,
                tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('series', 'anime', 'movie')),
                descricao TEXT,
                url_poster TEXT,
                url_banner TEXT,
                ano_lancamento INT,
                data_lancamento DATE,
                total_episodios INT DEFAULT 0,
                duracao_minutos INT DEFAULT 45,
                status VARCHAR(50) DEFAULT 'Running',
                generos TEXT NULL,
                provedores_streaming TEXT NULL,
                ts_ultima_sincronizacao TIMESTAMP NULL,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL
            );",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS tmdb_id INT UNIQUE NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS mal_id INT UNIQUE NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS data_lancamento DATE;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS total_episodios INT DEFAULT 0;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS duracao_minutos INT DEFAULT 45;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Running';",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS generos TEXT NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS provedores_streaming TEXT NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS ts_ultima_sincronizacao TIMESTAMP NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS ts_cancelamento TIMESTAMP NULL;",

            // 3. Episodio
            "CREATE TABLE IF NOT EXISTS episodio (
                id_episodio SERIAL PRIMARY KEY,
                id_item INT NOT NULL,
                numero_temporada INT NOT NULL,
                numero_episodio INT NOT NULL,
                titulo VARCHAR(255) NOT NULL,
                descricao TEXT,
                data_exibicao DATE,
                duracao_minutos INT DEFAULT 45,
                nota NUMERIC(3,1),
                url_imagem TEXT,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT episodio_fk_item FOREIGN KEY (id_item) REFERENCES item(id_item) ON DELETE CASCADE,
                CONSTRAINT uk_episodio_item_temporada_numero UNIQUE (id_item, numero_temporada, numero_episodio),
                CONSTRAINT episodio_nota_check CHECK (nota IS NULL OR nota BETWEEN 0 AND 10)
            );",

            // 4. Usuario_Item (Watchlist status)
            "CREATE TABLE IF NOT EXISTS usuario_item (
                id_usuario_item SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL,
                id_item INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'assistindo' CHECK (status IN ('assistindo', 'concluido', 'em_pausa', 'quero_ver', 'avaliado', 'reassistindo', 'abandonado')),
                nota NUMERIC(3,1) NULL,
                comentario TEXT NULL,
                eh_favorito BOOLEAN NOT NULL DEFAULT FALSE,
                quantidade_reassistida INT DEFAULT 0,
                ts_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT usuario_item_fk_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                CONSTRAINT usuario_item_fk_item FOREIGN KEY (id_item) REFERENCES item(id_item) ON DELETE CASCADE,
                CONSTRAINT uk_usuario_item UNIQUE (id_usuario, id_item)
            );",

            // 5. Usuario_Episodio (Watched episodes tracking)
            "CREATE TABLE IF NOT EXISTS usuario_episodio (
                id_usuario_episodio SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL,
                id_episodio INT NOT NULL,
                quantidade_reassistida INT DEFAULT 0,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT usuario_episodio_fk_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                CONSTRAINT usuario_episodio_fk_episodio FOREIGN KEY (id_episodio) REFERENCES episodio(id_episodio) ON DELETE CASCADE,
                CONSTRAINT uk_usuario_episodio UNIQUE (id_usuario, id_episodio)
            );",

            // 6. Feedback
            "CREATE TABLE IF NOT EXISTS feedback (
                id_feedback SERIAL PRIMARY KEY,
                id_usuario INT NULL,
                tipo_feedback VARCHAR(20) NOT NULL DEFAULT 'bug',
                conteudo TEXT NOT NULL,
                captura_tela TEXT NULL,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT feedback_fk_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE
            );",
            "ALTER TABLE feedback ADD COLUMN IF NOT EXISTS captura_tela TEXT NULL;",
            "ALTER TABLE feedback ADD COLUMN IF NOT EXISTS ts_cancelamento TIMESTAMP NULL;",
            "ALTER TABLE feedback ADD COLUMN IF NOT EXISTS id_mutacao_cliente VARCHAR(100) NULL;",
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_feedback_usuario_mutacao ON feedback (id_usuario, id_mutacao_cliente) WHERE id_mutacao_cliente IS NOT NULL;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS quantidade_reassistida INT DEFAULT 0;",
            "ALTER TABLE usuario_item DROP CONSTRAINT IF EXISTS usuario_item_status_check;",
            "ALTER TABLE usuario_item ADD CONSTRAINT usuario_item_status_check CHECK (status IN ('assistindo', 'concluido', 'em_pausa', 'quero_ver', 'avaliado', 'reassistindo', 'abandonado'));",
            "ALTER TABLE episodio ADD COLUMN IF NOT EXISTS url_imagem TEXT;",
            "ALTER TABLE episodio ADD COLUMN IF NOT EXISTS nota NUMERIC(3,1);",
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_episodio_item_temporada_numero ON episodio (id_item, numero_temporada, numero_episodio);",
            "ALTER TABLE usuario ADD COLUMN IF NOT EXISTS url_avatar TEXT;",
            "ALTER TABLE usuario ADD COLUMN IF NOT EXISTS hash_token_api TEXT NULL;",
            "ALTER TABLE usuario ADD COLUMN IF NOT EXISTS hash_token_api_ts_inclusao TIMESTAMP NULL;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS comentario TEXT NULL;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS eh_favorito BOOLEAN NOT NULL DEFAULT FALSE;",
            "ALTER TABLE usuario_episodio ADD COLUMN IF NOT EXISTS quantidade_reassistida INT DEFAULT 0;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS ts_cancelamento TIMESTAMP NULL;",
            "ALTER TABLE usuario_episodio ADD COLUMN IF NOT EXISTS ts_cancelamento TIMESTAMP NULL;",

            // 7. Notificacao
            "CREATE TABLE IF NOT EXISTS notificacao (
                id_notificacao SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL,
                tipo VARCHAR(30) NOT NULL DEFAULT 'info',
                id_item INT NULL,
                titulo VARCHAR(255) NOT NULL,
                mensagem TEXT NOT NULL,
                lida BOOLEAN NOT NULL DEFAULT FALSE,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT notificacao_fk_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                CONSTRAINT notificacao_fk_item FOREIGN KEY (id_item) REFERENCES item(id_item) ON DELETE CASCADE
            );",
            "CREATE TABLE IF NOT EXISTS usuario_lista (
                id_lista SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL,
                nome VARCHAR(255) NOT NULL,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT usuario_lista_fk_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE
            );",
            "CREATE TABLE IF NOT EXISTS usuario_lista_item (
                id_lista_item SERIAL PRIMARY KEY,
                id_lista INT NOT NULL,
                id_item INT NOT NULL,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT usuario_lista_item_fk_lista FOREIGN KEY (id_lista) REFERENCES usuario_lista(id_lista) ON DELETE CASCADE,
                CONSTRAINT usuario_lista_item_fk_item FOREIGN KEY (id_item) REFERENCES item(id_item) ON DELETE CASCADE,
                CONSTRAINT unique_lista_item UNIQUE (id_lista, id_item)
            );",
            "CREATE TABLE IF NOT EXISTS requisicao_idempotente (
                id_requisicao_idempotente SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL,
                id_mutacao_cliente VARCHAR(100) NOT NULL,
                resposta JSONB NULL,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL,
                CONSTRAINT requisicao_idempotente_fk_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                CONSTRAINT uk_requisicao_idempotente_usuario_mutacao UNIQUE (id_usuario, id_mutacao_cliente)
            );"
        ];

        foreach ($queries as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Silently skip if table/constraint exists
            }
        }
    }
}
