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
 * Zip data format writer for proforma responses
 *
 * @package   proformasubmexport
 * @copyright  2020 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// require_once($CFG->dirroot . '/mod/quiz/report/proformasubmexport/classes/proforma_options.php');

/**
 * Zip data format writer for proforma responses
 *
 * @package   proformasubmexport
 * @copyright  2020 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dataformat_zip_writer extends \core\dataformat\base {

    /** @var $mimetype */
    public $mimetype = "application/zip";

    /** @var $extension */
    public $extension = ".zip";

    /** @var string response filename  */
    protected $responsefilename = 'editorresponse.txt';

    /** @var $zipper zip_archive object  */
    protected $ziparch = null;

    protected $ignoreinvalidfiles = true;
    protected $abort = false;


    /** @var null database column names */
    protected $columns = null;
    /** @var null table object to get data from */
    protected $table = null;

    public function __construct() {
        $this->ziparch = new zip_archive();
    }

    /**
     * store database column names
     * @param $columns
     */
    public function set_columns($columns) {
        $this->columns = $columns;
    }

    /**
     * store table object (reference)
     * @param $table
     */
    public function set_table(/*quiz_proforma_last_responses_table*/ $table) {
        $this->table = $table;
    }

    /**
     * Write the start of the file.
     */
    public function start_output() {
        if (!$this->ziparch->open($this->filename, file_archive::OVERWRITE)) {
            debugging("Can not open zip file", DEBUG_DEVELOPER);
            $this->abort = true;
        } else {
            $this->abort = false;
        }
    }

    /**
     * write stored file to zip archive
     * @param type $ziparch
     * @param type $archivepath
     * @param type $file
     * @return boolean
     */
    private function archive_stored($ziparch, $archivepath, $file) {
        if (!$file->archive_file($ziparch, $archivepath)) {
            return false;
        }

        if (!$file->is_directory()) {
            return true;
        }

        // Create directory????
        $baselength = strlen($file->get_filepath());
        $fs = get_file_storage();
        $files = $fs->get_directory_files($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(),
                $file->get_filepath(), true, true);
        foreach ($files as $file) {
            $path = $file->get_filepath();
            $path = substr($path, $baselength);
            $path = $archivepath.'/'.$path;
            if (!$file->is_directory()) {
                $path = $path.$file->get_filename();
            }
            // Ignore result here, partial zipping is ok for now.
            $file->archive_file($ziparch, $path);
        }

        return true;
    }

    /**
     * Write a single record
     *
     * @param array $record
     * @param int $rownum
     */
    public function write_record($record, $rownum) {
        if (!isset($this->table)) {
            throw new coding_exception('table not set');
        }
        $options = $this->table->get_options();
        if (!$options) {
            throw new coding_exception('options not set');
        }
            
        $q = 1; // Question number.
        $end = false;
        while (!$end) {
            if (!isset($this->columns['response' . $q])) {
                $end = true;
                break;
            }
            // Preset values.
            // $editortext = null;
            // $files = null;
            // Response value will be an array with editor response or file list.
            list($editortext, $files) = $record[$this->columns['response' . $q]];

            // Create pathname.
            $questionname = 'Q' . $q; // . '-'. $record[$this->columns['question' . $q]];
            $attemptname = $record[$this->columns['lastname']] . '-' .
                    $record[$this->columns['firstname']] . '-R' . $rownum;
            switch ($options->folders) {
                case quiz_proforma_options::QUESTION_WISE:
                    $archivepath =  $questionname . '/'. $attemptname;
                    break;
                case quiz_proforma_options::STUDENT_WISE:
                    $archivepath = $attemptname . '/' . $questionname;
                    break;
                default:
                    throw new coding_exception('folders option not supported ' . $options->folders);
            }
            $archivepath = trim($archivepath, '/') . '/';

            if (is_string($editortext)) {
                $responsefile = $this->responsefilename;
                // Editor content.
                switch ($options->editorfilename) {
                    case quiz_proforma_options::FIXED_NAME:
                        $responsefile = $archivepath . $responsefile;
                        break;
                    case quiz_proforma_options::NAME_FROM_QUESTION_WITH_PATH:
                    case quiz_proforma_options::NAME_FROM_QUESTION_WO_PATH:
                        $questions = $this->table->get_questions();
                        $question = $questions[$q];
                        if (isset ($question->options) && isset($question->options->responsefilename)) {
                            $responsefile = $question->options->responsefilename;
                        }
                        if ($options->editorfilename == quiz_proforma_options::NAME_FROM_QUESTION_WO_PATH) {
                            $responsefile = basename($responsefile);
                        }
                        $responsefile = $archivepath . $responsefile;
                        break;
                    default:
                        throw new coding_exception('editorfilename option not set');
                }
                $content = $editortext;
                if (!$this->ziparch->add_file_from_string($responsefile, $content)) {
                    debugging("Can not zip '$responsefile' file", DEBUG_DEVELOPER);
                    if (!$this->ignoreinvalidfiles) {
                        $this->abort = true;
                    }
                }
            } 
            if (is_array($files)) {
                // Files.
                // $fs_count = 0;
                foreach ($files as $zipfilepath => $storedfile) {
                    // $fs_count++;
                    $filename = $storedfile->get_filename();
                    if (!$this->archive_stored($this->ziparch, $archivepath . $filename, $storedfile)) {
                        debugging("Can not zip '$archivepath' file", DEBUG_DEVELOPER);
                        if (!$this->ignoreinvalidfiles) {
                            $this->abort = true;
                        }
                    }
                    // $pathfilename = $pathprefix . $storedfile->get_filepath() . $zipfilename;
                    // $pathfilename = clean_param($pathfilename, PARAM_PATH);
                    // $filesforzipping[$pathfilename] = $storedfile;
                }
            }
            $q++;
        }
    }

    /**
     * Write the end of the file.
     */
    public function close_output() {
        if (!$this->ziparch->close()) {
            @unlink($this->filename);
            return false;
        }

        if ($this->abort) {
            @unlink($this->filename);
            return false;
        }

        echo readfile($this->filename);
    }
}
