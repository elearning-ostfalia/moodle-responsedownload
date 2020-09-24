# moodle-responsedownload

#### Moodle Quiz Report Plugin for downloading Quiz responses as zip file. 

This plugin is a modification of the Moodle core quiz response report (base version 3.8.4). 

It allows teachers to download the student responses packed as a zip archive. 
It focuses on question types that require the student to enter text in an editor or to upload files: 
* Essay
* ProFormA 

But other question types may also work.

#### Installation
* Add the plugin folder responsedownload under 'mod/quiz/report'.

The download form will be available as a quiz report. 

#### Design

The code uses many parts of the quiz_responses plugin which is part of Moodle core 
(version 3.8.4). 
The quiz_responses classes used are copied in order to avoid problems with interface changes in the future.
Changes to those base classes are mostly done in seperate and inherited classes (polymorphism). 
This avoids mixing the code.

This is the complex table class inheritence:

    ...
    quiz_attempts_report_table [Moodle Core]
    quiz_last_responses_table [quiz_responses from Moodle core]
    quiz_responsedownload_last_responses_table [quiz_responsedownload]
    quiz_first_or_all_responses_table [quiz_responses from Moodle core]
    quiz_responsedownload_first_or_all_responses_table [quiz_responsedownload]
