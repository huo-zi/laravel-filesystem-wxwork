<?php
namespace Huozi\LaravelFilesystemWxwork;

use League\Flysystem\Config;
use League\Flysystem\Util;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use Overtrue\LaravelWeChat\Facade;
use EasyWeChat\Work\Application as Work;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class WorkWechatAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     *
     * @var Work
     */
    private $work;

    /**
     *
     * @var \Psr\SimpleCache\CacheInterface
     */
    private $cache;

    private $config;

    public function __construct($app, $config)
    {
        $this->cache = $app['cache'];
        $this->config = $config;
    }

    public function work($work)
    {
        if ($work instanceof Work) {
            $this->work = $work;
        } elseif ($work instanceof \Closure) {
            $this->work = $work($this->config);
        } else {
            $this->work = Facade::work($work);
        }
    }

    public function getUrl($path)
    {
        $host = Util::normalizePath($this->config['file_host'] ?? env('APP_URL'));
        $uri = Util::normalizePath($this->config['file_uri'] ?? 'work/media/');
        return $host . '/' . $uri . '/' . $this->getFileId($path);
    }

    public function writeStream($path, $resource, Config $config)
    {
        $multipart = [
            [
                'name' => 'media',
                'contents' => $resource,
                'filename' => basename($path)
            ]
        ];
        $response = $this->getWork()->media->request('cgi-bin/media/upload', 'POST', array_merge([
            'query' => [
                'type' => 'file'
            ],
            'multipart' => $multipart,
            'connect_timeout' => 30,
            'timeout' => 30,
            'read_timeout' => 30
        ], $config->get('options', [])));
        if ($response || $response['errcode']) {
            return false;
        }
        return $this->cache->set($this->formatPath($path), $response['media_id'], $this->getSeconds());
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    public function write($path, $contents, Config $config)
    {
        $stream = fopen('php://temp', 'wb+');
        fwrite($stream, $contents);
        rewind($stream);
        return $this->writeStream($path, $stream, $config);
    }

    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    public function read($path)
    {
        $contents = $this->getFile($path)->getBody() . '';
        return compact('contents', 'path');
    }

    public function readStream($path)
    {
        $stream = $this->getFile($path)->getBody();
        return compact('stream', 'path');
    }

    public function has($path)
    {
        return ! empty($this->getFileId($path));
    }

    public function copy($path, $newpath)
    {
        return $this->cache->set($this->formatPath($newpath), $this->getFileId($path), $this->getSeconds());
    }

    public function rename($path, $newpath)
    {
        $result = $this->copy($path, $newpath);
        $this->delete($path);
        return $result;
    }

    public function delete($path)
    {
        $paths = is_array($path) ? $path : [$path];
        array_walk($paths, function ($path) {
            $this->cache->delete($this->formatPath($path));
        });
        return true;
    }

    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    public function getMetadata($path)
    {
        $file = $this->getFile($path);
        return [
            'type' => 'file',
            'path' => trim(str_replace('\\', '/', pathinfo($path)), '/'),
            'size' => $file->getHeader('Content-Length')[0] ?? 0,
            'timestamp' => strtotime($file->getHeader('Date')[0])
        ];
    }

    public function getMimetype($path)
    {
        $mimetype = $this->getFile($path)->getHeader('Content-Type') ?? '';
        return [
            'path' => $path,
            'type' => 'file',
            'mimetype' => $mimetype
        ];
    }

    public function listContents($directory = '', $recursive = false)
    {
        return [];
    }

    public function createDir($dirname, Config $config)
    {
        return true;
    }

    public function deleteDir($dirname)
    {
        return true;
    }

    protected function formatPath($path)
    {
        $profix = isset($this->config['profix']) ? $this->config['profix'] . ':' : '';
        return $profix . str_replace('/', ':', $path);
    }

    protected function getSeconds()
    {
        return $this->config['seconds'] ?? 3 * 24 * 3600;
    }

    protected function getFileId($path)
    {
        $fileId = $this->cache->get($this->formatPath($path));
        if (! $fileId) {
            throw new FileNotFoundException($path . ' is not found');
        }
        return $fileId;
    }

    protected function getFile($path)
    {
        $fileId = $this->getFileId($path);
        $file = $this->getWork()->media->requestRaw('cgi-bin/media/get', 'GET', [
            'query' => [
                'media_id' => $fileId
            ]
        ]);
        if (! $file || $file->getHeader('Error-Code')) {
            throw new FileNotFoundException($file ? $file->getHeader('Error-Msg')[0] : 'get file error');
        }
        return $file;
    }

    protected function getWork()
    {
        if ($this->work) {
            return $this->work;
        }
        return $this->work = Facade::work($this->config['work'] ?? '');
    }
}
