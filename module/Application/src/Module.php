<?php

namespace Application;

use Laminas\Mvc\MvcEvent;

class Module {
    public function getConfig() {
        return include __DIR__ . '/../config/module.config.php';
    }

    public function onBootstrap(MvcEvent $e) {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('tvtime_session');
            session_set_cookie_params([
                'lifetime' => 86400,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }

        $eventManager = $e->getApplication()->getEventManager();
        $eventManager->attach(MvcEvent::EVENT_DISPATCH, [$this, 'checkAuthentication'], 100);
    }

    public function checkAuthentication(MvcEvent $e) {
        $routeMatch = $e->getRouteMatch();
        if (!$routeMatch) {
            return;
        }

        $routeName = $routeMatch->getMatchedRouteName();
        $guestRoutes = ['login', 'register', 'api-google-login', 'api-v1-auth-login', 'api-v1-auth-register'];

        $isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

        if (!$isLoggedIn && !in_array($routeName, $guestRoutes)) {
            if (str_starts_with((string)$routeName, 'api')) {
                $response = $e->getResponse();
                $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
                $response->setStatusCode(401);
                $response->setContent(json_encode([
                    'success' => false,
                    'data' => null,
                    'message' => '401 Unauthorized',
                ]));
                return $response;
            }

            $response = $e->getResponse();
            $response->getHeaders()->addHeaderLine('Location', '/login');
            $response->setStatusCode(302);
            return $response;
        }

        if ($isLoggedIn && in_array($routeName, ['login', 'register'])) {
            $request = $e->getRequest();
            if ($request->isGet()) {
                $response = $e->getResponse();
                $response->getHeaders()->addHeaderLine('Location', '/dashboard');
                $response->setStatusCode(302);
                return $response;
            }
        }
    }
}
