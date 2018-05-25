<?php

namespace local_mahara_group_sync\task;

class mahara_group_sync extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('scheduledtaskname', 'local_mahara_group_sync');
    }

    public function execute() {
        global $CFG;
        require_once($CFG->dirroot .'/local/mahara_group_sync/lib.php');

        $enablecron = get_config('moodle', 'local_mahara_group_sync_enable_cron');
        if (!$enablecron) return;

        $lastfullsync = get_config('moodle', 'local_mahara_group_sync_last_full_sync');
        $syncall = get_config('moodle', 'local_mahara_group_sync_sync_all');
        $groupreportrecipient = get_config('moodle', 'local_mahara_group_sync_report_old_groups');
        $deleteoldgroups = get_config('moodle', 'local_mahara_group_sync_delete_old_groups');

        if ($groupreportrecipient) {
            \local_mahara_group_sync::send_report($groupreportrecipient);
            set_config('local_mahara_group_sync_report_old_groups', null);
        }
        if ($deleteoldgroups) {
            \local_mahara_group_sync::delete_old_groups();
            set_config('local_mahara_group_sync_delete_old_groups', null);
        }

        if($syncall || !$lastfullsync) {
            $time = time();
            \local_mahara_group_sync::sync_all();
            \local_mahara_group_sync::clear_queue($time);
            set_config('local_mahara_group_sync_sync_all', 0);
            set_config('local_mahara_group_sync_last_full_sync', time());
        }
        else {
            \local_mahara_group_sync::process_queue();
        }
    }
}
