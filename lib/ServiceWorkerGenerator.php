<?php
namespace rogoss;

/** 
 * @author Rocco Gossmann <github.com/rocco-gossmann>
 * @license MIT
 */


/** @class Thrown by ServiceWorkerGenerator, in case anything goes wrong */
class ServiceWorkerGeneratorException extends \Exception {
    const FILE_OUTSIDE_DOCUMENT_ROOT = 1;
    const INVALID_CACHENAME = 2;
    const INVALID_LOCKFILENAME = 3;
}

/** @class meant to make it easy to generate ServiceWorkers, to cache
 * Files on Browsers
 */
class ServiceWorkerGenerator
{
    /** @var {string} Helper to validate filepaths */
    private $sPregDocRoot = '';

    /** @var {string} The name of the cache, that the ServiceWorker will store its files in */
    private $sCacheName = '__PhpSWGen__';

    /** @var {string[]} Filenames to be handled cache first */
    private $aCacheFirst = [];

    /** @var {string[]} List of files handled by the Service-Worker [ md5(content) => filename ] */
    private $aFileMD5 = [];

    /** @var {string} Path to the file containing meta data for already generated Service-Workers */
    private $sLockFileName = 'sw.lock';

    /** @var {integer} Internal value used, to force the SW to update even if the filelist has not changed */
    private $iTimeStamp = 0;

    /** @var {boolean} Keeps track of if a new Lockfile and ServiceWorker needed to be generated */
    private $bChanged = false;

    public function __construct($sLockFileName = 'sw.lock') {
        ob_start();

        $this->sPregDocRoot = '/^' . preg_quote($_SERVER['DOCUMENT_ROOT'], '/') . '/';

        $this->sLockFileName = $this->_sanitizeMetaName(
            $sLockFileName,
            'LockFileName',
            ServiceWorkerGeneratorException::INVALID_LOCKFILENAME,
            '0-9a-z_\-.\/'
        );

        if(file_exists($this->sLockFileName)) {
            require $this->sLockFileName;

            if(empty($iTime)) 
                $this->bChanged = true;
            else 
                $this->iTimeStamp = $iTime;

        } else {
            $this->iTimeStamp = time();
            $this->bChanged = true;
        }

        // TODO: restore MD5 List from that
    }

    public function cachePrefix($sCacheName) {
        $this->sCacheName =
            $this->_sanitizeMetaName(
                $sCacheName,
                'cachename',
                ServiceWorkerGeneratorException::INVALID_CACHENAME
            );

        // TODO: Handle cleanup for renaming existing caches

        return $this;
    }

    public function dirCacheFirst($sDir) {
        self::_recursiveTransfere(
            fn($src) => $this->aCacheFirst[$src] = $src,
            $sDir
        );

        return $this;
    }

    public function fileCacheFirst($sFile) {
        $this->_addFile($this->aCacheFirst, $sFile);
        return $this;
    }

    public function printAndExit() {
        ob_end_clean();
        header('content-type: application/javascript');

        if($this->bChanged) {
            $this->iTimeStamp = time();
        }

        $bCacheFirst = count($this->aCacheFirst) > 0;

        // TODO: Code for cache cleanup on regeneration

         
        echo "/* ts:", $this->iTimeStamp, " */\n",
            "\n const cache_name='", $this->sCacheName, "';";

        $CacheCMDs = [];

        if ($bCacheFirst) {
            echo "\nconst cacheFirst = [\n\t",
                implode(",\n\t", array_map(fn($e) => '"' . $e . '"', $this->aCacheFirst)),
                "\n];\n\n";

            $CacheCMDs[] = ' await cache.addAll(cacheFirst) ';
        }


        if (count($CacheCMDs)) {
            $sJSActivateCMDs = implode(";\n\t", $CacheCMDs);

            echo <<<JS
                self.addEventListener("activate", (event) => {

                    const inst = async () => {
                        const cache = await caches.open(cache_name);

                        {$sJSActivateCMDs}
                    }

                    event.waitUntil(inst());
                });
                JS;
        }

        $this->_generateLockFile();

        exit;
    }

    private function _generateLockFile() {
        file_put_contents($this->sLockFileName, <<<PHP
<?php
    \$iTime = {$this->iTimeStamp};
PHP
);
        //TODO: Put MD5-Filelist in

    }

    private function _recursiveTransfere($fncCallback, $sSrcDir) {
        if (empty($sSrcDir) or
                !is_string($sSrcDir))
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

    private function _sanitizeFilePath($sFile) {
        $sFullName = realpath($sFile);
        if (!preg_match($this->sPregDocRoot, $sFullName))
            throw
                new ServiceWorkerGeneratorException(
                    "'" . $sFile . "' is outside of '" . $_SERVER['DOCUMENT_ROOT'] . "' (" . $sFullName . ' => ' . $this->sPregDocRoot . ')', ServiceWorkerGeneratorException::FILE_OUTSIDE_DOCUMENT_ROOT
                );

        return $sFullName;
    }

    private function _trimPath($sFile) {
        return preg_replace($this->sPregDocRoot, '', $sFile);
    }

    private function _addFile(&$aList, $sFile) {
        $sFilePath = $this->_sanitizeFilePath($sFile);

        $this->aFileMD5[$sFilePath] = md5(file_get_contents($sFilePath));
        $aList[] = $this->_trimPath($sFilePath);
    }

    private function _sanitizeMetaName($sStr, $sErrorValue, $iErrorCode, $sRegEx = '0-9a-z_\-')
    {
        $sTrimmed = trim($sStr);

        if (strlen($sTrimmed) == 0)
            throw new ServiceWorkerGeneratorException(
                "$sErrorValue can't be empty",
                $iErrorCode
            );

        $sRegEx = "/^[" . $sRegEx . "]+$/i";
        $arr = []; 

        if (!preg_match($sRegEx, $sTrimmed, $arr)) {
            error_log(var_export($arr));
            throw new ServiceWorkerGeneratorException(
                "$sErrorValue '$sTrimmed' can only contain characters'$sRegEx'",
                $iErrorCode
            );
        }

        return $sTrimmed;
    }
}