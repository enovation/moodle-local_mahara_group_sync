<?php

defined('MOODLE_INTERNAL') || die();
require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/../../lib/filelib.php');
require_once(dirname(__FILE__) . '/../../lib/externallib.php');
require_once(dirname(__FILE__) . '/classes/RestWebServiceClient.class.php');

define('COURSE_PREFIX', '_course_');
define('GROUP_PREFIX', '_group_');
define('MGS_SUCCESSFUL', 'successful');
define('MGS_FAILED', 'failed');
define('GRP_AMEND', 'amend');
define('GRP_CREATE', 'create');
define('GRP_DELETE', 'delete');
define('GRP_USER_ADD', 'add');
define('GRP_USER_REMOVE', 'remove');
define('MAX_GROUP_MEMBER_SIZE', 900);

const CUT_OFF_PERIOD  = '4 years';
define('CUT_OFF_TIMESTAMP', strtotime('-'.CUT_OFF_PERIOD));

class local_mahara_group_sync
{
    private $usecron = true;
    private $institutions = [];

    public function __construct($usecron = true) {
        $this->usecron = $usecron;
    }

    public static function get_instance($usecron = true) {
        return new local_mahara_group_sync($usecron);
    }

    /**
     * Get formatted mahara institution name
     *
     * @return string
     */
    private function get_default_institution() {
        global $CFG;
        return strtolower($CFG->local_mahara_group_sync_institution);
    }

    private function call_webservice($action, $params = null) {
        global $CFG;

        $restClient = new RestWebServiceClient();
        $restClient->setToken($CFG->local_mahara_group_sync_token);
        $restClient->setDomainName($CFG->local_mahara_group_sync_server_url);
        $restClient->addArg('wsfunction');
        $restClient->addValue($action);
        $service_url = $restClient->getUrl();

        $curl = new curl;

        if(!is_null($params)) {
            $params = format_postdata_for_curlcall($params);
        }
        return $curl->post($service_url, $params);
    }

    /**
     * @param $response XML String from Mahara webservice
     * @return stdClass Basic XML object containing outcome
     */
    public static function process_response($response)
    {
        $xml = simplexml_load_string($response);

        $responsedetails = new stdClass;
        $responsedetails->message = isset($xml->MESSAGE) ? (string)$xml->MESSAGE : null;
        $responsedetails->success = isset($xml->ERRORCODE) ? false : true;
        return $responsedetails;
    }

    /**
     * Log the webservice call details and the Mahara response in the DB
     *
     * @param $action
     * @param $params
     * @param $response
     */
    public static function log_response($action, $params, $response)
    {
        global $DB;

        $log = new stdClass;
        $log->action = $action;
        $log->params = is_array($params) ? serialize($params) : $params;
        $log->webservicecalled = time();
        $log->error = isset($response->message) ? $response->message : null;
        $DB->insert_record('mahara_group_sync_log', $log);
    }

    /**
     * Format string to ensure Mahara group name constraints are met
     *
     * @param $string
     * @param $id Optional
     * @return string
     */
    public static function format_group_string($string, $id = null) {
        $groupname = substr(preg_replace('/[^\w-.]/', '', $string), 0, 240);
        if (!is_null($id)) {
            $groupname .= " (#$id)";
        }
        return $groupname;
    }

    /**
     * Get an array of ids of roles that have been configured as tutors/admins through the plugin settings
     *
     * @return array
     */
    public static function get_role_ids($type = 'admin') {
        global $CFG, $DB;

        if($type == 'admin') {
            $unformattedroles = $CFG->local_mahara_group_sync_admin_roles;
        }
        else {
            $unformattedroles = $CFG->local_mahara_group_sync_tutor_roles;
        }

        $rolearray = explode(PHP_EOL, $unformattedroles);

        $roleids = array();
        foreach($rolearray as &$role) {
            $shortname = trim($role);
            if(strlen($role) > 0) {
                $roleids[] = $DB->get_field('role', 'id', ['shortname'=>$shortname]);
            }
        }

        return $roleids;
    }

    /**
     * Returns and array of users correctly formatted for webservice call
     *
     * @param $type
     * @param $courseid
     * @param $groupid
     * @return array
     */
    private function get_group_members($type, $courseid, $groupid = null) {

        global $CFG;

        $members = array(
            0 => array(
                'username' => $CFG->local_mahara_group_sync_institution_admin,
                'role' => 'admin'
            )
        );

        $context = context_course::instance($courseid);

        if($type == 'course') {
            $users = get_enrolled_users($context);
        } else {
            $users = groups_get_members($groupid);
        }

        if (!empty($users)) {
            $users = array_filter($users, function($user) {
                return $this->validate_user($user);
            });
        }

        $adminroleids = self::get_role_ids('admin');
        $courseadmins = get_role_users($adminroleids, $context, false, 'ra.id, u.id, u.username, u.lastname, u.firstname');
        $tutorroleids = self::get_role_ids('tutor');
        $coursetutors = get_role_users($tutorroleids, $context, false, 'ra.id, u.id, u.username, u.lastname, u.firstname');

        //if the user is an admin then dont assign them as student or tutor
        foreach($courseadmins as $userid => $admin) {
            if ($admin->username != $CFG->local_mahara_group_sync_institution_admin) {
                unset($users[$userid]);
                unset($coursetutors[$userid]);
                $members[] = $this->create_user_param(null, $admin->username, 'admin');
            }
        }
        //if the user is tutor then dont assign them as a student
        foreach($coursetutors as $userid => $tutor) {
            if ($tutor->username != $CFG->local_mahara_group_sync_institution_admin) {
                unset($users[$userid]);
                $members[] = $this->create_user_param(null, $tutor->username, 'tutor');
            }
        }
        foreach($users as $user) {
            if ($user->username != $CFG->local_mahara_group_sync_institution_admin) {
                $members[] = $this->create_user_param(null, $user->username, 'member');
            }

        }

        return $members;
    }


    /**
     * Returns a valid parameter array to be sent as param in webservice call
     *
     * @param $mode "amend" or "create"
     * @param $shortname
     * @param $members
     * @param $name Only required when creating a group
     * @return array
     */
    private function create_group_param($mode, $shortname, $members = null, $name = null) {

        $group = array(
            'shortname' => $shortname,
            'institution' => $this->get_default_institution()
        );

        if($mode != GRP_DELETE) {

            $group['members'] = $members;

            if($mode == GRP_CREATE) {
                if(!is_null($name)) {
                    $group['name'] = $name;
                    $group['description'] = $name;
                }
                $group['grouptype'] = 'course';
                $group['controlled'] = 1;
                $group['open'] = 0;
                $group['submitpages'] = 0;
            }
        }

        return $group;
    }

    /**
     * Returns a valid parameter array to be sent as param in webservice call
     *
     * @param $userid
     * @param $username
     * @param $role
     * @param $action
     * @return array
     */
    private function create_user_param($userid = null, $username = null, $role = null, $action = null) {

        global $DB;

        $param = array();

        if(is_null($userid) && is_null($username)) {
            throw new moodle_exception('idorusernamerequired', 'local_mahara_group_sync');
        } else if(!is_null($username)) {
            $param['username'] = $username;
        } else if(!is_null($userid)) {
            $param['username'] = $DB->get_field('user', 'username', ['id'=>$userid]);
        }

        if(!is_null($role)) {
            $param['role'] = $role;
        }

        if(!is_null($action)) {
            $param['action'] = $action;
        }

        return $param;
    }

    /**
     * Add webservice call details to database - to be retrieved on cron run
     *
     * @param $action Mahara webservice action
     * @param $params
     */
    private function add_call_to_queue($action, $params = null) {
        global $DB;

        $todb = new stdClass;
        $todb->action = $action;
        $todb->params = serialize($params);
        $todb->timecreated = time();
        $DB->insert_record('mahara_group_sync_queue', $todb);
    }

    /**
     * Either add a call to the queue or call webservice immediately
     *
     * @param $action
     * @param null $params
     * @return bool
     */
    private function create_call($action, $params = null) {
        if($this->usecron) {
            $this->add_call_to_queue($action, $params);
        }
        else{
            return $this->call_webservice($action, $params);
        }
    }

    public function get_institution_assignments() {
        global $DB;

        list ($inSql, $inParams) = $DB->get_in_or_equal(['department', 'faculty']);

        $sql = "
            SELECT uid.data, u.username
            FROM {user_info_data} uid
            JOIN {user} u on u.id = uid.userid
            WHERE uid.fieldid IN (
              SELECT uif.id 
              FROM {user_info_field} uif 
              WHERE uif.shortname $inSql
            )
        ";

        if ($results = $DB->get_records_sql($sql, $inParams)) {
            foreach ($results as $institution => $result) {
                $this->institutions[strtolower($institution)][] = $result->username;
            }
        };
    }

    /**
     * This function is called when the plugin is first installed, or on the next cron run if enabled
     * from the plugin settings
     *
     * It compiles a list of all users/courses/group in moodle, and creates users and groups in mahara for each
     */
    public static function sync_all()
    {
        global $DB;

        $MGS = self::get_instance(false);

        // Get a list of all users from the default Mahara Institution
        $maharausers = $MGS->mahara_institution_get_members();
        $maharausernames = array_keys($maharausers);

        // Get a list of users that don't exist in Mahara within the default institution
        if (!empty($maharausernames)) {
            list($usql, $params) = $DB->get_in_or_equal($maharausernames, SQL_PARAMS_QM, 'param', false);
            $userstocreate = $DB->get_records_select('user', "username $usql", $params);
        }
        else {
            $userstocreate = $DB->get_records('user');
        }

        // Create users in Mahara in the default Institution
        if (!empty($userstocreate)) {
            $createids = array_keys($userstocreate);
            $MGS->mahara_user_create($createids);
        }

        // Get a list of additional institutions their user assignments from Moodle profile configurations
        $MGS->get_institution_assignments();

        if (!empty($MGS->institutions)) {
            foreach ($MGS->institutions as $institution => $moodleusernames) {

                // Get the users assigned to those institutions is Mahara
                $maharausers = $MGS->mahara_institution_get_members($institution);
                $maharausernames = array_keys($maharausers);

                // Add users to institutions in Mahara if they are not members already
                $userstoadd = array_diff($moodleusernames, $maharausernames);

                if (!empty($userstoadd)) {
                    $MGS->mahara_institution_add_members($institution, $userstoadd);
                }
            }
        }

        $groupstosync = array();
        $maharagroupnames = self::get_mahara_groupnames();

        //Groups
        $courses = $DB->get_records('course');
        if(!empty($courses)) {
            foreach($courses as $course) {
                if ($course->id == 1) continue;

                $formattedshortname = COURSE_PREFIX.$course->id;
                if (in_array($formattedshortname, $maharagroupnames)) {
                    $action = 'update';
                } else if ($course->timecreated >= CUT_OFF_TIMESTAMP) {
                    $action = 'create';
                } else {
                    continue;
                }

                $group = new stdClass;
                $group->name = self::format_group_string($course->shortname, $course->id);
                $group->shortname = $formattedshortname;
                $group->members = $MGS->get_group_members('course', $course->id);
                $group->action = $action;
                $groupstosync[] = $group;
            }
        }

        $moodlegroups = $DB->get_records('groups');
        if(!empty($moodlegroups)) {
            foreach($moodlegroups as $mdlgroup) {
                if ($mdlgroup->courseid == 1) continue;

                if ($course = $DB->get_record('course', ['id'=>$mdlgroup->courseid])) {
                    $formattedshortname = COURSE_PREFIX.$course->id.GROUP_PREFIX.$mdlgroup->id;

                    if (in_array($formattedshortname, $maharagroupnames)) {
                        $action = 'update';
                    } else if ($course->timecreated >= CUT_OFF_TIMESTAMP) {
                        $action = 'create';
                    } else {
                        continue;
                    }

                    $group = new stdClass;
                    $group->name = self::format_group_string($course->shortname, $course->id);
                    $group->name.= ' '.self::format_group_string($mdlgroup->name);
                    $group->shortname = $formattedshortname;
                    $group->members = $MGS->get_group_members('group', $mdlgroup->courseid, $mdlgroup->id);
                    $group->action = $action;
                    $groupstosync[] = $group;
                }

                $group = new stdClass;
                $group->name = self::format_group_string($course->shortname, $course->id);
                $group->name.= ' '.self::format_group_string($mdlgroup->name);
                $group->shortname = $formattedshortname;
                $group->members = $MGS->get_group_members('group', $mdlgroup->courseid, $mdlgroup->id);
                $group->action = $action;
                $groupstosync[] = $group;
            }
        }

        foreach($groupstosync as $group) {
            if($group->action == 'create') {
                $MGS->mahara_group_create($group->shortname, $group->members, $group->name);
            }
            else {
                $MGS->mahara_group_update($group->shortname, $group->members, $group->name);
            }
        }
    }

    /**
     * This function will send via email a list of groups
     * that exist in mahara that were created from courses in moodle that are over x years old
     * to the specified user
     *
     * @param $recipient Moodle user id
     */
    public static function send_report($recipient) {
        global $CFG, $DB;

        $user = $DB->get_record('user', ['id' => $recipient]);
        $supportuser = core_user::get_support_user();
        $subject = get_string('reportsubject', 'local_mahara_group_sync');
        $html = get_string('reportsubjectintro', 'local_mahara_group_sync', ['cutoff' => CUT_OFF_PERIOD, 'time'=>date('d/m/y H:i:s')]);
        $html .= '<br/><br/>';

        $maharagroups = self::get_instance(false)->mahara_group_get();
        $maharagroupnames = array_keys($maharagroups);

        $courses = $DB->get_records_sql('
            SELECT * 
            FROM {course}
            WHERE timecreated < ?
        ', [CUT_OFF_TIMESTAMP]);

        $moodlecoursestr = get_string('moodlecoursegroup', 'local_mahara_group_sync');
        $maharagroupstr = get_string('maharagroup', 'local_mahara_group_sync');

        if (!empty($courses)) {

            $html .= "[$moodlecoursestr] --> [$maharagroupstr]<br/><br/>";

            foreach ($courses as $course) {
                $courseshortname = COURSE_PREFIX.$course->id;

                if (in_array($courseshortname, $maharagroupnames)) {
                    $maharagroup = $maharagroups[$courseshortname];
                    $moodleurl = new moodle_url($CFG->wwwroot.'/course/view.php', ['id' => $course->id]);
                    $moodlelink = html_writer::link($moodleurl, $course->shortname);
                    $mahararoot = get_config('moodle', 'local_mahara_group_sync_server_url');
                    $maharaurl = $mahararoot.'/group/view.php?id='.$maharagroup->id;
                    $maharalink = ' - <a href="' . $maharaurl . '">' . $maharagroup->name . '</a><br/>';
                    $html .= "$moodlelink --> $maharalink";
                }

                $groups = $DB->get_records('groups', ['courseid'=>$course->id]);
                if (!empty($groups)) {
                    foreach ($groups as $group) {
                        $groupshortname = COURSE_PREFIX.$course->id.GROUP_PREFIX.$group->id;

                        if (in_array($groupshortname, $maharagroupnames)) {
                            $maharagroup = $maharagroups[$groupshortname];
                            $moodleurl = new moodle_url($CFG->wwwroot.'/group/index.php', ['id' => $course->id]);
                            $moodlelink = html_writer::link($moodleurl, $group->name);
                            $mahararoot = get_config('moodle', 'local_mahara_group_sync_server_url');
                            $maharaurl = $mahararoot.'/group/view.php?id='.$maharagroup->id;
                            $maharalink = '<a href="' . $maharaurl . '">' . $maharagroup->name . '</a><br/>';
                            $html .= " - $moodlelink --> $maharalink";
                        }
                    }
                }
            }
        } else {
            $html .= get_string('nogroups', 'local_mahara_group_sync');
        }

        $text = strip_tags($html);
        email_to_user($user, $supportuser, $subject, $text, $html);

    }

    /**
     * This function will delete all groups that exist in mahara that were created from courses in moodle that are over x years old
     */
    public static function delete_old_groups() {
        global $DB;

        $maharagroupnames = self::get_mahara_groupnames();

        $courses = $DB->get_records_sql('
            SELECT * 
            FROM {course}
            WHERE timecreated < ?
        ', [CUT_OFF_TIMESTAMP]);

        if (!empty($courses)) {
            foreach ($courses as $course) {
                $groupshortname = COURSE_PREFIX.$course->id;
                if (in_array($groupshortname, $maharagroupnames)) {
                    $MGS = self::get_instance(false);
                    $MGS->mahara_group_delete($groupshortname);
                }
            }
        }
    }

    private static function is_mahara_admin($user) {
        global $CFG;
        return in_array($user->username, ['admin', $CFG->local_mahara_group_sync_institution_admin]);
    }

    /**
     * This function will returns a list of mahara institutions that a user needs to be part of, determined by their extra
     * profile fields in moodle
     *
     * @param $user
     * @return array
     */
    public static function get_user_institutions($user) {
        $details = user_get_user_details($user);

        $institutions = array();

        if (!empty($details['customfields'])) {
            foreach ($details['customfields'] as $customfield) {
                if ($customfield['shortname'] == 'department' ||
                    $customfield['shortname'] == 'faculty') {

                    if (!empty($customfield['value'])) {
                        $institutions[] = strtolower($customfield['value']);
                    }
                }
            }
        }

        return $institutions;
    }

    /**
     * This function will process a queue of webservice requests that have been created from moodle events
     */
    public static function process_queue() {
        global $DB;

        $MGS = self::get_instance(false);

        $itemsinqueue = $DB->get_records_sql('
            SELECT * FROM {mahara_group_sync_queue} WHERE status NOT LIKE "'.MGS_SUCCESSFUL.'"
        ');

        foreach ($itemsinqueue as $item) {
            $action = $item->action;
            $params = unserialize($item->params);

            $response = $MGS->create_call($action, $params);
            $response = self::process_response($response);
            self::log_response($action, $params, $response);
            if ($response->success) {
                $DB->delete_records('mahara_group_sync_queue', ['id'=>$item->id]);
            }
        }
    }

    /**
     * This function will delete all webservice requests from the queue that were created before the date specified
     * @param $before
     */
    public static function clear_queue($before) {
        global $DB;
        $DB->delete_records_select('mahara_group_sync_queue', 'timecreated < :before', ['before'=>$before]);
    }

    /**
     * This function is used to determine that a specified user is valid for use in a werbservice request
     *
     * @param $user
     * @return bool
     */
    private function validate_user($user) {
        global $DB;

        if (is_numeric($user)) {
            $user = $DB->get_record('user', ['id' => $user]);
        }

        if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }

    /**
     * This function is used to return an array of all the group shortnames in mahara
     *
     * @return array
     */
    private static function get_mahara_groupnames() {
        $MGS = self::get_instance(false);
        $maharagroups = $MGS->mahara_group_get();
        return array_keys($maharagroups);
    }

    /*****************
     * MAHARA ACTIONS
     ****************/

    private function mahara_user_create($userids)
    {
        global $DB;

        if(!is_array($userids)) {
            $userids = array($userids);
        }

        list($usql, $sqlparams) = $DB->get_in_or_equal($userids);
        $users = $DB->get_records_select('user', "id $usql", $sqlparams);

        $action = 'mahara_user_create_users';
        $params = array();
        $postparams = array();
        $i = 0;

        foreach($users as $user) {

            if (!$this->validate_user($user)) {
                continue;
            }

            $params['users'][] = array(
                'username' => $user->username,
                'password' => '',
                'firstname' => strip_tags($user->firstname),
                'lastname' => strip_tags($user->lastname),
                'email' => strtolower($user->email),
                'auth' => 'xmlrpc',
                'institution' => $this->get_default_institution()
            );
            $i++;

            //users need to be split into 100's otherwise call will fail
            if($i%100==0) {
                $postparams[] = $params;
                unset($params);
            }
        }
        if($i%100!=0) {
            $postparams[] = $params;
        }

        foreach($postparams as $params) {
            $response = $this->create_call($action, $params);
            if(!$this->usecron) {
                $response = self::process_response($response);
                self::log_response($action, $params, $response);
            }
        }
    }

    private static function group_will_be_created($groupname) {
        global $DB;

        $groupstocreate = $DB->get_records('mahara_group_sync_queue', ['action' => 'mahara_group_create_groups']);
        if (!empty($groupstocreate)) {
            foreach ($groupstocreate as $grouptocreate) {
                $data = unserialize($grouptocreate->params);
                $shortname = $data['groups'][0]['shortname'];
                if ($groupname == $shortname) {
                    return true;
                }
            }
        }
        return false;
    }

    private function mahara_group_get()
    {
        $response = $this->create_call('mahara_group_get_groups');

        $groups = array();
        $xml = simplexml_load_string($response);
        if($xml && is_object($xml->MULTIPLE->SINGLE)) {
            foreach($xml->MULTIPLE->SINGLE as $items) {
                $group = new stdClass;
                foreach ($items as $item) {
                    $property_name = (string)$item->attributes()['name'];
                    $group->{$property_name} = (string)$item->VALUE;
                }
                $groups[$group->shortname] = $group;
            }
        }

        return $groups;
    }

    private function mahara_institution_get_members($institution = null)
    {
        if (!$institution) {
            $institution = $this->get_default_institution();
        }

        $action = 'mahara_institution_get_members';
        $params = array('institution'=>$institution);

        $response = $this->create_call($action, $params);
        self::log_response($action, $params, $response);

        $members = array();
        $xml = simplexml_load_string($response);
        if($xml && is_object($xml->MULTIPLE->SINGLE)) {
            foreach ($xml->MULTIPLE->SINGLE as $items) {
                $member = new stdClass;
                foreach ($items as $item) {
                    $property_name = (string)$item->attributes()['name'];
                    $member->{$property_name} = (string)$item->VALUE;
                }
                $members[$member->username] = $member;
            }
        }
        return $members;
    }

    private function mahara_institution_add_members($institution = null, $usernames) {
        if (!$institution) {
            $institution = $this->get_default_institution();
        }

        $usernames = (array)$usernames;

        $action = 'mahara_institution_add_members';
        $params = array('institution'=>$institution);

        foreach ($usernames as $username) {
            $params['users'][]['username'] = $username;
        }

        $response = $this->create_call($action, $params);
        if(!$this->usecron) {
            $response = self::process_response($response);
            self::log_response($action, $params, $response);
        }
    }

    private function mahara_group_create($shortname, $members, $name)
    {
        // large numbers of group members need to be split up
        if (count($members) < MAX_GROUP_MEMBER_SIZE) {
            $action = 'mahara_group_create_groups';
            $params['groups'][0] = $this->create_group_param(GRP_CREATE, $shortname, $members, $name);

            $response = $this->create_call($action, $params);
            if(!$this->usecron) {
                $response = self::process_response($response);
                self::log_response($action, $params, $response);
            }
        }
        // otherwise we need to send the users in chunks
        else {
            $membersparts = array_chunk($members, MAX_GROUP_MEMBER_SIZE);

            for ($i = 0; $i < sizeof($membersparts); $i++) {
                // the first call creates the group with some members
                if ($i == 0) {
                    $action = 'mahara_group_create_groups';
                    $params['groups'][0] = $this->create_group_param(GRP_CREATE, $shortname, $membersparts[$i], $name);
                    $response = $this->create_call($action, $params);
                    if(!$this->usecron) {
                        $response = self::process_response($response);
                        self::log_response($action, $params, $response);
                    }
                }
                // and subsequent calls updates the group with the other members
                else {
                    $this->mahara_group_update_members($shortname, $membersparts[$i]);
                }
            }

        }
    }

    private function mahara_group_update($shortname, $members, $name)
    {
        // large numbers of group members need to be split up
        if (count($members) < MAX_GROUP_MEMBER_SIZE) {
            $action = 'mahara_group_update_groups';
            $params['groups'][0] = $this->create_group_param(GRP_CREATE, $shortname, $members, $name);

            $response = $this->create_call($action, $params);
            if(!$this->usecron) {
                $response = self::process_response($response);
                self::log_response($action, $params, $response);
            }
        }
        // otherwise we need to send the users in chunks
        else {
            $membersparts = array_chunk($members, MAX_GROUP_MEMBER_SIZE);

            for ($i = 0; $i < sizeof($membersparts); $i++) {
                // the first call creates the group with some members
                if ($i == 0) {
                    $action = 'mahara_group_update_groups';
                    $params['groups'][0] = $this->create_group_param(GRP_CREATE, $shortname, $members, $name);

                    $response = $this->create_call($action, $params);
                    if(!$this->usecron) {
                        $response = self::process_response($response);
                        self::log_response($action, $params, $response);
                    }
                }
                // and subsequent calls updates the group with the other members
                else {
                    $this->mahara_group_update_members($shortname, $membersparts[$i]);
                }
            }
        }
    }

    private function mahara_group_update_members($shortname, $members)
    {
        $action = 'mahara_group_update_group_members';

        foreach ($members as &$member) {
            $member['action'] = GRP_USER_ADD;
        }
        $params['groups'][0] = $this->create_group_param(GRP_AMEND, $shortname, $members);

        $response = $this->create_call($action, $params);
        if(!$this->usecron) {
            $response = self::process_response($response);
            self::log_response($action, $params, $response);
        }
    }

    private function mahara_group_add_member($shortname, $userid, $role)
    {
        $action = 'mahara_group_update_group_members';
        $members = array();
        $members[] = $this->create_user_param($userid, null, $role, GRP_USER_ADD);
        $params['groups'][0] = $this->create_group_param(GRP_AMEND, $shortname, $members);

        $response = $this->create_call($action, $params);
        if(!$this->usecron) {
            $response = self::process_response($response);
            self::log_response($action, $params, $response);
        }
    }

    private function mahara_group_remove_member($shortname, $userid, $role = 'member')
    {
        $action = 'mahara_group_update_group_members';
        $members = array();
        $members[] = $this->create_user_param($userid, null, $role, GRP_USER_REMOVE);
        $params['groups'][0] = $this->create_group_param(GRP_AMEND, $shortname, $members);

        $response = $this->create_call($action, $params);
        if(!$this->usecron) {
            $response = self::process_response($response);
            self::log_response($action, $params, $response);
        }
    }

    private function mahara_group_delete($shortname)
    {
        $action = 'mahara_group_delete_groups';
        $params['groups'][0] = $this->create_group_param(GRP_DELETE, $shortname);

        $response = $this->create_call($action, $params);
        if(!$this->usecron) {
            $response = self::process_response($response);
            self::log_response($action, $params, $response);
        }
    }

    /*****************
     * EVENT HANDLERS
     ****************/

    /**
     * @param \core\event\user_created $event
     *
     * Create a user in Mahara via webservice
     */
    public static function handle_user_created(\core\event\user_created $event) {
        global $DB;

        $data = $event->get_data();
        $MGS = self::get_instance();
        $MGS->mahara_user_create($data['objectid']);

        $user = $DB->get_record('user', ['id' => $data['objectid']]);
        $institutions = self::get_user_institutions($user);

        if (!empty($institutions)) {
            foreach ($institutions as $institution) {
                $MGS = self::get_instance();
                $MGS->mahara_institution_add_members($institution, $user->username);
            }
        }
    }

    /**
     * @param \core\event\user_updated $event
     *
     * Create a user in Mahara via webservice
     */
    public static function handle_user_updated(\core\event\user_updated $event) {
        global $DB;

        $data = $event->get_data();

        $user = $DB->get_record('user', ['id' => $data['objectid']]);
        $institutions = self::get_user_institutions($user);

        if (!empty($institutions)) {
            foreach ($institutions as $institution) {

                $MGS = self::get_instance(false);
                $maharausers = $MGS->mahara_institution_get_members($institution);
                $maharausernames = array_keys($maharausers);

                if (!in_array($user->username, $maharausernames)) {
                    $MGS = self::get_instance(false);
                    $MGS->mahara_institution_add_members($institution, $user->username);
                }
            }
        }
    }

    /**
     * @param \core\event\course_created $event
     *
     * Create a group in Mahara via webservice
     * Group name in Mahara will be the same as that of the Course Shortname in Moodle
     */
    public static function handle_course_created(\core\event\course_created $event)
    {
        $data = $event->get_data();
        $MGS = self::get_instance();
        $name = self::format_group_string($data['other']['shortname'], $data['objectid']);
        $shortname = COURSE_PREFIX.$data['objectid'];
        $members = $MGS->get_group_members('course', $data['objectid']);
        $MGS->mahara_group_create($shortname, $members, $name);
    }

    /**
     * @param \core\event\course_updated $event
     *
     * Update a group in Mahara via webservice
     * Group name in Mahara will be the same as Course shortname
     */
    public static function handle_course_updated(\core\event\course_updated $event)
    {
        global $DB;

        $maharagroupnames = self::get_mahara_groupnames();

        $MGS = self::get_instance();
        $data = $event->get_data();
        //build course group param
        $shortname = COURSE_PREFIX.$data['objectid'];
        $name = self::format_group_string($data['other']['shortname'], $data['objectid']);
        $members = $MGS->get_group_members('course', $data['objectid']);

        if (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname)) {
            $MGS->mahara_group_update($shortname, $members, $name);
        }

        //build moodle-group group params
        $course = get_course($data['objectid']);
        $course_groups = $DB->get_records('groups', ['courseid'=>$course->id]);
        foreach($course_groups as $group) {
            $shortname = COURSE_PREFIX.$course->id.GROUP_PREFIX.$group->id;
            $name = self::format_group_string($data['other']['shortname'], $data['objectid']);
            $name.= ' '.self::format_group_string($group->name);
            if (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname)) {
                $MGS->mahara_group_update($shortname, $members, $name);
            }
        }
    }

    /**
     * @param \core\event\course_deleted $event
     *
     * Delete a group in Mahara via webservice
     */
    public static function handle_course_deleted(\core\event\course_deleted $event)
    {
        $data = $event->get_data();
        $shortname = COURSE_PREFIX.$data['objectid'];
        $maharagroupnames = self::get_mahara_groupnames();

        if (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname)) {
            $MGS = self::get_instance();
            $MGS->mahara_group_delete($shortname);
        }
    }

//  This is not needed since role_assigned is triggered immediately afterwards,
//  and it's role_assigned that contains the information we need

//    public static function handle_user_enrolment(\core\event\user_enrolment_created $event)
//    {
//        $data = $event->get_data();
//        $userid = $data['relateduserid'];
//        $shortname = COURSE_PREFIX.$data['courseid'];
//        $MGS = self::get_instance();
//        $MGS->mahara_group_add_member($shortname, $userid);
//    }

    /**
     * @param \core\event\user_enrolment_deleted $event
     *
     * Remove user from relevant mahara group
     */
    public static function handle_user_unenrolment(\core\event\user_enrolment_deleted $event)
    {
        global $DB;

        $maharagroupnames = self::get_mahara_groupnames();

        $data = $event->get_data();
        $userid = $data['relateduserid'];
        $user = $DB->get_record('user', ['id' => $userid]);
        $shortname = COURSE_PREFIX.$data['courseid'];

        if (!self::is_mahara_admin($user) &&
            (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname))) {
            $MGS = self::get_instance();
            $MGS->mahara_group_remove_member($shortname, $userid);
        }
    }

    /**
     * @param \core\event\role_assigned $event
     *
     * Update user role in relevant mahara group
     */
    public static function handle_role_change($event)
    {
        global $DB;

        $data = $event->get_data();
        $userid = $data['relateduserid'];
        $shortname = COURSE_PREFIX.$data['courseid'];
        $user = $DB->get_record('user', ['id' => $userid]);

        $maharagroupnames = self::get_mahara_groupnames();

        if (!self::is_mahara_admin($user) &&
            (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname))) {
            $tutorroleids = self::get_role_ids('tutor');
            $adminroleids = self::get_role_ids('admin');

            $context = context_course::instance($data['courseid']);
            $userroles = get_user_roles($context, $userid);

            $role = 'member';
            if (!empty($userroles)) {
                foreach ($userroles as $userrole) {
                    if (in_array($userrole->roleid, $adminroleids)) {
                        $role = 'admin';
                        break;
                    } else if (in_array($userrole->roleid, $tutorroleids)) {
                        $role = 'tutor';
                    }
                }
            }

            $MGS = self::get_instance();
            $MGS->mahara_group_add_member($shortname, $userid, $role);
        }
    }


    /**
     * @param \core\event\group_created $event
     *
     * Create a group in Mahara via webservice
     * Group name in Mahara will be the same as "[Course shortname]_[Group name]"
     */
    public static function handle_group_created(\core\event\group_created $event)
    {
        global $DB;

        $MGS = self::get_instance();

        $data = $event->get_data();
        $course = $DB->get_record('course', ['id'=>$data['courseid']]);

        if ($course->timecreated >= CUT_OFF_TIMESTAMP) {
            $mdl_group_name = $DB->get_field('groups', 'name', ['id'=>$data['objectid']]);
            //build params

            $name = self::format_group_string($course->shortname, $course->id);
            $name.= ' '.self::format_group_string($mdl_group_name);
            $shortname = COURSE_PREFIX.$data['courseid'].GROUP_PREFIX.$data['objectid'];
            $members = $MGS->get_group_members('group', $data['courseid'], $data['objectid']);

            $MGS->mahara_group_create($shortname, $members, $name);
        }


    }

    /**
     * @param \core\event\group_updated $event
     *
     * Update a group in Mahara via webservice
     * Group name in Mahara will be the same as Course shortname
     */
    public static function handle_group_updated(\core\event\group_updated $event)
    {
        global $DB;
        $MGS = self::get_instance();
        $data = $event->get_data();
        $course_shortname = $DB->get_field('course', 'shortname', ['id'=>$data['courseid']]);
        $mdl_group_name = $DB->get_field('groups', 'name', ['id'=>$data['objectid']]);
        //build params
        $name = self::format_group_string($course_shortname, $data['courseid']);
        $name.= ' '.self::format_group_string($mdl_group_name);
        $shortname = COURSE_PREFIX.$data['courseid'].GROUP_PREFIX.$data['objectid'];

        $maharagroupnames = self::get_mahara_groupnames();
        if (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname)) {
            $members = $MGS->get_group_members('group', $data['courseid'], $data['objectid']);
            $MGS->mahara_group_update($shortname, $members, $name);
        }
    }

    /**
     * @param \core\event\group_deleted $event
     *
     * Delete a group in Mahara via webservice
     */
    public static function handle_group_deleted(\core\event\group_deleted $event)
    {
        $MGS = self::get_instance();
        $data = $event->get_data();
        $shortname = COURSE_PREFIX.$data['courseid'].GROUP_PREFIX.$data['objectid'];
        $maharagroupnames = self::get_mahara_groupnames();
        if (in_array($shortname, $maharagroupnames)) {
            $MGS->mahara_group_delete($shortname);
        }
    }

    /**
     * @param \core\event\group_member_added $event
     *
     * Enrol user in relevant mahara group
     */
    public static function handle_user_added_to_group(\core\event\group_member_added $event)
    {
        global $DB;

        $MGS = self::get_instance();
        $data = $event->get_data();
        $userid = $data['relateduserid'];
        $shortname = COURSE_PREFIX.$data['courseid'].GROUP_PREFIX.$data['objectid'];
        $user = $DB->get_record('user', ['id' => $userid]);

        $maharagroupnames = self::get_mahara_groupnames();

        if (!self::is_mahara_admin($user) &&
            (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname))) {

            $context = context_course::instance($data['courseid']);

            $userroles = get_user_roles($context, $userid);

            $adminroleids = self::get_role_ids('admin');
            $tutorroleids = self::get_role_ids('tutor');

            $role = 'member';
            if (!empty($userroles)) {
                foreach ($userroles as $userrole) {
                    if (in_array($userrole->roleid, $adminroleids)) {
                        $role = 'admin';
                        break;
                    } else if (in_array($userrole->roleid, $tutorroleids)) {
                        $role = 'tutor';
                    }
                }
            }

            $MGS->mahara_group_add_member($shortname, $userid, $role);
        }
    }

    /**
     * @param \core\event\group_member_removed $event
     *
     * Remove user from relevant mahara group
     */
    public static function handle_user_removed_from_group(\core\event\group_member_removed $event)
    {
        global $DB;

        $MGS = self::get_instance();
        $data = $event->get_data();
        $userid = $data['relateduserid'];
        $shortname = COURSE_PREFIX.$data['courseid'].GROUP_PREFIX.$data['objectid'];
        $user = $DB->get_record('user', ['id' => $userid]);

        $maharagroupnames = self::get_mahara_groupnames();

        if (!self::is_mahara_admin($user) &&
            (in_array($shortname, $maharagroupnames) || self::group_will_be_created($shortname))) {
            $MGS->mahara_group_remove_member($shortname, $userid);
        }
    }
}