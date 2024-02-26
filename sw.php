<?php
    require_once __DIR__ . "/lib/ServiceWorkerGenerator.php";

    (new rogoss\ServiceWorkerGenerator())

        ->cachePrefix("PHPSWGen_Test")

        ->fileCacheFirst("./index.html")
        ->dirCacheFirst("./vendor")

        ->printAndExit()

    ;
