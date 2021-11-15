<?php

namespace Huozi\LaravelFilesystemWxwork;

use EasyWeChat\Work\Application as Work;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Overtrue\LaravelWeChat\Facade;

class WorkWechatAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;
    use StreamedReadingTrait;

    /**
     * @var Work
     */
    private $work;

    /**
     * @var \Illuminate\Redis\Connections\Connection
     */
    private $redis;

    /**
     * @var array
     */
    private $config;

    public function __construct($app, $config)
    {
        $this->config = $config;
        $this->config['global_prefix'] = $app['config']->get('database.redis.options.prefix');
        $this->redis = $app['redis']->connection($config['redis'] ?? null);
        $this->setPathPrefix($config['prefix'] ?? 'work');
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

    /**
     * @inheritDoc
     */
    public function writeStream($path, $resource, Config $config)
    {
        $result = array();
        $result['type'] = 'file';
        if (stream_is_local($resource)) {
            $stat = fstat($resource);
            $result['size'] = $stat['size'];
            $result['timestamp'] = $stat['ctime'];
        }

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
        
        $md5 = $response['media_id'];
        $this->updateObject($path, $result + compact('path', 'md5'), true);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * @inheritDoc
     */
    public function write($path, $contents, Config $config)
    {
        $stream = fopen('php://temp', 'wb+');
        fwrite($stream, $contents);
        rewind($stream);
        return $this->writeStream($path, $stream, $config);
    }

    /**
     * @inheritDoc
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * @inheritDoc
     */
    public function read($path)
    {
        $contents = $this->getFile($path)->getBody() . '';
        return compact('contents', 'path');
    }

    /**
     * @inheritDoc
     */
    public function rename($path, $newpath)
    {
        if ($object = $this->getObject($path)) {
            $this->delete($path);
            $object['path'] = $newpath;
            $this->updateObject($newpath, $object);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function copy($path, $newpath)
    {
        if ($object = $this->getObject($path)) {
            $object = array_merge($object, Util::pathinfo($newpath));
            $this->updateObject($newpath, $object);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete($path)
    {
        return $this->redis->del($this->getKey($path));
    }

    /**
     * @inheritDoc
     */
    public function has($path)
    {
        return $this->redis->exists($this->getKey($path));
    }

    /**
     * @inheritDoc
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritDoc
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($path)
    {
        return $this->getObject($path);
    }

    /**
     * @inheritDoc
     */
    public function getMimetype($path)
    {
        $result = $this->getObject($path);
        if (! $result) {
            return false;
        }
        if (isset($result['mimetype'])) {
            return $result;
        }

        $result['mimetype'] = $this->getFile($path)->getHeader('Content-Type') ?? '';
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function listContents($directory = '', $recursive = false)
    {
        $cursor = null;
        $keys = [];
        while($result = $this->redis->scan($cursor, [
            'match' =>  $this->config['global_prefix'] . $this->getKey($directory) . '*',
            'count' => 100,
        ])) {
            $cursor = $result[0];
            $keys = array_merge($keys, array_map(function($key) {
                return ltrim($key, $this->config['global_prefix']);
            }, $result[1]));
            if ($cursor == 0) break;
        }

        if ($keys) {
            return array_map(function($item) {
                return $item ? unserialize($item) : $item;
            }, $this->redis->mget($keys));
        }
        return [];
    }

    /**
     * @inheritDoc
     */
    public function createDir($dirname, Config $config)
    {
        $type = 'dir';
        $path = $dirname;
        $this->updateObject($dirname, compact('path', 'type'));
        return compact('path', 'type');
    }

    /**
     * @inheritDoc
     */
    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
    }

    /**
     * 获取上传至企微后返回的media_id
     *
     * @param string $path
     */
    public function getMediaId($path)
    {
        $result = $this->getObject($path);
        if ($result !== false) {
            return $result['md5'];
        }
        throw new FileNotFoundException($path . ' is not found');
    }

    /**
     * 获取文件内容
     *
     * @param string $path
     */
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

    /**
     * 获取当前企微对象
     */
    protected function getWork()
    {
        if ($this->work) {
            return $this->work;
        }
        return $this->work = Facade::work($this->config['work'] ?? '');
    }

    /**
     * Update the metadata for an object.
     *
     * @param string $path     object path
     * @param array  $object   object metadata
     * @param bool   $autosave whether to trigger the autosave routine
     */
    protected function updateObject($path, array $object)
    {
        $object = array_merge(Util::pathinfo($path), $object);

        return $this->redis->pipeline(function($pipe) use ($path, $object) {
            $redis = $this->redis;
            $this->redis = $pipe;
            $this->save($path, $object, true);
            // 遍历生成目录缓存
            $this->ensureParentDirectories($path);
            $this->redis = $redis;
        });
    }

    /**
     * save parent path
     *
     * @param string $path
     */
    protected function ensureParentDirectories($path)
    {
        $object = Util::pathinfo($path);

        while ($object['dirname'] !== '') {
            $object = Util::pathinfo($object['dirname']);
            $object['type'] = 'dir';
            $this->save($object['path'], $object);
        }
    }

    /**
     * Read a file.
     *
     * @param string $path
     * @return array|false
     */
    protected function getObject($path)
    {
        return unserialize($this->redis->get($this->getKey($path)));
    }

    /**
     * Store the cache.
     *
     * @param string $path
     * @param array $contents
     */
    public function save($path, $contents, $temporary = false)
    {
        if ($temporary) {
            return $this->redis->setex($this->getKey($path), $this->config['expire'] ?? 3 * 86400, serialize($contents));
        }
        return $this->redis->set($this->getKey($path), serialize($contents));
    }

    /**
     * get path cache key
     *
     * @param string $path
     */
    protected function getKey($path)
    {
        return str_replace('/', ':', $this->getPathPrefix() . Util::normalizePath($path));
    }

}
