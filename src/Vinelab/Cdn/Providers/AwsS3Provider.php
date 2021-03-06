<?php

namespace Vinelab\Cdn\Providers;

use Aws\S3\BatchDelete;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\Console\Output\ConsoleOutput;
use Vinelab\Cdn\Contracts\CdnHelperInterface;
use Vinelab\Cdn\Providers\Contracts\ProviderInterface;
use Vinelab\Cdn\Validators\Contracts\ProviderValidatorInterface;

/**
 * Class AwsS3Provider
 * Amazon (AWS) S3.
 *
 *
 * @category Driver
 *
 * @property string  $provider_url
 * @property string  $threshold
 * @property string  $version
 * @property string  $region
 * @property string  $credential_key
 * @property string  $credential_secret
 * @property string  $buckets
 * @property string  $acl
 * @property string  $cloudfront
 * @property string  $cloudfront_url
 *
 * @author   Mahmoud Zalt <mahmoud@vinelab.com>
 */
class AwsS3Provider extends Provider implements ProviderInterface
{
    /**
     * All the configurations needed by this class with the
     * optional configurations default values.
     *
     * @var array
     */
    protected $default = [
        'url' => null,
        'threshold' => 10,
        'providers' => [
            'aws' => [
                's3' => [
                    'version' => null,
                    'region' => null,
                    'buckets' => null,
                    'acl' => 'public-read',
                    'cloudfront' => [
                        'use' => false,
                        'cdn_url' => null,
                    ],
                ],
            ],
        ],
    ];

    /**
     * Required configurations (must exist in the config file).
     *
     * @var array
     */
    protected $rules = ['version', 'region', 'key', 'secret', 'buckets', 'url'];

    /**
     * this array holds the parsed configuration to be used across the class.
     *
     * @var Array
     */
    protected $supplier;

    /**
     * @var Instance of Aws\S3\S3Client
     */
    protected $s3_client;

    /**
     * @var Instance of Guzzle\Batch\BatchBuilder
     */
    protected $batch;

    /**
     * @var \Vinelab\Cdn\Contracts\CdnHelperInterface
     */
    protected $cdn_helper;

    /**
     * @var \Vinelab\Cdn\Validators\Contracts\ConfigurationsInterface
     */
    protected $configurations;

    /**
     * @var \Vinelab\Cdn\Validators\Contracts\ProviderValidatorInterface
     */
    protected $provider_validator;

    /**
     * @param \Symfony\Component\Console\Output\ConsoleOutput              $console
     * @param \Vinelab\Cdn\Validators\Contracts\ProviderValidatorInterface $provider_validator
     * @param \Vinelab\Cdn\Contracts\CdnHelperInterface                    $cdn_helper
     */
    public function __construct(
        ConsoleOutput $console,
        ProviderValidatorInterface $provider_validator,
        CdnHelperInterface $cdn_helper
    ) {
        $this->console = $console;
        $this->provider_validator = $provider_validator;
        $this->cdn_helper = $cdn_helper;
    }

    /**
     * Read the configuration and prepare an array with the relevant configurations
     * for the (AWS S3) provider. and return itself.
     *
     * @param $configurations
     *
     * @return $this
     */
    public function init($configurations)
    {
        // merge the received config array with the default configurations array to
        // fill missed keys with null or default values.
        $this->default = array_merge($this->default, $configurations);

        $supplier = [
            'provider_url' => $this->default['url'],
            'threshold' => $this->default['threshold'],
            'version' => $this->default['providers']['aws']['s3']['version'],
            'region' => $this->default['providers']['aws']['s3']['region'],
            'buckets' => $this->default['providers']['aws']['s3']['buckets'],
            'acl' => $this->default['providers']['aws']['s3']['acl'],
            'cloudfront' => $this->default['providers']['aws']['s3']['cloudfront']['use'],
            'cloudfront_url' => $this->default['providers']['aws']['s3']['cloudfront']['cdn_url'],
        ];

        // check if any required configuration is missed
        $this->provider_validator->validate($supplier, $this->rules);

        $this->supplier = $supplier;

        return $this;
    }

    /**
     * Create an S3 client instance
     * (Note: it will read the credentials form the .env file).
     *
     * @return bool
     */
    public function connect()
    {
        try {
            // Instantiate an S3 client
            $this->setS3Client(new S3Client([
                        'version' => $this->supplier['version'],
                        'region' => $this->supplier['region'],
                    ]
                )
            );
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Upload assets.
     *
     * @param $assets
     *
     * @return bool
     */
    public function upload($assets, $compress=true)
    {
        // connect before uploading
        $connected = $this->connect();

        if (!$connected) {
            return false;
        }

        // user terminal message
        $this->console->writeln('<fg=yellow>Uploading in progress...</fg=yellow>');

        // upload each asset file to the CDN
        foreach ($assets as $file) {

            try {

                $this->batch->add($this->s3_client->getCommand('PutObject', [

                    'Bucket' => $this->getBucket(),
                    // the bucket name
                    'Key'    => str_replace('\\', '/', $file->getPathName()),
                    // the path of the file on the server (CDN)
                    'Body'   => fopen($file->getRealPath(), 'r'),
                    // the path of the path locally
                    'ACL'    => $this->acl,
                    // the permission of the file

                    'CacheControl' => $this->default['providers']['aws']['s3']['cache-control'],
                    'MetaData' => $this->default['providers']['aws']['s3']['metadata'],
                    "Expires" => $this->default['providers']['aws']['s3']['expires']

                ]));
                
                // upload gzip file version to js/css scripts
                if ($compress && (ends_with($file->getPathName(), '.css') || ends_with($file->getPathName(), '.js'))) {                    

                    $filename = preg_replace('/\.(css|js)/', '.gzip', $file->getPathName());
                    file_put_contents($filename, gzencode(file_get_contents($file->getRealPath()), 9));                       

                    $this->batch->add($this->s3_client->getCommand('PutObject', [
                       'Bucket'            => $this->getBucket(),
                        // the bucket name
                        'Key'               => str_replace('\\', '/', $filename),
                        // the path of the file on the server (CDN)
                        'Body'              => fopen($filename, 'r'),
                        // the path of the path locally
                        'ACL'               => $this->acl,
                        // extra headers for compressed files
                        'ContentType'       => 'binary/octet-stream',
                        'Content-Encoding'  => 'gzip',
                        // the permission of the file
                        'CacheControl'      => $this->default['providers']['aws']['s3']['cache-control'],
                        'MetaData'          => $this->default['providers']['aws']['s3']['metadata'],
                        "Expires"           => $this->default['providers']['aws']['s3']['expires']

                    ]));                    
                }


            } catch(S3Exception $e) {
                $this->console->writeln("<fg=red>Error while uploading: ($file->getRealpath())</fg=red>");

                return false;
            }
        }

        // user terminal message
        $this->console->writeln('<fg=green>Upload completed successfully.</fg=green>');

        return true;
    }

    /**
     * Empty bucket.
     *
     * @return bool
     */
    public function emptyBucket()
    {

        // connect before uploading
        $connected = $this->connect();

        if (!$connected) {
            return false;
        }

        // user terminal message
        $this->console->writeln('<fg=yellow>Emptying in progress...</fg=yellow>');

        try {

            // Get the contents of the bucket for information purposes
            $contents = $this->s3_client->listObjects([
                'Bucket' => $this->getBucket(),
                'Key' => '',
            ]);

            // Check if the bucket is already empty
            if (!$contents['Contents']) {
                $this->console->writeln('<fg=green>The bucket '.$this->getBucket().' is already empty.</fg=green>');

                return true;
            }

            // Empty out the bucket
            $empty = BatchDelete::fromListObjects($this->s3_client, [
                'Bucket' => $this->getBucket(),
                'Prefix' => null,
            ]);

            $empty->delete();
        } catch (S3Exception $e) {
            $this->console->writeln('<fg=red>'.$e->getMessage().'</fg=red>');

            return false;
        }

        $this->console->writeln('<fg=green>The bucket '.$this->getBucket().' is now empty.</fg=green>');

        return true;
    }

    /**
     * This function will be called from the CdnFacade class when
     * someone use this {{ Cdn::asset('') }} facade helper.
     *
     * @param $path
     *
     * @return string
     */
    public function urlGenerator($path)
    {
        if ($this->getCloudFront() === true) {
            $url = $this->cdn_helper->parseUrl($this->getCloudFrontUrl());

            return $url['scheme'].'://'.$url['host'].'/'.$path;
        }

        $url = $this->cdn_helper->parseUrl($this->getUrl());

        $bucket = $this->getBucket();
        $bucket = (!empty($bucket)) ? $bucket.'.' : '';

        return $url['scheme'].'://'.$bucket.$url['host'].'/'.$path;
    }

    /**
     * @param $s3_client
     */
    public function setS3Client($s3_client)
    {
        $this->s3_client = $s3_client;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return rtrim($this->provider_url, '/').'/';
    }

    /**
     * @return string
     */
    public function getCloudFront()
    {
        if (!is_bool($cloudfront = $this->cloudfront)) {
            return false;
        }

        return $cloudfront;
    }

    /**
     * @return string
     */
    public function getCloudFrontUrl()
    {
        return rtrim($this->cloudfront_url, '/').'/';
    }

    /**
     * @return array
     */
    public function getBucket()
    {
        // this step is very important, "always assign returned array from
        // magical function to a local variable if you need to modify it's
        // state or apply any php function on it." because the returned is
        // a copy of the original variable. this prevent this error:
        // Indirect modification of overloaded property
        // Vinelab\Cdn\Providers\AwsS3Provider::$buckets has no effect
        $bucket = $this->buckets;

        return rtrim(key($bucket), '/');
    }

    /**
     * @param $attr
     *
     * @return Mix | null
     */
    public function __get($attr)
    {
        return isset($this->supplier[$attr]) ? $this->supplier[$attr] : null;
    }
}
