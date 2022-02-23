<?php


namespace Xjaqil\AliOSS;

use Illuminate\Support\Facades\Log;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use OSS\Core\OssException;
use OSS\OssClient;

class AliOssAdapter implements FilesystemAdapter
{


    /** @var OssClient */
    protected OssClient $client;


    /** @var PathPrefixer */
    protected PathPrefixer $prefixer;

    /** @var MimeTypeDetector */
    protected MimeTypeDetector $mimeTypeDetector;

    protected bool $debug;

    protected string $bucket;

    protected string $endPoint;

    protected string $cdnDomain;

    protected bool $ssl;

    protected bool $isCname;

    protected array $options = [
        'Multipart' => 128
    ];

    protected static array $resultMap = [
        'Body' => 'raw_contents',
        'Content-Length' => 'size',
        'ContentType' => 'mimetype',
        'Size' => 'size',
        'StorageClass' => 'storage_class',
    ];


    protected static array $metaOptions = [
        'CacheControl',
        'Expires',
        'ServerSideEncryption',
        'Metadata',
        'ACL',
        'ContentType',
        'ContentDisposition',
        'ContentLanguage',
        'ContentEncoding',
    ];

    protected static array $metaMap = [
        'CacheControl' => 'Cache-Control',
        'Expires' => 'Expires',
        'ServerSideEncryption' => 'x-oss-server-side-encryption',
        'Metadata' => 'x-oss-metadata-directive',
        'ACL' => 'x-oss-object-acl',
        'ContentType' => 'Content-Type',
        'ContentDisposition' => 'Content-Disposition',
        'ContentLanguage' => 'response-content-language',
        'ContentEncoding' => 'Content-Encoding',
    ];

    public function __construct(
        OssClient $client,
        array     $config,
        string    $prefix = '',
        array     $options = [],
    )
    {
        $this->client = $client;
        $this->debug = $config['debug'];
        $this->bucket = $config['bucket'];
        $this->endPoint = $config['endpoint'];
        $this->ssl = $config['ssl'];
        $this->isCname = $config['is_cname'];
        $this->cdnDomain = $config['cdn_domain'];
        $this->options = array_merge($this->options, $options);
        $this->prefixer = new PathPrefixer($prefix);
        $this->mimeTypeDetector = new FinfoMimeTypeDetector();
    }


    public function getBucket(): string
    {
        return $this->bucket;
    }


    public function getClient(): OssClient
    {
        return $this->client;
    }

    public function fileExists(string $path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->doesObjectExist($this->bucket, $location);

            return true;
        } catch (OssException $exception) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        return $this->fileExists($path);
    }


    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptions($this->options, $config);
        if (!isset($options[OssClient::OSS_LENGTH])) {
            $options[OssClient::OSS_LENGTH] = $this->contentSize($contents);
        }
        if (!isset($options[OssClient::OSS_CONTENT_TYPE])) {
            $options[OssClient::OSS_CONTENT_TYPE] = $this->mimeTypeDetector->detectMimeType($path, $contents);
        }
        try {
            $this->client->putObject($this->bucket, $object, $contents, $options);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            throw UnableToWriteFile::atLocation($object, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $contents = stream_get_contents($contents);
            $this->write($location, $contents, $config);
        } catch (OssException $e) {
            throw UnableToWriteFile::atLocation($location, $e->getMessage(), $e);
        }
    }

    public function writeFile($path, $filePath, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptions($this->options, $config);

        $options[OssClient::OSS_CHECK_MD5] = true;

        if (!isset($options[OssClient::OSS_CONTENT_TYPE])) {
            $options[OssClient::OSS_CONTENT_TYPE] = $this->mimeTypeDetector->detectMimeTypeFromPath($path);
        }
        try {
            $this->client->uploadFile($this->bucket, $object, $filePath, $options);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return $this->normalizeResponse($options, $path);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        if (!$config->has('visibility') && !$config->has('ACL')) {
            $config->set(static::$metaMap['ACL'], $this->getObjectACL($path));
        }
        // $this->delete($path);
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);
        return $this->update($path, $contents, $config);
    }


    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $path = $this->applyPathPrefix($source);
        $newPath = $this->applyPathPrefix($destination);

        try {
            $this->client->copyObject($this->bucket, $path, $this->bucket, $newPath);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            throw UnableToCopyFile::fromLocationTo($path, $newPath, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->deleteObject($this->bucket, $location);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            throw UnableToDeleteFile::atLocation($location, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $dirname = rtrim($this->applyPathPrefix($dirname), '/') . '/';
        $dirObjects = $this->listDirObjects($dirname, true);

        if (count($dirObjects['objects']) > 0) {

            foreach ($dirObjects['objects'] as $object) {
                $objects[] = $object['Key'];
            }

            try {
                $this->client->deleteObjects($this->bucket, $objects);
            } catch (OssException $e) {
                $this->logErr(__FUNCTION__, $e);
                return false;
            }

        }

        try {
            $this->client->deleteObject($this->bucket, $dirname);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        return true;
    }

    /**
     * 列举文件夹内文件列表；可递归获取子文件夹；
     * @param string $dirname 目录
     * @param bool $recursive 是否递归
     * @return mixed
     * @throws OssException
     */
    public function listDirObjects($dirname = '', $recursive = false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;

        //存储结果
        $result = [];

        while (true) {
            $options = [
                'delimiter' => $delimiter,
                'prefix' => $dirname,
                'max-keys' => $maxkeys,
                'marker' => $nextMarker,
            ];

            try {
                $listObjectInfo = $this->client->listObjects($this->bucket, $options);
            } catch (OssException $e) {
                $this->logErr(__FUNCTION__, $e);
                // return false;
                throw $e;
            }

            $nextMarker = $listObjectInfo->getNextMarker(); // 得到nextMarker，从上一次listObjects读到的最后一个文件的下一个文件开始继续获取文件列表
            $objectList = $listObjectInfo->getObjectList(); // 文件列表
            $prefixList = $listObjectInfo->getPrefixList(); // 目录列表

            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {

                    $object['Prefix'] = $dirname;
                    $object['Key'] = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag'] = $objectInfo->getETag();
                    $object['Type'] = $objectInfo->getType();
                    $object['Size'] = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();

                    $result['objects'][] = $object;
                }
            } else {
                $result["objects"] = [];
            }

            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            } else {
                $result['prefix'] = [];
            }

            //递归查询子目录所有文件
            if ($recursive) {
                foreach ($result['prefix'] as $pfix) {
                    $next = $this->listDirObjects($pfix, $recursive);
                    $result["objects"] = array_merge($result['objects'], $next["objects"]);
                }
            }

            //没有更多结果了
            if ($nextMarker === '') {
                break;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $object = $this->applyPathPrefix($dirname);
        $options = $this->getOptionsFromConfig($config);

        try {
            $this->client->createObjectDir($this->bucket, $object, $options);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $object = $this->applyPathPrefix($path);
            $acl = ($visibility === Visibility::PUBLIC) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
            $this->client->putObjectAcl($this->bucket, $object, $acl);
        } catch (OssException $e) {
            throw UnableToSetVisibility::atLocation($path, 'Adapter does not support visibility controls.');
        }
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $path): FileAttributes
    {
        // Noop
        return new FileAttributes($path);
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes(
            $path,
            null,
            null,
            null,
            $this->mimeTypeDetector->detectMimeTypeFromPath($path)
        );
    }


    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {

        $object = $this->readStream($path);

        $contents = stream_get_contents($object);
        fclose($object);
        unset($object);

        return $contents;
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        try {
            $result = $this->readObject($path);
            $result['stream'] = $result['raw_contents'];
            rewind($result['stream']);
            // Ensure the EntityBody object destruction doesn't close the stream
            $result['raw_contents']->detachStream();
            unset($result['raw_contents']);
        } catch (OssException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        return $result;
    }


    /**
     * Read an object from the OssClient.
     *
     * @param string $path
     *
     * @return array
     */
    protected function readObject($path)
    {
        $object = $this->applyPathPrefix($path);

        $result['Body'] = $this->client->getObject($this->bucket, $object);
        $result = array_merge($result, ['type' => 'file']);
        return $this->normalizeResponse($result, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        foreach ($this->listDirObjects($path, $deep) as $entry) {
            $storageAttrs = $this->normalizeResponse($entry);

            // Avoid including the base directory itself
            if ($storageAttrs->isDir() && $storageAttrs->path() === $path) {
                continue;
            }

            yield $storageAttrs;
        }
    }


    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $objectMeta = $this->client->getObjectMeta($this->bucket, $object);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        return $objectMeta;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        $object = $this->getMetadata($path);
        $object['size'] = $object['content-length'];
        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        if ($object = $this->getMetadata($path))
            $object['mimetype'] = $object['content-type'];
        return $object;
    }


    public function getTimestamp($path)
    {
        if ($object = $this->getMetadata($path))
            $object['timestamp'] = strtotime($object['last-modified']);
        return $object;
    }


    public function getVisibility($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $acl = $this->client->getObjectAcl($this->bucket, $object);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        if ($acl == OssClient::OSS_ACL_TYPE_PUBLIC_READ) {
            $res['visibility'] = Visibility::PUBLIC;
        } else {
            $res['visibility'] = Visibility::PRIVATE;
        }

        return $res;
    }


    /**
     * @throws FilesystemException
     */
    public function getUrl($path): string
    {
//        if (!$this->fileExists($path)) throw new  UnableToCheckExistence();
        return ($this->ssl ? 'https://' : 'http://') . ($this->isCname ? ($this->cdnDomain == '' ? $this->endPoint : $this->cdnDomain) : $this->bucket . '.' . $this->endPoint) . '/' . ltrim($path, '/');
    }

    /**
     * The the ACL visibility.
     *
     * @param string $path
     *
     * @return string
     */
    protected function getObjectACL($path)
    {
        $metadata = $this->getVisibility($path);

        return $metadata['visibility'] === Visibility::PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
    }


    protected function normalizeResponse(array $response): StorageAttributes
    {
        $timestamp = (isset($response['server_modified'])) ? strtotime($response['server_modified']) : null;

        if ($response['.tag'] === 'folder') {
            $normalizedPath = ltrim($this->prefixer->stripDirectoryPrefix($response['path_display']), '/');

            return new DirectoryAttributes(
                $normalizedPath,
                null,
                $timestamp
            );
        }

        $normalizedPath = ltrim($this->prefixer->stripPrefix($response['path_display']), '/');

        return new FileAttributes(
            $normalizedPath,
            $response['size'] ?? null,
            null,
            $timestamp,
            $this->mimeTypeDetector->detectMimeTypeFromPath($normalizedPath)
        );
    }


    /**
     * Get options for a OSS call. done
     *
     * @param array $options
     *
     * @return array OSS options
     */
    protected function getOptions(array $options = [], Config $config = null)
    {
        $options = array_merge($this->options, $options);

        if ($config) {
            $options = array_merge($options, $this->getOptionsFromConfig($config));
        }

        return array(OssClient::OSS_HEADERS => $options);
    }


    protected function getOptionsFromConfig(Config $config): array
    {
        $options = [];

        foreach (static::$metaOptions as $option) {
            if (!$config->get($option)) {
                continue;
            }
            $options[static::$metaMap[$option]] = $config->get($option);
        }

        if ($visibility = $config->get('visibility')) {
            $options['x-oss-object-acl'] = $visibility === Visibility::PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
        }

        if ($mimetype = $config->get('mimetype')) {
            $options['Content-Type'] = $mimetype;
        }

        return $options;
    }

    /**
     * @param $fun string function name : __FUNCTION__
     * @param $e
     */
    protected function logErr($fun, $e)
    {
        if ($this->debug) {
            Log::error($fun . ": FAILED");
            Log::error($e->getMessage());
        }
    }

    /**
     * @param string $path
     * @return void
     */
    public function deleteDirectory(string $path): void
    {
        // TODO: Implement deleteDirectory() method.
    }

    /**
     * @param string $path
     * @param Config $config
     * @return void
     */
    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->createObjectDir($this->bucket, $path);
        } catch (OssException $e) {
            logger('Create dir fail. Cause: ' . $e->getMessage());
        }
    }


    /**
     * @inheritDoc
     */
    public function lastModified(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->getMetadata($location);
        } catch (OssException $e) {
            throw UnableToRetrieveMetadata::lastModified($location, $e->getMessage());
        }

        $timestamp = (isset($response['server_modified'])) ? strtotime($response['server_modified']) : null;

        return new FileAttributes(
            $path,
            null,
            null,
            $timestamp
        );
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->getMetadata($location);
        } catch (OssException $e) {
            throw UnableToRetrieveMetadata::lastModified($location, $e->getMessage());
        }

        return new FileAttributes(
            $path,
            $response['size'] ?? null
        );
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $path = $this->applyPathPrefix($source);
        $newPath = $this->applyPathPrefix($destination);

        try {
            $this->client->copyObject($this->bucket, $path, $this->bucket, $newPath);
            $this->client->deleteObject($this->bucket, $path);
        } catch (OssException $e) {
            throw UnableToMoveFile::fromLocationTo($path, $newPath, $e);
        }
    }

    protected function applyPathPrefix($path): string
    {
        return $this->prefixer->prefixPath($path);
    }

    public static function contentSize($contents): bool|int
    {
        return defined('MB_OVERLOAD_STRING') ? mb_strlen($contents, '8bit') : strlen($contents);
    }
}
