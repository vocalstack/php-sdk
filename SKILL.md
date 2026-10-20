---
name: vocalstack
description: Use when writing PHP code that calls the VocalStack speech API with the vocalstack/sdk Composer package, for example to transcribe an audio or video URL or a local file, stream live audio for realtime transcription, fetch or export a transcript (JSON, SRT, XLSX, PDF), or search transcripts. Covers installing, API keys, base URLs, five copy-paste programs and error handling (401, 402, 403, 429).
---

# VocalStack PHP SDK

The VocalStack API transcribes speech (files, links, live audio), with speaker
labels and translation. The `vocalstack/sdk` package has one synchronous
client, `VocalStack\VocalStack`, with one group per resource:
`$vs->transcriptions`, `$vs->jobs`, `$vs->recordings`, `$vs->broadcasts`,
`$vs->search`, `$vs->realtime` and a few more (listed at the end). Answers are
associative arrays with the wire's camelCase keys (`$job['recordingId']`).

## What is what

- A **transcription** is a file, a link, a microphone or a stream, transcribed
  and stored. Create one with `$vs->transcriptions->create([...])`.
- A **recording** is one take inside it. Its id (`recordingId`) is what you
  read the transcript and exports with: `$vs->recordings->transcript($recordingId)`.
- A **job** is a file or link recording's transcription work:
  `$vs->jobs->wait($recordingId)` polls it until it is `done` or `failed`
  (`onProgress: fn (array $job) => ...` sees each read: `$job['state']`,
  `$job['progress']['pct']`).
- A **broadcast** is a link listeners open to follow a live recording.
- A **realtime session** (`$vs->realtime`) is a socket you stream audio into
  and read the transcript from. Nothing is stored. "Session" means only this.

## Install

```sh
composer require vocalstack/sdk          # PHP 8.1 or newer, ext-json; ext-curl for the default HTTP client
composer require phrity/websocket:^3     # only for realtime sessions and live audio (program 4)
```

Live transcription (program 4) also needs `ffmpeg` on PATH.

Speaker numbers start at 0. A speaker of `-1` means speaker labels were off
for that transcription, so print the text without a speaker.

## Auth and base URL

- Read the key from `VOCALSTACK_API_KEY` and pass it as `apiKey:`. It goes
  out as `Authorization: Bearer <key>`, also on sockets.
- An API key starts with `vs_key_`. There is one kind of key: every call is
  real usage on the account's tier and quotas.
- Keys are for servers. Never put a key in browser code or in a URL. The API
  refuses a key sent from a web page (`Origin` header) or in a query string.
- Scopes: `read` (read transcripts, search, export), `write` and
  `recordings:publish` (create transcriptions, stream live audio), or `*`.
- Base URL: `https://api.vocalstack.com` (the default). Override it with
  `VOCALSTACK_BASE_URL` (for example, a local or staging server).

Every program below opens the client the same way (a null base URL means the
default):

```php
$vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'), baseUrl: getenv('VOCALSTACK_BASE_URL') ?: null);
```

## 1. Transcribe a URL and wait for the result

The server fetches the link itself, so it must be a public http(s) address.
Private, loopback and cloud-metadata addresses are refused.

```php
<?php
require 'vendor/autoload.php';

use VocalStack\VocalStack;

$vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'), baseUrl: getenv('VOCALSTACK_BASE_URL') ?: null);
$tx = $vs->transcriptions->create([
    'url' => 'https://raw.githubusercontent.com/openai/whisper/main/tests/jfk.flac',
    'name' => 'JFK sample',
]);
$rec = $tx['job']['recordingId'];
$job = $vs->jobs->wait($rec);  // queued -> fetching -> transcribing -> done | failed
if ($job['state'] !== 'done') {
    exit("transcription {$job['state']}: " . json_encode($job['error'] ?? null) . "\n");
}
$snap = $vs->recordings->transcript($rec);
foreach ($snap['sentences'] as $s) {
    echo $s['speaker'] >= 0 ? "[speaker {$s['speaker']}] {$s['text']}" : $s['text'], "\n";
}
echo "recording_id {$rec}\n";
```

## 2. Transcribe a local file

```php
<?php
require 'vendor/autoload.php';

use VocalStack\VocalStack;

$path = $argv[1];  // mp3, wav, flac, ogg, m4a, mp4 ...
$vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'), baseUrl: getenv('VOCALSTACK_BASE_URL') ?: null);
$tx = $vs->transcriptions->create(
    ['settings' => ['spokenLanguage' => 'en', 'speakerLabels' => 'on']],  // both optional; "auto" | "on" | "off"
    file: $path,  // a path, a stream resource, or VocalStack\Upload::bytes($data, 'name.mp3'); streamed, not read whole
);
$job = $vs->jobs->wait($tx['job']['recordingId']);
if ($job['state'] !== 'done') {
    exit("transcription {$job['state']}: " . json_encode($job['error'] ?? null) . "\n");
}
$snap = $vs->recordings->transcript($job['recordingId']);
echo implode(' ', array_column($snap['sentences'], 'text')), "\n";
```

## 3. Fetch a transcript or an export file

The transcript holds segments (with word timings), sentences and speakers.
Export formats are `json`, `srt`, `xlsx` and `pdf`; add `lang: '<code>'` for a
translated file. `export` returns the file's bytes as a string.

```php
<?php
require 'vendor/autoload.php';

use VocalStack\VocalStack;

$rec = $argv[1];
$vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'), baseUrl: getenv('VOCALSTACK_BASE_URL') ?: null);
$snap = $vs->recordings->transcript($rec);
printf("%d segments, %d sentences, state %s\n", count($snap['segments']), count($snap['sentences']), $snap['state']);
file_put_contents('transcript.srt', $vs->recordings->export($rec, 'srt'));
```

## 4. Live transcription from a microphone or a file

A live transcription takes raw PCM: 32-bit float little-endian, 16 kHz, mono.
Create it, start a recording, and open the publisher socket the grant names
(this needs `phrity/websocket`). Here ffmpeg decodes a file, and
`paceRealtime` sends it at realtime speed, yields the transcript events as
they arrive, and calls `eof()` at the end. For a microphone, read ffmpeg's
stdout as it arrives (`-f avfoundation -i :0` on macOS, `-f pulse -i default`
on Linux), call `$stream->sendAudio($chunk)` for each chunk, and read
`$stream->poll(0)` between chunks; end with `$stream->eof()` and
`foreach ($stream as $event)`.

```php
<?php
require 'vendor/autoload.php';

use VocalStack\VocalStack;

use function VocalStack\paceRealtime;

$path = $argv[1];
$vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'), baseUrl: getenv('VOCALSTACK_BASE_URL') ?: null);
$tx = $vs->transcriptions->createLive(['name' => 'Live demo', 'settings' => ['spokenLanguage' => 'en']]);
$grant = $vs->transcriptions->startRecording($tx['id']);

$pcm = shell_exec('ffmpeg -loglevel error -i ' . escapeshellarg($path) . ' -f f32le -ac 1 -ar 16000 -');

// A cold engine can take a while to load, so allow a long ready deadline.
$stream = $vs->recordings->publish($grant, readyDeadline: 180);
foreach (paceRealtime($stream, $pcm) as $event) {  // then eof(), which ends the recording
    if ($event['type'] === 'transcript.committed') {
        echo "[speaker {$event['segment']['speaker']}] {$event['segment']['text']}\n";
    }
}
$stream->close();

// To end a recording without eof(): $vs->recordings->pause($id), then $vs->recordings->stop($id).
$snap = $vs->recordings->transcript($grant['recording']['id']);
echo 'final: ', implode(' ', array_column($snap['sentences'], 'text')), "\n";
```

To transcribe without storing anything, use a realtime session instead:
`$vs->realtime->transcribeFile('talk.mp3')->text()`.

## 5. Search transcripts

```php
<?php
require 'vendor/autoload.php';

use VocalStack\VocalStack;

$q = $argv[1];  // 2+ characters
$vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'), baseUrl: getenv('VOCALSTACK_BASE_URL') ?: null);
$res = $vs->search->query($q, limit: 5);
foreach ($res['hits'] as $h) {
    printf("%s @ %.1fs [speaker %d]: %s (recording %s)\n", $h['linkName'], $h['mediaStart'], $h['speaker'], $h['text'], $h['recordingId']);
}
```

## Errors

A failed call throws `VocalStack\VocalStackException` with `->errorCode` (also
`errorCode()`), `getMessage()`, `->status`, `->retryable`, `->details` (the
body's `data.details`), `->headers` (lowercased names), `->requestId` and
`retryAfter()` (seconds, from `Retry-After`). A `429`, and a
`503 unavailable` on a call that is safe to repeat, is retried for you after
its `Retry-After`, twice by default (`maxRetries:` on the client). No answer at
all (DNS, connect, timeout) throws `VocalStack\Http\ConnectionException`.

| Status | `errorCode` | What to do |
|---|---|---|
| 401 | `invalid_token`, `key_expired`, `key_revoked` | Check `VOCALSTACK_API_KEY`. Use the replacement key, or create a new one. |
| 403 | `forbidden` | Usually a missing scope: the message names it, and `$e->headers['www-authenticate']` lists the scopes the route needs. A suspended key or account gets `suspended`. |
| 403 | `terms_acceptance_required` | The account has not accepted the current Terms of Service and Privacy Policy: someone signs in to the Dashboard and accepts them (an organisation's keys work once an owner or admin has). Not retryable until then. |
| 402 | `insufficient_credit` | The account's credit is out, or does not cover the transcription: every transcription is billed at least the plan's minimum (`rates.minimumSeconds`, 5 minutes). `details`: `balance`, `plan`, `topUpUrl`, and `required` when the cost was known up front. Add credit; `$vs->billing->get()` shows the balance. |
| 409 | `payment_method_required` | Auto top-up (`$vs->billing->setAutoTopUp`) and the portal (`$vs->billing->portal()`) need a payment first (`$vs->billing->createCheckout(['kind' => 'topup', 'amount' => 25, 'acceptTerms' => true])` opens one; a signed-in person's token, never an API key). |
| 402 | `quota_exceeded` | The plan's transcriptions-at-once limit is reached. `details`: `metric` (`concurrent_transcriptions`), `limit`, `used`, `plan`, `topUpUrl`. |
| 429 | `rate_limited` | Retried automatically; if it still fails, wait `$e->retryAfter()` seconds. Every response carries `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset`. |
| 503 | `unavailable` | The service is waking (its database was asleep). Retried automatically after `Retry-After` (2 s) on calls that are safe to repeat: GET, HEAD, DELETE, or any call with `idempotencyKey:`. A create without a key is not retried: retry it yourself, with an `idempotencyKey:` so it cannot run twice. A realtime socket refused this way throws `unavailable`; a bare 503 there is `capacity_exceeded`. |

```php
use VocalStack\VocalStackException;

try {
    $vs->search->query('country');
} catch (VocalStackException $e) {
    if (in_array($e->errorCode, ['insufficient_credit', 'quota_exceeded'], true)) {
        echo 'billing: ', $e->getMessage(), ' ', json_encode($e->details), "\n";
    } else {
        echo $e->status, ' ', $e->errorCode, ' ', $e->getMessage(), "\n";
    }
}
```

## More

- The other groups: `$vs->transcriptions` (`list`, `get`, `update`, `archive`,
  `restore`, `purge`, `listShareLinks`, `createShareLink`), `$vs->recordings`
  (`get`, `summarize`, `summary`, `translation`, `audioUrl`, `subscribe`),
  `$vs->corrections`, `$vs->shareLinks`, `$vs->voices`, `$vs->broadcasts`,
  `$vs->me`, `$vs->connect` (`token`; PKCE helpers in `VocalStack\Connect\Pkce`),
  `$vs->billing` (`get`, `usage`), `$vs->languages`, `$vs->rtmp`,
  `$vs->events`, `$vs->meta`. Each method is its operation's name in the
  OpenAPI document, in camelCase (`transcriptions->create` is
  `POST /v1/transcriptions`).
- `<base>/llms.txt` lists every operation in a form written for agents.
  `<base>/llms-full.txt` adds parameters, auth mode, tier and scopes.
- `<base>/docs` is the interactive reference. `<base>/v1/openapi.json` is
  the OpenAPI document the answers follow.
