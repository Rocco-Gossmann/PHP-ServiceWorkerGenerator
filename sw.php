<?php
    require_once __DIR__ . "/lib/ServiceWorkerGenerator.php";


    (new rogoss\ServiceWorkerGenerator())

        ->cachePrefix("PHPSWGen_Test")

        ->enableDirectoryIndexCache("/")

        ->fileCacheFirst("./index.html")
        ->fileCacheFirst("./js/sw.js")

        ->dirCacheFirst("./vendor")

        ->patternFallback("\.svg$", "./img/PhWifiSlashBold.svg")

        ->printAndExit()

    ;

