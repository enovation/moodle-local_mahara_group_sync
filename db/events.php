<?php

defined('MOODLE_INTERNAL') || die();

// Events 2 handler
$observers = array(

    /*******
     * USER
     *******/

    //Created
    array(
        'eventname' => 'core\event\user_created',
        'callback' => 'local_mahara_group_sync::handle_user_created',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    //Updated
    array(
        'eventname' => 'core\event\user_updated',
        'callback' => 'local_mahara_group_sync::handle_user_updated',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    /*********
     * COURSE
     *********/

    //Created
    array(
        'eventname' => 'core\event\course_created',
        'callback' => 'local_mahara_group_sync::handle_course_created',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    //Updated
    array(
        'eventname' => 'core\event\course_updated',
        'callback' => 'local_mahara_group_sync::handle_course_updated',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    //Deleted
    array(
        'eventname' => 'core\event\course_deleted',
        'callback' => 'local_mahara_group_sync::handle_course_deleted',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),


    /********
     * GROUP
     ********/

    //Created
    array(
        'eventname' => 'core\event\group_created',
        'callback' => 'local_mahara_group_sync::handle_group_created',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    //Updated
    array(
        'eventname' => 'core\event\group_updated',
        'callback' => 'local_mahara_group_sync::handle_group_updated',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    //Deleted
    array(
        'eventname' => 'core\event\group_deleted',
        'callback' => 'local_mahara_group_sync::handle_group_deleted',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    //User Added
    array(
        'eventname' => 'core\event\group_member_added',
        'callback' => 'local_mahara_group_sync::handle_user_added_to_group',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
    //User Removed
    array(
        'eventname' => 'core\event\group_member_removed',
        'callback' => 'local_mahara_group_sync::handle_user_removed_from_group',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),


    /************
     * ENROLMENT
     ***********/

    //Created
//    array(
//        'eventname' => 'core\event\user_enrolment_created',
//        'callback' => 'local_mahara_group_sync::handle_user_enrolment',
//        'includefile' => '/local/mahara_group_sync/lib.php'
//    ),

    //Deleted
    array(
        'eventname' => 'core\event\user_enrolment_deleted',
        'callback' => 'local_mahara_group_sync::handle_user_unenrolment',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),

    /*******************
     * ROLE ASSIGNMENTS
     *******************/

    //Assigned
    array(
        'eventname' => 'core\event\role_assigned',
        'callback' => 'local_mahara_group_sync::handle_role_change',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),

    //Unassigned
    array(
        'eventname' => 'core\event\role_unassigned',
        'callback' => 'local_mahara_group_sync::handle_role_change',
        'includefile' => '/local/mahara_group_sync/lib.php'
    ),
);