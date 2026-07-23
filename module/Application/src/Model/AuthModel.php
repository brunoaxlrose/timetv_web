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

    public function isUsernameOrEmailTaken(string $username, string $email): bool {
        $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE email = :email OR username = :username LIMIT 1");
        $stmt->execute([':email' => $email, ':username' => $username]);
        return (bool)$stmt->fetch();
    }

    public function createUser(string $username, string $email, string $hash): int {
        $stmt = $this->pdo->prepare("INSERT INTO usuario (username, email, password_hash) VALUES (:username, :email, :hash) RETURNING id_usuario");
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':hash' => $hash
        ]);
        return (int)$stmt->fetchColumn();
    }

    public function isUsernameTaken(string $username, int $excludeUserId): bool {
        $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE username = :username AND id_usuario != :id LIMIT 1");
        $stmt->execute([':username' => $username, ':id' => $excludeUserId]);
        return (bool)$stmt->fetch();
    }

    public function updateUsername(int $userId, string $username): bool {
        $stmt = $this->pdo->prepare("UPDATE usuario SET username = :username WHERE id_usuario = :id");
        return $stmt->execute([':username' => $username, ':id' => $userId]);
    }

    public function clearLibrary(int $userId): void {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_item WHERE id_usuario = :id");
        $stmt->execute([':id' => $userId]);

        $stmt = $this->pdo->prepare("DELETE FROM usuario_episodio WHERE id_usuario = :id");
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
