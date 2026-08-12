<?php
$root = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api')) {
    $route = trim(substr($path, 4), '/');
    $_GET['route'] = $route;
    require $root . '/api/index.php';
    return true;
}
$file = realpath($root . $path);
if ($path !== '/' && $file && str_starts_with($file, $root) && is_file($file)) return false;
readfile($root . '/index.html');
return true;
