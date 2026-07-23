<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;

class AuthController extends AbstractActionController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function loginAction() {
        if (isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('dashboard');
        }
        
        $request = $this->getRequest();
        if ($request->isPost()) {
            $post = $request->getPost();
            $email = trim($post->get('email', ''));
            $password = $post->get('password', '');

            if (empty($email) || empty($password)) {
                return new JsonModel(['success' => false, 'message' => 'Por favor, preencha todos os campos.']);
            }

            try {
                $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE email = :email AND ts_cancelamento IS NULL LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    $_SESSION['user_id'] = $user['id_usuario'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    session_write_close();
                    return new JsonModel(['success' => true, 'redirect' => '/dashboard']);
                } else {
                    return new JsonModel(['success' => false, 'message' => 'E-mail ou senha incorretos.']);
                }
            } catch (\PDOException $e) {
                return new JsonModel(['success' => false, 'message' => 'Erro interno no servidor. Tente novamente mais tarde.']);
            }
        }

        $view = new ViewModel();
        $this->layout()->title = "Entrar - Time View";
        return $view;
    }

    public function registerAction() {
        if (isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('dashboard');
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $post = $request->getPost();
            $username = trim($post->get('username', ''));
            $email = trim($post->get('email', ''));
            $password = $post->get('password', '');

            if (empty($username) || empty($email) || empty($password)) {
                return new JsonModel(['success' => false, 'message' => 'Por favor, preencha todos os campos.']);
            }

            if (strlen($username) < 3) {
                return new JsonModel(['success' => false, 'message' => 'O nome de usuário deve ter pelo menos 3 caracteres.']);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new JsonModel(['success' => false, 'message' => 'Formato de e-mail inválido.']);
            }

            if ($email === 'brunoaxlrose8@gmail.com' || $email === 'alcantarablao019@gmail.com') {
                return new JsonModel(['success' => false, 'message' => 'Este e-mail está associado a uma conta do Google. Por favor, entre usando o Google.']);
            }

            if (strlen($password) < 6) {
                return new JsonModel(['success' => false, 'message' => 'A senha deve ter pelo menos 6 caracteres.']);
            }

            try {
                $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE email = :email OR username = :username LIMIT 1");
                $stmt->execute([':email' => $email, ':username' => $username]);
                if ($stmt->fetch()) {
                    return new JsonModel(['success' => false, 'message' => 'E-mail ou nome de usuário já cadastrado.']);
                }

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $this->pdo->prepare("INSERT INTO usuario (username, email, password_hash) VALUES (:username, :email, :hash) RETURNING id_usuario");
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':hash' => $hash
                ]);

                $_SESSION['user_id'] = $stmt->fetchColumn();
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                session_write_close();

                return new JsonModel(['success' => true, 'redirect' => '/dashboard']);
            } catch (\PDOException $e) {
                return new JsonModel(['success' => false, 'message' => 'Erro ao salvar usuário no banco de dados.']);
            }
        }

        $view = new ViewModel();
        $this->layout()->title = "Criar Conta - Time View";
        return $view;
    }

    public function logoutAction() {
        session_destroy();
        return $this->redirect()->toRoute('login');
    }

    public function googleLoginAction() {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->redirect()->toRoute('login');
        }

        $post = $request->getPost();
        $accountType = $post->get('account', 'oliveira');
        if ($accountType === 'alcantara') {
            $email = "alcantarablao019@gmail.com";
            $username = "Bruno Alcantara";
        } else {
            $email = "brunoaxlrose8@gmail.com";
            $username = "Bruno Oliveira";
        }
        
        $hash = password_hash(uniqid(), PASSWORD_BCRYPT);
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE email = :email AND ts_cancelamento IS NULL LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();
            
            if ($user) {
                $_SESSION['user_id'] = $user['id_usuario'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['login_type'] = 'google';
                session_write_close();
                return new JsonModel(['success' => true, 'redirect' => '/dashboard']);
            }
            
            $stmt = $this->pdo->prepare("INSERT INTO usuario (username, email, password_hash) VALUES (:username, :email, :hash) RETURNING id_usuario");
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':hash' => $hash
            ]);
            
            $_SESSION['user_id'] = $stmt->fetchColumn();
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['login_type'] = 'google';
            session_write_close();
            
            return new JsonModel(['success' => true, 'redirect' => '/dashboard']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao logar com o Google: ' . $e->getMessage()]);
        }
    }

    public function updateProfileAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método não permitido.']);
        }

        $post = $request->getPost();
        $userId = $_SESSION['user_id'];
        $newUsername = trim($post->get('username', ''));
        
        if (empty($newUsername) || strlen($newUsername) < 3) {
            return new JsonModel(['success' => false, 'message' => 'Nome de usuário deve ter no mínimo 3 caracteres.']);
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuario WHERE username = :username AND id_usuario != :id LIMIT 1");
            $stmt->execute([':username' => $newUsername, ':id' => $userId]);
            if ($stmt->fetch()) {
                return new JsonModel(['success' => false, 'message' => 'Nome de usuário já está em uso.']);
            }
            
            $stmt = $this->pdo->prepare("UPDATE usuario SET username = :username WHERE id_usuario = :id");
            $stmt->execute([':username' => $newUsername, ':id' => $userId]);
            
            $_SESSION['username'] = $newUsername;
            
            return new JsonModel(['success' => true, 'message' => 'Nome de usuário atualizado!']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro no banco de dados ao atualizar.']);
        }
    }

    public function clearLibraryAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("DELETE FROM usuario_item WHERE id_usuario = :id");
            $stmt->execute([':id' => $userId]);
            
            $stmt = $this->pdo->prepare("DELETE FROM usuario_episodio WHERE id_usuario = :id");
            $stmt->execute([':id' => $userId]);
            
            $this->pdo->commit();
            return new JsonModel(['success' => true, 'message' => 'Biblioteca limpa com sucesso!']);
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return new JsonModel(['success' => false, 'message' => 'Erro ao limpar a biblioteca: ' . $e->getMessage()]);
        }
    }

    public function deleteAccountAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        
        try {
            $stmt = $this->pdo->prepare("UPDATE usuario SET ts_cancelamento = CURRENT_TIMESTAMP WHERE id_usuario = :id");
            $stmt->execute([':id' => $userId]);
            
            session_destroy();
            return new JsonModel(['success' => true, 'message' => 'Sua conta foi eliminada.']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao deletar conta.']);
        }
    }

    public function feedbackAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'message' => 'Método não permitido.']);
        }

        $post = $request->getPost();
        $type = trim($post->get('feedback_type', 'bug'));
        $content = trim($post->get('content', ''));
        $screenshot = trim($post->get('screenshot_base64', ''));

        if (empty($content)) {
            return new JsonModel(['success' => false, 'message' => 'Por favor, escreva a mensagem do seu feedback.']);
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO feedback (id_usuario, feedback_type, content, screenshot)
                VALUES (:user_id, :type, :content, :screenshot)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':type' => $type,
                ':content' => $content,
                ':screenshot' => !empty($screenshot) ? $screenshot : null
            ]);

            return new JsonModel(['success' => true, 'message' => 'Feedback registrado com sucesso! Obrigado pela colaboração.']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao salvar feedback no banco de dados.']);
        }
    }
}
