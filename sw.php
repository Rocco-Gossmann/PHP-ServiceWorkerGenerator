<?php
    require_once __DIR__ . "/lib/ServiceWorkerGenerator.php";

    (new rogoss\core\ServiceWorkerGenerator())

        ->cacheName("PHPSWGen_Test")

        ->fileCacheFirst("./index.html")
        ->dirCacheFirst("./vendor")

        ->printAndExit()

    ;

