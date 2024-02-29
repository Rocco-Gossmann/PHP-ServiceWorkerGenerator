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

            if(isset($aFiles))
                $this->aFileMD5 = $aFiles;

        } else {
            $this->iTimeStamp = time();
            $this->bChanged = true;
        }

        // TODO: restore MD5 List from that
    }

    /**
     * changes the name of the cache, that the service-worker will use
     * @param  {string} $sCacheName
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
     * Registers all Files within the given Directory and its subdirectories to be precached and delivered cache first
     *
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
        $this->_addFile($this->aCacheFirst, $sFile);
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

            echo <<<JS
                                self.addEventListener("install", (event) => {

                                    const inst = async () => {
                                        const cache = await caches.open(cache_name);

                                        {$sJSInstallCMDs}
                                    }

                                    event.waitUntil(inst());
                                });
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

                $sFullName = $this->_sanitizeFilePath(
                    $sDir . '/' . $oF->getFilename(),
                    false
                );

                if ($oF->isDir()) {
                    array_push($aDirs, $sFullName);
                } else {
                    $fncCallback($this->_trimPath($sFullName));
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
                    "'" . $sFile . "' is outside of '" . $_SERVER['DOCUMENT_ROOT'] . "' (" . $sFullName . ' => ' . $this->sPregDocRoot . ')',
                    ServiceWorkerGeneratorException::FILE_OUTSIDE_DOCUMENT_ROOT
                );

        return $sFullName;
    }

    /**
     * @ignore
     */
    private function _trimPath($sFile)
    {
        return preg_replace($this->sPregDocRoot, '.', $sFile);
    }

    /**
     * @ignore
     */
    private function _addFile(&$aList, $sFile)
    {
        $sFilePath = $this->_sanitizeFilePath($sFile);
        $sFileMD5 = md5(file_get_contents($sFilePath));

        if(isset($this->aFileMD5[$sFilePath])) {
            if($this->aFileMD5[$sFilePath] !== $sFileMD5) {
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
}
