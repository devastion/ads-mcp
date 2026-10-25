<?php

require __DIR__.'/../vendor/autoload.php';

/*
 * PHPUnit's forced <env> values reach $_ENV and getenv() but not $_SERVER, and Laravel reads
 * $_SERVER first. Inside the compose container $_SERVER still holds the dev APP_ENV, DB_DATABASE
 * and CACHE_STORE, so without this sync the suite would run against the dev database.
 */
foreach ($_ENV as $name => $value) {
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== $value) {
        $_SERVER[$name] = $value;
    }
}
