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
 * AI Chat - Get course settings external function
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Return per-course feature toggles.
 */
class get_course_settings extends external_api {

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get course settings.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/aichat:use', $context);

        require_once($CFG->dirroot . '/local/aichat/lib.php');
        $settings = \local_aichat_get_course_settings($params['courseid']);

        return [
            'enable_export'        => $settings->enable_export,
            'enable_upload'        => $settings->enable_upload,
            'enable_transcription' => $settings->enable_transcription,
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enable_export' => new external_value(PARAM_BOOL, 'Whether chat export is enabled'),
            'enable_upload' => new external_value(PARAM_BOOL, 'Whether file upload is enabled'),
            'enable_transcription' => new external_value(PARAM_BOOL,
                'Whether SCORM video transcription is enabled', VALUE_OPTIONAL),
        ]);
    }
}
