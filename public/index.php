<?php
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

session_start();

$app = AppFactory::create();
$app->setBasePath('/git_backend/survey_system/public/index.php');
$app->addErrorMiddleware(true, true, true);

$twig = Twig::create(__DIR__ . '/../templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8",
            $_ENV['DB_USER'],
            $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

// ── Auth Routes ──
$app->get('/admin/login', \App\Controllers\AuthController::class . ':loginForm');
$app->post('/admin/login', \App\Controllers\AuthController::class . ':login');
$app->get('/admin/logout', \App\Controllers\AuthController::class . ':logout');

// ── Admin Routes (protected) ──
$app->group('/admin', function ($group) {
    $group->get('/dashboard', \App\Controllers\AdminController::class . ':dashboard');
    $group->get('/upload', \App\Controllers\AdminController::class . ':uploadForm');
    $group->post('/upload', \App\Controllers\AdminController::class . ':upload');
    $group->post('/toggle/{id}', \App\Controllers\AdminController::class . ':toggle');
    $group->get('/results/{id}', \App\Controllers\AdminController::class . ':results');
    $group->get('/download/{id}', \App\Controllers\AdminController::class . ':download');
})->add(new \App\Middleware\AuthMiddleware());

// ── Survey Routes ──
$app->get('/survey/{slug}', \App\Controllers\SurveyController::class . ':show');
$app->post('/survey/{slug}', \App\Controllers\SurveyController::class . ':submit');

$app->run();