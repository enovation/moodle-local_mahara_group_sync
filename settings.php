<?php

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
 * mahara_group_sync
 *
 * @package    mahara_group_sync
 * @subpackage local
 * @copyright  2016 Enovation Solutions
 * @author     Callum Bennett (callum.bennett@enovation.ie)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {

    $settings = new admin_settingpage(
        'local_mahara_group_sync',
        get_string('pluginname', 'local_mahara_group_sync')
    );

    $ADMIN->add('localplugins', $settings);
    $settings->add(new admin_setting_configtext(
        'local_mahara_group_sync_token', get_string('token', 'local_mahara_group_sync'),
        get_string('tokendesc', 'local_mahara_group_sync'), null, PARAM_RAW));

    $settings->add(new admin_setting_configtext(
        'local_mahara_group_sync_server_url', get_string('maharaurl', 'local_mahara_group_sync'),
        get_string('maharaurldesc', 'local_mahara_group_sync'), null, PARAM_RAW));

    $settings->add(new admin_setting_configtext(
        'local_mahara_group_sync_institution', get_string('maharainstitution', 'local_mahara_group_sync'),
        get_string('maharainstitutiondesc', 'local_mahara_group_sync'), null, PARAM_RAW)
    );

    $settings->add(new admin_setting_configtext(
            'local_mahara_group_sync_institution_admin', get_string('maharainstitutionadmin', 'local_mahara_group_sync'),
            get_string('maharainstitutionadmindesc', 'local_mahara_group_sync'), 'lisadonaldson', PARAM_RAW));

    $settings->add(new admin_setting_configtextarea(
        'local_mahara_group_sync_tutor_roles', get_string('grouptutorroles', 'local_mahara_group_sync'),
        get_string('grouptutorrolesdesc', 'local_mahara_group_sync'), 'teacher', PARAM_RAW)
    );

    $adminrolesdefault = 'manager'.PHP_EOL.'coursecreator'.PHP_EOL.'editingteacher';
    $settings->add(new admin_setting_configtextarea(
        'local_mahara_group_sync_admin_roles', get_string('groupadminroles', 'local_mahara_group_sync'),
        get_string('groupadminrolesdesc', 'local_mahara_group_sync'), $adminrolesdefault, PARAM_RAW)
    );

    $settings->add(new admin_setting_configcheckbox(
            'local_mahara_group_sync_enable_cron', get_string('enablecron', 'local_mahara_group_sync'),
            get_string('enablecrondesc', 'local_mahara_group_sync'), 1)
    );

    $lastfullsynclabel = '';
    if($lastfullsync = get_config('moodle', 'local_mahara_group_sync_last_full_sync')) {
        $lastfullsyncstring = get_string('lastfullsync', 'local_mahara_group_sync');
        $lastfullsynclabel = PHP_EOL.PHP_EOL.$lastfullsyncstring.': '.date('d-m-y H:i:s', $lastfullsync);
    }

    $settings->add(new admin_setting_configcheckbox(
            'local_mahara_group_sync_sync_all', get_string('syncall', 'local_mahara_group_sync'),
            get_string('syncalldesc', 'local_mahara_group_sync').$lastfullsynclabel, 1)
    );

    $siteadminids = $DB->get_field('config', 'value', ['name'=>'siteadmins']);
    $siteadminids = explode(',', $siteadminids);

    $siteadminoptions = array('' => '');
    if (!empty($siteadminids)) {
        foreach ($siteadminids as $siteadminid) {
            $admin = $DB->get_record('user', ['id' => $siteadminid]);
            $fullname = fullname($admin);
            $siteadminoptions[$siteadminid] = " $fullname - $admin->email";
        }
    }

    $settings->add(new admin_setting_configselect(
                    'local_mahara_group_sync_report_old_groups', get_string('reportoldgroups', 'local_mahara_group_sync'),
                    get_string('reportoldgroupsdesc', 'local_mahara_group_sync'), null, $siteadminoptions)
    );

    $settings->add(new admin_setting_configcheckbox(
                    'local_mahara_group_sync_delete_old_groups', get_string('deleteoldgroups', 'local_mahara_group_sync'),
                    get_string('deleteoldgroupsdesc', 'local_mahara_group_sync'), 0)
    );
}