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
 * AI Chat - Ad-hoc course re-index task.
 *
 * Runs a forced re-index (including video transcription) for one course in the
 * background, so a cold rebuild - which may make several minute-long transcription
 * calls - never blocks a web request or hits a proxy timeout.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad-hoc task: forced re-index of a single course.
 */
class rebuild_course_index extends \core\task\adhoc_task {

    /**
     * Execute the task.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $courseid = (int) ($data->courseid ?? 0);
        if ($courseid <= 0) {
            return;
        }

        // Run as a site admin so availability-restricted activities are visible
        // and their embeddings are not orphan-deleted.
        \core\session\manager::set_user(get_admin());

        // Do NOT swallow exceptions or a locked result: Moodle's ad-hoc runner
        // records a thrown task as failed and retries it with a growing fail
        // delay, which is exactly what a contended lock or a transient indexing
        // error needs. Swallowing here would drop an accepted rebuild silently.
        $stats = \local_aichat\rag\vector_store::index_course($courseid, true, true);
        if (!empty($stats['locked'])) {
            throw new \moodle_exception('error', 'local_aichat', '', null,
                "course {$courseid} index is locked by another run; deferring rebuild for retry");
        }
        mtrace("local_aichat: rebuilt course {$courseid} index - "
            . "indexed {$stats['indexed']}, skipped {$stats['skipped']}, deleted {$stats['deleted']}");
    }
}
