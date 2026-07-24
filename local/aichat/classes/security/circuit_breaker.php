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
 * AI Chat - Circuit breaker
 *
 * Prevents cascading failures when Azure OpenAI is unavailable.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\security;

defined('MOODLE_INTERNAL') || die();

/**
 * Circuit breaker implementation for Azure OpenAI API calls.
 *
 * States: closed (normal), open (blocking), half-open (one probe allowed).
 */
class circuit_breaker {

    /** @var string Cache area name. */
    private const CACHE_AREA = 'circuit_breaker';

    /** @var string Closed state — normal operation. */
    private const STATE_CLOSED = 'closed';

    /** @var string Open state — blocking all requests. */
    private const STATE_OPEN = 'open';

    /** @var string Half-open state — allowing one probe request. */
    private const STATE_HALF_OPEN = 'half_open';

    /** @var string Default operation scope (chat completions). */
    private const DEFAULT_OPERATION = 'chat';

    /** @var array Allowed operation scopes (bounds the cache key). */
    private const OPERATIONS = ['chat', 'transcription'];

    /**
     * Resolve a validated cache key for an operation scope.
     *
     * @param string $operation
     * @return string
     */
    private static function state_key(string $operation): string {
        if (!in_array($operation, self::OPERATIONS, true)) {
            $operation = self::DEFAULT_OPERATION;
        }
        return 'cb_state:' . $operation;
    }

    /**
     * Check whether a request is allowed through the circuit breaker.
     *
     * Note: get_state()/set_state() are not atomic — under high concurrency two
     * requests may both read HALF_OPEN and both proceed as probes. This is an
     * acceptable trade-off for a cache-based circuit breaker; the worst case is
     * two probe requests instead of one.
     *
     * @param string $operation Operation scope ('chat' or 'transcription'); a failing
     *                          batch of transcriptions must not open the chat circuit.
     * @throws \moodle_exception If the circuit is open and the cooldown has not elapsed.
     */
    public static function check(string $operation = self::DEFAULT_OPERATION): void {
        // If circuit breaker is disabled in settings, skip entirely.
        if (!get_config('local_aichat', 'cbenabled')) {
            return;
        }

        $state = self::get_state($operation);

        if ($state['state'] === self::STATE_OPEN) {
            $cooldown = (int) get_config('local_aichat', 'cbcooldownminutes') ?: 5;
            $elapsed = time() - $state['opened_at'];

            if ($elapsed < $cooldown * 60) {
                self::log_blocked($state['failure_count'], $cooldown, $elapsed);
                throw new \moodle_exception('assistantunavailable', 'local_aichat');
            }

            // Cooldown elapsed: move to half-open (allow one probe).
            self::set_state($operation, self::STATE_HALF_OPEN, $state['failure_count'], $state['opened_at']);
        }
        // STATE_CLOSED and STATE_HALF_OPEN allow the request to proceed.
    }

    /**
     * Record a successful API call.
     *
     * @param string $operation Operation scope.
     */
    public static function record_success(string $operation = self::DEFAULT_OPERATION): void {
        self::set_state($operation, self::STATE_CLOSED, 0, 0);
    }

    /**
     * Record a failed API call. Opens the circuit after exceeding the threshold.
     *
     * @param string $operation Operation scope.
     */
    public static function record_failure(string $operation = self::DEFAULT_OPERATION): void {
        // If circuit breaker is disabled, do not track failures.
        if (!get_config('local_aichat', 'cbenabled')) {
            return;
        }

        $state = self::get_state($operation);
        $count = $state['failure_count'] + 1;
        $threshold = (int) get_config('local_aichat', 'cbfailurethreshold') ?: 3;

        if ($state['state'] === self::STATE_HALF_OPEN || $count >= $threshold) {
            // Open the circuit.
            self::set_state($operation, self::STATE_OPEN, $count, time());
        } else {
            self::set_state($operation, self::STATE_CLOSED, $count, 0);
        }
    }

    /**
     * Whether the circuit for an operation is currently open (blocking).
     *
     * Unlike check(), this never throws — callers that must degrade (e.g. a
     * transcription batch) can test the state and stop making provider calls
     * for the run without aborting the surrounding work.
     *
     * @param string $operation Operation scope.
     * @return bool
     */
    public static function is_open(string $operation = self::DEFAULT_OPERATION): bool {
        if (!get_config('local_aichat', 'cbenabled')) {
            return false;
        }
        $state = self::get_state($operation);
        if ($state['state'] !== self::STATE_OPEN) {
            return false;
        }
        $cooldown = (int) get_config('local_aichat', 'cbcooldownminutes') ?: 5;
        return (time() - $state['opened_at']) < $cooldown * 60;
    }

    /**
     * Get the current circuit breaker state from cache.
     *
     * @param string $operation Operation scope.
     * @return array {state: string, failure_count: int, opened_at: int}
     */
    private static function get_state(string $operation = self::DEFAULT_OPERATION): array {
        $cache = \cache::make('local_aichat', self::CACHE_AREA);
        $data = $cache->get(self::state_key($operation));
        if ($data === false) {
            return [
                'state' => self::STATE_CLOSED,
                'failure_count' => 0,
                'opened_at' => 0,
            ];
        }
        return $data;
    }

    /**
     * Set the circuit breaker state in cache.
     *
     * @param string $operation Operation scope.
     * @param string $state The new state.
     * @param int $failurecount The current consecutive failure count.
     * @param int $openedat Timestamp when the circuit was opened (0 if closed).
     */
    private static function set_state(string $operation, string $state, int $failurecount, int $openedat): void {
        $cache = \cache::make('local_aichat', self::CACHE_AREA);
        $cache->set(self::state_key($operation), [
            'state' => $state,
            'failure_count' => $failurecount,
            'opened_at' => $openedat,
        ]);
    }

    /**
     * Log a blocked request to the aichat log file.
     *
     * @param int $failures Number of consecutive failures that triggered the circuit.
     * @param int $cooldown Cooldown duration in minutes.
     * @param int $elapsed Seconds elapsed since the circuit opened.
     */
    private static function log_blocked(int $failures, int $cooldown, int $elapsed): void {
        global $CFG, $USER;
        if (!get_config('local_aichat', 'enablefilelog')) {
            return;
        }
        $dir = $CFG->dataroot . '/local_aichat';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $ts = date('Y-m-d H:i:s');
        $remaining = ($cooldown * 60) - $elapsed;
        $uid = isset($USER->id) ? $USER->id : 0;
        $logline = "[{$ts}] [local_aichat] [WARN] circuit_breaker_blocked"
            . " userid={$uid}"
            . " failures={$failures}"
            . " cooldown_min={$cooldown}"
            . " remaining_sec={$remaining}"
            . PHP_EOL;
        @file_put_contents($dir . '/aichat.log', $logline, FILE_APPEND | LOCK_EX);
    }
}
