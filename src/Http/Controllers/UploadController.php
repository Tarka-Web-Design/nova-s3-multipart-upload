<?php

namespace Ahmedkandel\NovaS3MultipartUpload\Http\Controllers;

use Illuminate\Support\Str;
use Laravel\Nova\Http\Requests\NovaRequest;

class UploadController
{
    use ResolvesTool;

    /**
     * Resource tool instance.
     *
     * @var \Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload
     */
    private $tool;

    /**
     * S3 client instance.
     *
     * @var \Aws\S3\S3ClientInterface
     */
    private $s3Client;

    /**
     * Set preflight response header to allow X-CSRF-TOKEN in Uppy request headers.
     *
     * @return void
     */
    public function preflightHeader()
    {
        header('Access-Control-Allow-Headers: Authorization, Origin, Content-Type, Accept, X-CSRF-TOKEN');
    }

    /**
     * Retrieve resource tool and Authorize then create S3Client..
     *
     * @param \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return void
     */
    protected function init($request)
    {
        $this->tool = $this->resolveTool($request);

        abort_unless($this->tool->element->authorizedToSee($request), 403);

        abort_unless($this->tool->canUpload, 403);

        $this->s3Client = app()->makeWith('novas3client', ['disk' => $this->tool->disk]);
    }

    /**
     * Generate file storage key, relative to the disk.
     *
     * @param string $fileName
     * @return string
     */
    private function generateFileKey($fileName)
    {
        $path = $this->tool->storagePath ? $this->tool->storagePath . '/' : '';
        $name = $this->tool->keepOriginalName ? pathinfo($fileName, PATHINFO_FILENAME) : (string) Str::uuid();
        $extension = preg_replace('/[^A-Za-z0-9]/', '', (string) pathinfo($fileName, PATHINFO_EXTENSION));

        return $path . $name . ($extension ? '.' . $extension : '');
    }

    /**
     * The key in the bucket of an upload already under way. Uppy hands back
     * the key it was given when the upload was created, which is relative to
     * the disk and inside the tool's storage path.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return string
     */
    private function uploadKey($request)
    {
        $key = (string) $request->query('key');

        abort_if($key === '' || Str::contains($key, '..'), 422);

        if ($this->tool->storagePath) {
            abort_unless(Str::startsWith($key, $this->tool->storagePath . '/'), 403);
        }

        return $this->bucketKey($this->tool, $key);
    }

    /**
     * Create an S3 multipart upload. With this, files can be uploaded in chunks of 5MB+ each.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function createMultipartUpload(NovaRequest $request)
    {
        $this->init($request);

        $key = $this->generateFileKey((string) $request->input('filename'));

        // The file's own details stay with the model rather than travelling
        // as x-amz-meta headers, which S3 only takes in plain ASCII: a file
        // called "Café.mp4" would have failed to start uploading.
        $data = $this->s3Client->createMultipartUpload([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $this->bucketKey($this->tool, $key),
            'ContentType' => $request->input('type') ?: 'application/octet-stream',
        ]);

        return [
            'key' => $key,
            'uploadId' => $data['UploadId'],
        ];
    }

    /**
     * List parts that have been fully uploaded so far.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function getUploadedParts(NovaRequest $request)
    {
        $this->init($request);

        $data = $this->s3Client->listParts([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $this->uploadKey($request),
            'UploadId' => $request->route('uploadId'),
            'PartNumberMarker' => 0,
        ]);

        return $data['Parts'] ?? [];
    }

    /**
     * Get presigned url for specific part.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @param  int  $partNumber
     * @return string
     */
    public function getSignedUrl($request, $partNumber)
    {
        $command = $this->s3Client->getCommand('UploadPart', [
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $this->uploadKey($request),
            'UploadId' => $request->route('uploadId'),
            'PartNumber' => $partNumber,
        ]);
        $s3Request = $this->s3Client->createPresignedRequest($command, '+20 minutes');

        return (string) $s3Request->getUri();
    }

    /**
     * Get parameters for uploading a batch of parts.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function batchSignPartsUpload(NovaRequest $request)
    {
        $this->init($request);

        $partNumbers = explode(',', $request->query('partNumbers'));

        $data = [];

        foreach ($partNumbers as $partNumber) {
            $data[(string) $partNumber] = $this->getSignedUrl($request, $partNumber);
        }

        return [
            'presignedUrls' => $data,
        ];
    }

    /**
     * Get parameters for uploading one part.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function signPartUpload(NovaRequest $request)
    {
        $this->init($request);

        $url = $this->getSignedUrl($request, $request->route('partNumber'));

        return [
            'url' => $url,
        ];
    }

    /**
     * Complete a multipart upload, combining all the parts into a single object in the S3 bucket.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function completeMultipartUpload(NovaRequest $request)
    {
        $this->init($request);

        $data = $this->s3Client->completeMultipartUpload([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $this->uploadKey($request),
            'UploadId' => $request->route('uploadId'),
            'MultipartUpload' => [
                'Parts' => $request->input('parts'),
            ],
        ]);

        return [
            'location' => $data['Location'],
        ];
    }

    /**
     * Abort a multipart upload, deleting already uploaded parts.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function abortMultipartUpload(NovaRequest $request)
    {
        $this->init($request);

        $this->s3Client->abortMultipartUpload([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $this->uploadKey($request),
            'UploadId' => $request->route('uploadId'),
        ]);

        return [];
    }
}
