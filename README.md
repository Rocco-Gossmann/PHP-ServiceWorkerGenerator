# PHP - ServiceWorker Generator (very much WIP right now)

## Requiresments.
This requres PHP 8.x or higher.

## the Plan

Generating ServiceWorker scripts is easy, if you are on Node. There you you have many tools to do that for you.

On something like PHP, that is a different story.

So this is going to be a Tool/Class for PHP to generate and automatically Update a ServiceWorker, based on a few parameters you can set.


## Requirements to fullfill

- Standalone (No external dependencies at all)

- Needs to be able to generate a valid service worker script to:
    - precache static files in the client browser and deliver them in a "cacheFirst" approach.
    - store files in an "onDemand" fashion, and deliver them cacheFirst .  (means cache them only once they have been requested)
    - store files in an "onDemand" fashion, and use the cache as a fallback for Offline / error cases.
    
- Maintain a list of files, currently handled by the Service-Worker

- Only recreate the Service-Worker if things actually change in the project.


## Ideas for the Future
Add more functionality, like "Push Notification" handling.


# Usage:

Create a `sw.php` file.

- From there create an instance of the class.
- configure what directories/files are handled how by the ServiceWorker 
- `printAndExit()` and  the Configuration.

```php

<?php
    require_once __DIR__ . "/lib/ServiceWorkerGenerator.php";

    (new rogoss\ServiceWorkerGenerator())

        ->cachePrefix("PHPSWGen_Test") // Define what prefix the cache in the browser will receive
                                       // Needs to be set, if you have multiple ServiceWorkers in different scopes on the same server.

        ->fileCacheFirst("./index.html") // precache the index.html and deliver it CacheFirst
        ->dirCacheFirst("./vendor")  // precache everyting in the folder ./vendor and deliver it CacheFirst

        // TODO: add the other functions ... 

        ->printAndExit() // Finish the generation and end the PHP-Script
    ;
```

All Setter - Functions return `$this`, which means, you can chain all setters as shown above.
