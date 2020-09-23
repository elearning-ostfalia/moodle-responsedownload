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
 * Class to store the options for a {@link quiz_responses_report}.
 *
 * @package   quiz_responses
 * @copyright 2012 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport_options.php');


/**
 * Class to store the options for a {@link quiz_responses_report}.
 *
 * @copyright 2012 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_proforma_options extends mod_quiz_attempts_report_options {

    /** @var bool whether to show the question text. */
    public $showqtext = false;

    /** @var string question/student in zip file  */
    const QUESTION_WISE = '1';
    /** @var string student/question in zip file  */
    const STUDENT_WISE = '2';
    /** @var string which folder structure in zip file */
    public $folders = self::QUESTION_WISE;

    /** @var string system default filename  */
    const FIXED_NAME = '1';
    /** @var string responsefilename from question (with path) */
    const NAME_FROM_QUESTION_WITH_PATH = '2';
    /** @var string responsefilename from question (without path) */
    const NAME_FROM_QUESTION_WO_PATH  = '3';
    /** @var string which filename to use for editor response in zip file */
    public $editorfilename = self::FIXED_NAME;

    /** @var bool which try/tries to show responses from. */
    public $whichtries = question_attempt::LAST_TRY;

    protected function get_url_params() {
        $params = parent::get_url_params();
        $params['qtext']      = $this->showqtext;
        $params['folders']      = $this->folders;
        $params['editorfilename']      = $this->editorfilename;
        if (quiz_allows_multiple_tries($this->quiz)) {
            $params['whichtries'] = $this->whichtries;
        }
        return $params;
    }

    public function get_initial_form_data() {
        $toform = parent::get_initial_form_data();
        $toform->qtext      = $this->showqtext;
        $toform->folders    = $this->folders;
        $toform->editorfilename = $this->editorfilename;
        if (quiz_allows_multiple_tries($this->quiz)) {
            $toform->whichtries = $this->whichtries;
        }

        return $toform;
    }

    public function setup_from_form_data($fromform) {
        parent::setup_from_form_data($fromform);

        $this->showqtext   = $fromform->qtext;
        $this->folders     = $fromform->folders;
        $this->editorfilename = $fromform->editorfilename;
        if (isset($fromform->download)) {
            $this->download = 'zip';
        }
        if (quiz_allows_multiple_tries($this->quiz)) {
            $this->whichtries = $fromform->whichtries;
        }
    }

    public function setup_from_params() {
        parent::setup_from_params();

        $download = optional_param('download', null, PARAM_ALPHA);
        $submitbutton = optional_param('$submitbutton', null, PARAM_ALPHA);
        if (isset($download) && !isset($submitbutton)) {
            // Force download format to zip.
            $this->download   = 'zip';
        } else {
            $this->download = '';
        }

        $this->showqtext = optional_param('qtext', $this->showqtext, PARAM_BOOL);
        $this->folders   = optional_param('folders', $this->folders, PARAM_ALPHANUM);
        $this->editorfilename = optional_param('editorfilename', $this->editorfilename, PARAM_ALPHANUM);
        if (quiz_allows_multiple_tries($this->quiz)) {
            $this->whichtries    = optional_param('whichtries', $this->whichtries, PARAM_ALPHA);
        }
    }

    public function setup_from_user_preferences() {
        parent::setup_from_user_preferences();

        $this->showqtext   = get_user_preferences('quiz_report_proformasubmexport_qtext', $this->showqtext);
        $this->folders     = get_user_preferences('quiz_report_proformasubmexport_folders', $this->folders);
        $this->editorfilename = get_user_preferences('quiz_report_proformasubmexport_editorfilename', $this->editorfilename);
        if (quiz_allows_multiple_tries($this->quiz)) {
            $this->whichtries    = get_user_preferences('quiz_report_proformasubmexport_which_tries', $this->whichtries);
        }
    }

    public function update_user_preferences() {
        parent::update_user_preferences();

        set_user_preference('quiz_report_proformasubmexport_qtext', $this->showqtext);
        set_user_preference('quiz_report_proformasubmexport_folders', $this->folders);
        set_user_preference('quiz_report_proformasubmexport_editorfilename', $this->editorfilename);
        if (quiz_allows_multiple_tries($this->quiz)) {
            set_user_preference('quiz_report_proformasubmexport_which_tries', $this->whichtries);
        }
    }

    public function resolve_dependencies() {
        parent::resolve_dependencies();
        // Nothing to be done?
    }
}
