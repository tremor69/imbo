<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace Imbo\Storage;

use Imbo\Exception\StorageException,
    Imbo\Helpers\Parameters,
    Imbo\Exception\ConfigurationException,
    Aws\S3\S3Client,
    Aws\S3\Exception\S3Exception,
    DateTime,
    DateTimeZone;

/**
 * Amazon Simple Storage Service storage adapter
 *
 * Parameters for this adapter:
 *
 * - (string) key Your AWS access key
 * - (string) secret Your AWS secret key
 * - (string) bucket The name of the bucket to store the files in. The bucket should exist prior
 *                   to using this client. Imbo will not try to automatically add the bucket for
 *                   you.
 * - (string) endpoint url use in combination with "use_path_style_endpoint"
 * - (bool)   use_path_style_endpoint flag to use endpoint and append bucket to this endpoint
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @package Storage
 */
class S3 implements StorageInterface {
    /**
     * S3 client
     *
     * @var S3Client
     */
    private $client;

    /**
     * Parameters for the driver
     *
     * @var array
     */
    private $params = [
        // Access key
        'key' => null,

        // Secret key
        'secret' => null,

        // Name of the bucket to store the files in
        'bucket' => null,

        // Set to true to send requests to an S3 path style endpoint by default. Can be enabled or disabled on individual
        // '@use_path_style_endpoint\' to true or false. Note: you cannot use it together with an accelerate endpoint.
        'use_path_style_endpoint' => null,

        // use in combination with "use_path_style_endpoint"
        'endpoint' => '',

        // Region
        'region' => null,

        // Version of API
        'version' => '2006-03-01',
    ];

    /**
     * Class constructor
     *
     * @param array $params Parameters for the adapter
     * @param S3Client $client Configured S3Client instance
     */
    public function __construct(array $params = null, S3Client $client = null) {
        if ($params !== null) {
            $this->params = array_replace_recursive($this->params, $params);
        }

        if ($client !== null) {
            $this->client = $client;
        } else {
            $missingFields = Parameters::getEmptyOrMissingParamFields(
                ['key', 'secret', 'bucket', 'region'],
                $this->params
            );

            if ($missingFields) {
                throw new ConfigurationException(
                    'Missing required configuration parameters in ' . __CLASS__ . ': ' .
                    join(', ', $missingFields)
                );
            }

            if ($this->params['use_path_style_endpoint'] && $this->params['endpoint'] === '') {
                throw new ConfigurationException(
                    'Missing required configuration parameters after enabling use_path_style_endpoint in ' . __CLASS__ . ':
                     use_path_style_endpoint is true, endpoint is empty string'
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function store($user, $imageIdentifier, $imageData) {
        try {
            $this->getClient()->putObject([
                'Bucket' => $this->getParams()['bucket'],
                'Key' => $this->getImagePath($user, $imageIdentifier),
                'Body' => $imageData,
            ]);
        } catch (S3Exception $e) {
            error_log($e);
            throw new StorageException('Could not store image , error : '. $e, 500);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($user, $imageIdentifier) {
        if (!$this->imageExists($user, $imageIdentifier)) {
            throw new StorageException('File not found', 404);
        }

        $this->getClient()->deleteObject([
            'Bucket' => $this->getParams()['bucket'],
            'Key' => $this->getImagePath($user, $imageIdentifier),
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getImage($user, $imageIdentifier) {
        try {
            $model = $this->getClient()->getObject([
                'Bucket' => $this->getParams()['bucket'],
                'Key' => $this->getImagePath($user, $imageIdentifier),
            ]);
        } catch (S3Exception $e) {
            throw new StorageException('File not found', 404);
        }

        return (string) $model->get('Body');
    }

    /**
     * {@inheritdoc}
     */
    public function getLastModified($user, $imageIdentifier) {
        try {
            $model = $this->getClient()->headObject([
                'Bucket' => $this->getParams()['bucket'],
                'Key' => $this->getImagePath($user, $imageIdentifier),
            ]);
        } catch (S3Exception $e) {
            throw new StorageException('File not found', 404);
        }

        return new DateTime($model->get('LastModified'), new DateTimeZone('UTC'));
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus() {
        try {
            $this->getClient()->headBucket([
                'Bucket' => $this->getParams()['bucket'],
            ]);
        } catch (S3Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function imageExists($user, $imageIdentifier) {
        try {
            $this->getClient()->headObject([
                'Bucket' => $this->getParams()['bucket'],
                'Key' => $this->getImagePath($user, $imageIdentifier),
            ]);
        } catch (S3Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Get the path to an image
     *
     * @param string $user The user which the image belongs to
     * @param string $imageIdentifier Image identifier
     * @return string
     */
    protected function getImagePath($user, $imageIdentifier) {
        $userPath = str_pad($user, 3, '0', STR_PAD_LEFT);
        return implode('/', [
            $userPath[0],
            $userPath[1],
            $userPath[2],
            $user,
            $imageIdentifier[0],
            $imageIdentifier[1],
            $imageIdentifier[2],
            $imageIdentifier,
        ]);
    }

    /**
     * Get the set of params provided when creating the instance
     *
     * @return array<string>
     */
    protected function getParams() {
        return $this->params;
    }

    /**
     * Get the S3Client instance
     *
     * @return S3Client
     */
    protected function getClient() {
        if ($this->client === null) {
            $params = [
                'credentials' => [
                    'key' => $this->params['key'],
                    'secret' => $this->params['secret'],
                ],
                'version' => $this->params['version'],
            ];

            if ($this->params['region']) {
                $params['region'] = $this->params['region'];
            }

            if ($this->params['use_path_style_endpoint']) {
                $params['use_path_style_endpoint'] = $this->params['use_path_style_endpoint'];
                $params['endpoint'] = $this->params['endpoint'];
            }

            $this->client = new S3Client($params);
        }

        return $this->client;
    }
}
