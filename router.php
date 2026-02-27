<?php
$path = $_SERVER['REQUEST_URI'];
$file = __DIR__ . parse_url($path, PHP_URL_PATH);

// Serve static files directly
if (is_file($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mimeTypes = [
        'html' => 'text/html',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'ico'  => 'image/x-icon',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    return false; // Let PHP built-in server handle static files
}

// Default to index.html
if ($path === '/' || !is_file($file)) {
    if (is_file(__DIR__ . '/index.html')) {
        include __DIR__ . '/index.html';
        return true;
    }
}

return false;
