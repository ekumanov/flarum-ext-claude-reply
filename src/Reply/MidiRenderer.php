<?php

namespace Ekumanov\ClaudeReply\Reply;

use Ekumanov\ClaudeReply\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Renders a MIDI file to MP3 on this server, so readers can listen to a
 * generated piece without a synthesizer of their own.
 *
 * Local rather than an online converter on purpose: no account, no per-file
 * cost, and the file never leaves the box. It needs two packages and a
 * soundfont — `fluidsynth` and `lame`, and an .sf2 file whose program 0 is a
 * piano (on Debian/Ubuntu, `fluid-soundfont-gm` installs FluidR3_GM, which
 * is the default path below). When any of the three is missing the renderer
 * reports itself unavailable and the MIDI is published on its own.
 *
 * Both tools are given fixed arguments and files this process wrote itself;
 * nothing the model produced reaches a command line except as file contents.
 */
final class MidiRenderer
{
    /** Hard wall-clock cap per step. FluidSynth renders several times faster than real time. */
    private const TIMEOUT_SECONDS = 180;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $log,
    ) {}

    public function available(): bool
    {
        return $this->settings->renderMidiAudio()
            && $this->binary('fluidsynth') !== null
            && $this->binary('lame') !== null
            && is_readable($this->settings->soundfontPath());
    }

    /**
     * @return string|null the MP3 bytes, or null if rendering failed
     */
    public function toMp3(string $midiBytes): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $dir = sys_get_temp_dir().'/claude-reply-'.bin2hex(random_bytes(6));

        if (! @mkdir($dir, 0700)) {
            return null;
        }

        $mid = "$dir/in.mid";
        $wav = "$dir/out.wav";
        $mp3 = "$dir/out.mp3";

        try {
            file_put_contents($mid, $midiBytes);

            $this->run([
                $this->binary('fluidsynth'),
                '-ni',                 // no shell, no MIDI input driver
                '-q',
                '-g', '0.6',           // gain: FluidSynth's default 0.2 is very quiet, 1+ clips on dense chords
                '-r', '44100',
                '-F', $wav,
                $this->settings->soundfontPath(),
                $mid,
            ]);

            $this->run([
                $this->binary('lame'),
                '--quiet',
                '-V', '5',             // ~130 kbps VBR: about 1 MB a minute, transparent enough for solo piano
                '--tt', 'Claude',
                $wav,
                $mp3,
            ]);

            $bytes = @file_get_contents($mp3);

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        } catch (Throwable $e) {
            $this->log->warning('claude-reply: MIDI render failed', ['err' => $e->getMessage()]);

            return null;
        } finally {
            foreach ([$mid, $wav, $mp3] as $f) {
                @unlink($f);
            }

            @rmdir($dir);
        }
    }

    /** @param list<string|null> $command */
    private function run(array $command): void
    {
        $process = new Process($command, null, null, null, self::TIMEOUT_SECONDS);
        $process->mustRun();
    }

    private function binary(string $name): ?string
    {
        static $found = [];

        return $found[$name] ??= (new ExecutableFinder())->find($name, null, ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']);
    }
}
