<!-- Generated from https://api.vocalstack.com/docs/guides/transcribe-a-file.md; edits here are overwritten. -->

# Transcribe a file

> Upload a file, wait for its job, read the transcript and export it. Asynchronous; nothing to keep open.

| Endpoint | What it does |
|---|---|
| `POST /v1/transcriptions` | Create a transcription |
| `GET /v1/recordings/{id}/job` | Get a job |
| `GET /v1/recordings/{id}/transcript` | Get a transcript |
| `GET /v1/recordings/{id}/export` | Export a recording |

A file transcription is stored: it is listed under Transcriptions, can be shared and exported, and its transcript can be read any time later. The work is asynchronous. The upload answers at once; the server transcribes at full speed (much faster than the audio plays) while you wait on its job: upload, then read the job until it is `done`, then read the transcript.

To transcribe without storing anything, stream the file through a realtime session instead ([Stream a file at full speed](stream-a-file.md)).

## 1. Upload the file

`POST /v1/transcriptions` takes the file as `multipart/form-data` (`file`, plus an optional `options` part, JSON) and answers `201` with the transcription. Its `job.recordingId` names the recording the file became: everything that follows is addressed by it.

`settings` are optional: `spokenLanguage` (a code such as `en`; absent, the language is detected), `speakerLabels` (`off` by default, `auto` or `on`). The extras are off unless asked for: `summary` and `title` (the language model's summary and title, read with `GET /v1/recordings/{id}/summary`), `summaryTranslations`, and `voice` (spoken translations); `shareLink: true` beside `settings` makes a public share link to the transcript. A recording under a minute never gets speaker labels, a summary, a title or voices. Many files at once cost less as a batch ([Transcribe a batch of files](transcribe-a-batch.md)). Most audio and video files (mp3, wav, flac, ogg, m4a, mp4…) up to the size in `GET /v1/realtime/capabilities` (`limits.maxUploadBytes`) are accepted; a video keeps only its audio.

Send an `Idempotency-Key` with the upload. If the API answers `503 unavailable` while it wakes, the SDKs retry a create only when it carries one, and a retry with the same key returns the first answer instead of a second transcription.

## 2. Wait for the job

A job moves `queued` → `transcribing` → `done`, or `failed` with `error` and, when it is machine-readable, `errorCode` (`insufficient_credit`: the file costs more than the account's credit). A link job passes through `fetching` first ([Transcribe a link](transcribe-a-link.md)).

There are no webhooks. Read `GET /v1/recordings/{id}/job` until the state is `done` or `failed`: `vs.jobs.wait` does it every 2 s, and hands each read to `onProgress` (`on_progress` in Python) with `progress.pct` (0 to 100) and `progress.processedS`, the media seconds done so far; the program prints a line only when the state or the percentage changes. A page that is open anyway can follow the transcription's events socket instead (`WS /v1/transcriptions/{id}/events`, a `job.progress` frame per step).

## 3. Read the transcript

`GET /v1/recordings/{id}/transcript` answers with the whole transcript: `sentences` (what to show people, with `speaker`, `start` and `end` in seconds), `segments` (with word timings in `words`) and `speakers`. Speakers are numbered from 0; `-1` means speaker labels were off. Add `lang=es` to read it translated.

## 4. Export it

`GET /v1/recordings/{id}/export` returns a file: `format=srt`, `json`, `xlsx` or `pdf`, with `lang=` for a translated one. In the SDKs, `vs.recordings.export` returns the response; read its bytes (PHP's `$vs->recordings->export` returns them as a string).

## Complete code

Each program runs as it is once `VOCALSTACK_API_KEY` holds one of your API keys.

### `transcribe.php`

```php
<?php
require 'vendor/autoload.php';

use VocalStack\VocalStack;

$vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'), baseUrl: 'https://api.vocalstack.com');

$tx = $vs->transcriptions->create(
    ['name' => 'Talk', 'settings' => ['spokenLanguage' => 'en', 'speakerLabels' => 'on']],
    file: './talk.mp3',
    idempotencyKey: bin2hex(random_bytes(16)),  // makes a retried upload safe
);
$recordingId = $tx['job']['recordingId'];

$last = '';
$job = $vs->jobs->wait($recordingId, onProgress: function (array $j) use (&$last): void {
    // Called on every read of the job: print it when it changes.
    $line = "{$j['state']} {$j['progress']['pct']}%";
    if ($line !== $last) {
        $last = $line;
        echo $line, "\n";
    }
});
if ($job['state'] !== 'done') {
    throw new RuntimeException("transcription {$job['state']}: " . ($job['error'] ?? ''));
}

$snapshot = $vs->recordings->transcript($recordingId);
foreach ($snapshot['sentences'] as $s) {
    echo $s['speaker'] >= 0 ? "[speaker {$s['speaker']}] {$s['text']}" : $s['text'], "\n";
}

$srt = $vs->recordings->export($recordingId, 'srt');  // the file's bytes
file_put_contents('talk.srt', $srt);
echo "wrote talk.srt\n";
```

---

The same guide for every SDK and for plain HTTP + WebSocket: https://api.vocalstack.com/docs/guides/transcribe-a-file.md. Every guide: [docs/](./).
