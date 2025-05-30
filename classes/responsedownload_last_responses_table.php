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
 * This file defines the quiz responsedownload table.
 *
 * @package   quiz_responsedownload
 * @copyright 2020 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport_table.php');
require_once($CFG->dirroot . '/mod/quiz/report/responsedownload/classes/dataformat_zip_writer.php');
require_once($CFG->dirroot . '/mod/quiz/report/responsedownload/classes/table_zip_export_format.php');
require_once($CFG->dirroot . '/mod/quiz/report/responsedownload/classes/last_responses_table.php');


/**
 * Modified version of question_attempt_steps_with_submitted_response_2_iterator.
 *
 * @copyright  modification: 2020 Ostfalia
 *             original class: 2014 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_attempt_steps_with_submitted_response_2_iterator extends question_attempt_steps_with_submitted_response_iterator {
    /**
     * checks if there is actual data within this data (no data starting with _).
     * This is relevant to detect a last step with data without pressing
     * the submit button.
     *
     * @param type $qtdata
     */
    protected function has_actual_data($qtdata) {
        foreach ($qtdata as $key => $value) {
            if ($key[0] != '_') {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the step nos  in which a student has submitted a response. Including any step with a response that is saved before
     * the question attempt finishes.
     *
     * Called from constructor, should not be called from elsewhere.
     *
     */
    protected function find_steps_with_submitted_response() {
        $stepnos = array();
        $lastsavedstep = null;
        foreach ($this->qa->get_step_iterator() as $stepno => $step) {
            if ($this->qa->get_behaviour()->step_has_a_submitted_response($step)) {
                $stepnos[] = $stepno;
                $lastsavedstep = null;
            } else {
                $qtdata = $step->get_qt_data();
                // Use different method in order to detect if
                // we have a last saved step.
                // Original condition: count($qtdata) > 0.
                if ($this->has_actual_data($qtdata)) {
                    $lastsavedstep = $stepno;
                }
            }
        }

        if (!is_null($lastsavedstep)) {
            $stepnos[] = $lastsavedstep;
        }
        if (empty($stepnos)) {
            $this->stepswithsubmittedresponses = array();
        } else {
            // Re-index array so index starts with 1.
            $this->stepswithsubmittedresponses = array_combine(range(1, count($stepnos)), $stepnos);
        }
    }
}

/**
 * This is a table subclass for downloading the responses.
 * It is derived from quiz_last_responses_table and add special handling for
 * the response column.
 *
 * @package   responsedownload
 * @copyright  2020 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_responsedownload_last_responses_table extends quiz_last_responses_table {

    /* usageid of last attempt */
    protected $lastusageid = null;
    /* last question usage (cache)*/
    protected $lastquba = null;

    /**
     * Constructor.
     *
     * @param $quiz
     * @param $context
     * @param $qmsubselect
     * @param quiz_responsedownload_options $options
     * @param \core\dml\sql_join $groupstudentsjoins
     * @param \core\dml\sql_join $studentsjoins
     * @param $questions
     * @param $reporturl
     */
    public function __construct($quiz, $context, $qmsubselect, quiz_responsedownload_options $options,
            \core\dml\sql_join $groupstudentsjoins, \core\dml\sql_join $studentsjoins, $questions, $reporturl) {
        quiz_attempts_report_table::__construct('mod-quiz-responsedownload', $quiz, $context,
                $qmsubselect, $options, $groupstudentsjoins, $studentsjoins, $questions, $reporturl);
    }

    public function build_table() {
        $this->lastusageid = null;
        $this->lastquba = null;

        if (!$this->rawdata) {
            return;
        }
        // Set references needed by export class.
        if (isset($this->exportclass)) {
            $this->exportclass->set_db_columns($this->columns);
            $this->exportclass->set_table_object($this);
        }

        $this->strtimeformat = '%F-%H-%M';
        quiz_attempts_report_table::build_table();
    }



    public function data_col($slot, $field, $attempt) {
        if ($attempt->usageid == 0) {
            return '-';
        }

        if ($field == 'response') {
            // New: special handling for response column.
            list ($editortext,  $files) = $this->field_from_extra_data($attempt, $slot, $field);
            if ($this->is_downloading()) {
                // Pass to writer.
                return array($editortext,  $files);
            } else {
                $output = '';
                if (isset($files) and count($files) > 0) {
                    // Display filenames.
                    $output = '<i>Files: ';
                    foreach ($files as $zipfilepath => $storedfile) {
                        $output .= $storedfile->get_filename() . ' ';
                    }
                    $output .= '</i><br>';
                }
                if (strlen($editortext) > 150) {
                    // If text is long than only show beginning of text.
                    $output .= substr($editortext, 0, 150) . '...';
                } else {
                    $output .= $editortext;
                }
                return $output;
            }
        } else {
            return parent::data_col($slot, $field, $attempt);
        }
    }

    /**
     * Column text from the extra data loaded in load_extra_data(), before html formatting etc.
     *
     * @param object $attempt
     * @param int $slot
     * @param string $field
     * @return string
     */
    protected function field_from_extra_data($attempt, $slot, $field) {
        if (!isset($this->lateststeps[$attempt->usageid][$slot])) {
            // New: special handling for response column.
            if ($field == 'response') {
                // Special handling for response.
                return array('', array());
            } else {
                return '-';
            }
        }
        $stepdata = $this->lateststeps[$attempt->usageid][$slot];

        if (property_exists($stepdata, $field . 'full')) {
            $value = $stepdata->{$field . 'full'};
        } else {
            // New: special handling for response column.
            if ($field == 'response') {
                // Special handling for response.
                return $this->response_value($attempt, $slot);
            } else {
                $value = $stepdata->$field;
            }
        }
        return $value;
    }

    /**
     * handle response column
     *
     * @param type $colname
     * @param type $attempt
     * @return type
     */
    public function other_cols($colname, $attempt) {
        if (preg_match('/^response(\d+)$/', $colname, $matches)) {
            // New: handle response.
            return $this->data_col($matches[1], 'response', $attempt);
        } else {
            return parent::other_cols($colname, $attempt);
        }
    }

    /**
     * same as 
     * question_engine_data_mapper::load_questions_usage_by_activity 
     * but without actually reading feedback which may allocate too much memory.
     * 
     * @global type $DB
     * @param type $qubaid
     * @return type
     * @throws coding_exception
     */
    protected function my_load_questions_usage_by_activity($qubaid) {
        GLOBAL $DB;
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
    quba.id = :qubaid

ORDER BY
    qa.slot,
    qas.sequencenumber
    ", array('qubaid' => $qubaid));

        if (!$records->valid()) {
            throw new coding_exception('Failed to load questions_usage_by_activity ' . $qubaid);
        }

        $quba = question_usage_by_activity::load_from_records($records, $qubaid);
        $records->close();

        return $quba;
    }    
    
    /**
     * retrieves the student response from editor or file upload
     * @param type $attempt
     * @param type $slot
     * @return type
     */
    protected function response_value($attempt, $slot) {
        // Use array with qt data keys to look for in order
        // to be able to extend keys in future.
        // Assume only one key in each array is used per question.
        $answerkeys = array('answer');
        $attachmentkeys = array('attachments');

        // Get question usage.
        if ($attempt->usageid == $this->lastusageid) {
            // Get cached question usage.
            $quba = $this->lastquba;
        } else {       
            unset($this->lastquba);       
            // Get question usage from database.
            $quba = $this->my_load_questions_usage_by_activity($attempt->usageid);
            $this->lastusageid = $attempt->usageid;
            $this->lastquba = $quba;
        }
        $qubacontextid = $quba->get_owning_context()->id;
        $qa = $quba->get_question_attempt($slot);
        unset($quba);

        // Preset return values.
        $files = array();
        $editortext = null;
        if (isset($attempt->try)) {
            // All tries or first try:
            // We have to check the try data.
            $submissionsteps = new question_attempt_steps_with_submitted_response_2_iterator($qa);
            $step = $submissionsteps[$attempt->try];
            if ($step === null) {
                return array ($editortext, $files);
            }
            $qtdata = $step->get_qt_data();
            foreach ($answerkeys as $key) {
                if (isset($qtdata[$key])) {
                    $answer = $qtdata[$key];
                }
            }
            foreach ($attachmentkeys as $key) {
                if (isset($qtdata[$key])) {
                    $files = $step->get_qt_files($key, $qubacontextid);
                }
            }
        } else {
            // Only use last try.
            foreach ($answerkeys as $key) {
                $answer = $qa->get_last_qt_var($key);
            }
            foreach ($attachmentkeys as $key) {
                $files = $qa->get_last_qt_files($key, $qubacontextid);
            }
        }
        // Get text from editor.
        if (isset($answer)) {
            if (is_string($answer)) {
                $editortext = $answer;
            } else if (get_class($answer) == 'question_file_loader') {
                $editortext = $answer->__toString();
            } else {
                debugging(get_class($answer));
                $editortext = $answer;
            }
        }

        unset($qa);
        // Force garbage collector to run because this function allocates a lot of memory.
        gc_collect_cycles();
        return array ($editortext, $files);
    }

    /**
     * Special version for download button:
     * only display zip as choice option.
     * Note that the download button should bee a secondary button (at first you need
     * to load the report)
     */
    public function download_buttons() {
        global $OUTPUT;

        if ($this->is_downloadable() && !$this->is_downloading()) {
            $label = get_string('downloadas', 'table');
            $hiddenparams = array();
            foreach ($this->baseurl->params() as $key => $value) {
                $hiddenparams[] = array(
                        'name' => $key,
                        'value' => $value,
                );
            }
            $data = array(
                'label' => $label,
                'base' => $this->baseurl->out_omit_querystring(),
                'name' => 'download',
                'params' => $hiddenparams,
                'options' => [[
                        'name' => 'zip',
                        'label' => 'zip'
                ]],
                'sesskey' => sesskey(),
                'submit' => get_string('download'),
            );

            return $OUTPUT->render_from_template('core/dataformat_selector', $data);

        } else {
            return '';
        }
    }

    public function get_options() {
        return $this->options;
    }
    public function get_questions() {
        return $this->questions;
    }

    /**
     * Get, and optionally set, the export class.
     * @param $exportclass (optional) if passed, set the table to use this export class.
     * @return table_default_export_format_parent the export class in use (after any set).
     */
    public function export_class_instance($exportclass = null) {
        if (!is_null($exportclass)) {
            $this->started_output = true;
            $this->exportclass = $exportclass;
            $this->exportclass->table = $this;
        } else if (is_null($this->exportclass) && !empty($this->download)) {
            // Change table format class.
            $this->exportclass = new table_zip_export_format($this, $this->download);
            if (!$this->exportclass->document_started()) {
                $this->exportclass->start_document($this->filename, $this->sheettitle);
            }
        }
        return $this->exportclass;
    }

    function finish_output($closeexportclassdoc = true) {
        if (!defined('UNITTEST_IS_RUNNING') or !$this->is_downloading()) {
            parent::finish_output($closeexportclassdoc);
        } else {
            if ($this->exportclass!==null) {
                $this->exportclass->finish_table();
                if ($closeexportclassdoc) {
                    $writer = $this->exportclass;

                    $reflection = new ReflectionClass($writer);
                    $property = $reflection->getProperty('dataformat');
                    $property->setAccessible(true);
                    $val = $property->getValue($writer);

                    $val->close_output();
                }
            }
        }
    }
}

