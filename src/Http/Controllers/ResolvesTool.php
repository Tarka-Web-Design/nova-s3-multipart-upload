<?php

namespace Ahmedkandel\NovaS3MultipartUpload\Http\Controllers;

use Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload;
use Illuminate\Support\Str;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\ResourceToolElement;

trait ResolvesTool
{
    /**
     * Find the resource tool the request is for.
     *
     * Nova lists a resource tool's element among the resource's fields, and
     * the element points back at the tool that made it: `panel` on Nova 5
     * (`assignedPanel` on Nova 4).
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return \Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload
     */
    protected function resolveTool(NovaRequest $request, $resource = null)
    {
        $resource = $resource ?? $request->findResourceOrFail();

        $tool = $resource->availableFields($request)
            ->map(
                fn ($field) => $field instanceof ResourceToolElement
                    ? ($field->panel ?? $field->assignedPanel ?? null)
                    : $field
            )
            ->whereInstanceOf(NovaS3MultipartUpload::class)
            ->firstWhere('attribute', $request->route('field'));

        abort_unless($tool, 404);

        return $tool;
    }

    /**
     * The disk's root folder, if it has one, ending in a slash.
     *
     * Keys are stored relative to the disk, as Laravel's Storage reads them,
     * so a disk with a root (`'root' => 'staging'`) keeps its files under
     * that folder. The S3 client talks to the bucket directly and has to be
     * given the full key.
     *
     * @param  \Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload  $tool
     * @return string
     */
    protected function diskRoot($tool)
    {
        $root = trim((string) config("filesystems.disks.{$tool->disk}.root"), '/');

        return $root === '' ? '' : $root.'/';
    }

    /**
     * The full key in the bucket for a key relative to the disk.
     *
     * @param  \Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload  $tool
     * @param  string  $key
     * @return string
     */
    protected function bucketKey($tool, $key)
    {
        return $this->diskRoot($tool).ltrim((string) $key, '/');
    }

    /**
     * The key relative to the disk for a full key in the bucket.
     *
     * @param  \Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload  $tool
     * @param  string  $key
     * @return string
     */
    protected function diskKey($tool, $key)
    {
        $root = $this->diskRoot($tool);

        return $root !== '' && Str::startsWith($key, $root) ? Str::after($key, $root) : $key;
    }
}
