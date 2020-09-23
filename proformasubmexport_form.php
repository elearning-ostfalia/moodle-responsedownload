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
 * This file defines the setting form for the quiz proformasubmexport report.
 *
 * @package   quiz_proformasubmexport
 * @copyright modified: 2020 Ostfalia, 
 *            base: 2008 Jean-Michel Vedrine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport_form.php');
/**
 * Quiz proformasubmexport report settings form.
 *
 * @copyright 2008 Jean-Michel Vedrine, 2020 Ostfalia, 2017 IIT Bombay
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */



class quiz_proformasubmexport_settings_form extends mod_quiz_attempts_report_form {

    protected function other_preference_fields(MoodleQuickForm $mform) {
        $mform->addElement('header', 'preferencespage',
                get_string('options', 'quiz_proformasubmexport'));

        $mform->addElement('select', 'folders',
                get_string('folderhierarchy', 'quiz_proformasubmexport'), array(
                        '1' => get_string('questionwise', 'quiz_proformasubmexport'),
                        '2' => get_string('attemptwise', 'quiz_proformasubmexport'
                        )));

        $mform->addElement('select', 'editorfilename',
                get_string('editorfilename', 'quiz_proformasubmexport'), array(
                        '1' => get_string('fix', 'quiz_proformasubmexport') . ' (' .
                                get_string('editorresponsename', 'quiz_proformasubmexport') . ')',
                        '2' => get_string('pathname', 'quiz_proformasubmexport'),
                        '3' => get_string('basename', 'quiz_proformasubmexport')
                ));

        $mform->disabledIf('qtext', 'attempts', 'eq', quiz_attempts_report::ENROLLED_WITHOUT);
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if ($data['attempts'] != quiz_attempts_report::ENROLLED_WITHOUT && !(
                $data['qtext'] /* || $data['resp'] || $data['right'] */ )) {
            $errors['coloptions'] = get_string('reportmustselectstate', 'quiz');
        }

        return $errors;
    }

    protected function other_attempt_fields(MoodleQuickForm $mform) {
        parent::other_attempt_fields($mform);
        if (quiz_allows_multiple_tries($this->_customdata['quiz'])) {
            $mform->addElement('select', 'whichtries', get_string('whichtries', 'question'), array(
                                           question_attempt::FIRST_TRY    => get_string('firsttry', 'question'),
                                           question_attempt::LAST_TRY     => get_string('lasttry', 'question'),
                                           question_attempt::ALL_TRIES    => get_string('alltries', 'question'))
            );
            $mform->setDefault('whichtries', question_attempt::LAST_TRY);
            $mform->disabledIf('whichtries', 'attempts', 'eq', quiz_attempts_report::ENROLLED_WITHOUT);
        }
        $mform->addElement('advcheckbox', 'qtext',
                get_string('include', 'quiz_proformasubmexport'),
                get_string('questiontext', 'quiz_proformasubmexport'));        
    }
}