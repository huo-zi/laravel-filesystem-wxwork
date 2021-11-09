<?php

namespace Huozi\LaravelFilesystemWxwork;

use League\Flysystem\Config;
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
     * @var Cache
     */
    private $cache;

    private $config;

    public function __construct($app, $config)
    {
        $this->config = $config;
        $this->cache = new Cache(
            $app['cache']->store($config['store'] ?? null),
            $config['prefix'] ?? 'flysystem',
            $config['expire'] ?? 3 * 86400
        );
        $this->cache->load();
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
        if (!$response || $response['errcode']) {
            return false;
        }
        $result = array();
        $result['type'] = 'file';
        $md5 = $response['media_id'];
        $this->cache->updateObject($path, $result + compact('path', 'md5'), true);
        return $result;
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

    public function rename($path, $newpath)
    {
        return $this->cache->rename($path, $newpath);
    }

    public function copy($path, $newpath)
    {
        return $this->cache->copy($path, $newpath);
    }

    public function delete($path)
    {
        return $this->cache->delete($path);
    }

    public function has($path)
    {
        return $this->cache->has($path);
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
        if (isset($this->meta[$path])) {
            return $this->meta;
        }
        $file = $this->getFile($path);
        return $this->meta[$path] = [
            'type' => 'file',
            'path' => trim(str_replace('\\', '/', pathinfo($path)['dirname']), '/'),
            'size' => $file->getHeaderLine('Content-Length') ?? 0,
            'timestamp' => strtotime($file->getHeaderLine('Date'))
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
        if ($this->cache->isComplete($directory, $recursive)) {
            return $this->cache->listContents($directory, $recursive);
        }
        return [];
    }

    public function createDir($dirname, Config $config)
    {
        $type = 'dir';
        $path = $dirname;
        $this->cache->updateObject($dirname, compact('path', 'type'), true);
        return compact('path', 'type');
    }

    public function deleteDir($dirname)
    {
        $this->cache->deleteDir($dirname);
        return true;
    }

    public function getMediaId($path)
    {
        $result = $this->cache->read($path);
        if ($result !== false) {
            return $result['md5'];
        }
        throw new FileNotFoundException($path . ' is not found');
    }

    protected function getFile($path)
    {
        $fileId = $this->getMediaId($path);
        $file = $this->getWork()->media->requestRaw('cgi-bin/media/get', 'GET', [
            'query' => [
                'media_id' => $fileId
            ]
        ]);
        if (!$file || $file->getHeader('Error-Code')) {
            throw new FileNotFoundException($file ? $file->getHeaderLine('Error-Msg') : 'get file error');
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

    public function __call($name, $arguments)
    {
        return $this->cache->{$name}(...$arguments);
    }
}
