<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class B2StorageService
{
    public function deleteAllB2Versions(
        string $path,
        bool $isDirectory = false
    ): void {
        $disk = Storage::disk('b2');
        $client = $disk->getClient();
        $bucket = config('filesystems.disks.b2.bucket');

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
