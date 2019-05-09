<?php

spl_autoload_register(function($class){
    $class = str_replace('sworker', 'sworker/src', str_replace('\\','/',$class));
    $file = dirname(__DIR__) . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
