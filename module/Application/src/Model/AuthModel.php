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
        $stmt = $this->pdo->prepare("UPDATE usuario SET api_token_hash = :hash WHERE id_usuario = :id");
        $stmt->execute([':hash' => $hash, ':id' => $userId]);
        return $token;
    }

    public function getUserByToken(string $token) {
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE api_token_hash = :hash AND ts_cancelamento IS NULL LIMIT 1");
        $stmt->execute([':hash' => $hash]);
        return $stmt->fetch();
    }

    public function clearApiToken(int $userId): void {
        $stmt = $this->pdo->prepare("UPDATE usuario SET api_token_hash = NULL WHERE id_usuario = :id");
        $stmt->execute([':id' => $userId]);
    }

    public function isUsernameOrEmailTaken(string $userName, string $email): bool {
        $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE email = :email OR user_name = :user_name LIMIT 1");
        $stmt->execute([':email' => $email, ':user_name' => $userName]);
        return (bool)$stmt->fetch();
    }

    public function createUser(string $userName, string $email, string $hash, string $nome, string $sobrenome): int {
        $stmt = $this->pdo->prepare("INSERT INTO usuario (user_name, email, password_hash, nome, sobrenome) VALUES (:user_name, :email, :hash, :nome, :sobrenome) RETURNING id_usuario");
        $stmt->execute([
            ':user_name' => $userName,
            ':email' => $email,
            ':hash' => $hash,
            ':nome' => $nome,
            ':sobrenome' => $sobrenome
        ]);
        return (int)$stmt->fetchColumn();
    }

    public function isUsernameTaken(string $userName, int $excludeUserId): bool {
        $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE user_name = :user_name AND id_usuario != :id LIMIT 1");
        $stmt->execute([':user_name' => $userName, ':id' => $excludeUserId]);
        return (bool)$stmt->fetch();
    }

    public function updateProfile(int $userId, string $userName, string $nome, string $sobrenome): bool {
        $stmt = $this->pdo->prepare("UPDATE usuario SET user_name = :user_name, nome = :nome, sobrenome = :sobrenome WHERE id_usuario = :id");
        return $stmt->execute([
            ':user_name' => $userName,
            ':nome' => $nome,
            ':sobrenome' => $sobrenome,
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

    public function saveFeedback(int $userId, string $type, string $content, ?string $screenshot): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO feedback (id_usuario, feedback_type, content, screenshot)
            VALUES (:user_id, :type, :content, :screenshot)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':content' => $content,
            ':screenshot' => $screenshot
        ]);
    }

    public function getUserById(int $id) {
        $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE id_usuario = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function updatePassword(int $userId, string $hash): bool {
        $stmt = $this->pdo->prepare("UPDATE usuario SET password_hash = :hash WHERE id_usuario = :id");
        return $stmt->execute([':hash' => $hash, ':id' => $userId]);
    }
}
