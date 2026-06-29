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
     * Build request headers with provider-specific authentication.
     *
     * @param string $apikey The API key.
     * @param string $provider 'openai' or 'azure'.
     * @return string[]
     */
    private static function build_request_headers(string $apikey, string $provider): array {
        return [
            'Content-Type: application/json',
            $provider === 'openai' ? 'Authorization: Bearer ' . $apikey : 'api-key: ' . $apikey,
        ];
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
