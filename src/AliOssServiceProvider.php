<?php

namespace Xjaqil\AliOSS;


use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use OSS\OssClient;

class AliOssServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {

        Storage::extend('oss', function ($app, $config) {
            $accessId = $config['access_id'];
            $accessKey = $config['access_key'];

            $cdnDomain = empty($config['cdnDomain']) ? '' : $config['cdnDomain'];
            $bucket = $config['bucket'];
            $ssl = empty($config['ssl']) ? false : $config['ssl'];
            $isCname = empty($config['isCName']) ? false : $config['isCName'];
            $debug = empty($config['debug']) ? false : $config['debug'];

            $endPoint = $config['endpoint']; // 默认作为外部节点
//            $epInternal= $isCname?$cdnDomain:(empty($config['endpoint_internal']) ? $endPoint : $config['endpoint_internal']); // 内部节点
            $epInternal = empty($config['endpoint_internal']) ? $endPoint : $config['endpoint_internal']; // 内部节点


            if ($debug) Log::debug('OSS config:', $config);

            $client = new OssClient($accessId, $accessKey, $epInternal);
            $adapter = new AliOssAdapter($client, $bucket, $endPoint, $ssl, $cdnDomain, '', $isCname, $debug);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });

    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
    }

}
