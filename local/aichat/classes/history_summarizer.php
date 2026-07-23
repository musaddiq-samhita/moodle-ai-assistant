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
 * AI Chat - History summarizer
 *
 * Compresses older conversation history into a rolling summary with a
 * persisted coverage cursor (threads.summarylastmessageid) so it is always
 * known exactly which messages the summary covers.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages conversation history compression via rolling summaries.
 */
class history_summarizer {

    /** @var int On summary failure, at most this many uncovered older messages are sent raw as fallback. */
    const FALLBACK_RAW_MESSAGES = 4;

    /**
     * Get the conversation context for an API call: summary of older + recent raw messages.
     *
     * @param int $threadid The thread ID.
     * @param int $excludemessageid Message ID to exclude (the just-stored current
     *                              user message, which the caller appends itself).
     * @return array {summary: string|null, messages: \stdClass[]}
     */
    public static function get_context_history(int $threadid, int $excludemessageid = 0): array {
        global $DB;

        $window = (int) get_config('local_aichat', 'historywindow') ?: 5;
        if ($window < 1) {
            $window = 1;
        }

        // Load all messages in deterministic chronological order. Timestamps
        // have one-second resolution, so id is required as a tiebreak.
        $messages = $DB->get_records('local_aichat_messages', ['threadid' => $threadid],
            'timecreated ASC, id ASC', 'id, role, message, timecreated');
        $messages = array_values($messages);

        // Exclude the current (just-stored) user message by exact ID so it
        // neither occupies a raw-window slot nor gets sent twice.
        if ($excludemessageid > 0) {
            $messages = array_values(array_filter($messages, function($msg) use ($excludemessageid) {
                return (int) $msg->id !== $excludemessageid;
            }));
        }

        $total = count($messages);
        if ($total <= $window) {
            // All messages fit in the raw window — no summary needed.
            return ['summary' => null, 'messages' => $messages];
        }

        // Split: older messages for summary, recent messages sent raw.
        $recentmessages = array_slice($messages, -$window);
        $oldermessages = array_slice($messages, 0, $total - $window);
        $lastolderid = (int) end($oldermessages)->id;

        $thread = $DB->get_record('local_aichat_threads', ['id' => $threadid],
            'id, summary, summarylastmessageid');
        $cursor = (int) ($thread->summarylastmessageid ?? 0);
        $summary = (string) ($thread->summary ?? '');

        if ($summary !== '' && $cursor >= $lastolderid) {
            // Summary provably covers everything outside the raw window.
            return ['summary' => $summary, 'messages' => $recentmessages];
        }

        // Some older messages are not covered by the summary yet.
        $uncovered = array_values(array_filter($oldermessages, function($msg) use ($cursor) {
            return (int) $msg->id > $cursor;
        }));

        if ($summary === '') {
            $newsummary = self::generate_summary($oldermessages);
        } else {
            $newsummary = self::update_summary($summary, $uncovered);
        }

        if ($newsummary !== '') {
            self::save_summary($threadid, $newsummary, $lastolderid);
            return ['summary' => $newsummary, 'messages' => $recentmessages];
        }

        // Summarization failed. Never silently drop context: keep whatever
        // stale summary exists and prepend a bounded tail of the uncovered
        // older messages so the model still sees them raw.
        $fallback = array_slice($uncovered, -self::FALLBACK_RAW_MESSAGES);
        return [
            'summary' => $summary !== '' ? $summary : null,
            'messages' => array_merge($fallback, $recentmessages),
        ];
    }

    /**
     * Update the summary when new messages rotate out of the raw window.
     *
     * Called after storing a new message, when the raw window shifts.
     *
     * @param int $threadid The thread ID.
     */
    public static function update_summary_if_needed(int $threadid): void {
        global $DB;

        $window = (int) get_config('local_aichat', 'historywindow') ?: 5;
        if ($window < 1) {
            $window = 1;
        }

        $total = $DB->count_records('local_aichat_messages', ['threadid' => $threadid]);
        if ($total <= $window) {
            return; // No summary needed yet.
        }

        // Deterministic ordering with id tiebreak.
        $oldercount = $total - $window;
        $oldermessages = $DB->get_records('local_aichat_messages', ['threadid' => $threadid],
            'timecreated ASC, id ASC', 'id, role, message, timecreated', 0, $oldercount);
        $oldermessages = array_values($oldermessages);
        if (empty($oldermessages)) {
            return;
        }
        $lastolderid = (int) end($oldermessages)->id;

        $thread = $DB->get_record('local_aichat_threads', ['id' => $threadid],
            'id, summary, summarylastmessageid');
        $cursor = (int) ($thread->summarylastmessageid ?? 0);
        $currentsummary = (string) ($thread->summary ?? '');

        if ($cursor >= $lastolderid && $currentsummary !== '') {
            return; // Summary already covers all rotated-out messages.
        }

        // Only summarize messages beyond the persisted cursor so a failed
        // batch is retried on the next call instead of being skipped.
        $uncovered = array_values(array_filter($oldermessages, function($msg) use ($cursor) {
            return (int) $msg->id > $cursor;
        }));
        if (empty($uncovered)) {
            return;
        }

        if ($currentsummary === '') {
            $summary = self::generate_summary($oldermessages);
        } else {
            $summary = self::update_summary($currentsummary, $uncovered);
        }

        if ($summary !== '') {
            self::save_summary($threadid, $summary, $lastolderid);
        }
        // On failure: leave summary and cursor untouched — the uncovered
        // messages remain > cursor and will be retried next time.
    }

    /**
     * Persist summary text and coverage cursor together, guarding against a
     * concurrent request regressing a newer cursor (poor-man's CAS).
     *
     * @param int $threadid The thread ID.
     * @param string $summary The new summary text.
     * @param int $coveredupto The highest message ID this summary covers.
     */
    private static function save_summary(int $threadid, string $summary, int $coveredupto): void {
        global $DB;

        $DB->execute(
            "UPDATE {local_aichat_threads}
                SET summary = ?, summarylastmessageid = ?
              WHERE id = ?
                AND (summarylastmessageid IS NULL OR summarylastmessageid <= ?)",
            [$summary, $coveredupto, $threadid, $coveredupto]
        );
    }

    /**
     * Generate a summary of conversation messages using Azure OpenAI.
     *
     * @param array $messages Array of message objects (role, message).
     * @return string The summary text.
     */
    private static function generate_summary(array $messages): string {
        if (empty($messages)) {
            return '';
        }

        $transcript = '';
        foreach ($messages as $msg) {
            $role = ucfirst($msg->role);
            $transcript .= $role . ': ' . $msg->message . "\n";
        }

        $apimessages = [
            [
                'role' => 'system',
                'content' => 'Summarize the following conversation history in max 200 words, '
                           . 'preserving key topics, questions asked, and facts discussed. '
                           . 'Write in the same language as the conversation.'
            ],
            [
                'role' => 'user',
                'content' => $transcript,
            ],
        ];

        try {
            $result = azure_openai_client::complete($apimessages);
            return $result['response'];
        } catch (\Exception $e) {
            debugging('AI Chat: Summary generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Incrementally update an existing summary with newly rotated messages.
     *
     * @param string $currentsummary The current rolling summary.
     * @param array $newmessages The newly rotated-out messages.
     * @return string The updated summary, or '' on failure.
     */
    private static function update_summary(string $currentsummary, array $newmessages): string {
        if (empty($newmessages)) {
            return $currentsummary;
        }

        $transcript = '';
        foreach ($newmessages as $msg) {
            $role = ucfirst($msg->role);
            $transcript .= $role . ': ' . $msg->message . "\n";
        }

        $apimessages = [
            [
                'role' => 'system',
                'content' => 'Update the following conversation summary to incorporate the new messages below. '
                           . 'Keep it under 200 words. Preserve key topics and facts. '
                           . 'Write in the same language as the conversation.'
            ],
            [
                'role' => 'user',
                'content' => "Current summary:\n" . $currentsummary
                           . "\n\nNew messages to incorporate:\n" . $transcript,
            ],
        ];

        try {
            $result = azure_openai_client::complete($apimessages);
            return $result['response'];
        } catch (\Exception $e) {
            debugging('AI Chat: Summary update failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            // Return '' (not the stale summary) so callers know coverage did
            // NOT advance and can retry the same batch later.
            return '';
        }
    }
}
