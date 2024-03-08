<?php
    require_once __DIR__ . "/lib/ServiceWorkerGenerator.php";


    (new rogoss\ServiceWorkerGenerator())

        ->cacheName("PHPSW_Test_001")

        ->enableDirectoryIndexCache("/")

        ->fileCacheFirst("./index.html")
        ->fileCacheFirst("./js/sw.js")

        ->fileCacheOnDemand("onDemand/odm1.txt")
        ->dirCacheOnDemand("onDemand/dir")

        ->dirCacheFirst("./vendor")

        ->patternFallback("\.svg$", "./img/PhWifiSlashBold.svg")

        ->printAndExit()

    ;

