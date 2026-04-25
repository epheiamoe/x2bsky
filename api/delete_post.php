<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;
use X2BSky\Logger;

header('Content-Type: application/json');

Config::init(APP_ROOT . '/.env');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$postId = $input['id'] ?? null;
$type = $input['type'] ?? 'fetched'; // 'fetched' or 'master'

if (!$postId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No post ID provided']);
    exit;
}

try {
    $pdo = Database::getInstance();

    if ($type === 'fetched') {
        $stmt = $pdo->prepare('SELECT x_post_id FROM fetched_posts WHERE id = ?');
        $stmt->execute([$postId]);
        $post = $stmt->fetch();

        if (!$post) {
            echo json_encode(['success' => false, 'error' => 'Post not found']);
            exit;
        }

        $xPostId = $post['x_post_id'];

        $stmt = $pdo->prepare('DELETE FROM fetched_posts WHERE id = ?');
        $stmt->execute([$postId]);

        $stmt = $pdo->prepare('SELECT id FROM posts WHERE x_post_id = ?');
        $stmt->execute([$xPostId]);
        $masterPost = $stmt->fetch();

        if ($masterPost) {
            $stmt = $pdo->prepare('DELETE FROM posts WHERE id = ?');
            $stmt->execute([$masterPost['id']]);
        }

        Logger::info('Deleted fetched post', ['id' => $postId, 'x_post_id' => $xPostId]);

    } else {
        $stmt = $pdo->prepare('SELECT id, x_post_id FROM posts WHERE id = ?');
        $stmt->execute([$postId]);
        $post = $stmt->fetch();

        if (!$post) {
            echo json_encode(['success' => false, 'error' => 'Post not found']);
            exit;
        }

        $stmt = $pdo->prepare('DELETE FROM posts WHERE id = ?');
        $stmt->execute([$postId]);

        $stmt = $pdo->prepare('DELETE FROM fetched_posts WHERE x_post_id = ?');
        $stmt->execute([$post['x_post_id']]);

        Logger::info('Deleted master post', ['id' => $postId, 'x_post_id' => $post['x_post_id']]);
    }

    echo json_encode(['success' => true]);

} catch (\Throwable $e) {
    Logger::error('Delete post failed', ['id' => $postId, 'error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}