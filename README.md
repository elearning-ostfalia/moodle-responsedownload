# moodle-responsedownload

#### Moodle Quiz Report Plugin for downloading Quiz responses as zip file. 

This plugin is a modification of the Moodle core quiz response report (base version 3.9.6). 

It allows teachers to download the student responses packed as a zip archive. 
It focuses on question types that require the student to enter text in an editor or to upload files: 
* Essay
* ProFormA 

But other question types may also work.

#### Installation
* Add the plugin folder responsedownload under 'mod/quiz/report'.

The download form will be available as a quiz report. 

Note: The student information written into the archive will contain the first name and the 
surname. 
If the person who creates the archive has 'moodle/site:viewuseridentity' capabilty
AND in config.php the configuration value 
$CFG->showuseridentity contains 'username' then also the username (loginname) will 
be prepended to the student name.


#### Design

The code uses many parts of the quiz_responses plugin which is part of Moodle core. 
The original quiz_responses classes used are copied in order to avoid problems with interface changes in the future.

    mod\quiz\report\responses\last_responses_table.php
    mod\quiz\report\responses\first_or_all_responses_table.php

Changes to those base classes are mostly done in seperate and inherited classes (polymorphism). 
This avoids mixing the code.

This is the complex table class inheritence:

    ...
    quiz_attempts_report_table [Moodle Core]
    quiz_last_responses_table [quiz_responses from Moodle core]
    quiz_responsedownload_last_responses_table [quiz_responsedownload]
    quiz_first_or_all_responses_table [quiz_responses from Moodle core]
    quiz_responsedownload_first_or_all_responses_table [quiz_responsedownload]
