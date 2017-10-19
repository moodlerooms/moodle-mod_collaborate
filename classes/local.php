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
 * Common local functions used by the collaborate module.
 *
 * @package   mod_collaborate
 * @copyright Copyright (c) 2015 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_collaborate;

defined('MOODLE_INTERNAL') || die();

use mod_collaborate\soap\generated\BuildHtmlSessionUrl;
use mod_collaborate\soap\generated\ServerConfiguration;
use mod_collaborate\soap\generated\UpdateHtmlSessionDetails;
use mod_collaborate\soap\generated\HtmlAttendeeCollection;
use mod_collaborate\soap\generated\HtmlAttendee;
use mod_collaborate\soap\generated\HtmlSessionRecording;
use mod_collaborate\soap\generated\RemoveHtmlSessionRecording;
use mod_collaborate\soap\generated\RemoveHtmlSession;
use mod_collaborate\soap\api as soapapi;
use mod_collaborate\rest\api as restapi;
use mod_collaborate\logging\constants as loggingconstants;
use mod_collaborate\testable_api;
use mod_collaborate\event\recording_deleted;
use stdClass;

class local {

    const DURATIONOFCOURSE = 9999;

    const TIMEDURATIONOFCOURSE = '3000-01-01 00:00';

    /**
     * Get timeend from duration.
     *
     * @param int $timestart
     * @param int $duration
     * @return int
     */
    public static function timeend_from_duration($timestart, $duration) {
        if ($duration != self::DURATIONOFCOURSE) {
            $timeend = ($timestart + intval($duration));
        } else {
            $timeend = strtotime(self::TIMEDURATIONOFCOURSE);
        }
        return $timeend;
    }

    /**
     * Is timeend classed as open ended.
     * @param $timeend
     * @return bool
     */
    public static function timeend_open_ended($timeend) {
        return ($timeend >= (strtotime(self::TIMEDURATIONOFCOURSE) - WEEKSECS));
    }

    /**
     * Get boundary time in minutes.
     *
     * @return int
     */
    public static function boundary_time() {
        // Hard coded.
        return 15;
    }

    /**
     * get_times
     *
     * @param int | object $collaborate
     * @return object
     */
    public static function get_times($collaborate) {
        global $DB;

        if (!is_object($collaborate)) {
            $collaborate = $DB->get_record('collaborate', array('id' => $collaborate));
        }
        $times = (object) array(
            'start' => intval($collaborate->timestart),
            'end' => self::timeend_from_duration($collaborate->timestart, $collaborate->duration),
            'duration' => $collaborate->duration
        );
        return ($times);
    }

    /**
     * Update the calendar entries for this assignment.
     *
     * @param \stdClass $collaborate- collaborate record
     *
     * @return bool
     */
    public static function update_calendar($collaborate) {
        global $DB, $CFG;
        require_once($CFG->dirroot.'/calendar/lib.php');

        $event = new \stdClass();

        $params = array('modulename' => 'collaborate', 'instance' => $collaborate->id);
        $event->id = $DB->get_field('event', 'id', $params);
        $event->name = $collaborate->name;
        $event->timestart = $collaborate->timestart;
        $event->timeend = self::timeend_from_duration($collaborate->timestart, $collaborate->duration);
        if (!empty($event->timeend)) {
            // Ask if duration is set to "duration of course", then replace the
            // timeend (just in calendar) by the timestart creating a timeduration of 0.
            $lastsdurationofcourse = strtotime(self::TIMEDURATIONOFCOURSE);
            if ($event->timeend == $lastsdurationofcourse) {
                $event->timeend = $event->timestart;
            }
            $event->timeduration = ($event->timeend - $event->timestart);
        } else {
            $event->timeduration = 0;
        }

        // Convert the links to pluginfile. It is a bit hacky but at this stage the files
        // might not have been saved in the module area yet.
        $intro = $collaborate->intro;
        if ($draftid = file_get_submitted_draft_itemid('introeditor')) {
            $intro = file_rewrite_urls_to_pluginfile($intro, $draftid);
        }

        // We need to remove the links to files as the calendar is not ready
        // to support module events with file areas.
        $intro = strip_pluginfile_content($intro);

        $event->description = array(
            'text' => $intro,
            'format' => $collaborate->introformat
        );

        if ($event->id) {
            $calendarevent = \calendar_event::load($event->id);
            $calendarevent->update($event, false);
        } else {
            unset($event->id);
            $event->courseid    = $collaborate->course;
            $event->groupid     = 0;
            $event->userid      = 0;
            $event->modulename  = 'collaborate';
            $event->instance    = $collaborate->id;
            $event->eventtype   = 'due';
            \calendar_event::create($event, false);
        }
    }

    /**
     * Convert a time on the server - e.g. in db - to a UTC time.
     * @param int|\DateTime $time
     *
     * @return bool|int
     */
    public static function servertime_to_utc($time) {

        if ($time instanceof \DateTime) {
            $time = clone ($time); // Clone to break reference.
        }

        // Is this a string that should be an integer? This is stricter than is_numeric.
        if (is_string($time) && strval(intval($time)) === $time) {
            // This is a string that should be an integer - e.g. UTS that has come from a database.
            $time = intval($time);
        }
        if (is_string($time)) {
            if (substr(trim($time), -1, 1) == 'Z') {
                // The date has been specified as a UTC date (see ISO 8601) so strtotime will automatically convert it
                // to local server time.
                return strtotime($time);
            }
            $time = strtotime($time);
        } else if ($time instanceof \DateTime) {
            $time = $time->getTimestamp();
        }

        return strtotime(gmdate('Y-m-d H:i:s', $time));
    }

    /**
     * Is the API configured.
     * @param stdClass|null $config
     * @return bool
     */
    public static function configured(stdClass $config = null) {
        return restapi::configured($config) || soapapi::configured($config);
    }

    /**
     * Require the API to be configured.
     * @param stdClass|null $config
     */
    public static function require_configured(stdClass $config = null) {
        $api = self::select_api($config);
        $api::require_configured();
    }

    /**
     * Return 'sessionid' or 'sessionuid' depending on contents of a record (collaborate or collaborate_session_link).
     * @param $record
     */
    public static function select_sessionid_or_sessionuid($record) {
        return !empty($record->sessionuid) ? 'sessionuid' : 'sessionid';
    }

    public static function get_sessionid_or_sessionuid($record) {
        return self::select_sessionid_or_sessionuid($record);
    }

    /**
     * Select the api className based on configuration / testing status.
     * @param stdClass|null $config
     * @return string
     */
    public static function select_api(stdClass $config = null) {
        if (self::duringtesting()) {
            return 'mod_collaborate\testable_api';
        } else if (restapi::configured($config)) {
            return 'mod_collaborate\rest\api';
        } else if (soapapi::configured($config)) {
            return 'mod_collaborate\soap\api';
        }
        return '';
    }

    /**
     * Return true if api is legacy soap.
     * @return boolean
     */
    public static function api_is_legacy() {
        return stripos(self::select_api(), 'soap');
    }

    /**
     * Get the appropriate API.
     * @param bool $reset
     * @param stdClass|null $config
     * @param string $forceapi - if specified, the specific api will be recovered.
     * @return restapi|soapapi
     */
    public static function get_api($reset = false, stdClass $config = null, $forceapi = '') {
        if (!empty($forceapi)) {
            if ($forceapi === 'rest') {
                return restapi::instance($reset, $config);
            } else if ($forceapi === 'soap') {
                $config = get_config('collaborate');
                return soapapi::get_api($reset, [], null, $config);
            } else if ($forceapi === 'testable') {
                return testable_api::instance($reset, $config);
            }
        }
        // This should use self::select_api once testable_api is implemented fully and all unit tests migrated to not
        // use soap/fakeapi.
        if (restapi::configured($config)) {
            return restapi::instance($reset, $config);
        } else {
            $config = get_config('collaborate');
            return soapapi::get_api($reset, [], null, $config);
        }
    }

    /**
     * Verify that the api works.
     *
     * @param bool $silent
     * @param bool|stdClass $config
     * @return bool
     */
    public static function api_verified($silent = false, $config = false) {
        static $apiverified = null;

        // Only do this once! settings.php was calling this 3 times, hence the static to stop this!
        if ($apiverified !== null) {
            return $apiverified;
        }

        if (self::configured($config)) {
            $api = self::get_api(true, $config);

            if ($api instanceof soapapi) {
                if ($api && $api->is_usable()) {
                    // If silent, will stop error output for now.
                    $api->set_silent($silent);
                    try {
                        $param = new ServerConfiguration();
                        $result = @$api->GetServerConfiguration($param);
                    } catch (\Exception $e) {
                        $result = false;
                    }
                    // Renable error output.
                    $api->set_silent(false);
                } else {
                    $result = false;
                }
            } else {
                // This is enough for checking the REST server's readiness.
                return $api && $api->is_usable();
            }
        } else {
            $result = false;
        }
        $apiverified = false;
        if (!empty($result)) {
            $configresp = $result->getServerConfigurationResponse();
            if (!empty($configresp[0])) {
                $tzone = $configresp[0]->getTimeZone();
                if (!empty($tzone)) {
                    $apiverified = true;
                }
            }
        }
        return ($apiverified);
    }

    /**
     * Get API times from start unix timestamps and duration.
     *
     * @param $starttime
     * @param $duration
     * @return array
     */
    public static function get_apitimes($starttime, $duration) {
        // Note it would be great if we could use date('c', $data->timestart) which would include the server timezone
        // offset in the date - e.g. 2015-04-02T17:00:00+01:00.
        // However, the apollo api does not accept 2015-04-02T17:00:00+01:00
        // So we are converting starttime to a UTC date by subtracting the server time zone offset.
        $starttime = self::servertime_to_utc($starttime);
        $endtime = self::timeend_from_duration($starttime, $duration);
        $api = self::get_api();
        $timestart = $api->api_datetime($starttime);
        $timeend = $api->api_datetime($endtime);
        return [$timestart, $timeend];
    }

    /**
     * Delete a collaborate session.
     * @param int $sessionid
     * @throws \moodle_exception
     * @return bool - true on success, false on failure.
     */
    public static function api_delete_session($sessionid) {

        // API request deletion.
        $api = soapapi::get_api();
        $api->set_silent(true);

        $params = new RemoveHtmlSession($sessionid);
        try {
            $result = $api->RemoveHtmlSession($params);
        } catch (Exception $e) {
            $result = false;
        }
        if ($result === null) {
            // TODO: Warning - this is a bodge fix! - the wsdl2phpgenerator has set up this class so that it is expecting
            // a Success Response object but we are actually getting back a RemoveSessionSuccessResponse element in the
            // xml and as a result of that we end up with a 'null' object.
            $xml = $api->__getLastResponse();
            if (preg_match('/<success[^>]*>true<\/success>/', $xml)) {
                // Manually create the response object!
                $result = new SuccessResponse(true);
            } else {
                $result = false;
            }
        }

        if (!$result || !$result->getSuccess()) {
            $api->process_error(
                'error:failedtodeletesession', constants::SEV_WARNING
            );
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get enrolee ids for course.
     *
     * @param stdClass|string $course
     * @param string $withcapability
     * @param string $withoutcapability
     * @param int $groupid
     * @return array
     */
    public static function enrolees_array(
                                        $course,
                                        $withcapability = '',
                                        $withoutcapability = '',
                                        $groupid = 0,
                                        $incuser = false,
                                        $excuser = false) {

        $courseid = is_string($course) ? $course : $course->id;
        $excludeuserids = [];
        if ($excuser) {
            $excludeuserids[] = $excuser;
        }
        $ids = [];
        if ($incuser) {
            $ids[] = $incuser;
        }
        $users = get_enrolled_users(\context_course::instance($courseid), $withcapability, $groupid);
        if (!empty($withoutcapability)) {
            $excludeusers = get_enrolled_users(\context_course::instance($courseid), $withoutcapability, $groupid);
            foreach ($excludeusers as $user) {
                $excludeuserids[] = $user->id;
            }
        }

        foreach ($users as $user) {
            if (!in_array($user->id, $excludeuserids)) {
                $ids[] = $user->id;
            }
        }

        return array_unique($ids);
    }

    /**
     * Get chair enrolees for course.
     *
     * @param stdClass|string $course
     * @param int $groupid
     * @return array
     */
    public static function moderator_enrolees($course, $groupid = 0) {
        global $USER;
        return self::enrolees_array($course, 'moodle/grade:viewall', '', $groupid, $USER->id);
    }

    /**
     * Get non-chair enrolees for course.
     *
     * @param stdClass|string $course
     * @param int $groupid
     * @return array
     */
    public static function participant_enrolees($course, $groupid = 0) {
        global $USER;
        return self::enrolees_array($course, '', 'moodle/grade:viewall', $groupid, false, $USER->id);
    }

    /**
     * Is the current request via ajax?
     *
     * @return bool
     */
    public static function via_ajax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }

    /**
     * get recordings
     *
     * @param stdClass $collaborate
     * @param \cm_info $cm
     * @return soap\generated\HtmlSessionRecordingResponse[][]
     */
    public static function get_recordings($collaborate, \cm_info $cm) {

        $sessionlinks = sessionlink::my_active_links($collaborate, $cm);

        $sessionrecordings = [];

        $api = soapapi::get_api();
        foreach ($sessionlinks as $sessionlink) {
            if (empty($sessionlink->sessionid)) {
                continue;
            }
            $session = new HtmlSessionRecording();
            $session->setSessionId($sessionlink->sessionid);
            $result = $api->ListHtmlSessionRecording($session);
            $recordings = [];
            if ($result) {
                $respobjs = $result->getHtmlSessionRecordingResponse();
                if (is_array($respobjs) && !empty($respobjs)) {
                    $recordings = $respobjs;
                }
            }
            $sessionrecordings[$sessionlink->sessionid] = $recordings;
        }
        return $sessionrecordings;
    }

    /**
     * Delete recording
     *
     * @param int $recordingid
     * @param string $recordingname
     * @param \cm_info $cm
     */
    public static function delete_recording($recordingid, $recordingname, \cm_info $cm) {
        global $DB;

        require_capability('mod/collaborate:deleterecordings', $cm->context);

        $api = soapapi::get_api();

        $delrec = new RemoveHtmlSessionRecording($recordingid);
        // Note, this is returning 'null' at the moment, so no way to return success boolean.
        $api->RemoveHtmlSessionRecording($delrec);

        // Recording deleted, log this event!
        $data = [
            'context' => $cm->context,
            'objectid' => intval($cm->instance),
            'other' => [
                'recordingid' => $recordingid,
                'recordingname' => $recordingname
            ],
        ];
        $event = recording_deleted::create($data);

        // Delete recording info (view counts, etc).
        $record = ['instanceid' => $cm->instance, 'recordingid' => $recordingid];
        $DB->delete_records('collaborate_recording_info', $record);

        // Delete the cached recording counts.
        \cache::make('mod_collaborate', 'recordingcounts')->delete($cm->instance);

        // Trigger the event.
        $event->trigger();

    }

    /**
     * Get / cache guest url.
     *
     * @param stdClass $collaborate - collaborate record.
     * @param bool $forcesoap - force a soap call.
     */
    public static function guest_url(stdClass $collaborate, $forcesoap = false) {
        global $DB;

        if (empty($collaborate->guestaccessenabled)) {
            return;
        }

        if (!empty($collaborate->guesturl) && !$forcesoap) {
            return $collaborate->guesturl;
        }

        // Get guest url.
        $api = soapapi::get_api();
        $param = new BuildHtmlSessionUrl($collaborate->sessionid);
        $sessionurl = $api->BuildHtmlSessionUrl($param);
        $url = $sessionurl->getUrl();

        // Update collaborate record with guest url.
        $record = (object) [
            'id' => $collaborate->id,
            'guesturl' => $url
        ];
        $DB->update_record('collaborate', $record);

        return $url;
    }

    /**
     * Is this script running during testing?
     *
     * @return bool
     */
    public static function duringtesting() {
        $runningphpunittest = defined('PHPUNIT_TEST') && PHPUNIT_TEST;
        $runningbehattest = defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING;
        return ($runningphpunittest || $runningbehattest);
    }

}
