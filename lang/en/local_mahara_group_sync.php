<?php

$string['pluginname'] = 'Mahara Group Sync';
$string['scheduledtaskname'] = 'Mahara group Synchronization';

$string['token'] = 'Webservice Token';
$string['tokendesc'] = 'Enter the Mahara webservice token';
$string['maharaurl'] = 'Mahara URL';
$string['maharaurldesc'] = 'eg. http://domain.com';
$string['maharainstitution'] = 'Default Mahara institution';
$string['maharainstitutiondesc'] = 'Select the Default Mahara institution which the users should be authenticated with';
$string['maharainstitutionadmin'] = 'Default Mahara institution administrator';
$string['maharainstitutionadmindesc'] = 'This user MUST be an administrator of the Default Mahara institution selected above';
$string['grouptutorroles'] = 'Group tutor roles';
$string['grouptutorrolesdesc'] = 'Select Moodle roles which will be mapped to tutor roles in Mahara';
$string['groupadminroles'] = 'Group admin roles';
$string['groupadminrolesdesc'] = 'Select Moodle roles which will be mapped to admin roles in Mahara<br/>
    <b>Any roles previously selected as tutor roles will be overwritten</b>';
$string['syncall'] = 'Sync All';
$string['syncalldesc'] = 'Check this option to synchronize all user, course & group data to Mahara on the next cron run';
$string['enablecron'] = 'Enable Cron';
$string['enablecrondesc'] = 'If disabled, user/course/group changes made in Moodle will not be synced to Mahara, but will be stored in Moodle and synced once the setting is re-enabled';
$string['lastfullsync'] = 'Last Full Sync';

$string['reportoldgroups'] = 'Mahara group report';
$string['reportoldgroupsdesc'] = 'If set, an email will be sent to the selected administrator on the next cron run, containing a list of groups which were created for a Moodle course that is over 4 years old';
$string['deleteoldgroups'] = 'Delete old groups in Mahara';
$string['deleteoldgroupsdesc'] = 'Enabling this setting will, on the next cron run only, delete all groups and group data from Mahara, for groups which were created for a Moodle course that is over 4 years old. <br/><b>Please make sure to review these groups first using the Mahara group report setting, as changes are irreversible.</b>';
$string['reportsubject'] = 'Mahara group sychronization - Group Review';
$string['reportsubjectintro'] = 'The following is a list of Moodle courses/groups that are more than {$a->cutoff} old (correct as of {$a->time}), and their corresponding groups in Mahara. These groups will be deleted from Mahara if the "Delete old groups" setting is enabled';

$string['moodlecoursegroup'] = 'Moodle Course/Group';
$string['maharagroup'] = 'Mahara Group';
$string['nogroups'] = 'There are no groups to display';