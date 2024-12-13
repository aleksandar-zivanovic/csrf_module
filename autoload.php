<?php

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR .  'csrf_config.php';

if (!in_array($configPath, get_included_files())) {
    require_once $configPath;
}

spl_autoload_register(function ($class) {
    $classDir = __DIR__ . DIRECTORY_SEPARATOR . 'src';
    $relativeClass = str_replace('CSRFModule\\', DIRECTORY_SEPARATOR, $class);
    $file = $classDir . $relativeClass . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});