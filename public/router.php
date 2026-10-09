<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path!=='/' && is_file(__DIR__.$path) && str_starts_with(realpath(__DIR__.$path),__DIR__.DIRECTORY_SEPARATOR)) return false;
require __DIR__.'/index.php';
