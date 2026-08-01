<?php

namespace Application\Model;

use PDO;

class AuthModel {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getUserByEmail(string $email) {
        $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE email = :email AND ts_cancelamento IS NULL LIMIT 1");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch();
    }

    public function issueApiToken(int $userId): string {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare("UPDATE usuario SET hash_token_api = :hash WHERE id_usuario = :id");
        $stmt->execute([':hash' => $hash, ':id' => $userId]);
        return $token;
    }

    public function getUserByToken(string $token) {
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE hash_token_api = :hash AND ts_cancelamento IS NULL LIMIT 1");
        $stmt->execute([':hash' => $hash]);
        return $stmt->fetch();
    }

    public function clearApiToken(int $userId): void {
        $stmt = $this->pdo->prepare("UPDATE usuario SET hash_token_api = NULL WHERE id_usuario = :id");
        $stmt->execute([':id' => $userId]);
    }

    public function isUsernameOrEmailTaken(string $userName, string $email): bool {
        $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE email = :email OR nome_usuario = :nome_usuario LIMIT 1");
        $stmt->execute([':email' => $email, ':nome_usuario' => $userName]);
        return (bool)$stmt->fetch();
    }

    public function createUser(string $userName, string $email, string $hash, string $nome, string $sobrenome): int {
        $stmt = $this->pdo->prepare("INSERT INTO usuario (nome_usuario, email, hash_senha, nome, sobrenome) VALUES (:nome_usuario, :email, :hash, :nome, :sobrenome) RETURNING id_usuario");
        $stmt->execute([
            ':nome_usuario' => $userName,
            ':email' => $email,
            ':hash' => $hash,
            ':nome' => $nome,
            ':sobrenome' => $sobrenome
        ]);
        return (int)$stmt->fetchColumn();
    }

    public function isUsernameTaken(string $userName, int $excludeUserId): bool {
        $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE nome_usuario = :nome_usuario AND id_usuario != :id LIMIT 1");
        $stmt->execute([':nome_usuario' => $userName, ':id' => $excludeUserId]);
        return (bool)$stmt->fetch();
    }

    public function updateProfile(int $userId, string $userName, string $nome, string $sobrenome, ?string $avatarUrl): bool {
        $stmt = $this->pdo->prepare("UPDATE usuario SET nome_usuario = :nome_usuario, nome = :nome, sobrenome = :sobrenome, url_avatar = :url_avatar WHERE id_usuario = :id");
        return $stmt->execute([
            ':nome_usuario' => $userName,
            ':nome' => $nome,
            ':sobrenome' => $sobrenome,
            ':url_avatar' => $avatarUrl,
            ':id' => $userId
        ]);
    }

    public function clearLibrary(int $userId): void {
        $stmt = $this->pdo->prepare("UPDATE usuario_item SET ts_cancelamento = CURRENT_TIMESTAMP WHERE id_usuario = :id AND ts_cancelamento IS NULL");
        $stmt->execute([':id' => $userId]);

        $stmt = $this->pdo->prepare("UPDATE usuario_episodio SET ts_cancelamento = CURRENT_TIMESTAMP WHERE id_usuario = :id AND ts_cancelamento IS NULL");
        $stmt->execute([':id' => $userId]);
    }

    public function deleteAccount(int $userId): void {
        $stmt = $this->pdo->prepare("UPDATE usuario SET ts_cancelamento = CURRENT_TIMESTAMP WHERE id_usuario = :id");
        $stmt->execute([':id' => $userId]);
    }

    public function saveFeedback(int $userId, string $type, string $content, ?string $screenshot, ?string $mutationId = null): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO feedback (id_usuario, tipo_feedback, conteudo, captura_tela, id_mutacao_cliente)
            VALUES (:id_usuario, :tipo, :conteudo, :captura_tela, :id_mutacao_cliente)
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([
            ':id_usuario' => $userId,
            ':tipo' => $type,
            ':conteudo' => $content,
            ':captura_tela' => $screenshot,
            ':id_mutacao_cliente' => $mutationId,
        ]);
    }

    public function getUserById(int $id) {
        $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE id_usuario = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function updatePassword(int $userId, string $hash): bool {
        $stmt = $this->pdo->prepare("UPDATE usuario SET hash_senha = :hash WHERE id_usuario = :id");
        return $stmt->execute([':hash' => $hash, ':id' => $userId]);
    }
}
