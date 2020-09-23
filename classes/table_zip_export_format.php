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
 * This file defines the table_zip_export_format class.
 *
 * @package   proformasubmexport
 * @copyright 2020 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class table_zip_export_format:
 * table_dataformat_export_format derived class using the custom zip dataformat.
 */
class table_zip_export_format extends table_dataformat_export_format {
    /**
     * Constructor
     *
     * @param string $table An sql table
     * @param string $dataformat type of dataformat for export
     */
    public function __construct(&$table, $dataformat) {
        // Prevent we are using csv instead of zip in order to pass the constructor call.
        parent::__construct($table, 'csv');
        $this->table = $table;

        if (ob_get_length()) {
            throw new coding_exception("Output can not be buffered before instantiating table_dataformat_export_format");
        }

        $classname = 'dataformat_zip_writer';
        if (!class_exists($classname)) {
            throw new coding_exception("Unable to locate " . $classname);
        }
        $this->dataformat = new $classname;

        // The dataformat export time to first byte could take a while to generate...
        set_time_limit(0);

        // Close the session so that the users other tabs in the same session are not blocked.
        \core\session\manager::write_close();
    }

    public function set_db_columns($columns) {
        $this->dataformat->set_columns($columns);
    }

    public function set_table_object($table) { // Must not be named set_table due to name clash.
        $this->dataformat->set_table($table);
    }
}