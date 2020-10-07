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
    
    /**
     * same as 
     * question_engine_data_mapper::load_questions_usages_by_activity 
     * but without actually reading feedback which may allocate too much memory.
     *
     * @param qubaid_condition $qubaids the condition that tells us which usages to load.
     * @return question_usage_by_activity[] the usages that were loaded.
     */
    protected function my_load_questions_usages_by_activity($qubaids) {
        global $DB;
        $records = $DB->get_recordset_sql("
SELECT
    quba.id AS qubaid,
    quba.contextid,
    quba.component,
    quba.preferredbehaviour,
    qa.id AS questionattemptid,
    qa.questionusageid,
    qa.slot,
    qa.behaviour,
    qa.questionid,
    qa.variant,
    qa.maxmark,
    qa.minfraction,
    qa.maxfraction,
    qa.flagged,
    qa.questionsummary,
    qa.rightanswer,
    qa.responsesummary,
    qa.timemodified,
    qas.id AS attemptstepid,
    qas.sequencenumber,
    qas.state,
    qas.fraction,
    qas.timecreated,
    qas.userid,
    qasd.name,
    CASE 
        WHEN qasd.name ='_feedback' THEN 'XXX' 
        ELSE qasd.value
    END as value

FROM      {question_usages}            quba
LEFT JOIN {question_attempts}          qa   ON qa.questionusageid    = quba.id
LEFT JOIN {question_attempt_steps}     qas  ON qas.questionattemptid = qa.id
LEFT JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid    = qas.id

WHERE
    quba.id {$qubaids->usage_id_in()}

ORDER BY
    quba.id,
    qa.slot,
    qas.sequencenumber
    ", $qubaids->usage_id_in_params());

        $qubas = array();
        while ($records->valid()) {
            $record = $records->current();
            $qubas[$record->qubaid] = question_usage_by_activity::load_from_records($records, $record->qubaid);
        }

        $records->close();

        return $qubas;
    }

    /**
     * overridden super class version allocating less memory 
     * by not reading feedback.
     */
    protected function load_extra_data() {
        if (count($this->rawdata) === 0) {
            return;
        }
        $qubaids = $this->get_qubaids_condition();
        $this->questionusagesbyactivity = $this->my_load_questions_usages_by_activity($qubaids);

        // Insert an extra field in attempt data and extra rows where necessary.
        $newrawdata = array();
        foreach ($this->rawdata as $attempt) {
            if (!isset($this->questionusagesbyactivity[$attempt->usageid])) {
                // This is a user without attempts.
                $attempt->try = 0;
                $attempt->lasttryforallparts = true;
                $newrawdata[] = $attempt;
                continue;
            }

            // We have an attempt, which may require several rows.
            $maxtriesinanyslot = 1;
            foreach ($this->questionusagesbyactivity[$attempt->usageid]->get_slots() as $slot) {
                $tries = $this->get_no_of_tries($attempt, $slot);
                $maxtriesinanyslot = max($maxtriesinanyslot, $tries);
            }
            for ($try = 1; $try <= $maxtriesinanyslot; $try++) {
                $newtablerow = clone($attempt);
                $newtablerow->lasttryforallparts = ($try == $maxtriesinanyslot);
                if ($try !== $maxtriesinanyslot) {
                    $newtablerow->state = quiz_attempt::IN_PROGRESS;
                }
                $newtablerow->try = $try;
                $newrawdata[] = $newtablerow;
                if ($this->options->whichtries == question_attempt::FIRST_TRY) {
                    break;
                }
            }
        }
        $this->rawdata = $newrawdata;
    }
}

