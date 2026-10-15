<?php

namespace App\Jobs;

use App\Models\LessonVideo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessLessonVideo implements ShouldQueue
{
    use Queueable;

    /**
     * Number of automatic attempts.
     */
    public int $tries = 3;

    /**
     * Wait 60 seconds before retrying.
     */

    public int $backoff = 60;

    public function __construct(
        public int $lessonVideoId
    ) {
        //
    }

    public function handle(): void
    {
        $lessonVideo = LessonVideo::with('lesson')
            ->findOrFail($this->lessonVideoId);

        Log::info('ProcessLessonVideo started', [
            'lesson_video_id' => $lessonVideo->id,
            'source_path' => $lessonVideo->source_path,
        ]);

        $lessonVideo->update([
            'status' => 'processing',
        ]);

        $tempDirectory = storage_path(
            "app/private/video-processing/{$lessonVideo->id}"
        );

        $sourcePath = "{$tempDirectory}/source.mp4";
        $outputDirectory = "{$tempDirectory}/hls";

        try {

            if (! is_dir($tempDirectory)) {
                mkdir($tempDirectory, 0755, true);
            }

            Log::info('Downloading source video from B2', [
                'lesson_video_id' => $lessonVideo->id,
            ]);

            $sourceStream = Storage::disk('b2')->readStream(
                $lessonVideo->source_path
            );

            if ($sourceStream === false) {
                throw new \RuntimeException(
                    'Unable to read source video from B2.'
                );
            }

            $localFile = fopen($sourcePath, 'wb');

            if ($localFile === false) {
                fclose($sourceStream);

                throw new \RuntimeException(
                    'Unable to create temporary source file.'
                );
            }

            stream_copy_to_stream($sourceStream, $localFile);

            fclose($sourceStream);
            fclose($localFile);

            Log::info('Source video downloaded', [
                'lesson_video_id' => $lessonVideo->id,
                'source_path' => $sourcePath,
                'size' => filesize($sourcePath),
            ]);

            if (! is_dir($outputDirectory)) {
                mkdir($outputDirectory, 0755, true);
            }

            $videoInfo = $this->getVideoInfo($sourcePath);

            $duration = $videoInfo['duration'];
            $originalHeight = $videoInfo['height'];

            Log::info('Video information detected', [
                'lesson_video_id' => $lessonVideo->id,
                'duration_seconds' => $duration,
                'original_height' => $originalHeight,
            ]);

            $resolutions = [
                '360p' => [
                    'width' => 640,
                    'height' => 360,
                    'bandwidth' => 600000,
                ],

                '480p' => [
                    'width' => 854,
                    'height' => 480,
                    'bandwidth' => 1000000,
                ],

                '720p' => [
                    'width' => 1280,
                    'height' => 720,
                    'bandwidth' => 2500000,
                ],

                '1080p' => [
                    'width' => 1920,
                    'height' => 1080,
                    'bandwidth' => 5000000,
                ],
            ];

            $resolutions = array_filter(
                $resolutions,
                fn (array $resolution) =>
                    $resolution['height'] <= $originalHeight
            );

            Log::info('Selected video resolutions', [
                'lesson_video_id' => $lessonVideo->id,
                'resolutions' => array_keys($resolutions),
            ]);

            foreach ($resolutions as $name => $resolution) {

                Log::info("Starting {$name} processing", [
                    'lesson_video_id' => $lessonVideo->id,
                ]);

                $this->processResolution(
                    $sourcePath,
                    $outputDirectory,
                    $name,
                    $resolution
                );

                Log::info("Finished {$name} processing", [
                    'lesson_video_id' => $lessonVideo->id,
                ]);
            }

            $this->createMasterPlaylist(
                $outputDirectory,
                $resolutions
            );

            Log::info('Uploading HLS files to B2', [
                'lesson_video_id' => $lessonVideo->id,
            ]);

            $this->uploadDirectoryToB2(
                $outputDirectory,
                "lessons/{$lessonVideo->lesson_id}/hls"
            );

            $lessonVideo->update([
                'playlist_path' =>
                    "lessons/{$lessonVideo->lesson_id}/hls/master.m3u8",

                'duration_seconds' => $duration,

                'status' => 'ready',
            ]);

            $lessonVideo->lesson->update([
                'duration_seconds' => $duration,
            ]);

            Log::info('Lesson video processing completed', [
                'lesson_video_id' => $lessonVideo->id,
                'duration_seconds' => $duration,
            ]);

        } catch (Throwable $e) {

            /*
             * Do NOT set status to failed here.
             *
             * Laravel will retry the job automatically.
             * The status will become failed only after
             * all attempts are exhausted in failed().
             */

            Log::error('Lesson video processing attempt failed', [
                'lesson_video_id' => $lessonVideo->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;

        } finally {

            if (is_dir($tempDirectory)) {
                $this->deleteDirectory($tempDirectory);
            }
        }
    }

    /**
     * This method runs only after all automatic attempts fail.
     */
    public function failed(?Throwable $exception): void
    {
        $lessonVideo = LessonVideo::find($this->lessonVideoId);

        if ($lessonVideo) {
            $lessonVideo->update([
                'status' => 'failed',
            ]);
        }

        Log::error('Lesson video processing permanently failed', [
            'lesson_video_id' => $this->lessonVideoId,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function getVideoInfo(string $sourcePath): array
    {
        $process = new Process([
            config('video.ffprobe_path'),

            '-v',
            'error',

            '-show_entries',
            'format=duration',

            '-show_entries',
            'stream=height',

            '-select_streams',
            'v:0',

            '-of',
            'json',

            $sourcePath,
        ]);

        $process->setTimeout(300);

        $process->mustRun();

        $data = json_decode(
            $process->getOutput(),
            true
        );

        return [
            'duration' => (int) round(
                (float) ($data['format']['duration'] ?? 0)
            ),

            'height' => (int) (
                $data['streams'][0]['height'] ?? 0
            ),
        ];
    }

    private function processResolution(
        string $sourcePath,
        string $outputDirectory,
        string $name,
        array $resolution
    ): void {

        $directory = "{$outputDirectory}/{$name}";

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $playlist = "{$directory}/playlist.m3u8";

        $segments = "{$directory}/segment_%03d.ts";

        $process = new Process([
            config('video.ffmpeg_path'),

            '-y',

            '-i',
            $sourcePath,

            '-vf',
            "scale=-2:{$resolution['height']}",

            '-c:v',
            'libx264',

            '-preset',
            'medium',

            '-crf',
            '23',

            '-c:a',
            'aac',

            '-b:a',
            '128k',

            '-f',
            'hls',

            '-hls_time',
            '6',

            '-hls_playlist_type',
            'vod',

            '-hls_segment_filename',
            $segments,

            $playlist,
        ]);

        $process->setTimeout(3600);

        $process->mustRun();
    }

    private function createMasterPlaylist(
        string $outputDirectory,
        array $resolutions
    ): void {
        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:3\n\n";

        foreach ($resolutions as $name => $resolution) {

            $content .= "#EXT-X-STREAM-INF:";
            $content .= "BANDWIDTH={$resolution['bandwidth']},";
            $content .= "RESOLUTION={$resolution['width']}x{$resolution['height']}";
            $content .= "\n";

            $content .= "{$name}/playlist.m3u8\n\n";
        }

        file_put_contents(
            "{$outputDirectory}/master.m3u8",
            $content
        );
    }

    private function uploadDirectoryToB2(
        string $localDirectory,
        string $b2Directory
    ): void {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDirectory)
        );

        foreach ($files as $file) {

            if (! $file->isFile()) {
                continue;
            }

            $localPath = $file->getPathname();

            $relativePath = ltrim(
                str_replace(
                    $localDirectory,
                    '',
                    $localPath
                ),
                DIRECTORY_SEPARATOR
            );

            $b2Path = "{$b2Directory}/" .
                str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    $relativePath
                );

            $stream = fopen($localPath, 'rb');

            Storage::disk('b2')->put(
                $b2Path,
                $stream
            );

            fclose($stream);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {

            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($directory);
    }
}
