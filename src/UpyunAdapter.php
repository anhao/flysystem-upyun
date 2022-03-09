<?php

namespace JellyBool\Flysystem\Upyun;

use JetBrains\PhpStorm\ArrayShape;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use Upyun\Upyun;

/**
 * Class UpyunAdapter
 * @package JellyBool\Flysystem\Upyun
 */
class UpyunAdapter implements FilesystemAdapter
{
    /**
     * @var string
     */
    protected string $bucket;
    /**
     * @var string
     */
    protected string $operator;
    /**
     * @var string
     */
    protected string $password;

    /**
     * @var string
     */
    protected string $domain;

    /**
     * @var string
     */
    protected string $protocol;

    /**
     * UpyunAdapter constructor.
     * @param $bucket
     * @param $operator
     * @param $password
     * @param mixed $domain
     * @param mixed $protocol
     */
    public function __construct($bucket, $operator, $password, $domain, $protocol = 'http')
    {
        $this->bucket = $bucket;
        $this->operator = $operator;
        $this->password = $password;
        $this->domain = $domain;
        $this->protocol = $protocol;
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @throws \Exception
     */
    public function write($path, $contents, Config $config): void
    {
        $this->client()->write($path, $contents);
    }

    /**
     * @param string $path
     * @param $contents
     * @param Config $config
     * @throws \Exception
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->client()->write($path, $contents);
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @throws \Exception
     */
    public function update(string $path, string $contents, Config $config)
    {
        $this->write($path, $contents, $config);
    }

    /**
     * @param string $path
     * @param resource $resource
     * @param Config $config
     * @throws \Exception
     */
    public function updateStream(string $path, $resource, Config $config)
    {
        $this->writeStream($path, $resource, $config);
    }

    /**
     *
     * @param string $path
     * @param string $newpath
     */
    public function rename(string $path, string $newpath)
    {
        $this->copy($path, $newpath, null);
        $this->delete($path);
    }


    public function copy(string $source, string $destination, $config): void
    {
        $this->client()->copy($source, $destination);
    }

    /**
     * @param string $path
     * @throws \Exception
     */
    public function delete(string $path): void
    {
        $this->client()->delete($path);
    }

    /**
     * @param string $dirname
     * @return bool
     * @throws \Exception
     */
    public function deleteDir($dirname)
    {
        return $this->client()->deleteDir($dirname);
    }

    /**
     * @param string $dirname
     * @param Config $config
     */
    public function createDir($dirname, Config $config): void
    {
        $this->client()->createDir($dirname);
    }

    /**
     * @param string $path
     * @param string $visibility
     */
    public function setVisibility(string $path, string $visibility): void
    {
        //TODO
    }

    /**
     * @param string $path
     */
    public function has($path): bool
    {
        return $this->client()->has($path);
    }

    /**
     * @param string $path
     * @return string
     * @throws \Exception
     */
    public function read(string $path): string
    {
        return $this->client()->read($path);
    }

    /**
     * @param string $path
     * @return array
     */
    public function readStream(string $path): array
    {
        $stream = fopen($this->getUrl($path), 'r');
        return compact('stream', 'path');
    }

    /**
     * @param string $path
     * @param bool $deep
     * @return iterable
     * @throws \Exception
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {

        $result = $this->client()->read($path, null, ['X-List-Limit' => 100, 'X-List-Iter' => null]);

        foreach ($result['files'] as $files) {
            yield $this->normalizeFileInfo($files, $path);
        }
    }

    /**
     * @param string $path
     * @return FileAttributes|null
     */
    public function getMetadata(string $path): ?FileAttributes
    {
        $info = $this->client()->info($path, array('content-type'));
        return new FileAttributes(
            $path,
            isset($info['x-upyun-file-size']) ? intval($info['x-upyun-file-size']) : null,
            null,
            isset($info['Last-Modified'][0]) ? \strtotime($info['Last-Modified'][0]) : null,
            $meta['content-type'][0] ?? null,
        );
    }

    /**
     * @param string $path
     * @return array
     */
    public function getType(string $path): array
    {
        $response = $this->getMetadata($path);

        return ['type' => $response['x-upyun-file-type']];
    }

    /**
     * @param string $path
     */
    public function getSize(string $path): array
    {
        $response = $this->getMetadata($path);

        return ['size' => $response['x-upyun-file-size']];
    }

    /**
     * @param string $path
     * @deprecated
     */
    public function getMimetype($path): array
    {
        $headers = get_headers($this->getUrl($path), 1);
        $mimetype = $headers['Content-Type'];
        return compact('mimetype');
    }

    /**
     * @param string $path
     * @return array
     */
    public function getTimestamp(string $path): array
    {
        $response = $this->getMetadata($path);

        return ['timestamp' => $response['x-upyun-file-date']];
    }

    /**
     * @param string $path
     * @return bool
     */
    public function getVisibility(string $path): bool
    {
        return true;
    }

    /**
     * @param string $path
     * @return string
     */
    public function getUrl(string $path): string
    {
        return $this->normalizeHost($this->domain) . $path;
    }

    /**
     * @return Upyun
     */
    protected function client(): Upyun
    {
        $config = new \Upyun\Config($this->bucket, $this->operator, $this->password);
        $config->useSsl = config('filesystems.disks.upyun.protocol') === 'https' ? true : false;
        return new Upyun($config);
    }

    /**
     * Normalize the file info.
     *
     * @param array $stats
     * @param string $directory
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats, string $directory): array
    {
        $filePath = ltrim($directory . '/' . $stats['name'], '/');

        return [
            'type' => $this->getType($filePath)['type'],
            'path' => $filePath,
            'timestamp' => $stats['time'],
            'size' => $stats['size'],
        ];
    }

    /**
     * @param $domain
     * @return string
     */
    protected function normalizeHost($domain): string
    {
        if (0 !== stripos($domain, 'https://') && 0 !== stripos($domain, 'http://')) {
            $domain = $this->protocol . "://{$domain}";
        }

        return rtrim($domain, '/') . '/';
    }

    /**
     * @param string $path
     * @return bool
     * @throws \Exception
     */
    public function fileExists(string $path): bool
    {
        return $this->client()->has($path);
    }

    /**
     * @param string $path
     * @return bool
     * @throws \Exception
     */
    public function directoryExists(string $path): bool
    {
        return $this->client()->has($path);
    }

    /**
     * @param string $path
     * @return void
     * @throws \Exception
     */
    public function deleteDirectory(string $path): void
    {
        $this->client()->deleteDir($path);
    }

    /**
     * @param string $path
     * @param Config $config
     * @return void
     * @throws \Exception
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->client()->createDir($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     * @throws \Exception
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $this->client()->move($source, $destination);
    }
}
