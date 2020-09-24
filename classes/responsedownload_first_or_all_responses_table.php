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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * This file defines the quiz responsedownload table for first or all tries at a question.
 *
 * @package   responsedownload
 * @copyright 2020 Ostfalia University of Applied Sciences
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/responsedownload/classes/first_or_all_responses_table.php');

/**
 * This is a table subclass for the quiz responsedownload report, showing first or all tries.
 *
 * @package   responsedownload
 * @copyright 2020 Ostfalia University of Applied Sciences
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_responsedownload_first_or_all_responses_table extends quiz_first_or_all_responses_table  {

    protected function field_from_extra_data($tablerow, $slot, $field) {
        $questionattempt = $this->get_question_attempt($tablerow->usageid, $slot);
        if ($field == 'response') {
            return $this->response_value($tablerow, $slot);
        } else {
            return parent::field_from_extra_data($tablerow, $slot, $field);
        }
    }
}


