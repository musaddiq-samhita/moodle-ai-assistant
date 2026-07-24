<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Chat - Azure OpenAI client
 *
 * Handles all communication with Azure OpenAI Chat Completions API.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

use local_aichat\security\circuit_breaker;

/**
 * Azure OpenAI Chat Completions client.
 */
class azure_openai_client {

    /**
     * Write a log entry to the PHP error log and optionally to a dedicated log file.
     *
     * The file log is written to {$CFG->dataroot}/local_aichat/aichat.log and can be
     * enabled via the admin setting local_aichat/enablefilelog.
     *
     * @param string $level One of: DEBUG, INFO, WARN, ERROR.
     * @param string $message Human-readable message.
     * @param array $context Optional key-value pairs appended to the entry.
     */
    private static function log(string $level, string $message, array $context = []): void {
        $loglevel = get_config('local_aichat', 'loglevel') ?: 'ERROR';
        $levels = ['DEBUG' => 0, 'INFO' => 1, 'WARN' => 2, 'ERROR' => 3];
        if (($levels[$level] ?? 0) < ($levels[$loglevel] ?? 0)) {
            return;
        }

        $parts = ['[local_aichat]', "[{$level}]", $message];
        foreach ($context as $k => $v) {
            $parts[] = "{$k}=" . (is_scalar($v) ? $v : json_encode($v));
        }
        $entry = implode(' ', $parts);
        error_log($entry);

        if ($level === 'DEBUG') {
            debugging($entry, DEBUG_DEVELOPER);
        }

        // Write to dedicated log file if enabled.
        if (get_config('local_aichat', 'enablefilelog')) {
            self::write_to_file($level, $entry);
        }
    }

    /**
     * Write a log line to the dedicated aichat log file.
     *
     * @param string $level The log level.
     * @param string $entry The formatted log entry.
     */
    private static function write_to_file(string $level, string $entry): void {
        global $CFG;
        $dir = $CFG->dataroot . '/local_aichat';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $logfile = $dir . '/aichat.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logfile, "[{$timestamp}] {$entry}" . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Validate that the configured endpoint is a trusted Azure OpenAI domain.
     *
     * @param string $endpoint The Azure endpoint URL.
     * @throws \moodle_exception If the endpoint is not a valid Azure OpenAI URL.
     */
    private static function validate_endpoint(string $endpoint, string $provider): void {
        if ($provider === 'openai') {
            // Allow only the official OpenAI API host (SSRF allowlist).
            if (!preg_match('#^https://api\.openai\.com/?$#i', $endpoint)) {
                throw new \moodle_exception('invalidopenaiendpoint', 'local_aichat');
            }
            return;
        }
        // Allow only Azure OpenAI endpoints (*.openai.azure.com) (SSRF allowlist).
        if (!preg_match('#^https://[a-z0-9\-]+\.openai\.azure\.com/?$#i', $endpoint)) {
            throw new \moodle_exception('invalidazureendpoint', 'local_aichat');
        }
    }

    /**
     * Resolve the configured AI provider.
     *
     * @return string 'openai' or 'azure' (default).
     */
    public static function get_provider(): string {
        return get_config('local_aichat', 'provider') === 'openai' ? 'openai' : 'azure';
    }

    /**
     * Resolve the effective endpoint base URL for the provider.
     *
     * For OpenAI the host is fixed, so the admin endpoint field is optional.
     *
     * @param string $provider 'openai' or 'azure'.
     * @return string The endpoint base URL.
     */
    private static function resolve_endpoint(string $provider): string {
        $endpoint = get_config('local_aichat', 'endpoint');
        if ($provider === 'openai' && empty($endpoint)) {
            return 'https://api.openai.com';
        }
        return (string) $endpoint;
    }

    /**
     * Build the chat completions URL for the provider.
     *
     * @param string $endpoint Endpoint base URL.
     * @param string $deployment Azure deployment name / OpenAI model id.
     * @param string $apiversion Azure API version (ignored for OpenAI).
     * @param string $provider 'openai' or 'azure'.
     * @return string
     */
    private static function build_chat_url(string $endpoint, string $deployment, string $apiversion, string $provider): string {
        if ($provider === 'openai') {
            return rtrim($endpoint, '/') . '/v1/chat/completions';
        }
        return rtrim($endpoint, '/') . '/openai/deployments/' . urlencode($deployment)
             . '/chat/completions?api-version=' . urlencode($apiversion);
    }

    /**
     * Build the provider-specific authentication header only (no Content-Type).
     *
     * Multipart uploads (transcription) must let cURL set the multipart
     * Content-Type + boundary, so they use this instead of the JSON headers.
     *
     * @param string $apikey The API key.
     * @param string $provider 'openai' or 'azure'.
     * @return string[]
     */
    private static function build_auth_header(string $apikey, string $provider): array {
        return [$provider === 'openai' ? 'Authorization: Bearer ' . $apikey : 'api-key: ' . $apikey];
    }

    /**
     * Build request headers with provider-specific authentication (JSON calls).
     *
     * @param string $apikey The API key.
     * @param string $provider 'openai' or 'azure'.
     * @return string[]
     */
    private static function build_request_headers(string $apikey, string $provider): array {
        return array_merge(['Content-Type: application/json'], self::build_auth_header($apikey, $provider));
    }

    /**
     * Build the audio-transcription URL for the provider.
     *
     * @param string $endpoint Endpoint base URL.
     * @param string $model OpenAI model id / Azure whisper deployment name.
     * @param string $apiversion Azure API version (ignored for OpenAI).
     * @param string $provider 'openai' or 'azure'.
     * @return string
     */
    private static function build_transcription_url(string $endpoint, string $model, string $apiversion, string $provider): string {
        if ($provider === 'openai') {
            return rtrim($endpoint, '/') . '/v1/audio/transcriptions';
        }
        return rtrim($endpoint, '/') . '/openai/deployments/' . urlencode($model)
             . '/audio/transcriptions?api-version=' . urlencode($apiversion);
    }

    /**
     * Build the system prompt with course-only guardrails.
     *
     * @param string $coursename The course full name.
     * @param string $lang The user's language code.
     * @param string $ragcontext The assembled RAG context.
     * @return string The complete system prompt.
     */
    public static function build_system_prompt(string $coursename, string $lang, string $ragcontext): string {
        $template = get_config('local_aichat', 'systemprompt');
        if (empty($template)) {
            $template = get_string('systemprompt_default', 'local_aichat');
        }

        $systemprompt = str_replace(
            ['{coursename}', '{lang}'],
            [$coursename, $lang],
            $template
        );

        if (!empty($ragcontext)) {
            $systemprompt .= "\n\n--- Course Context ---\n" . $ragcontext;
        }

        // Append follow-up suggestion instruction if enabled.
        if (get_config('local_aichat', 'enablesuggestions')) {
            $systemprompt .= "\n\nAfter each response, suggest 2-3 brief follow-up questions the student might ask. "
                           . "Format them on the last line as: [SUGGESTIONS]question1|question2|question3[/SUGGESTIONS]";
        }

        return $systemprompt;
    }

    /**
     * Build the messages array for the API call.
     *
     * @param string $systemprompt The system prompt.
     * @param array $history Previous messages [{role, message}].
     * @param string|null $summary Summary of older messages.
     * @param string $usermessage The current user message.
     * @param string|null $imagebase64 Base64-encoded image for vision (optional).
     * @return array The messages array for the API.
     */
    public static function build_messages(
        string $systemprompt,
        array $history,
        ?string $summary,
        string $usermessage,
        ?string $imagebase64 = null
    ): array {
        $messages = [
            ['role' => 'system', 'content' => $systemprompt],
        ];

        // Add summary of older conversation if present.
        if (!empty($summary)) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Previous conversation summary: ' . $summary,
            ];
        }

        // Add recent conversation history.
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg->role === 'user' ? 'user' : 'assistant',
                'content' => $msg->message,
            ];
        }

        // Add the current user message.
        if (!empty($imagebase64)) {
            // Vision format.
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $usermessage],
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => 'data:image/jpeg;base64,' . $imagebase64],
                    ],
                ],
            ];
        } else {
            $messages[] = ['role' => 'user', 'content' => $usermessage];
        }

        return $messages;
    }

    /**
     * Send a non-streaming chat completion request.
     *
     * @param array $messages The messages array.
     * @return array {response: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, deployment: string}
     * @throws \moodle_exception On failure.
     */
    public static function complete(array $messages): array {
        // Check circuit breaker before calling.
        circuit_breaker::check();

        $provider = self::get_provider();
        $endpoint = self::resolve_endpoint($provider);
        $apikey = get_config('local_aichat', 'apikey');
        $deployment = get_config('local_aichat', 'chatdeployment');
        $apiversion = get_config('local_aichat', 'apiversion') ?: '2024-08-01-preview';
        $maxtokens = (int) get_config('local_aichat', 'maxtokens') ?: 1024;
        $temperature = (float) get_config('local_aichat', 'temperature') ?: 0.3;

        if (empty($endpoint) || empty($apikey) || empty($deployment)) {
            self::log('ERROR', 'complete() called with incomplete configuration');
            throw new \moodle_exception('azurenotconfigured', 'local_aichat');
        }

        self::validate_endpoint($endpoint, $provider);

        $url = self::build_chat_url($endpoint, $deployment, $apiversion, $provider);

        self::log('INFO', 'complete() request', [
            'deployment' => $deployment,
            'api_version' => $apiversion,
            'messages' => count($messages),
            'max_tokens' => $maxtokens,
            'temperature' => $temperature,
        ]);

        $body = [
            'messages' => $messages,
            'max_completion_tokens' => $maxtokens,
            'temperature' => $temperature,
        ];
        if ($provider === 'openai') {
            $body['model'] = $deployment;
        }
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader(self::build_request_headers($apikey, $provider));

        $tstart = microtime(true);
        $response = $curl->post($url, $payload);
        $elapsed = round((microtime(true) - $tstart) * 1000);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode !== 200) {
            self::log('ERROR', 'complete() API error', [
                'http_code' => $httpcode,
                'elapsed_ms' => $elapsed,
                'deployment' => $deployment,
            ]);
            circuit_breaker::record_failure();
            throw new \moodle_exception('azureapierror', 'local_aichat', '', $httpcode);
        }

        circuit_breaker::record_success();

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            self::log('ERROR', 'complete() invalid JSON response', ['http_code' => $httpcode]);
            throw new \moodle_exception('azureinvalidresponse', 'local_aichat');
        }
        if (!isset($data['choices'][0]['message']['content'])) {
            self::log('ERROR', 'complete() missing choices in response', ['http_code' => $httpcode]);
            throw new \moodle_exception('azureinvalidresponse', 'local_aichat');
        }

        $usage = $data['usage'] ?? [];

        self::log('INFO', 'complete() success', [
            'deployment' => $deployment,
            'elapsed_ms' => $elapsed,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
        ]);

        return [
            'response' => $data['choices'][0]['message']['content'],
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'deployment' => $deployment,
        ];
    }

    /** @var string Transcription produced usable text. */
    const TRANSCRIBE_SUCCESS = 'success';
    /** @var string Decodable audio but blank text. */
    const TRANSCRIBE_EMPTY = 'empty';
    /** @var string File could not be decoded (e.g. no audio track). */
    const TRANSCRIBE_UNDECODABLE = 'undecodable';
    /** @var string Transient failure — retry after backoff. */
    const TRANSCRIBE_RETRYABLE = 'retryable_error';
    /** @var string Content-specific hard failure (e.g. oversized). */
    const TRANSCRIBE_PERMANENT = 'permanent_error';
    /** @var string Provider/config problem — run-level, do not cache per file. */
    const TRANSCRIBE_CONFIG_BLOCKED = 'config_blocked';

    /** @var int Whisper hard upload limit (25 MB). */
    const TRANSCRIBE_MAX_BYTES = 26214400;

    /**
     * Build a normalized transcription outcome array.
     *
     * @param string $status One of the TRANSCRIBE_* constants.
     * @param array $extra Overrides (transcript, language, provider, model, lasterror, retryafterheader).
     * @return array
     */
    private static function transcription_outcome(string $status, array $extra = []): array {
        return array_merge([
            'status'           => $status,
            'transcript'       => null,
            'language'         => null,
            'provider'         => null,
            'model'            => null,
            'lasterror'        => null,
            'retryafterheader' => null,
        ], $extra);
    }

    /**
     * Parse a Retry-After header value (delta-seconds or an HTTP date) into a
     * positive delay in seconds, or null if it cannot be interpreted.
     *
     * @param string $value
     * @return int|null
     */
    private static function parse_retry_after(string $value) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        $delta = $ts - time();
        return $delta > 0 ? $delta : null;
    }

    /**
     * Transcribe an audio/video file via the configured speech-to-text provider.
     *
     * Transport only: the caller owns the temp file lifecycle (copy from the
     * Moodle stored_file, delete in finally). Never throws — returns a
     * discriminated outcome so the cache/pipeline can decide retry/skip policy.
     * Phase 1 certifies OpenAI only; a non-OpenAI provider returns config_blocked.
     *
     * @param string $tmpfilepath Absolute path to a readable local media file.
     * @param string $filename Original filename (for the multipart part).
     * @return array {status, transcript, language, provider, model, lasterror, retryafterheader}
     */
    public static function transcribe(string $tmpfilepath, string $filename): array {
        $provider = self::get_provider();

        // Phase 1: OpenAI is the only certified transcription provider.
        if ($provider !== 'openai') {
            return self::transcription_outcome(self::TRANSCRIBE_CONFIG_BLOCKED,
                ['lasterror' => 'transcription requires the OpenAI provider']);
        }

        // If the transcription circuit is open, stop the run (do not cache).
        if (circuit_breaker::is_open('transcription')) {
            return self::transcription_outcome(self::TRANSCRIBE_CONFIG_BLOCKED,
                ['lasterror' => 'transcription circuit open']);
        }

        $endpoint = self::resolve_endpoint($provider);
        $apikey = get_config('local_aichat', 'apikey');
        $model = trim((string) get_config('local_aichat', 'transcriptionmodel'));
        if ($model === '') {
            $model = 'whisper-1';
        }
        $apiversion = get_config('local_aichat', 'apiversion') ?: '2024-08-01-preview';

        if (empty($endpoint) || empty($apikey)) {
            return self::transcription_outcome(self::TRANSCRIBE_CONFIG_BLOCKED,
                ['lasterror' => 'provider not configured']);
        }
        if (!is_readable($tmpfilepath)) {
            return self::transcription_outcome(self::TRANSCRIBE_PERMANENT,
                ['lasterror' => 'local media file unreadable', 'provider' => $provider, 'model' => $model]);
        }
        $size = filesize($tmpfilepath);
        if ($size !== false && $size > self::TRANSCRIBE_MAX_BYTES) {
            return self::transcription_outcome(self::TRANSCRIBE_PERMANENT,
                ['lasterror' => 'file exceeds 25MB provider limit', 'provider' => $provider, 'model' => $model]);
        }

        // Endpoint validation can throw (e.g. an invalid stored endpoint after a
        // provider switch). Contain it so this method upholds its never-throw
        // contract: a bad configuration degrades to config_blocked, not an
        // exception that would fail the whole SCORM source.
        try {
            self::validate_endpoint($endpoint, $provider);
        } catch (\Throwable $e) {
            return self::transcription_outcome(self::TRANSCRIBE_CONFIG_BLOCKED,
                ['lasterror' => 'invalid endpoint configuration']);
        }
        $url = self::build_transcription_url($endpoint, $model, $apiversion, $provider);

        // Raw multipart upload (this exact shape is verified against the live
        // OpenAI endpoint). cURL generates the multipart boundary from the array
        // + CURLFile; we send only the auth header, never a Content-Type. The
        // whole cURL lifecycle is wrapped so a setup fault degrades to retryable
        // and the handle is always closed.
        $retryafterheader = null;
        $ch = null;
        $response = false;
        $httpcode = 0;
        $elapsed = 0;
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => self::build_auth_header($apikey, $provider),
                CURLOPT_POSTFIELDS     => [
                    'file'            => new \CURLFile($tmpfilepath, 'video/mp4', $filename),
                    'model'           => $model,
                    'response_format' => 'verbose_json',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => function ($curlh, $header) use (&$retryafterheader) {
                    if (stripos($header, 'retry-after:') === 0) {
                        $retryafterheader = self::parse_retry_after(trim(substr($header, strlen('retry-after:'))));
                    }
                    return strlen($header);
                },
            ]);

            $tstart = microtime(true);
            $response = curl_exec($ch);
            $elapsed = round((microtime(true) - $tstart) * 1000);
            $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } catch (\Throwable $e) {
            circuit_breaker::record_failure('transcription');
            return self::transcription_outcome(self::TRANSCRIBE_RETRYABLE,
                ['lasterror' => 'transport setup error', 'provider' => $provider, 'model' => $model]);
        } finally {
            if ($ch) {
                curl_close($ch);
            }
        }

        self::log('INFO', 'transcribe() response', [
            'http_code' => $httpcode,
            'elapsed_ms' => $elapsed,
            'model' => $model,
        ]);

        // Transport-level failure (no HTTP response): retryable.
        if ($httpcode === 0 || $response === false) {
            circuit_breaker::record_failure('transcription');
            return self::transcription_outcome(self::TRANSCRIBE_RETRYABLE,
                ['lasterror' => 'transport error', 'provider' => $provider, 'model' => $model,
                 'retryafterheader' => $retryafterheader]);
        }

        if ($httpcode === 200) {
            $data = json_decode($response, true);
            if (!is_array($data) || !isset($data['text'])) {
                // Malformed success response: a real failure, not healthy.
                circuit_breaker::record_failure('transcription');
                return self::transcription_outcome(self::TRANSCRIBE_RETRYABLE,
                    ['lasterror' => 'invalid JSON response', 'provider' => $provider, 'model' => $model,
                     'retryafterheader' => $retryafterheader]);
            }
            circuit_breaker::record_success('transcription');
            $text = trim((string) $data['text']);
            $language = isset($data['language']) ? (string) $data['language'] : null;
            if ($text === '') {
                return self::transcription_outcome(self::TRANSCRIBE_EMPTY,
                    ['provider' => $provider, 'model' => $model, 'language' => $language]);
            }
            return self::transcription_outcome(self::TRANSCRIBE_SUCCESS,
                ['transcript' => $text, 'language' => $language, 'provider' => $provider, 'model' => $model]);
        }

        // No decodable audio (e.g. silent, video-only MP4). Content-specific;
        // cached against the policy so a future model/recipe can re-try.
        if ($httpcode === 400 && stripos((string) $response, 'could not be decoded') !== false) {
            return self::transcription_outcome(self::TRANSCRIBE_UNDECODABLE,
                ['lasterror' => 'no decodable audio', 'provider' => $provider, 'model' => $model]);
        }

        // Auth / configuration problems: run-level, NOT cached per file.
        if ($httpcode === 401 || $httpcode === 403 || $httpcode === 404) {
            circuit_breaker::record_failure('transcription');
            return self::transcription_outcome(self::TRANSCRIBE_CONFIG_BLOCKED,
                ['lasterror' => 'auth/config error http ' . $httpcode]);
        }

        // Rate limit / server error / anything else: retryable (never poison the cache).
        circuit_breaker::record_failure('transcription');
        return self::transcription_outcome(self::TRANSCRIBE_RETRYABLE,
            ['lasterror' => 'http ' . $httpcode, 'provider' => $provider, 'model' => $model,
             'retryafterheader' => $retryafterheader]);
    }

    /**
     * Send a streaming chat completion request via SSE.
     *
     * @param array $messages The messages array.
     * @param callable $callback Function called for each token: function(string $token): void
     * @return array {prompt_tokens: int, completion_tokens: int, total_tokens: int, deployment: string}
     * @throws \moodle_exception On failure.
     */
    public static function stream(array $messages, callable $callback): array {
        // Check circuit breaker before calling.
        circuit_breaker::check();

        $provider = self::get_provider();
        $endpoint = self::resolve_endpoint($provider);
        $apikey = get_config('local_aichat', 'apikey');
        $deployment = get_config('local_aichat', 'chatdeployment');
        $apiversion = get_config('local_aichat', 'apiversion') ?: '2024-08-01-preview';
        $maxtokens = (int) get_config('local_aichat', 'maxtokens') ?: 1024;
        $temperature = (float) get_config('local_aichat', 'temperature') ?: 0.3;

        if (empty($endpoint) || empty($apikey) || empty($deployment)) {
            self::log('ERROR', 'stream() called with incomplete configuration');
            throw new \moodle_exception('azurenotconfigured', 'local_aichat');
        }

        self::validate_endpoint($endpoint, $provider);

        $url = self::build_chat_url($endpoint, $deployment, $apiversion, $provider);

        self::log('INFO', 'stream() request', [
            'deployment' => $deployment,
            'api_version' => $apiversion,
            'messages' => count($messages),
            'max_tokens' => $maxtokens,
            'temperature' => $temperature,
        ]);

        $body = [
            'messages' => $messages,
            'max_completion_tokens' => $maxtokens,
            'temperature' => $temperature,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
        if ($provider === 'openai') {
            $body['model'] = $deployment;
        }
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => self::build_request_headers($apikey, $provider),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $buffer = '';
        $rawbody = '';
        $tstart = microtime(true);

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer, &$callback, &$usage, &$rawbody) {
            $rawbody .= $data;
            $buffer .= $data;

            // Process complete SSE lines.
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                // PHP 7.4-safe prefix check (str_starts_with is PHP 8.0+ and is not
                // polyfilled on the target Moodle 4.1 / PHP 7.4 server).
                if (empty($line) || strpos($line, 'data: ') !== 0) {
                    continue;
                }

                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    continue;
                }

                $chunk = json_decode($json, true);
                if (!is_array($chunk)) {
                    continue;
                }

                // Extract usage if present (final chunk).
                if (isset($chunk['usage'])) {
                    $usage['prompt_tokens'] = (int) ($chunk['usage']['prompt_tokens'] ?? 0);
                    $usage['completion_tokens'] = (int) ($chunk['usage']['completion_tokens'] ?? 0);
                    $usage['total_tokens'] = (int) ($chunk['usage']['total_tokens'] ?? 0);
                }

                // Extract delta content.
                $delta = $chunk['choices'][0]['delta']['content'] ?? null;
                if ($delta !== null) {
                    $callback($delta);
                }
            }

            return strlen($data);
        });

        curl_exec($ch);
        // Read HTTP code AFTER exec — CURLINFO_HTTP_CODE is unreliable inside WRITEFUNCTION
        // for chunked/streaming responses and may return 0 prematurely.
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $elapsed = round((microtime(true) - $tstart) * 1000);
        curl_close($ch);

        if ($httpcode !== 200 || !empty($curlError)) {
            self::log('ERROR', 'stream() API error', [
                'http_code' => $httpcode,
                'curl_error' => $curlError ?: 'none',
                'elapsed_ms' => $elapsed,
                'deployment' => $deployment,
                'response_body' => mb_substr($rawbody, 0, 1000),
            ]);
            circuit_breaker::record_failure();
            throw new \moodle_exception('azureapierror', 'local_aichat', '', $httpcode ?: $curlError);
        }

        circuit_breaker::record_success();

        self::log('INFO', 'stream() success', [
            'deployment' => $deployment,
            'elapsed_ms' => $elapsed,
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'total_tokens' => $usage['total_tokens'],
        ]);

        $usage['deployment'] = $deployment;
        return $usage;
    }

    /**
     * Parse follow-up suggestions from the AI response.
     *
     * @param string $response The full AI response.
     * @return array {clean_response: string, suggestions: string[]}
     */
    public static function parse_suggestions(string $response): array {
        $suggestions = [];
        $clean = $response;

        if (!get_config('local_aichat', 'enablesuggestions')) {
            return ['clean_response' => $clean, 'suggestions' => []];
        }

        if (preg_match('/\[SUGGESTIONS\](.*?)\[\/SUGGESTIONS\]/s', $response, $matches)) {
            $clean = trim(str_replace($matches[0], '', $response));
            $suggestions = array_map('trim', explode('|', $matches[1]));
            $suggestions = array_filter($suggestions, fn($s) => !empty($s));
            $suggestions = array_values($suggestions);
        }

        return [
            'clean_response' => $clean,
            'suggestions' => $suggestions,
        ];
    }
}
