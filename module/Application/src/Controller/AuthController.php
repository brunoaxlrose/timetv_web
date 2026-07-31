<?php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Application\Model\AuthModel;
use Application\InputFilter\LoginInputFilter;
use Application\InputFilter\RegisterInputFilter;

class AuthController extends AbstractActionController {
    private $authModel;

    public function __construct(AuthModel $authModel) {
        $this->authModel = $authModel;
    }

    public function loginAction() {
        if (isset($_SESSION['user_id'])) {
            return $this->redirect()->toRoute('dashboard');
        }
        
        $request = $this->getRequest();
        if ($request->isPost()) {
            $inputFilter = new LoginInputFilter();
            $inputFilter->setData($request->getPost());

            if (!$inputFilter->isValid()) {
                $messages = $inputFilter->getMessages();
                $firstMsg = reset($messages);
                $errorMsg = reset($firstMsg);
                return new JsonModel(['success' => false, 'message' => $errorMsg]);
            }

            $data = $inputFilter->getValues();
            $email = $data['email'];
            $password = $data['password'];

            try {
                $user = $this->authModel->getUserByEmail($email);

                if ($user && password_verify($password, $user['password_hash'])) {
                    $_SESSION['user_id'] = $user['id_usuario'];
                    $_SESSION['username'] = $user['user_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['nome'] = $user['nome'];
                    $_SESSION['sobrenome'] = $user['sobrenome'];
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
            $inputFilter = new RegisterInputFilter();
            $inputFilter->setData($request->getPost());

            if (!$inputFilter->isValid()) {
                $messages = $inputFilter->getMessages();
                $firstMsg = reset($messages);
                $errorMsg = reset($firstMsg);
                return new JsonModel(['success' => false, 'message' => $errorMsg]);
            }

            $data = $inputFilter->getValues();
            $username = trim($data['user_name']);
            $email = $data['email'];
            $password = $data['password'];
            $nome = $this->capitalizeName(trim($data['nome']));
            $sobrenome = $this->capitalizeName(trim($data['sobrenome']));

            try {
                if ($this->authModel->isUsernameOrEmailTaken($username, $email)) {
                    return new JsonModel(['success' => false, 'message' => 'E-mail ou nome de usuário já cadastrado.']);
                }

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $userId = $this->authModel->createUser($username, $email, $hash, $nome, $sobrenome);

                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                $_SESSION['nome'] = $nome;
                $_SESSION['sobrenome'] = $sobrenome;
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
        if (session_status() === PHP_SESSION_ACTIVE || isset($_SESSION)) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
        }
        return $this->redirect()->toUrl('/login');
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
        $nome = $this->capitalizeName(trim($post->get('nome', '')));
        $sobrenome = $this->capitalizeName(trim($post->get('sobrenome', '')));
        
        if (empty($newUsername) || strlen($newUsername) < 3) {
            return new JsonModel(['success' => false, 'message' => 'Nome de usuário deve ter no mínimo 3 caracteres.']);
        }

        if (empty($nome) || empty($sobrenome)) {
            return new JsonModel(['success' => false, 'message' => 'Nome e sobrenome são obrigatórios.']);
        }
        
        try {
            if ($this->authModel->isUsernameTaken($newUsername, $userId)) {
                return new JsonModel(['success' => false, 'message' => 'Nome de usuário já está em uso.']);
            }

            // Check if password change is requested
            $currentPassword = $post->get('current_password', '');
            $newPassword = $post->get('new_password', '');
            $confirmNewPassword = $post->get('confirm_new_password', '');

            if (!empty($currentPassword) || !empty($newPassword) || !empty($confirmNewPassword)) {
                if (empty($currentPassword) || empty($newPassword) || empty($confirmNewPassword)) {
                    return new JsonModel(['success' => false, 'message' => 'Preencha todos os campos de senha para alterá-la.']);
                }

                if (strlen($newPassword) < 6) {
                    return new JsonModel(['success' => false, 'message' => 'A nova senha deve ter no mínimo 6 caracteres.']);
                }

                if ($newPassword !== $confirmNewPassword) {
                    return new JsonModel(['success' => false, 'message' => 'As novas senhas não coincidem.']);
                }

                $user = $this->authModel->getUserById($userId);
                if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                    return new JsonModel(['success' => false, 'message' => 'Senha atual incorreta.']);
                }

                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $this->authModel->updatePassword($userId, $newHash);
            }
            
            $this->authModel->updateProfile($userId, $newUsername, $nome, $sobrenome);
            $_SESSION['username'] = $newUsername;
            $_SESSION['nome'] = $nome;
            $_SESSION['sobrenome'] = $sobrenome;
            
            return new JsonModel(['success' => true, 'message' => 'Perfil atualizado com sucesso!']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao atualizar perfil no banco de dados.']);
        }
    }

    public function clearLibraryAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        try {
            $this->authModel->clearLibrary($userId);
            return new JsonModel(['success' => true, 'message' => 'Toda a sua coleção foi limpa com sucesso.']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao limpar coleção.']);
        }
    }

    public function deleteAccountAction() {
        if (!isset($_SESSION['user_id'])) {
            return new JsonModel(['success' => false, 'message' => 'Não autorizado.']);
        }

        $userId = $_SESSION['user_id'];
        try {
            $this->authModel->deleteAccount($userId);
            
            $_SESSION = [];
            session_destroy();
            
            return new JsonModel(['success' => true, 'redirect' => '/login']);
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
            $this->authModel->saveFeedback(
                $_SESSION['user_id'], 
                $type, 
                $content, 
                !empty($screenshot) ? $screenshot : null
            );

            return new JsonModel(['success' => true, 'message' => 'Feedback registrado com sucesso! Obrigado pela colaboração.']);
        } catch (\PDOException $e) {
            return new JsonModel(['success' => false, 'message' => 'Erro ao salvar feedback no banco de dados.']);
        }
    }

    private function capitalizeName(string $name): string {
        $words = explode(' ', mb_strtolower(trim($name), 'UTF-8'));
        $lowercaseWords = ['de', 'da', 'do', 'dos', 'das', 'e'];
        $capitalizedWords = array_map(function($word) use ($lowercaseWords) {
            if (in_array($word, $lowercaseWords)) {
                return $word;
            }
            return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }, $words);
        return implode(' ', $capitalizedWords);
    }
}
