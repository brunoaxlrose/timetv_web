<?php

namespace Application\Controller\Api;

use Application\Model\AuthModel;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class AuthController extends AbstractActionController {
    public function __construct(private AuthModel $authModel) {
    }

    public function loginAction(): JsonModel {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonError('Metodo nao permitido.', 405);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->getPost()->toArray();
        }

        $email = trim((string)($payload['email'] ?? ''));
        $password = (string)($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->jsonError('Informe email e senha.', 422);
        }

        $user = $this->authModel->getUserByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->jsonError('Email ou senha incorretos.', 401);
        }

        $_SESSION['user_id'] = (int)$user['id_usuario'];
        $_SESSION['username'] = $user['user_name'];
        $_SESSION['nome'] = $user['nome'];
        $_SESSION['sobrenome'] = $user['sobrenome'];
        $apiToken = $this->authModel->issueApiToken((int)$user['id_usuario']);

        return new JsonModel([
            'success' => true,
            'data' => $this->serializeUser($user, $apiToken),
            'message' => 'Login realizado com sucesso.',
        ]);
    }

    public function registerAction(): JsonModel {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonError('Metodo nao permitido.', 405);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->getPost()->toArray();
        }

        $username = trim((string)($payload['user_name'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $passwordConfirm = (string)($payload['password_confirm'] ?? '');
        $nome = $this->capitalizeName(trim((string)($payload['nome'] ?? '')));
        $sobrenome = $this->capitalizeName(trim((string)($payload['sobrenome'] ?? '')));

        if (strlen($username) < 3) {
            return $this->jsonError('O nome de usuario deve ter pelo menos 3 caracteres.', 422);
        }
        if ($nome === '' || $sobrenome === '') {
            return $this->jsonError('Nome e sobrenome sao obrigatorios.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('Formato de email invalido.', 422);
        }
        if (strlen($password) < 6) {
            return $this->jsonError('A senha deve ter pelo menos 6 caracteres.', 422);
        }
        if ($password !== $passwordConfirm) {
            return $this->jsonError('As senhas nao coincidem.', 422);
        }
        if ($this->authModel->isUsernameOrEmailTaken($username, $email)) {
            return $this->jsonError('Email ou nome de usuario ja cadastrado.', 409);
        }

        try {
            $userId = $this->authModel->createUser(
                $username,
                $email,
                password_hash($password, PASSWORD_BCRYPT),
                $nome,
                $sobrenome
            );

            $user = $this->authModel->getUserById($userId);
            $_SESSION['user_id'] = (int)$user['id_usuario'];
            $_SESSION['username'] = $user['user_name'];
            $_SESSION['nome'] = $user['nome'];
            $_SESSION['sobrenome'] = $user['sobrenome'];
            $apiToken = $this->authModel->issueApiToken((int)$user['id_usuario']);

            return new JsonModel([
                'success' => true,
                'data' => $this->serializeUser($user, $apiToken),
                'message' => 'Conta criada com sucesso.',
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError('Erro ao criar conta.', 500);
        }
    }

    public function meAction(): JsonModel {
        $user = $this->currentUser();
        if (!$user) {
            return $this->jsonError('Usuario nao encontrado.', 404);
        }

        return new JsonModel([
            'success' => true,
            'data' => $this->serializeUser($user),
            'message' => 'Usuario autenticado.',
        ]);
    }

    public function logoutAction(): JsonModel {
        $user = $this->currentUser();
        if ($user) {
            $this->authModel->clearApiToken((int)$user['id_usuario']);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return new JsonModel([
            'success' => true,
            'data' => null,
            'message' => 'Logout realizado com sucesso.',
        ]);
    }

    public function updateProfileAction(): JsonModel {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonError('Metodo nao permitido.', 405);
        }

        $user = $this->currentUser();
        if (!$user) {
            return $this->jsonError('Nao autorizado.', 401);
        }

        $payload = $this->payload();
        $userId = (int)$user['id_usuario'];
        $username = trim((string)($payload['username'] ?? ''));
        $nome = $this->capitalizeName(trim((string)($payload['nome'] ?? '')));
        $sobrenome = $this->capitalizeName(trim((string)($payload['sobrenome'] ?? '')));

        if (strlen($username) < 3) {
            return $this->jsonError('Nome de usuario deve ter no minimo 3 caracteres.', 422);
        }
        if ($nome === '' || $sobrenome === '') {
            return $this->jsonError('Nome e sobrenome sao obrigatorios.', 422);
        }
        if ($this->authModel->isUsernameTaken($username, $userId)) {
            return $this->jsonError('Nome de usuario ja esta em uso.', 409);
        }

        $currentPassword = (string)($payload['current_password'] ?? '');
        $newPassword = (string)($payload['new_password'] ?? '');
        $confirmPassword = (string)($payload['confirm_new_password'] ?? '');

        if ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '') {
            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                return $this->jsonError('Preencha todos os campos de senha para altera-la.', 422);
            }
            if (strlen($newPassword) < 6) {
                return $this->jsonError('A nova senha deve ter no minimo 6 caracteres.', 422);
            }
            if ($newPassword !== $confirmPassword) {
                return $this->jsonError('As novas senhas nao coincidem.', 422);
            }

            $user = $this->authModel->getUserById($userId);
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                return $this->jsonError('Senha atual incorreta.', 422);
            }

            $this->authModel->updatePassword($userId, password_hash($newPassword, PASSWORD_BCRYPT));
        }

        $this->authModel->updateProfile($userId, $username, $nome, $sobrenome);
        $_SESSION['username'] = $username;
        $_SESSION['nome'] = $nome;
        $_SESSION['sobrenome'] = $sobrenome;

        $user = $this->authModel->getUserById($userId);
        $apiToken = $this->authModel->issueApiToken($userId);
        return new JsonModel([
            'success' => true,
            'data' => $this->serializeUser($user, $apiToken),
            'message' => 'Perfil atualizado com sucesso.',
        ]);
    }

    public function clearLibraryAction(): JsonModel {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonError('Metodo nao permitido.', 405);
        }

        $user = $this->currentUser();
        if (!$user) {
            return $this->jsonError('Nao autorizado.', 401);
        }

        $this->authModel->clearLibrary((int)$user['id_usuario']);

        return new JsonModel([
            'success' => true,
            'data' => null,
            'message' => 'Biblioteca limpa com sucesso.',
        ]);
    }

    public function deleteAccountAction(): JsonModel {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonError('Metodo nao permitido.', 405);
        }

        $user = $this->currentUser();
        if (!$user) {
            return $this->jsonError('Nao autorizado.', 401);
        }

        $this->authModel->deleteAccount((int)$user['id_usuario']);
        $this->authModel->clearApiToken((int)$user['id_usuario']);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return new JsonModel([
            'success' => true,
            'data' => null,
            'message' => 'Conta excluida com sucesso.',
        ]);
    }

    private function serializeUser(array $user, ?string $apiToken = null): array {
        $data = [
            'id' => (int)$user['id_usuario'],
            'username' => $user['user_name'],
            'email' => $user['email'],
            'nome' => $user['nome'],
            'sobrenome' => $user['sobrenome'],
        ];

        if ($apiToken !== null) {
            $data['api_token'] = $apiToken;
        }

        return $data;
    }

    private function payload(): array {
        $payload = json_decode($this->getRequest()->getContent(), true);
        if (!is_array($payload)) {
            $payload = $this->getRequest()->getPost()->toArray();
        }
        return $payload;
    }

    private function currentUser(): ?array {
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            $user = $this->authModel->getUserById((int)$_SESSION['user_id']);
            if ($user) {
                return $user;
            }
        }

        $header = (string)$this->getRequest()->getHeader('Authorization');
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            $token = trim($matches[1]);
            if ($token !== '') {
                $user = $this->authModel->getUserByToken($token);
                if ($user) {
                    return $user;
                }
            }
        }

        return null;
    }

    private function capitalizeName(string $name): string {
        $words = explode(' ', mb_strtolower(trim($name), 'UTF-8'));
        $lowercaseWords = ['de', 'da', 'do', 'dos', 'das', 'e'];

        $capitalizedWords = array_map(function(string $word) use ($lowercaseWords): string {
            if (in_array($word, $lowercaseWords, true)) {
                return $word;
            }

            return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }, $words);

        return implode(' ', $capitalizedWords);
    }

    private function jsonError(string $message, int $statusCode): JsonModel {
        $this->getResponse()->setStatusCode($statusCode);

        return new JsonModel([
            'success' => false,
            'data' => null,
            'message' => $message,
        ]);
    }
}
