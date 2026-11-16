<?php

namespace Ekumanov\ClaudeReply\Reply;

use Ekumanov\ClaudeReply\Anthropic\ClaudeClient;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Brings the files the code-execution sandbox made onto this forum, and
 * renders them as the attachment block at the end of the reply.
 *
 * The sandbox's own copies live in Anthropic's Files API, which needs the API
 * key to read — so they cannot simply be linked. Each one is downloaded and
 * written to a public disk under the forum's assets directory.
 *
 * What gets published is narrowed three ways, because the sandbox can write
 * anything and this publishes it under the forum's own origin:
 *
 *   - **Extension allow-list.** Nothing that a browser would execute or
 *     render as a document: no .html, no .svg (script inside an image), no
 *     .js. The stored name is rebuilt from a slug and the allow-listed
 *     extension, so what the model named the file cannot reach the path.
 *   - **Size and count caps.**
 *   - **Last version wins.** The model is told to deliver once, but if it
 *     delivers a file and then a corrected one under the same name, only the
 *     correction is published.
 *
 * Never throws: a file that cannot be fetched or stored is dropped and
 * logged, and the text reply is still posted.
 */
final class GeneratedFiles
{
    public const DISK = 'ekumanov-claude-reply-files';

    /** @var list<string> */
    public const EXTENSIONS = ['mid', 'midi', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf', 'csv', 'txt', 'mp3', 'wav', 'ogg', 'flac'];

    private const IMAGES = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public const MAX_FILES = 4;

    public const MAX_BYTES = 8 * 1024 * 1024;

    /** A server-rendered MP3 of a long piece runs larger than anything the sandbox should send. */
    private const MAX_AUDIO_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly MidiRenderer $renderer,
        private readonly Factory $filesystem,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Download, vet and store the turn's files.
     *
     * @param list<string> $fileIds in the order the sandbox produced them
     * @return list<array{name: string, url: string, image: bool, size: int}>
     */
    public function publish(array $fileIds, int $logId): array
    {
        if ($fileIds === []) {
            return [];
        }

        /** @var array<string, array{name: string, bytes: string, ext: string}> keyed by lower-cased name, so a later version replaces an earlier one */
        $byName = [];

        foreach ($fileIds as $id) {
            try {
                $file = $this->claude->downloadFile($id);
            } catch (Throwable $e) {
                $this->log->warning('claude-reply: could not download a generated file', [
                    'log_id' => $logId,
                    'file_id' => $id,
                    'err' => $e->getMessage(),
                ]);

                continue;
            }

            $name = basename(str_replace('\\', '/', $file['name']));
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (! in_array($ext, self::EXTENSIONS, true)) {
                $this->log->info('claude-reply: dropped a generated file of a type not published', [
                    'log_id' => $logId,
                    'name' => $name,
                ]);

                continue;
            }

            if (strlen($file['bytes']) === 0 || strlen($file['bytes']) > self::MAX_BYTES) {
                $this->log->warning('claude-reply: dropped a generated file outside the size limits', [
                    'log_id' => $logId,
                    'name' => $name,
                    'bytes' => strlen($file['bytes']),
                ]);

                continue;
            }

            $key = strtolower($name);
            unset($byName[$key]); // re-insert, so order follows the latest version
            $byName[$key] = ['name' => $name, 'bytes' => $file['bytes'], 'ext' => $ext];
        }

        $disk = $this->disk();

        if ($disk === null) {
            return [];
        }

        $published = [];

        foreach (array_slice(array_values($byName), -self::MAX_FILES) as $i => $file) {
            $slug = $this->slug(pathinfo($file['name'], PATHINFO_FILENAME));
            $stem = sprintf('%d-%d-%s-%s', $logId, $i + 1, bin2hex(random_bytes(4)), $slug);

            // A MIDI file is only music to a reader who owns a synthesizer.
            // Where the server can render it, the MP3 goes first — it is the
            // one most people will play — and the MIDI follows it.
            if (in_array($file['ext'], ['mid', 'midi'], true)) {
                $mp3 = $this->renderer->toMp3($file['bytes']);

                if ($mp3 !== null && strlen($mp3) <= self::MAX_AUDIO_BYTES) {
                    $this->store($disk, $stem.'.mp3', $slug.'.mp3', $mp3, false, $logId, $published);
                }
            }

            $this->store($disk, $stem.'.'.$file['ext'], $slug.'.'.$file['ext'], $file['bytes'], in_array($file['ext'], self::IMAGES, true), $logId, $published);
        }

        return $published;
    }

    /**
     * @param list<array{name: string, url: string, image: bool, size: int}> $published (appended to)
     */
    private function store(Cloud $disk, string $path, string $name, string $bytes, bool $image, int $logId, array &$published): void
    {
        try {
            $disk->put($path, $bytes);

            $published[] = [
                'name' => $name,
                'url' => $disk->url($path),
                'image' => $image,
                'size' => strlen($bytes),
            ];
        } catch (Throwable $e) {
            $this->log->error('claude-reply: could not store a generated file', [
                'log_id' => $logId,
                'path' => $path,
                'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The Markdown appended to the reply for these files.
     *
     * @param list<array{name: string, url: string, image: bool, size: int}> $files
     */
    public static function markdown(array $files): string
    {
        $lines = [];

        foreach ($files as $file) {
            $label = str_replace(['[', ']'], '', $file['name']);

            $lines[] = $file['image']
                ? '!['.$label.']('.$file['url'].')'
                : '📎 ['.$label.']('.$file['url'].') ('.self::humanSize($file['size']).')';
        }

        return implode("\n\n", $lines);
    }

    private static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }

    private function slug(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        return substr($slug !== '' ? $slug : 'file', 0, 60);
    }

    private function disk(): ?Cloud
    {
        try {
            $disk = $this->filesystem->disk(self::DISK);

            return $disk instanceof Cloud ? $disk : null;
        } catch (Throwable $e) {
            $this->log->error('claude-reply: generated-files disk unavailable', ['err' => $e->getMessage()]);

            return null;
        }
    }
}
