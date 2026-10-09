<?php
/*
 * api/index.php - the single entry point of the PHP backend.
 * The website calls addresses like  api/index.php/me  or  api/index.php/quizzes/5
 * (the part after index.php is the "route").
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/handlers.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Work out the route (the part after index.php)
    $path = $_SERVER['PATH_INFO'] ?? '';
    if ($path === '') {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $pos = strpos($uri, 'index.php');
        $path = $pos === false ? '' : substr($uri, $pos + strlen('index.php'));
    }
    $path = '/' . trim(rawurldecode($path), '/');

    if ($path === '/attempts') {
        h_attempts($method);
    }

    // CSRF guard: every state-changing request must carry this custom header
    if ($method !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'quiz') {
        throw new ApiException(403, 'Missing security header.');
    }
    $need = function ($ok) {
        if (!$ok) {
            throw new ApiException(405, 'Method not allowed.');
        }
    };

    if ($path === '/me') {
        $need($method === 'GET');
        h_me();
    } elseif ($path === '/register') {
        $need($method === 'POST');
        h_register();
    } elseif ($path === '/verify') {
        $need($method === 'POST');
        h_verify();
    } elseif ($path === '/resend') {
        $need($method === 'POST');
        h_resend();
    } elseif ($path === '/login') {
        $need($method === 'POST');
        h_login();
    } elseif ($path === '/logout') {
        $need($method === 'POST');
        h_logout();
    } elseif ($path === '/quizzes/parse') {
        $need($method === 'POST');
        h_parse_docx();
    } elseif ($path === '/quizzes') {
        if ($method === 'GET') {
            h_list_quizzes();
        } elseif ($method === 'POST') {
            h_save_quiz();
        } else {
            throw new ApiException(405, 'Method not allowed.');
        }
    } elseif (preg_match('#^/quizzes/(\d{1,9})$#', $path, $m)) {
        $id = (int) $m[1];
        if ($method === 'GET') {
            h_get_quiz($id);
        } elseif ($method === 'DELETE') {
            h_delete_quiz($id);
        } else {
            throw new ApiException(405, 'Method not allowed.');
        }
    } else {
        throw new ApiException(404, 'Not found.');
    }
} catch (ApiException $e) {
    send_json($e->status, ['error' => $e->getMessage(), 'code' => $e->errCode]);
} catch (InvalidArgumentException $e) {
    send_json(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    send_json(500, ['error' => 'Something went wrong on the server. Please try again.']);
}
