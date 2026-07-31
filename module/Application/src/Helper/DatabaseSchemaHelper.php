<?php

namespace Application\Helper;

class DatabaseSchemaHelper {
    public static function ensureTablesExist(\PDO $pdo) {
        $queries = [
            // 1. Usuario
            "CREATE TABLE IF NOT EXISTS usuario (
                id_usuario SERIAL PRIMARY KEY,
                user_name VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                nome VARCHAR(100) NOT NULL DEFAULT '',
                sobrenome VARCHAR(100) NOT NULL DEFAULT '',
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ts_cancelamento TIMESTAMP NULL
            );",

            // 2. Item (Movies, Series & Anime)
            "CREATE TABLE IF NOT EXISTS item (
                id_item SERIAL PRIMARY KEY,
                tvmaze_id INT UNIQUE NULL,
                tmdb_id INT UNIQUE NULL,
                mal_id INT UNIQUE NULL,
                title VARCHAR(255) NOT NULL,
                type VARCHAR(20) NOT NULL CHECK (type IN ('series', 'anime', 'movie')),
                description TEXT,
                poster_url TEXT,
                banner_url TEXT,
                release_year INT,
                total_episodes INT DEFAULT 0,
                runtime_minutes INT DEFAULT 45,
                status VARCHAR(50) DEFAULT 'Running',
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS tmdb_id INT UNIQUE NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS mal_id INT UNIQUE NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS total_episodes INT DEFAULT 0;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS runtime_minutes INT DEFAULT 45;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Running';",

            // 3. Episodio
            "CREATE TABLE IF NOT EXISTS episodio (
                id_episodio SERIAL PRIMARY KEY,
                id_item INT NOT NULL REFERENCES item(id_item) ON DELETE CASCADE,
                season_number INT NOT NULL,
                episode_number INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                air_date DATE,
                runtime_minutes INT DEFAULT 45,
                rating NUMERIC(3,1),
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );",

            // 4. Usuario_Item (Watchlist status)
            "CREATE TABLE IF NOT EXISTS usuario_item (
                id_usuario_item SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                id_item INT NOT NULL REFERENCES item(id_item) ON DELETE CASCADE,
                status VARCHAR(20) NOT NULL DEFAULT 'watching' CHECK (status IN ('watching', 'completed', 'dropped', 'plan_to_watch')),
                rating NUMERIC(3,1) NULL,
                ts_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uk_usuario_item UNIQUE (id_usuario, id_item)
            );",

            // 5. Usuario_Episodio (Watched episodes tracking)
            "CREATE TABLE IF NOT EXISTS usuario_episodio (
                id_usuario_episodio SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                id_episodio INT NOT NULL REFERENCES episodio(id_episodio) ON DELETE CASCADE,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uk_usuario_episodio UNIQUE (id_usuario, id_episodio)
            );",

            // 6. Feedback
            "CREATE TABLE IF NOT EXISTS feedback (
                id_feedback SERIAL PRIMARY KEY,
                id_usuario INT REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                feedback_type VARCHAR(20) NOT NULL DEFAULT 'bug',
                content TEXT NOT NULL,
                screenshot TEXT NULL,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );",
            "ALTER TABLE feedback ADD COLUMN IF NOT EXISTS screenshot TEXT NULL;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS rewatch_count INT DEFAULT 0;",
            "ALTER TABLE usuario_item DROP CONSTRAINT IF EXISTS usuario_item_status_check;",
            "ALTER TABLE usuario_item ADD CONSTRAINT usuario_item_status_check CHECK (status IN ('watching', 'completed', 'dropped', 'plan_to_watch', 'rewatching'));",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS release_date DATE;",
            "ALTER TABLE episodio ADD COLUMN IF NOT EXISTS image_url TEXT;",
            "ALTER TABLE usuario ADD COLUMN IF NOT EXISTS avatar_url TEXT;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS comment TEXT NULL;",
            "ALTER TABLE item ADD COLUMN IF NOT EXISTS watch_providers TEXT NULL;",
            "ALTER TABLE usuario_episodio ADD COLUMN IF NOT EXISTS rewatch_count INT DEFAULT 0;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS rewatch_count INT DEFAULT 0;",
            "ALTER TABLE usuario_item ADD COLUMN IF NOT EXISTS ts_cancelamento TIMESTAMP NULL;",
            "ALTER TABLE usuario_episodio ADD COLUMN IF NOT EXISTS ts_cancelamento TIMESTAMP NULL;",

            // 7. Notificacao
            "CREATE TABLE IF NOT EXISTS notificacao (
                id_notificacao SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                tipo VARCHAR(30) NOT NULL DEFAULT 'info',
                id_item INT NULL REFERENCES item(id_item) ON DELETE CASCADE,
                titulo VARCHAR(255) NOT NULL,
                mensagem TEXT NOT NULL,
                lida BOOLEAN NOT NULL DEFAULT FALSE,
                ts_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );",
            "CREATE TABLE IF NOT EXISTS usuario_lista (
                id_lista SERIAL PRIMARY KEY,
                id_usuario INT NOT NULL REFERENCES usuario(id_usuario) ON DELETE CASCADE,
                nome VARCHAR(255) NOT NULL,
                ts_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );",
            "CREATE TABLE IF NOT EXISTS usuario_lista_item (
                id_lista_item SERIAL PRIMARY KEY,
                id_lista INT NOT NULL REFERENCES usuario_lista(id_lista) ON DELETE CASCADE,
                id_item INT NOT NULL REFERENCES item(id_item) ON DELETE CASCADE,
                ts_inclusao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT unique_lista_item UNIQUE (id_lista, id_item)
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
