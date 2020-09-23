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
 * This file defines the quiz proforma responses table.
 *
 * @package   proformasubmexport
 * @copyright 2008 Jean-Michel Vedrine, 2020 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport_table.php');
require_once($CFG->dirroot . '/mod/quiz/report/proformasubmexport/classes/dataformat_zip_writer.php');
require_once($CFG->dirroot . '/mod/quiz/report/proformasubmexport/classes/table_zip_export_format.php');


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
                if ($this->has_actual_data($qtdata)) { // { count($qtdata)) {
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
 * This is a table subclass for downloading the proforma responses.
 * It is a copy of the class quiz_last_responses_table from Jean-Michel Vedrine
 * with some adaptations due to proforma question options.
 *
 * @package   proformasubmexport
 * @copyright  2008 Jean-Michel Vedrine, 2020 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_proforma_last_responses_table extends quiz_attempts_report_table {

    /**
     * The full question usage object for each try shown in report.
     *
     * @var question_usage_by_activity[]
     */
    private $questionusagesbyactivity;

    /**
     * Constructor.
     *
     * @param $quiz
     * @param $context
     * @param $qmsubselect
     * @param quiz_proforma_options $options
     * @param \core\dml\sql_join $groupstudentsjoins
     * @param \core\dml\sql_join $studentsjoins
     * @param $questions
     * @param $reporturl
     */
    public function __construct($quiz, $context, $qmsubselect, quiz_proforma_options $options,
            \core\dml\sql_join $groupstudentsjoins, \core\dml\sql_join $studentsjoins, $questions, $reporturl) {
        parent::__construct('mod-quiz-report-proforma-submission-export', $quiz, $context,
                $qmsubselect, $options, $groupstudentsjoins, $studentsjoins, $questions, $reporturl);
    }

    public function build_table() {
        if (!$this->rawdata) {
            return;
        }

        // $result1 = xdebug_start_trace('xdebugtrace_build_table', 2);
        
        $this->strtimeformat = str_replace(',', ' ', get_string('strftimedatetime'));
        // New (proforma): set references needed by export class.
        if (isset($this->exportclass)) {
            $this->exportclass->set_db_columns($this->columns);
            $this->exportclass->set_table_object($this);
        }
        parent::build_table();
        // $result2 = xdebug_stop_trace();
    }

    public function col_sumgrades($attempt) {
        if ($attempt->state != quiz_attempt::FINISHED) {
            return '-';
        }

        $grade = quiz_rescale_grade($attempt->sumgrades, $this->quiz);
        if ($this->is_downloading()) {
            return $grade;
        }

        $gradehtml = '<a href="review.php?q=' . $this->quiz->id . '&amp;attempt=' .
                $attempt->attempt . '">' . $grade . '</a>';
        return $gradehtml;
    }


    public function data_col($slot, $field, $attempt) {
        if ($attempt->usageid == 0) {
            return '-';
        }

        if ($field == 'response') {
            // New (proforma): special handling for response column.
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
                if (strlen($editortext) > 300) {
                    // If text is long than only show beginning of text.
                    $output .= substr($editortext, 0, 300) . '...';
                } else {
                    $output .= $editortext;
                }
                return $output;
            }
        } else {
            $value = $this->field_from_extra_data($attempt, $slot, $field);
        }

        if (is_null($value)) {
            $summary = '-';
        } else {
            $summary = trim($value);
        }

        if ($this->is_downloading() && $this->is_downloading() != 'html') {
            return $summary;
        }
        $summary = s($summary);

        if ($this->is_downloading() || $field != 'responsesummary') {
            return $summary;
        }

        return $this->make_review_link($summary, $attempt, $slot);
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
            // New (proforma): special handling for response column.
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
            // New (proforma): special handling for response column.
            if ($field == 'response') {
                // Special handling for response.
                return $this->response_value($attempt, $slot);
            } else {
                $value = $stepdata->$field;
            }
        }
        return $value;
    }

    public function other_cols($colname, $attempt) {
        if (preg_match('/^question(\d+)$/', $colname, $matches)) {
            return $this->data_col($matches[1], 'questionsummary', $attempt);

        } else if (preg_match('/^response(\d+)$/', $colname, $matches)) {
           // New (proforma): resturn response instead of response summary..
           return $this->data_col($matches[1], 'response', $attempt);

        } else if (preg_match('/^right(\d+)$/', $colname, $matches)) {
            return $this->data_col($matches[1], 'rightanswer', $attempt);

        } else {
            return null;
        }
    }

    protected function requires_extra_data() {
        return true;
    }

    protected function is_latest_step_column($column) {
        if (preg_match('/^(?:question|response|right)([0-9]+)/', $column, $matches)) {
            return $matches[1];
        }
        return false;
    }

    /**
     * Get any fields that might be needed when sorting on date for a particular slot.
     * @param int    $slot  the slot for the column we want.
     * @param string $alias the table alias for latest state information relating to that slot.
     * @return string sql fragment to alias fields.
     */
    protected function get_required_latest_state_fields($slot, $alias) {
        global $DB;
        $sortableresponse = $DB->sql_order_by_text("{$alias}.questionsummary");
        if ($sortableresponse === "{$alias}.questionsummary") {
            // Can just order by text columns. No complexity needed.
            return "{$alias}.questionsummary AS question{$slot},
                    {$alias}.rightanswer AS right{$slot},
                    {$alias}.responsesummary AS response{$slot}";
        } else {
            // Work-around required.
            return $DB->sql_order_by_text("{$alias}.questionsummary") . " AS question{$slot},
                    {$alias}.questionsummary AS question{$slot}full,
                    " . $DB->sql_order_by_text("{$alias}.rightanswer") . " AS right{$slot},
                    {$alias}.rightanswer AS right{$slot}full,
                    " . $DB->sql_order_by_text("{$alias}.responsesummary") . " AS response{$slot},
                    {$alias}.responsesummary AS response{$slot}full";
        }
    }


    // New functions.

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

        // TODO: Try and use already fetched data! Do not read once more!
        // Get question attempt.
        $dm = new question_engine_data_mapper();
        $quba = $dm->load_questions_usage_by_activity($attempt->usageid); // qubaid);
        $quba_contextid = $quba->get_owning_context()->id;
        // nur Zugriff!
        // $quba = $this->lateststeps[$attempt->usageid];
        $qa = $quba->get_question_attempt($slot);
        unset($quba);

        // Preset return values.
        $files = array();
        $editortext = null;
        if (isset($attempt->try)) {
            // We have to check the try data.
            $submissionsteps = new question_attempt_steps_with_submitted_response_2_iterator($qa);
            // $submissionsteps = $qa->get_steps_with_submitted_response_iterator();
            $step = $submissionsteps[$attempt->try];
            if ($step === null) {
                return array ($editortext, $files);
            }
            $qtdata = $step->get_qt_data();
            foreach($answerkeys as $key) {
                if (isset($qtdata[$key])) {
                    $answer = $qtdata[$key];
                }                
            }
            foreach($attachmentkeys as $key) {            
                if (isset($qtdata[$key])) {
                    $var_attachments = $qtdata[$key];
                    $files = $step->get_qt_files($key, $quba_contextid);
                }
            }
        } else {
            // Only use last try.
            foreach($answerkeys as $key) {            
                $answer = $qa->get_last_qt_var($key);
            }
            foreach($attachmentkeys as $key) {                
                $var_attachments = $qa->get_last_qt_var($key);
                $files = $qa->get_last_qt_files($key, $quba_contextid);
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

        /*
        // Get file attachements.
        // Check if attachments are allowed as response.
        $response_file_areas = $qa->get_question()->qtype->response_file_areas();
        $has_responsefilearea_attachments = in_array(ATTACHMENTS, $response_file_areas);

        // Check if attempt has submitted any attachment.
        $has_submitted_attachments = (isset($var_attachments));

        // Get files.
        if ($has_responsefilearea_attachments && $has_submitted_attachments) {
            $quba_contextid = $quba->get_owning_context()->id;
            $files = $qa->get_last_qt_files(ATTACHMENTS, $quba_contextid);
        }
        */
        

        unset($qa);
        // Force garbage collector to work because this function allocates a lot of memory.
        gc_collect_cycles();
        return array ($editortext, $files);
    }

    /**
     * Special version for download button:
     * only display zip as choice option.
     * Note that the download button should bee a secondary button (at first you need
     * to load the report)
     *
     */
    public function download_buttons() {
        global $OUTPUT;
        //return $OUTPUT->download_dataformat_selector('KARIN', // get_string('downloadas', 'table'),
        //    $this->baseurl->out_omit_querystring(), 'download', $this->baseurl->params());

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
                'base' =>  $this->baseurl->out_omit_querystring(),
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
    function export_class_instance($exportclass = null) {
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

    /**
     * prefetch all questian usages in order to save memory
     *
     * @throws coding_exception
     */
    protected function load_extra_data() {
        parent::load_extra_data();
        /*
        $qubaids = $this->get_qubaids_condition();
        $dm = new question_engine_data_mapper();
        $this->questionusagesbyactivity = $dm->load_questions_usages_by_activity($qubaids);
        */
    }

    /**
     * Return the question attempt object.
     *
     * @param int $questionusagesid
     * @param int $slot
     * @return question_attempt
     */
    protected function get_question_attempt($questionusagesid, $slot) {
        return $this->questionusagesbyactivity[$questionusagesid]->get_question_attempt($slot);
    }
}

