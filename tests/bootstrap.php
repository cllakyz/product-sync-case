<?php

$testingDatabase = dirname(__DIR__).'/database/testing.sqlite';

if (file_exists($testingDatabase)) {
    unlink($testingDatabase);
}

touch($testingDatabase);

require dirname(__DIR__).'/vendor/autoload.php';
