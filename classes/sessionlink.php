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
 * Class for mapping collaborate instances + groups to collaborate session ids.
 * @author    Guy Thomas <gthomas@moodlerooms.com>
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_collaborate;

use mod_collaborate\local;

defined('MOODLE_INTERNAL') || die();

class sessionlink {

    /**
     * Ensure a session and session link record exists with fields populated as per $sessionlink object.
     * @param stdClass $collaborate collaborate record
     * @param stdClass $sessionlink session link fields and values
     * @param stdClass $course moodle course
     * @return mixed
     * @throws \coding_exception
     */
    private static function ensure_session_link($collaborate, $sessionlink, $course) {
        global $DB;

        $existinglink = false;

        $sessionlink->deletionattempted = 0;

        if (!isset($sessionlink->collaborateid)) {
            throw new \coding_exception('The collaborateid must be set for a sessionlink', var_export($sessionlink, true));
        }
        if (!isset($sessionlink->groupid)) {
            $sessionlink->groupid = null;
        } else {
            $existinglink = $DB->get_record('collaborate_sessionlink', (array) $sessionlink);
            if ($existinglink) {
                $sessionlink->sessionid = $existinglink->sessionid;
            }
        }
        if (empty($sessionlink->sessionid)) {
            // Create a session.
            if (PHPUNIT_TEST && isset($collaborate->sessionid) && empty($sessionlink->groupid)) {
                $sessionlink->sessionid = $collaborate->sessionid;
            } else {
                $sessionlink->sessionid = local::api_create_session($collaborate, $course, $sessionlink->groupid);
            }
        } else {
            // Update session.
            local::api_update_session($collaborate, $course, $sessionlink);
        }

        if (!$existinglink) {
            $existinglink = $DB->get_record('collaborate_sessionlink', (array)$sessionlink);
        }

        if (!$existinglink) {
            $DB->insert_record('collaborate_sessionlink', $sessionlink);
        } else {
            $sessionlink->id = $existinglink->id;
            $DB->update_record('collaborate_sessionlink', $sessionlink);
        }
        if (!$existinglink) {
            $existinglink = $DB->get_record('collaborate_sessionlink', (array) $sessionlink);
        }
        return $existinglink;
    }

    /**
     * @param \stdClass $collaborate db record
     * @param \int $groupid
     * @throws \coding_exception
     */
    public static function get_group_session_link($collaborate, $groupid) {
        global $DB;
        $sessionlink = $DB->get_record('collaborate_sessionlink', [
            'collaborateid' => $collaborate->id, 'groupid' => $groupid
        ]);
        if (!$sessionlink) {
            $newsessionlink = (object) [
                'collaborateid' => $collaborate->id,
                'groupid' => $groupid
            ];
            $course = get_course($collaborate->course);
            $sessionlink = self::ensure_session_link($collaborate, $newsessionlink, $course);
        }
        return $sessionlink;
    }

    /**
     * Ensure collaborate record is linked to sessions.
     * @param \stdClass $collaborate collaborate record
     * @return bool
     */
    public static function apply_session_links($collaborate) {

        if (isset($collaborate->groupingid) && isset($collaborate->groupmode)) {
            $groupingid = $collaborate->groupingid;
            $course = get_course($collaborate->course);
            $groupmode = $collaborate->groupmode;
        } else {
            list($course, $cm) = get_course_and_cm_from_instance($collaborate, 'collaborate');
            $groupingid = $cm->groupingid;
            $groupmode = $cm->groupmode;
        }

        // Ensure session exists for collaborate instance (not group mode).
        if (!isset($collaborate->sessionid)) {
            $collaborate->sessionid = null;
        }
        $sessionlink = (object) [
            'collaborateid' => $collaborate->id,
            'sessionid' => $collaborate->sessionid,
            'groupid' => null
        ];
        self::ensure_session_link($collaborate, $sessionlink, $course);
        if ($groupmode > NOGROUPS) {
            // Ensure sessions exist for groups.
            $groups = groups_get_all_groups($course->id, 0, $groupingid);
            foreach ($groups as $group) {
                $sessionlink = (object) [
                    'collaborateid' => $collaborate->id,
                    'groupid' => $group->id
                ];
                self::ensure_session_link($collaborate, $sessionlink, $course);
            }
        }
        return true;
    }

    /**
     * Attempt to delete sessions.
     * @param array $sessionlinks hashed by session link id.
     * @return bool were all session deletions successful?
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \dml_transaction_exception
     */
    private static function attempt_delete_sessions(array $sessionlinks) {
        global $DB;

        if (empty($sessionlinks)) {
            // Nothing to delete.
            return true;
        }

        $delsuccesses = [];
        $delfails = [];
        foreach ($sessionlinks as $link) {
            $delok = local::api_delete_session($link->sessionid);
            if ($delok) {
                $delsuccesses[] = $link->sessionid;
            } else {
                $delfails[] = $link->sessionid;
            }
        }

        // Wipe out the recording counts regardless of success or failure.
        list($sql, $params) = $DB->get_in_or_equal(array_keys($sessionlinks));
        $DB->delete_records_select('collaborate_recording_info', 'sessionlinkid '.$sql, $params);

        if (empty($delfails)) {
            return true;
        }

        // Only delete the link records where the api delete call was successful.
        list($sql, $params) = $DB->get_in_or_equal($delsuccesses);
        $DB->delete_records_select('collaborate_sessionlink', 'sessionid '.$sql, $params);

        // Increment the deletion attempt count.
        // We can try to delete at a later date.
        list($sql, $params) = $DB->get_in_or_equal($delfails);
        $links = $DB->get_records_select('collaborate_sessionlink', 'sessionid '.$sql, $params);

        $transact = $DB->start_delegated_transaction();
        foreach ($links as $link) {
            $link->deletionattempted++;
            $DB->update_record('collaborate_sessionlink', $link, true);
        }
        $transact->allow_commit();

        return false;
    }

    /**
     * Task - attempt to cleanup failed session deletions.
     */
    public static function task_cleanup_failed_deletions() {
        global $DB;

        $sessionlinks = $DB->get_records_select('collaborate_sessionlink', 'deletionattempted > 0');
        $delok = self::attempt_delete_sessions($sessionlinks);
        if ($delok) {
            $DB->delete_records_select('collaborate_sessionlink', 'deletionattempted > 0');
        }
    }

    /**
     * Delete all sessions associated with a specific collaborateid.
     * This includes all group link sessions attributed to that collaborate instance.
     *
     * @param int $collaborateid
     */
    public static function delete_sessions($collaborateid) {
        global $DB;

        $sessionlinks = $DB->get_records('collaborate_sessionlink', ['collaborateid' => $collaborateid]);
        $noerrors = self::attempt_delete_sessions($sessionlinks);
        if ($noerrors) {
            // Clean up associated link records.
            $DB->delete_records('collaborate_sessionlink', ['collaborateid' => $collaborateid]);
        }
    }

    /**
     * Delete all sessions associated with a specific groupid.
     * @param int $groupid
     */
    public static function delete_sessions_for_group($groupid) {
        global $DB;

        $sessionlinks = $DB->get_records('collaborate_sessionlink', ['groupid' => $groupid]);
        $noerrors = self::attempt_delete_sessions($sessionlinks);
        if ($noerrors) {
            // Clean up associated link records.
            $DB->delete_records('collaborate_sessionlink', ['groupid' => $groupid]);
        }
    }

    /**
     * Return active session links available to current user.
     * @param \stdClass $collaborate
     * @param \cm_info $cm
     * @return array
     */
    public static function my_active_links($collaborate, \cm_info $cm) {
        global $DB;
        global $USER;
        $aag = has_capability('moodle/site:accessallgroups', $cm->context);
        $gpnullsql = '';
        $gpparams = [];
        if ($aag) {
            $groups = groups_get_all_groups($cm->get_course()->id, 0, 0, 'g.id');
            $gpparams = array_keys($groups);
            $gpnullsql = 'groupid IS null';
        } else {
            $groups = groups_get_all_groups($cm->get_course()->id, $USER->id, 0, 'g.id');
            if (!empty($groups)) {
                $gpparams = array_keys($groups);
            } else {
                // Pull back the main instance session as this user is not in any groups.
                $gpnullsql = 'groupid IS null';
            }
        }

        $params = [$collaborate->id];
        $gpinsql = '';
        if (!empty($gpparams)) {
            list ($gpinsql, $inparams) = $DB->get_in_or_equal($gpparams);
            $params = array_merge($params, $inparams);
            $gpinsql = ' groupid '.$gpinsql.' ';
        }

        $gpsqlarr = [];
        if (!empty($gpnullsql)) {
            $gpsqlarr[] = $gpnullsql;
        }
        if (!empty($gpinsql)) {
            $gpsqlarr[] = $gpinsql;
        }
        if (empty($gpsqlarr)) {
            // This should not happen.
            throw new \coding_exception('group sql cannot be empty');
        }
        $gpsql = ' ('.implode(' OR ', $gpsqlarr).')';

        $select = 'collaborateid = ? AND deletionattempted = 0 AND '.$gpsql;
        $links = $DB->get_records_select('collaborate_sessionlink', $select, $params);
        return ($links);
    }

    /**
     * Get titles of sessions by ids.
     * @param array $sessionids
     * @return array
     */
    public static function get_titles_by_sessionids(array $sessionids) {
        global $DB;

        list($insql, $inparams) = $DB->get_in_or_equal($sessionids);

        $sql = "SELECT sl.sessionid, c.name, g.name AS groupname
                  FROM {collaborate_sessionlink} sl
                  JOIN {collaborate} c
                    ON c.id = sl.collaborateid
             LEFT JOIN {groups} g 
                    ON g.id = sl.groupid
                WHERE sl.sessionid $insql
        
        ";
        $rs = $DB->get_records_sql($sql, $inparams);
        $titles = [];
        if (empty($rs)) {
            return [];
        }
        foreach ($rs as $row) {
            $title = $row->name;
            if (!empty($row->groupname)) {
                $title = get_string('sessiongroup', 'collaborate', $row->groupname);
            }
            $titles[$row->sessionid] = $title;
        }
        return $titles;
    }

}
