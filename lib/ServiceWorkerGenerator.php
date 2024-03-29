<?php

namespace rogoss;

/**
 * @author Rocco Gossmann <github.com/rocco-gossmann>
 * @license MIT
 */

/**
 * @class Thrown by ServiceWorkerGenerator, in case anything goes wrong
 */
class ServiceWorkerGeneratorException extends \Exception
{
    const FILE_OUTSIDE_DOCUMENT_ROOT = 1;
    const INVALID_CACHENAME = 2;
    const INVALID_LOCKFILENAME = 3;
    const INVALID_DIRECTORYINDEX_PATH = 4;
}

/**
 * @class meant to make it easy to generate ServiceWorkers, to cache
 * Files on Browsers
 */
class ServiceWorkerGenerator
{
    const DEFAULT_CACHENAME = '__PhpSWGen__';

    /**
     * @var {string} Helper to validate filepaths
     */
    private $sPregDocRoot = '';

    /**
     * @var {string} The name of the cache, that the ServiceWorker will store its files in
     */
    private $sCacheName = '__PhpSWGen__';

    /**
     * @var {boolean} Keeps track of if the CacheName was set in this run (if not reset the cachename to the default)
     */
    private $bCacheNameSet = false;

    /**
     * @var {string[]} Filenames to be handled cache first
     */
    private $aCacheFirst = [];

    /**
     * @var {string[]} Filenames to be handled on demand
     */
    private $aOnDemand = [];

    /**
     * @var {string[]} Filenames A list of files, that have been registered for on demand caching this run
     */
    private $aRegisteredOnDemand = [];

    /**
     * @var {boolean} If true, the onDemand cache will stay persistent throughout multiple updates of the ServiceWorker
     */
    private $bSaveOnDemandCache = false;

    /**
     * @var {string[]} A List of cache names, that the Service-Worker is supposed to clean up
     */
    private $aCacheCleanup = [];

    /**
     * @var {string[]} a list of URLs to be removed from cache after the Service-Worker has been activated
     */
    private $aFileCleanup = [];

    /**
     * @var {string[]} keeps track of what files have been registered this run (required to generate aFileCleanup)
     */
    private $aRegisterdFiles = [];

    /**
     * @var {string[]} keeps track of what pathes have been added via `enableDirectoryIndexCache` over the lifetime of the SW
     * @see self::enableDirectoryIndexCache
     */
    private $aRegisterdDirectoryIndexes = [];

    /**
     * @var {string[]} keeps track of what pathes have been added via `enableDirectoryIndexCache` in this run
     * @see self::enableDirectoryIndexCache
     */
    private $aUsedDirectoryIndexes = [];

    /**
     * @var {string[]} List of files handled by the Service-Worker [ md5(content) => filename ]
     */
    private $aFileMD5 = [];

    /**
     * @var {string} Path to the file containing meta data for already generated Service-Workers
     */
    private $sLockFileName = 'sw.lock';

    /**
     * @var {integer} Internal value used, to force the SW to update even if the filelist has not changed
     */
    private $iTimeStamp = 0;

    /**
     * @var {boolean} Keeps track of if a new Lockfile and ServiceWorker needed to be generated
     */
    private $bChanged = false;

    /**
     * @var {string[]} A list of patterns to be used for offline fallback
     * [ pattern => url ]
     */
    private $aFallbackPatterns = [];

    /**
     * The Constructor
     * @param  {string} $sLockFileName - Path to the file containing meta data for already generated Service-Workers
     */
    public function __construct($sLockFileName = 'sw.lock')
    {
        ob_start();

        $this->sPregDocRoot = '/^' . preg_quote($_SERVER['DOCUMENT_ROOT'], '/') . '/';

        $this->sLockFileName = $this->_sanitizeMetaName(
            $sLockFileName,
            'LockFileName',
            ServiceWorkerGeneratorException::INVALID_LOCKFILENAME,
            '0-9a-z_\-.\/'
        );

        if (file_exists($this->sLockFileName)) {
            require $this->sLockFileName;

            if (empty($iTime))
                $this->bChanged = true;
            else
                $this->iTimeStamp = $iTime;

            if (isset($aCacheClean) and is_array($aCacheClean))
                $this->aCacheCleanup = $aCacheClean;

            if (isset($sCacheName))
                $this->sCacheName = '' . $sCacheName;

            if (isset($aFiles))
                $this->aFileMD5 = $aFiles;

            if (isset($aDirIndexes))
                $this->aRegisterdDirectoryIndexes = $aDirIndexes;
        } else {
            $this->iTimeStamp = time();
            $this->bChanged = true;
        }
    }

    /**
     * changes the name of the cache, that the service-worker will use
     * @param  {string} $sCacheName  - the new name of the new cache
     * @return $this
     */
    public function cacheName($sCacheName)
    {
        $this->bCacheNameSet = true;

        if ($sCacheName === $this->sCacheName)
            return $this;

        $sOldCacheName = $this->sCacheName;

        $this->sCacheName =
            $this->_sanitizeMetaName(
                $sCacheName,
                'cachename',
                ServiceWorkerGeneratorException::INVALID_CACHENAME
            );

        $this->aCacheCleanup[$sOldCacheName] = $sOldCacheName;
        unset($this->aCacheCleanup[$this->sCacheName]);

        $this->bChanged = true;

        return $this;
    }

    /**
     * Registers the given path to be precached and delivered cache first
     * @param  {string} $sPath that the ServiceWorker will call directly
     * @return  $this
     */
    public function enableDirectoryIndexCache($sPath)
    {
        // DONE: Check if path ends with '/'
        if (!preg_match('/\/$/', $sPath))
            throw new ServiceWorkerGeneratorException(
                "Directory index path must end with '/'",
                ServiceWorkerGeneratorException::INVALID_DIRECTORYINDEX_PATH
            );

        // DONE: Make sure path does not call any hidden directories
        if (preg_match('/\/\.[^.]/', $sPath))
            throw new ServiceWorkerGeneratorException(
                "path must not contain a '/.'",
                ServiceWorkerGeneratorException::INVALID_DIRECTORYINDEX_PATH
            );

        // DONE: Resolve all .. in the path without the path needing to physically exist on the physically exist on the Server.
        $aParts = [];
        foreach (explode('/', $sPath) as $sPart) {
            if (empty($sPart) || $sPart === '.')
                continue;
            elseif ($sPart === '..') {
                if (count($aParts))
                    array_pop($aParts);
                else
                    throw new ServiceWorkerGeneratorException(
                        "path must not leave {$_SERVER['DOCUMENT_ROOT']}",
                        ServiceWorkerGeneratorException::INVALID_DIRECTORYINDEX_PATH
                    );
            }

            else
                $aParts[] = $sPart;
        }

        $sPath = implode('/', $aParts);

        if (empty($sPath))
            $sPath = '/';

        $this->aCacheFirst[$sPath] = $sPath;
        $this->aRegisterdDirectoryIndexes[$sPath] = $sPath;
        $this->aUsedDirectoryIndexes[$sPath] = $sPath;
        return $this;
    }

    /**
     * Registers all Files within the given Directory and its subdirectories to be precached and delivered cache first
     * @param  {string} $sDir  - the path to the directory
     * @return  $this
     */
    public function dirCacheFirst($sDir)
    {
        self::_recursiveTransfere(
            fn($src) => $this->_addFile($this->aCacheFirst, $src),
            $sDir
        );

        return $this;
    }

    /**
     * Registers the given file to be precached and delivered cache first
     *
     * @param  {string} $sFile  - the path to the file
     * @return  $this
     */
    public function fileCacheFirst($sFile)
    {
        $sFilePath = $this->_sanitizeFilePath($sFile);
        $this->_addFile($this->aCacheFirst, $sFilePath);
        return $this;
    }

    /**
     * Defines a Fallback file, which is used, should the requested url match the given Patter and
     * not load propperly on the Client
     *
     * @param  $sPattern  - a JavaScript Regular expression, to be checked against the requested URL
     * @param  $sFilePath - the url of the file inside the cache (this will be precached)
     *
     * @example
     * (new rogoss\ServiceWorkerGenerator())
     *  ->patternOfflineFallback('/\.svg$/', '/img/no_network.svg');
     *
     * @return $this
     */
    public function patternFallback($sPattern, $sFilePath)
    {
        $this->aFallbackPatterns[$sPattern] = $this->_addFile($this->aCacheFirst, $sFilePath);
        return $this;
    }

    /**
     * Registers all Files within the given Directory and its subdirectories to be cached once requested and delivered cache first after that
     * @param  {string} $sDir  - the path to the directory
     * @return  $this
     */
    public function dirCacheOnDemand($sDir)
    {
        self::_recursiveTransfere(
            function ($src) {
                $sRegisteredFile = $this->_addFile($this->aOnDemand, $src);
                $this->aRegisteredOnDemand[$sRegisteredFile] = $sRegisteredFile;
                return $sRegisteredFile;
            },
            $sDir
        );

        return $this;
    }

    /**
     * Registers the given file to be cached once requested and delivered cache first after that
     * @param {string} $sFile  - the path to the file
     * @return
     */
    public function fileCacheOnDemand($sFile)
    {
        $sFilePath = $this->_sanitizeFilePath($sFile);
        $sRegisteredFile = $this->_addFile($this->aOnDemand, $sFilePath);
        $this->aRegisteredOnDemand[$sRegisteredFile] = $sRegisteredFile;
        return $this;
    }

    /**
     * Enables saving the on demand cache on rebuild
     * @return $this
     */
    public function ignoreOnDemandCacheOnRebuild()
    {
        $this->bSaveOnDemandCache = true;
        return $this;
    }

    /**
     * Generates the ServiceWorkers content and sends it to the output and ends the script execution.
     * @return void
     */
    public function printAndExit()
    {
        ob_end_clean();
        header('content-type: application/javascript');

        // Reset the cache name, if it was not set this ru
        if (!$this->bCacheNameSet)
            $this->cacheName(self::DEFAULT_CACHENAME);

        // define which files to cleanup
        foreach ($this->aFileMD5 as $sFilePath => $_) {
            if (!empty($this->aRegisterdFiles[$sFilePath]))
                continue;
            $sTrimmedPath = $this->_trimPath($sFilePath);
            $this->aFileCleanup[$sTrimmedPath] = $sTrimmedPath;
        }

        // check directory indexes to cleanup
        foreach ($this->aRegisterdDirectoryIndexes as $sFilePath => $_) {
            if (!empty($this->aUsedDirectoryIndexes[$sFilePath]))
                continue;
            $this->aFileCleanup[$sFilePath] = $sFilePath;
        }

        // if a change should be forced update the timestamp, to make sure the browser
        // reloads the SW
        if ($this->bChanged)
            $this->iTimeStamp = time();

        $bCacheFirst = count($this->aCacheFirst) > 0;
        $bCacheClean = count($this->aCacheCleanup) > 0;
        $bFileCleanup = count($this->aFileCleanup) > 0;
        $bOnDemand = count($this->aRegisteredOnDemand) > 0;
        $bFallbackPatterns = count($this->aFallbackPatterns) > 0;

        echo '/* ts:', $this->iTimeStamp, " */\n",
            "\nconst cache_name='", $this->sCacheName, "';\n\n";

        $CacheCMDs = [];
        $ActivateCMDs = [];

        if ($bCacheFirst) {
            $this->_printJSStrArray('cacheFirst', $this->aCacheFirst);
            $CacheCMDs[] = ' await cache.addAll(cacheFirst) ';
        }

        // =============================================================================
        // Handle ondemand functions
        // =============================================================================
        $sJSCacheOnDemandFnc = '';
        if ($bOnDemand) {
            $this->_printJSStrArray('onDemand', $this->aRegisteredOnDemand);
            $sJSCacheOnDemandFnc = self::$_swtpl_ondemand;
            $sJSCleanupOnDemand = $this->_getJSStrArrayRaw($this->aOnDemand, 3);

            if (!$this->bSaveOnDemandCache) {
                $ActivateCMDs[] = <<<JS

                            const cleanupOnDemand = {$sJSCleanupOnDemand};
                                    
                            for (const key of (await caches.keys())) {
                                if (cleanupOnDemand.indexOf(key) !== -1) {
                                    await caches.delete(key);
                                }
                            } 
                    JS;
            }
        }

        // =============================================================================
        // Do the Cleanup thing
        // =============================================================================

        if ($bCacheClean)
            $this->_printJSStrArray('cacheCleanup', $this->aCacheCleanup);

        if ($bFileCleanup)
            $this->_printJSStrArray('fileCleanup', $this->aFileCleanup);

        if ($bFileCleanup || $bCacheClean) {
            if ($bCacheClean)
                $ActivateCMDs[] = <<<JS

                            for (const key of (await caches.keys())) {
                                if (cacheCleanup.indexOf(key) !== -1) {
                                    await caches.delete(key);
                                }
                            } 
                    JS;

            if ($bFileCleanup)
                $ActivateCMDs[] = <<<JS
                        const cache = await caches.open(cache_name);

                        if(cache) {
                            const domain = this.location.origin;
                            let url = "";
                            let mtch = -1;
                            for(const request of await cache.keys()) {
                                url = request.url.replace(domain, "");

                                if(fileCleanup.indexOf(url) !== -1) 
                                    await cache.delete(request);
                            }
                        }

                    JS;
        }

        if ($bFallbackPatterns) {
            $this->_printJSStrArrayKeyed('fallbackPatterns', $this->aFallbackPatterns);
            $sFallbackPatternFnc = self::$_swtpl_pattern_fallback;
        } else
            $sFallbackPatternFnc = '';

        $sJSInstallCMDs = count($CacheCMDs) ? implode(";\n\t", $CacheCMDs) : '';
        $sJSActivateCMDS = count($ActivateCMDs) ? implode(";\n\t", $ActivateCMDs) : '';
        $sJSCacheFirstFnc = $bCacheFirst ? self::$_swtpl_cache : '';

        echo self::$_swtpl_communications,
            <<<JS

            self.addEventListener("install", (event) => {
                const inst = async () => {
                    const cache = await caches.open(cache_name);

                    {$sJSInstallCMDs}

                    postEvent("install_done");
                }

                event.waitUntil(inst());
            });

            self.addEventListener("activate", (event) => {
                const act = async () => {
                    {$sJSActivateCMDS}
                    postEvent("activation_done");
                }

                event.waitUntil(act());
            })

            self.addEventListener("fetch", (event) => {

                async function patternFallback(request, response, cache) {
                    {$sFallbackPatternFnc}

                    return response;
                }

                function fetchAndCache(request, cache) {

                    postMsg(`'\${request.url}' is not part of cache, but should be. => Fetching now ... `);

                    return fetch(request).then( response => {

                        if(response) {
                            const statuscode = response.status.toString();

                            if(statuscode.startsWith('2')) {
                                if(statuscode==='200') 
                                    cache.put(request, response.clone());

                                return response;

                            } else return patternFallback(request, response, cache); 

                        } else return patternFallback(request, response, cache); 

                    })
                }

                async function req() {

                    {$sJSCacheFirstFnc}

                    {$sJSCacheOnDemandFnc}

                    return fetch(event.request.clone()).then( response => {
                        return patternFallback(event.request, response)
                    }).catch(() => patternFallback(event.request));
                }

                event.respondWith(req());

            })
            JS;

        $this->_generateLockFile();

        exit;
    }

    /**
     * @ignore
     */
    private function _generateLockFile()
    {
        $sCacheCleanup = var_export($this->aCacheCleanup, true);
        $sMD5Files = var_export($this->aFileMD5, true);
        $sDirectoryIndexes = var_export($this->aRegisterdDirectoryIndexes, true);

        file_put_contents(
            $this->sLockFileName,
            <<<PHP
            <?php
                \$iTime = {$this->iTimeStamp};

                \$sCacheName = "{$this->sCacheName}";

                \$aCacheClean = $sCacheCleanup;

                \$aFiles = $sMD5Files;

                \$aDirIndexes = $sDirectoryIndexes;

            PHP
        );
    }

    /**
     * @ignore
     */
    private function _recursiveTransfere($fncCallback, $sSrcDir)
    {
        if (
            empty($sSrcDir) or
            !is_string($sSrcDir)
        )
            return false;

        $aDirs = [realpath($sSrcDir)];

        while (count($aDirs) > 0) {
            $sDir = array_shift($aDirs);

            if (empty($sDir))
                continue;

            if (!file_exists($sDir))
                continue;

            $aFiles = new \DirectoryIterator($sDir);

            foreach ($aFiles as $oF) {
                if ($oF->isDot())
                    continue;

                $sFullName = $sDir . '/' . $oF->getFilename();

                if ($oF->isDir()) {
                    array_push($aDirs, $sFullName);
                } else {
                    $fncCallback($sFullName);
                }
            }
        }
    }

    /**
     * @ignore
     */
    private function _sanitizeFilePath($sFile)
    {
        $sFullName = realpath($sFile);
        if (!preg_match($this->sPregDocRoot, $sFullName))
            throw
                new ServiceWorkerGeneratorException(
                    "'" . $sFile . "' is outside of '" . $_SERVER['DOCUMENT_ROOT'] . "' ('$sFile' => '" . $sFullName . "' => " . $this->sPregDocRoot . ')',
                    ServiceWorkerGeneratorException::FILE_OUTSIDE_DOCUMENT_ROOT
                );

        return $sFullName;
    }

    /**
     * @ignore
     */
    private function _trimPath($sFile)
    {
        return preg_replace($this->sPregDocRoot, '', $sFile);
    }

    /**
     * @ignore
     */
    private function _addFile(&$aList, $sFile)
    {
        $sFilePath = $this->_sanitizeFilePath($sFile);
        $sFileMD5 = md5(file_get_contents($sFilePath));

        if (isset($this->aFileMD5[$sFilePath])) {
            if ($this->aFileMD5[$sFilePath] !== $sFileMD5) {
                $this->bChanged = true;
            }
        }

        $this->aFileMD5[$sFilePath] = $sFileMD5;
        $this->aRegisterdFiles[$sFilePath] = true;

        $sURLFile = $this->_trimPath($sFilePath);
        $aList[] = $sURLFile;

        return $sURLFile;
    }

    /**
     * @ignore
     */
    private function _sanitizeMetaName($sStr, $sErrorValue, $iErrorCode, $sRegEx = '0-9a-z_\-')
    {
        $sTrimmed = trim($sStr);

        if (strlen($sTrimmed) == 0)
            throw new ServiceWorkerGeneratorException(
                "$sErrorValue can't be empty",
                $iErrorCode
            );

        $sRegEx = '/^[' . $sRegEx . ']+$/i';
        $arr = [];

        if (!preg_match($sRegEx, $sTrimmed, $arr)) {
            throw new ServiceWorkerGeneratorException(
                "$sErrorValue '$sTrimmed' can only contain characters'$sRegEx'",
                $iErrorCode
            );
        }

        return $sTrimmed;
    }

    /**
     * @ignore
     */
    private function _printJSStrArray($sJSArrName, $arr)
    {
        echo "\nconst {$sJSArrName} = [\n\t",
            implode(",\n\t", array_map(fn($e) => '"' . $e . '"', $arr)),
            "\n];\n\n";
    }

    /**
     * @ignore
     */
    private function _getJSStrArrayRaw($arr, $iIndent=0)
    {
        
        $sIndent = str_repeat("\t", $iIndent);
        $sLastIndent = str_repeat("\t", max(0, $iIndent - 1));
        return "[\n{$sIndent}" . 
            implode(",\n{$sIndent}", array_map(fn($e) => '"' . $e . '"', $arr)) .
            "\n{$sLastIndent}]";
    }

    /**
     * @ignore
     */
    private function _printJSStrArrayKeyed($sJSArrName, $arr)
    {
        echo "\nconst {$sJSArrName} = {\n\t";

        foreach ($arr as $key => $value)
            echo '"' . $key . '" : "' . $value . "\",\n";

        echo "};\n\n";
    }

    // ==============================================================================
    // Template strings
    // ==============================================================================

    /**
     * @ignore
     */
    private static $_swtpl_communications = <<<JS
            
        const clientList = new Map();

        function postToClient(client, event, ...args) {
            client.postMessage(JSON.stringify({ type: event, data: args }))
        }


        function postEvent(event, ...args) {
            //console.log(clients, clientList);
            self.clients.matchAll().then(clients => {
                clients.forEach(client => postToClient(client, event, ...args) )
            })

            clientList.forEach(client => postToClient(client, event, ...args) )
        }

        function postMsg(...args) { postEvent("msg", ...args) }

        self.addEventListener("message", async (event) => {
            console.log("Message from",  event.source.id, ": ", event.data);
            clientList.set(event.source.id, event.source);
            if(event.data === "skip_waiting") {
                await self.skipWaiting();
            }
        })
        JS;

    /**
     * @ignore
     */
    private static $_swtpl_cache = <<<JS

                for(const file of cacheFirst) {
                    if(event.request.url.endsWith(file)) {
                        const cache = await caches.open(cache_name);

                        return cache.match(event.request.clone())
                            .then( (res) => res || fetchAndCache(event.request.clone(), cache))
                    }
                }
        JS;

    /**
     * @ignore
     */
    private static $_swtpl_pattern_fallback = <<<JS

                if(response && response.status.toString().match(/^(2|3)\d\d\$/)) 
                    return response;

                let regExp = undefined;
                for(const [pattern, file] of Object.entries(fallbackPatterns)) {
                    regExp = new RegExp(pattern, "i");

                    if(regExp.test(request.url)) {
                        if(!cache)
                            cache = await caches.open(cache_name);
                        const mtch = await cache.match(file);
                        if(mtch) return mtch.clone() 
                        else return response;
                    }
                }

                return response;
        JS;

    /**
     * @ignore
     */
    private static $_swtpl_ondemand = <<<JS

                for(const file of onDemand) {
                    if(event.request.url.endsWith(file)) {
                        const cache = await caches.open(cache_name);

                        return cache.match(event.request.clone())
                            .then( (res) => res || fetchAndCache(event.request.clone(), cache))
                    }            
                }
        JS;
}
