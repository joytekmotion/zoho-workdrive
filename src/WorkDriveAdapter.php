<?php

namespace Joytekmotion\Zoho\Workdrive;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Joytekmotion\Zoho\Oauth\Contracts\Client;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

class WorkDriveAdapter implements FilesystemAdapter
{
    protected Client $client;
    protected string $baseUrl = 'https://www.zohoapis.com/workdrive';
    protected string $downloadBaseUrl = 'https://download.zoho.com';
    const MAX_FILE_SIZE = 250 * 1024 * 1024;

    public function __construct(Client $client, string $baseUrl = null, string $downloadBaseUrl = null)
    {
        $this->client = $client;
        if ($baseUrl) {
            $this->baseUrl = $baseUrl;
        }
        if ($downloadBaseUrl) {
            $this->downloadBaseUrl = $downloadBaseUrl;
        }
    }

    protected function makeRequest(string $method, string $path, array $options = [], ?string $baseUrl = null): Response
    {
        var_dump($this->client->generateAccessToken());
        $baseUrl = $baseUrl ?? $this->baseUrl;
        $accessToken = $this->client->generateAccessToken();
        return Http::withToken($accessToken)->accept('application/vnd.api+json')
            ->$method($baseUrl . $path, $options);
    }

    protected function makeMultiPartRequest(string $path, array $options = []): PromiseInterface|Response
    {
        $accessToken = $this->client->generateAccessToken();
        $response = Http::withToken($accessToken)->accept('application/vnd.api+json')
            ->attach('content', $options['content'], $options['filename'])
            ->attach('parent_id', $options['parent_id'])
            ->attach('filename', $options['filename']);
        if (isset($options['override-name-exist'])) {
            $response->attach('override-name-exist', $options['override-name-exist']);
        }
        return $response->post($this->baseUrl . $path);
    }

    protected function requestFileInfo(string $resourceId): Response
    {
        return $this->makeRequest('get', '/api/v1/files/' . $resourceId);
    }

    protected function getFileAttributes(string $resourceId): array {

        $response = $this->requestFileInfo($resourceId);
        if ($response->status() !== 200) {
            throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
        }
        return $response->json()['data']['attributes'];
    }

    public function fileExists(string $path): bool
    {
        $response = $this->requestFileInfo($path);

        if ($response->status() === 200) {
            return true;
        }
        return false;
    }

    public function directoryExists(string $path): bool
    {
        return $this->fileExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeData($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->writeData($path, $contents, $config);
    }

    protected function splitPath(string $path): array
    {
        if (count(explode('/', $path)) > 1) {
            $filename = basename($path);
            $parentId = dirname($path);
        } else {
            $filename = uniqid();
            $parentId = $path;
        }
        return [$filename, $parentId];
    }

    protected function writeData(string $path, $contents, Config $config): void
    {
        $stream = Utils::streamFor($contents);
        [$filename, $parentId] = $this->splitPath($path);
        if ($stream->getSize() > self::MAX_FILE_SIZE) {
            throw new UnableToWriteFile('File size is greater than 250MB');
        }
        $options = [
            'content' => $stream,
            'parent_id' => $parentId,
            'filename' => $config->get('filename') ?? $filename
        ];
        if ($config->get('override-name-exist') !== null) {
            $options['override-name-exist'] = $config->get('override-name-exist') ? 'true' : 'false';
        }
        $response = $this->makeMultiPartRequest('/api/v1/upload', $options);
        if ($response->status() !== 200) {
            throw new UnableToWriteFile($this->getErrorMessage($response->json(), $response->status()));
        }
    }

    protected function getErrorMessage(array $response, int $statusCode): string
    {
        if ($statusCode == 409) {
            return 'File already exists';
        }
        if (isset($response['errors'][0]['id']) && isset($response['errors'][0]['title'])) {
            $errorCode = $response['errors'][0]['id'];
            $message = $response['errors'][0]['title'];
            return $errorCode . ': ' . $message;
        }
        return 'Unknown error';
    }

    public function read(string $path): string
    {
        $response = $this->makeRequest('get', '/v1/workdrive/download/' . $path, [], $this->downloadBaseUrl);
        if ($response->status() !== 200) {
            throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
        }
        return $response->body();
    }

    public function readStream(string $path)
    {
        return Utils::streamFor($this->read($path));
    }

    public function delete(string $path): void
    {
        $response = $this->makeRequest('patch', '/api/v1/files/' . $path, [
            'data' => [
                'attributes' => [
                    'status' => '51' // 51 - Trash
                ],
                'type' => 'files'
            ]
        ]);
        if ($response->status() !== 200) {
            throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
        }
    }

    public function deleteDirectory(string $path): void
    {
        $this->delete($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        [$directoryName, $parentId] = $this->splitPath($path);
        $response = $this->makeRequest('post', '/api/v1/files', [
            'data' => [
                'attributes' => [
                    'name' => $directoryName,
                    'parent_id' => $parentId
                ],
                'type' => 'files'
            ]
        ]);
        if ($response->status() !== 201) {
            throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $sharedType = $visibility === Visibility::PUBLIC ? 'publish' : 'personal';
        if ($visibility === Visibility::PRIVATE) {
            // Retrieve all permissions and delete them
            $response = $this->makeRequest('get', '/api/v1/files/' . $path . '/permissions');
            $permissions = $response->json()['data'];
            foreach ($permissions as $permission) {
                $response = $this->makeRequest('delete', '/api/v1/permissions/' . $permission['id']);
            }
            if ($response->status() !== 200 && $response->status() !== 204) {
                throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
            }
        } else if ($visibility === Visibility::PUBLIC) {
            $response = $this->makeRequest('post', '/api/v1/permissions', [
                'data' => [
                    'attributes' => [
                        'resource_id' => $path,
                        'shared_type' => $sharedType,
                        'role_id' => 34 // 34 - View
                    ],
                    'type' => 'permissions'
                ]
            ]);
            if ($response->status() !== 201) {
                throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
            }
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $attributes = $this->getFileAttributes($path);
        $visibility = $attributes['is_published'] ? Visibility::PUBLIC : Visibility::PRIVATE;
        return new FileAttributes(
            $path,
            null,
            $visibility
        );
    }

    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->getFileAttributes($path);
        $mimeTypeDetector = new FinfoMimeTypeDetector();
        $mimeType = $mimeTypeDetector->detectMimeTypeFromPath($attributes['name']) ?? $attributes['type'];
        return new FileAttributes(
            $path,
            null,
            null,
            null,
            $mimeType
        );
    }

    public function lastModified(string $path): FileAttributes
    {
        $attributes = $this->getFileAttributes($path);
        $lastModified = $attributes['modified_time_in_millisecond'];
        return new FileAttributes(
            $path,
            null,
            null,
            $lastModified
        );
    }

    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->getFileAttributes($path);
        $size = $attributes['storage_info']['size_in_bytes'];
        return new FileAttributes(
            $path,
            $size
        );
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $response = $this->makeRequest('get', '/api/v1/files/' . $path . '/files');
        if ($response->status() !== 200) {
            throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
        }
        $contents = $response->json()['data'];
        foreach ($contents as $content) {
            $isFolder = $content['attributes']['is_folder'];
            $visibility = $content['attributes']['is_published'] ? Visibility::PUBLIC : Visibility::PRIVATE;
            $lastModified = $content['attributes']['modified_time_in_millisecond'];
            $size = $content['attributes']['storage_info']['size_in_bytes'];
            $mimeTypeDetector = new FinfoMimeTypeDetector();
            $mimeType = $mimeTypeDetector->detectMimeTypeFromPath($content['attributes']['name'])
                ?? $content['attributes']['type'];
            yield $isFolder ? new DirectoryAttributes($content['id'], $visibility, $lastModified, $content['attributes']) :
                new FileAttributes($content['id'], $size, $visibility, $lastModified, $mimeType, $content['attributes']);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $response = $this->makeRequest('patch', '/api/v1/files/' . $source, [
            'data' => [
                'attributes' => [
                    'parent_id' => $destination
                ],
                'type' => 'files'
            ]
        ]);
        if ($response->status() !== 200) {
            throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $response = $this->makeRequest('post', '/api/v1/files/' . $destination . '/copy', [
            'data' => [
                'attributes' => [
                    'resource_id' => $source
                ],
                'type' => 'files'
            ]
        ]);
        if ($response->status() !== 201) {
            throw new UnableToReadFile($this->getErrorMessage($response->json(), $response->status()));
        }
    }
}
