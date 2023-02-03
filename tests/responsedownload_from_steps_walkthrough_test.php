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

namespace quiz_responses;

use question_bank;
use quiz_attempt;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/tests/attempt_walkthrough_from_csv_test.php');
require_once($CFG->dirroot . '/mod/quiz/report/default.php');
require_once($CFG->dirroot . '/mod/quiz/report/statistics/report.php');
require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');
require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/responsedownload/report.php');


/**
 * Quiz attempt walk through using data from csv file.
 *
 * @package    quiz_responses
 * @category   test
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responsedownload_from_steps_walkthrough_test extends \mod_quiz\attempt_walkthrough_from_csv_test {
    protected function get_full_path_of_csv_file($setname, $test) {
        // Overridden here so that __DIR__ points to the path of this file.
        return  __DIR__."/fixtures/{$setname}{$test}.csv";
    }

    protected $files = array('questions', 'steps', 'responses');
    protected $users = [];

    /**
     * Helper method: Store a test file with a given name and contents in a
     * draft file area.
     *
     * @param int $context context.
     * @param int $draftitemid draft item id.
     * @param string $contents file contents.
     * @param string $filename filename.
     */
    protected function upload_file($context, $draftitemid, $contents, $filename) {
        $fs = get_file_storage();

        $filerecord = new \stdClass();
        $filerecord->contextid = $context->id;
        $filerecord->component = 'user';
        $filerecord->filearea = 'draft';
        $filerecord->itemid = $draftitemid;
        $filerecord->filepath = '/';
        $filerecord->filename = $filename;

        // print_r($filerecord);
        $fs->create_file_from_string($filerecord, $contents);
        return $draftitemid;
    }

    /**
     * @param $steps array the step data from the csv file.
     * @return array attempt no as in csv file => the id of the quiz_attempt as stored in the db.
     */
    protected function my_walkthrough_attempts($steps) {
        global $DB;
        $attemptids = array();
        foreach ($steps as $steprow) {

            $step = $this->explode_dot_separated_keys_to_make_subindexs($steprow);
            // Find existing user or make a new user to do the quiz.
            $username = array('firstname' => $step['firstname'],
                'lastname'  => $step['lastname']);

            if (!$user = $DB->get_record('user', $username)) {
                $user = $this->getDataGenerator()->create_user($username);
                $this->users[$user->id] = $user;
            }

            global $USER;
            // Change user.
            $USER = $user;

            if (!isset($attemptids[$step['quizattempt']])) {
                // Start the attempt.
                $quizobj = \quiz::create($this->quiz->id, $user->id);
                if ($quizobj->has_questions()) {
                    $quizobj->load_questions();
                }
                $this->slots = [];
                foreach ($quizobj->get_questions() as $question) {
                    $this->slots[$question->slot] = $question;
                }

                $usercontext = \context_user::instance($user->id);
                foreach ($step['responses'] as $slot => &$response) { // slot or question??
                    $type = $this->slots[$slot]->qtype;
                    switch ($type) {
                        case 'proforma':
                            // Check for filepicker and explorer
                            switch ($this->slots[$slot]->options->responseformat) {
                                case 'editor':
                                    break;
                                case 'filepicker':
                                case 'explorer':
                                    $attachementsdraftid = file_get_unused_draft_itemid();
                                    $response['attachments'] = $this->upload_file($usercontext
                                        /*$quizobj->get_context()*/, $attachementsdraftid, $response['answer'],
                                        'response_' . $user->id. '_' . $slot . '.java');
                                    unset($response['answer']);
                                    break;
                                default:
                                    throw new \coding_exception('invalid proforma subtype ' . $this->slots[$slot]->options->responseformat);
                            }
                            break;
                        case 'essay':
                            $response['answerformat'] = FORMAT_PLAIN;
                            break;
                    }
                }


                $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', /* $usercontext*/ $quizobj->get_context());
                $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

                $prevattempts = quiz_get_user_attempts($this->quiz->id, $user->id, 'all', true);
                $attemptnumber = count($prevattempts) + 1;
                $timenow = time();
                $attempt = quiz_create_attempt($quizobj, $attemptnumber, false, $timenow, false, $user->id);
                // Select variant and / or random sub question.
                if (!isset($step['variants'])) {
                    $step['variants'] = array();
                }
                if (isset($step['randqs'])) {
                    // Replace 'names' with ids.
                    foreach ($step['randqs'] as $slotno => $randqname) {
                        $step['randqs'][$slotno] = $this->randqids[$slotno][$randqname];
                    }
                } else {
                    $step['randqs'] = array();
                }

                quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow, $step['randqs'], $step['variants']);
                quiz_attempt_save_started($quizobj, $quba, $attempt);
                // \question_engine::save_questions_usage_by_activity($quba);
                $attemptid = $attemptids[$step['quizattempt']] = $attempt->id;
            } else {
                $attemptid = $attemptids[$step['quizattempt']];
            }



            // Process some responses from the student.
            $attemptobj = quiz_attempt::create($attemptid);
            $attemptobj->process_submitted_actions($timenow, false, $step['responses']);

            // Finish the attempt.
            if (!isset($step['finished']) || ($step['finished'] == 1)) {
                $attemptobj = quiz_attempt::create($attemptid);
                $attemptobj->process_finish($timenow, false);
            }
        }
        return $attemptids;
    }

    /**
     * Create a quiz add questions to it, walk through quiz attempts and then check results.
     *
     * @param array $quizsettings settings to override default settings for quiz created by generator. Taken from quizzes.csv.
     * @param array $csvdata of data read from csv file "questionsXX.csv", "stepsXX.csv" and "responsesXX.csv".
     * @dataProvider get_data_for_walkthrough
     */
    public function test_walkthrough_from_csv($quizsettings, $csvdata) {

        $this->resetAfterTest(true);
        question_bank::get_qtype('random')->clear_caches_before_testing();

        $this->create_quiz($quizsettings, $csvdata['questions']);

        $quizattemptids = $this->my_walkthrough_attempts($csvdata['steps']);

        foreach ($csvdata['responses'] as $responsesfromcsv) {
            $responses = $this->explode_dot_separated_keys_to_make_subindexs($responsesfromcsv);

            if (!isset($quizattemptids[$responses['quizattempt']])) {
                throw new \coding_exception("There is no quizattempt {$responses['quizattempt']}!");
            }
            $this->assert_response_test($quizattemptids[$responses['quizattempt']], $responses);
        }

        // Prepare check.
        $report = new \quiz_responsedownload_report();
        // call of protected method $report->download_proforma_submissions
        $r = new \ReflectionMethod('\quiz_responsedownload_report', 'create_table');
        $r->setAccessible(true);
        $init = new \ReflectionMethod('\quiz_responsedownload_report', 'init');
        $init->setAccessible(true);
        global $DB;
        $course = $DB->get_record('course', array('id' => $this->quiz->course));
        $cm = \get_coursemodule_from_instance("quiz", $this->quiz->id, $course->id);
        $context = \context_module::instance($cm->id);

        // Possible combinations.
        $showqtexts = [
            1,
//            0
        ];
        $attempts = [
            \quiz_attempts_report::ALL_WITH,
//            \quiz_attempts_report::ENROLLED_WITH,
//            \quiz_attempts_report::ENROLLED_WITHOUT,
//            \quiz_attempts_report::ENROLLED_ALL,
        ];
        $whichtries = [
            \question_attempt::LAST_TRY,
//            \question_attempt::FIRST_TRY,
//            \question_attempt::ALL_TRIES
        ];
        foreach ($whichtries as $whichtry) {
            foreach ($showqtexts as $showqtext) {
                foreach ($attempts as $attempt) {
                    // Default initialisation.
                    list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $init->invoke($report,
                        'responsedownload', 'quiz_responsedownload_settings_form', $this->quiz, $cm, $course);

                    $qmsubselect = quiz_report_qm_filter_select($this->quiz);

                    $options = new \quiz_responsedownload_options('responsedownload', $this->quiz, $cm, $course);
                    $options->attempts = $attempt;
                    $options->showqtext = $showqtext;
                    $options->whichtries = $whichtry;
                    $options->download = false;

                    if ($options->whichtries === \question_attempt::LAST_TRY) {
                        $tableclassname = 'quiz_responsedownload_last_responses_table';
                    } else {
                        $tableclassname = 'quiz_responsedownload_first_or_all_responses_table';
                    }

                    // Load the required questions.
                    $questions = $report->load_fullquestions($this->quiz);

                    $table = new $tableclassname($this->quiz, $context, $qmsubselect,
                        $options, $groupstudentsjoins, $studentsjoins, $questions, $options->get_url());
                    // Create zip.
                    ob_start();
                    $r->invoke($report, $table, $questions, $this->quiz, $options, $allowedjoins);
                    $output = ob_get_contents();
                    ob_end_clean();
                    $this->checkHtml($output, $csvdata, $options);
                    break;
                }
            }
        }
    }

    protected function checkHtml($output, $csvdata, $options): void {
        // print_r($output);
        $doc = new \DOMDocument();
        $doc->loadHTML($output);
        $xpath = new \DOMXpath($doc);

        // Evaluate header columns.
        $headercol = $xpath->query("//table[@id='responses']/thead/tr/th/a[@data-sortby]");
        $header = [];
        $counter = 0;
        foreach ($headercol as $col) {
            switch ($col->getAttribute('data-sortby')) {
                case 'firstname':
                    $header[$counter] = 'name';
                    $counter++;
                    break;
                case 'lastname':
                    break;
                default:
                    $header[$counter] = $col->getAttribute('data-sortby');
                    $counter++;
                    break;
            }
        }

        $this->assertNotEquals(0, count($header));
        // print_r($header);

        // Evaluate body fields.
        $body = $xpath->query("//table[@id='responses']/tbody/tr");
        $rows = [];
        foreach ($body as $bodyrow) {
            $row = [];
            foreach ($bodyrow->childNodes as $child) {
                $classes = explode(' ', $child->getAttribute('class'));
                $col = array_filter($classes, function($x) { return str_starts_with($x, 'c') and $x != 'cell'; });
                $this->assertEquals(1, count($col));
                $col = reset($col);
                $col = substr($col, 1);
                $content = $child->textContent;
                $pos = strpos($content, 'Review attempt');
                if ($pos !== false) {
                    $content = substr($content, 0, $pos);
                }
                $row[$col] = trim($content);
            }
            // if ($row[1] != ' ' and $row[1] != '') {
            // There are empty rows at the end.
            $rows[] = $row;
            // }
        }
        $this->assertNotEquals(0, count($rows));
        // var_dump($rows);

        // Check all question texts if available:
        $options->questionindex = [];
        if ($options->showqtext) {
            foreach ($header as $colindex => $headercol) {
                if (preg_match('/question(\d+)/i', $headercol, $matches)) {
                    $options->questionindex[$matches[1]] = $colindex;
                }
            }
        }

        foreach ($csvdata['steps'] as $stepsfromcsv) {
            $steps = $this->explode_dot_separated_keys_to_make_subindexs($stepsfromcsv);
            print_r($steps);
            $this->assertTrue($this->find_responses($steps, $rows, $options, $header));
        }
    }

    protected function find_responses($steps, $rows, $options, $header) {
        $name = $steps['firstname'] . ' ' . $steps['lastname'];



        foreach ($rows as $row) {
            // Check user name.
            if ($row[1] != $name) {
                continue;
            }

            // Check question text if available
            if ($options->showqtext) {
                foreach ($options->questionindex as $slot => $qindex) {
                    // Convert German Umlauts.
                    $questiontext = mb_convert_encoding($row[$qindex], 'ISO-8859-1', 'UTF-8');
                    $this->assertEquals($this->slots[$slot]->questiontext, $questiontext);
                }
            }

            // Check all responses:
            foreach ($steps['responses'] as $index => $response) {
                $found = false;
                switch ($this->slots[$index]->options->responseformat) {
                    case 'editor':
                        foreach ($row as $col) {
                            if ($col != $response['answer']) {
                                continue;
                            }
                            $found = true;
                            break;
                        }
                        break;
                    case 'filepicker':
                    case 'explorer':
                        foreach ($row as $colindex => $col) {
                            if (strpos($col, 'Files:') === false) {
                                continue;
                            }
                            // Check if response belongs to user.
                            $pattern = "/Files: response_(\d+)_" . $index . ".java/i";
                            $matches = [];
                            if (!preg_match($pattern, $col, $matches)) {
/*                                echo 'File does not match:' . PHP_EOL;
                                var_dump($col);
                                var_dump($pattern);*/
                                continue;
                            }
                            $this->assertEquals(1, preg_match($pattern, $col, $matches));

                            $user = $this->users[$matches[1]];
                            $this->assertEquals($steps['lastname'], $user->lastname);
                            $this->assertEquals($steps['firstname'], $user->firstname);
                            $headercol = $header[$colindex];
                            $this->assertEquals(1, preg_match('/response(\d+)/i', $headercol, $matches));
                            $this->assertEquals($matches[1], $index);
                            $found = true;
                            break;
                        }
                        break;
                    default:
                        throw new \coding_exception('invalid proforma subtype ' .
                            $this->slots[$index]->options->responseformat);
                }
                $this->assertTrue($found);
            }
            return true;
        }

        return false;
    }


    protected function assert_response_test($quizattemptid, $responses) {
        $quizattempt = quiz_attempt::create($quizattemptid);

        foreach ($responses['slot'] as $slot => $tests) {
            $slothastests = false;
            foreach ($tests as $test) {
                if ('' !== $test) {
                    $slothastests = true;
                }
            }
            if (!$slothastests) {
                continue;
            }
            $qa = $quizattempt->get_question_attempt($slot);
            $stepswithsubmit = $qa->get_steps_with_submitted_response_iterator();
            $step = $stepswithsubmit[$responses['submittedstepno']];
            if (null === $step) {
                throw new \coding_exception("There is no step no {$responses['submittedstepno']} ".
                                           "for slot $slot in quizattempt {$responses['quizattempt']}!");
            }
            foreach (array('responsesummary', 'fraction', 'state') as $column) {
                if (isset($tests[$column]) && $tests[$column] != '') {
                    switch($column) {
                        case 'responsesummary' :
                            $actual = $qa->get_question()->summarise_response($step->get_qt_data());
                            break;
                        case 'fraction' :
                            if (count($stepswithsubmit) == $responses['submittedstepno']) {
                                // If this is the last step then we need to look at the fraction after the question has been
                                // finished.
                                $actual = $qa->get_fraction();
                            } else {
                                $actual = $step->get_fraction();
                            }
                           break;
                        case 'state' :
                            if (count($stepswithsubmit) == $responses['submittedstepno']) {
                                // If this is the last step then we need to look at the state after the question has been
                                // finished.
                                $state = $qa->get_state();
                            } else {
                                $state = $step->get_state();
                            }
                            $actual = substr(get_class($state), strlen('question_state_'));
                    }
                    $expected = $tests[$column];
                    $failuremessage = "Error in  quizattempt {$responses['quizattempt']} in $column, slot $slot, ".
                    "submittedstepno {$responses['submittedstepno']}";
                    $this->assertEquals($expected, $actual, $failuremessage);
                }
            }
        }
    }
}
