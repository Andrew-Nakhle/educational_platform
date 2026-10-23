<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class B2StorageService
{
    public function deleteAllB2Versions(
        string $path,
        bool $isDirectory = false
    ): void {
        $diskName = config('filesystems.upload_disk');
        $disk = Storage::disk($diskName);

        // Non-S3 disks (e.g. local_uploads) have no object versions.
        if (config("filesystems.disks.{$diskName}.driver") !== 's3') {
            $isDirectory
                ? $disk->deleteDirectory($path)
                : $disk->delete($path);

            return;
        }

        $client = $disk->getClient();
        $bucket = config("filesystems.disks.{$diskName}.bucket");

        $keyMarker = null;
        $versionIdMarker = null;

        do {
            $params = [
                'Bucket' => $bucket,
                'Prefix' => $path,
            ];

            if ($keyMarker !== null) {
                $params['KeyMarker'] = $keyMarker;
            }

            if ($versionIdMarker !== null) {
                $params['VersionIdMarker'] = $versionIdMarker;
            }

            $result = $client->listObjectVersions($params);

            $objectsToDelete = [];

            foreach ($result['Versions'] ?? [] as $version) {
                if (
                    ! $isDirectory
                    && $version['Key'] !== $path
                ) {
                    continue;
                }

                $objectsToDelete[] = [
                    'Key' => $version['Key'],
                    'VersionId' => $version['VersionId'],
                ];
            }

            foreach ($result['DeleteMarkers'] ?? [] as $marker) {
                if (
                    ! $isDirectory
                    && $marker['Key'] !== $path
                ) {
                    continue;
                }

                $objectsToDelete[] = [
                    'Key' => $marker['Key'],
                    'VersionId' => $marker['VersionId'],
                ];
            }

            if (! empty($objectsToDelete)) {
                foreach (array_chunk($objectsToDelete, 1000) as $chunk) {
                    $client->deleteObjects([
                        'Bucket' => $bucket,
                        'Delete' => [
                            'Objects' => $chunk,
                            'Quiet' => true,
                        ],
                    ]);
                }
            }

            $keyMarker = $result['NextKeyMarker'] ?? null;
            $versionIdMarker = $result['NextVersionIdMarker'] ?? null;
            $isTruncated = $result['IsTruncated'] ?? false;

        } while ($isTruncated);
    }
}
