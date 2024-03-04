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
    /**
     * @var {string} Helper to validate filepaths
     */
    private $sPregDocRoot = '';

    /**
     * @var {string} The name of the cache, that the ServiceWorker will store its files in
     */
    private $sCacheName = '__PhpSWGen__';

    /**
     * @var {string[]} Filenames to be handled cache first
     */
    private $aCacheFirst = [];

    /**
     * @var {string[]} A List of cache names, that the Service-Worker is supposed to clean up
     */
    private $aCacheCleanup = [];

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

            // DONE: restore MD5 List from that
            if (isset($aFiles))
                $this->aFileMD5 = $aFiles;
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
    public function cachePrefix($sCacheName)
    {
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
     * Generates the ServiceWorkers content and sends it to the output and ends the script execution.
     * @return void
     */
    public function printAndExit()
    {
        ob_end_clean();
        header('content-type: application/javascript');

        if ($this->bChanged) {
            $this->iTimeStamp = time();
        }

        $bCacheFirst = count($this->aCacheFirst) > 0;
        $bCacheClean = count($this->aCacheCleanup) > 0;

        echo '/* ts:', $this->iTimeStamp, " */\n",
            "\n const cache_name='", $this->sCacheName, "';";

        $CacheCMDs = [];

        if ($bCacheClean)
            $this->_printJSStrArray('cacheCleanup', $this->aCacheCleanup);
        // TODO: Make ServiceWorker do the Cleanup thing

        if ($bCacheFirst) {
            $this->_printJSStrArray('cacheFirst', $this->aCacheFirst);
            $CacheCMDs[] = ' await cache.addAll(cacheFirst) ';
        }

        if (count($CacheCMDs)) {
            $sJSInstallCMDs = implode(";\n\t", $CacheCMDs);

            $sJSCacheFirstFnc = $bCacheFirst
                ? self::$_swtpl_cache
                : '';

            echo self::$_swtpl_communications,
                <<<JS

                    self.addEventListener("install", (event) => {
                        const inst = async () => {
                            const cache = await caches.open(cache_name);

                            {$sJSInstallCMDs}

                        }

                        event.waitUntil(inst());
                    });

                    self.addEventListener("fetch", (event) => {

                        const req = async () => {

                            {$sJSCacheFirstFnc}

                            return fetch(event.request.clone());
                        }

                        event.respondWith(req());

                    })
                JS;
        }

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

        file_put_contents(
            $this->sLockFileName,
            <<<PHP
            <?php
                \$iTime = {$this->iTimeStamp};

                \$sCacheName = "{$this->sCacheName}";

                \$aCacheClean = $sCacheCleanup;

                \$aFiles = $sMD5Files;

            PHP
        );
        // TODO: Put MD5-Filelist in
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

        $aList[] = $this->_trimPath($sFilePath);
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

    // ==============================================================================
    // Template strings
    // ==============================================================================

    /**
     * @ignore
     */
    private static $_swtpl_communications = <<<JS
                    function postMessage(...args) {
                        self.clients.matchAll().then(clients => {
                            clients.forEach(client => {
                                client.postMessage({...args});
                            })
                        })
                    }

                    self.addEventListener("message", async (event) => {
                        console.log("MainThread send:", event.data)

                        if(event.data === "skip_waiting") {
                            console.log("Skip Waiting");
                            await self.skipWaiting();
                            postMessage("wait_finished");
                        }

                        postMessage("Thanks");
                    })
        JS;

    /**
     * @ignore
     */
    private static $_swtpl_cache = <<<JS

                    function fetchAndCache(request, cache) {
                        postMessage(`'\${request.url}' is not part of cache, but should be. => Fetching now ... `);
                        return fetch(request).then( response => {
                            cache.put(request, response.clone());
                            return response;
                        })
                    }

                    for(const file of cacheFirst) {
                        if(event.request.url.endsWith(file)) {
                            postMessage("foundfile", file);
                            const cache = await caches.open(cache_name);

                            return cache.match(event.request.clone())
                                .then( (res) => res || fetchAndCache(event.request.clone(), cache))
                        }

                        postMessage("missed", file);
                    }

        JS;
}
