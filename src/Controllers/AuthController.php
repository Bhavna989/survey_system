<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController {
    public function loginForm(Request $req, Response $res): Response {
        $view = Twig::fromRequest($req);
        return $view->render($res, 'admin/login.twig', [
            'error' => $_SESSION['login_error'] ?? null
        ]);
    }

    public function login(Request $req, Response $res): Response {
        $data = $req->getParsedBody();
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            unset($_SESSION['login_error']);
            return $res->withHeader('Location', '/git_backend/survey_system/public/index.php/admin/dashboard')->withStatus(302);
        }

        $_SESSION['login_error'] = 'Invalid username or password.';
        return $res->withHeader('Location', '/git_backend/survey_system/public/index.php/admin/login')->withStatus(302);
    }

    public function logout(Request $req, Response $res): Response {
        session_destroy();
        return $res->withHeader('Location', '/git_backend/survey_system/public/index.php/admin/login')->withStatus(302);
    }
}