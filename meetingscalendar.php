<?php

use core_calendar\local\event\proxies\coursecat_proxy;
use core_calendar\local\event\proxies\std_proxy;
use core_calendar\local\event\proxies\cm_info_proxy;
use core_calendar\local\event\value_objects\event_description;
use mod_zoom\external\visios_month_exporter;

require_once('../../config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/calendar/lib.php');

$categoryid = optional_param('category', null, PARAM_INT);
$courseid = optional_param('course', SITEID, PARAM_INT);
$view = optional_param('view', 'upcoming', PARAM_ALPHA);
$day = optional_param('cal_d', 0, PARAM_INT);
$mon = optional_param('cal_m', 0, PARAM_INT);
$year = optional_param('cal_y', 0, PARAM_INT);
$time = optional_param('time', 0, PARAM_INT);
$lookahead = optional_param('lookahead', null, PARAM_INT);

$url = new moodle_url('/calendar/view.php');

/**
 * Get the calendar view output.
 *
 * @param   \calendar_information $calendar The calendar being represented
 * @param   string  $view The type of calendar to have displayed
 * @param   bool    $includenavigation Whether to include navigation
 * @param   bool    $skipevents Whether to load the events or not
 * @param   int     $lookahead Overwrites site and users's lookahead setting.
 * @return  array[array, string]
 */
function sonate_calendar_get_view(\calendar_information $calendar, $view, $includenavigation = true, bool $skipevents = false,
        ?int $lookahead = null, $prev_month, $next_month) {
    global $PAGE, $CFG, $DB;

    $renderer = $PAGE->get_renderer('core_calendar');
    $type = \core_calendar\type_factory::get_calendar_instance();

    // Calculate the bounds of the month.
    $calendardate = $type->timestamp_to_date_array($calendar->time);

    $date = new \DateTime('now', core_date::get_user_timezone_object(99));
    $eventlimit = 0;

    if ($view === 'day') {
        $tstart = $type->convert_to_timestamp($calendardate['year'], $calendardate['mon'], $calendardate['mday']);
        $date->setTimestamp($tstart);
        $date->modify('+1 day');
    } else if ($view === 'upcoming' || $view === 'upcoming_mini') {
        // Number of days in the future that will be used to fetch events.
        if (!$lookahead) {
            if (isset($CFG->calendar_lookahead)) {
                $defaultlookahead = intval($CFG->calendar_lookahead);
            } else {
                $defaultlookahead = CALENDAR_DEFAULT_UPCOMING_LOOKAHEAD;
            }
            $lookahead = get_user_preferences('calendar_lookahead', $defaultlookahead);
        }

        // Maximum number of events to be displayed on upcoming view.
        $defaultmaxevents = CALENDAR_DEFAULT_UPCOMING_MAXEVENTS;
        if (isset($CFG->calendar_maxevents)) {
            $defaultmaxevents = intval($CFG->calendar_maxevents);
        }
        $eventlimit = get_user_preferences('calendar_maxevents', $defaultmaxevents);

        $tstart = $type->convert_to_timestamp($calendardate['year'], $calendardate['mon'], $calendardate['mday'],
                $calendardate['hours']);
        $date->setTimestamp($tstart);
        $date->modify('+' . $lookahead . ' days');
    } else {
        $tstart = $type->convert_to_timestamp($calendardate['year'], $calendardate['mon'], 1);
        $monthdays = $type->get_num_days_in_month($calendardate['year'], $calendardate['mon']);
        $date->setTimestamp($tstart);
        $date->modify('+' . $monthdays . ' days');

        if ($view === 'mini' || $view === 'minithree') {
            $template = 'core_calendar/calendar_mini';
        } else {
            $template = 'core_calendar/calendar_month';
        }
    }

    // We need to extract 1 second to ensure that we don't get into the next day.
    $date->modify('-1 second');
    //$tend = $date->getTimestamp();
    $tend = new DateTime('now', new DateTimeZone(core_date::normalise_timezone($CFG->timezone)));
    // Sub-query that fetches the list of unique events that were filtered based on priority.
    $subquery = "SELECT ev.modulename,
            ev.instance,
            ev.eventtype,
            MIN(ev.priority) as priority
        FROM {event} ev
        GROUP BY ev.modulename, ev.instance, ev.eventtype";

    // Build the main query.
    $sql = "SELECT e.*
        FROM {event} e
        INNER JOIN ($subquery) fe
        ON e.modulename = fe.modulename
        AND e.instance = fe.instance
        AND e.eventtype = fe.eventtype
        AND (e.priority = fe.priority OR (e.priority IS NULL AND fe.priority IS NULL))
        LEFT JOIN {modules} m
        ON e.modulename = m.name
        WHERE (m.visible = 1 OR m.visible IS NULL)
        AND (e.modulename = 'visio' OR e.modulename = 'zoom')
        AND e.timestart BETWEEN ? AND ?
        ORDER BY " . ("e.timestart");

    $events = $DB->get_records_sql($sql, array($prev_month, $next_month));
    $new_events = [];

    foreach($events as $event) {
        if (!is_numeric($event->timemodified)) {
            $event->timemodified = preg_replace('/[\W\s\/]+/', '-', $event->timemodified);
            $dateTime = new DateTime($event->timemodified, new DateTimeZone(core_date::normalise_timezone($CFG->timezone)));
            $event->timemodified = $dateTime->format('U');
        }
        $description = new event_description($event->description, 1);
        $category = new coursecat_proxy($event->categoryid);
        $lamecallable = function($id) use (&$event) { return (object)['id' => $event->courseid]; };
        $course = new std_proxy($event->courseid, $lamecallable);
        $module = new cm_info_proxy($event->modulename, $event->instance, $event->courseid);

	$time_event = new \core_calendar\local\event\value_objects\event_times(
            (new \DateTimeImmutable())->setTimestamp($event->timestart),
            (new \DateTimeImmutable())->setTimestamp($event->timestart + $event->timeduration),
            (new \DateTimeImmutable())->setTimestamp($event->timesort),
            (new \DateTimeImmutable())->setTimestamp($event->timemodified),
            (new \DateTimeImmutable())->setTimestamp(usergetmidnight(time()))
        );
        $obj = new \core_calendar\local\event\entities\event($event->id, $event->name, $description, $category, $course, null, null, null, $module, $event->type, $time_event, null);
        $new_events[] = $obj;
    }

    $related = [
        'events' => $new_events,
        'cache' => new \core_calendar\external\events_related_objects_cache($new_events),
        'type' => $type,
    ];

    $data = [];
    if ($view == "month" || $view == "mini" || $view == "minithree") {
        $month = new visios_month_exporter($calendar, $type, $related);
        $month->set_includenavigation($includenavigation);
        $month->set_initialeventsloaded(!$skipevents);
        $month->set_showcoursefilter(false);
        $data = $month->export($renderer);
        $data->viewingmonth = true;
    } else if ($view == "day") {
        $day = new \core_calendar\external\calendar_day_exporter($calendar, $related);
        $data = $day->export($renderer);
        $data->viewingday = true;
        $template = 'core_calendar/calendar_day';
    } else if ($view == "upcoming" || $view == "upcoming_mini") {
        $upcoming = new \core_calendar\external\calendar_upcoming_exporter($calendar, $related);
        $data = $upcoming->export($renderer);

        if ($view == "upcoming") {
            $template = 'core_calendar/calendar_upcoming';
            $data->viewingupcoming = true;
        } else if ($view == "upcoming_mini") {
            $template = 'core_calendar/calendar_upcoming_mini';
        }
    }

    return [$data, $template];
}


if (!empty($day) && !empty($mon) && !empty($year)) {
    if (checkdate($mon, $day, $year)) {
        $time = make_timestamp($year, $mon, $day);
    }
}

if (empty($time)) {
    $time = time();
}

$iscoursecalendar = $courseid != SITEID;

if ($iscoursecalendar) {
    $url->param('course', $courseid);
}

if ($categoryid) {
    $url->param('categoryid', $categoryid);
}

if ($view !== 'upcoming') {
    $time = usergetmidnight($time);
    $url->param('view', $view);
}

$url->param('time', $time);

$PAGE->set_url($url);

$course = get_course($courseid);

if ($iscoursecalendar && !empty($courseid)) {
    navigation_node::override_active_url(new moodle_url('/course/view.php', array('id' => $course->id)));
} else if (!empty($categoryid)) {
    core_course_category::get($categoryid); // Check that category exists and can be accessed.
    $PAGE->set_category_by_id($categoryid);
    navigation_node::override_active_url(new moodle_url('/course/index.php', array('categoryid' => $categoryid)));
} else {
    $PAGE->set_context(context_system::instance());
}

require_login($course, false);

$calendar = calendar_information::create($time, 0, 0);

$pagetitle = '';

$strcalendar = get_string('meetingscalendar', 'mod_zoom');

switch($view) {
    case 'day':
        $PAGE->navbar->add(userdate($time, get_string('strftimedate')));
        $pagetitle = get_string('dayviewtitle', 'calendar', userdate($time, get_string('strftimedaydate')));
    break;
    case 'month':
        $PAGE->navbar->add(userdate($time, get_string('strftimemonthyear')));
        $pagetitle = get_string('detailedmonthviewtitle', 'calendar', userdate($time, get_string('strftimemonthyear')));
    break;
    case 'upcoming':
        $pagetitle = get_string('upcomingevents', 'calendar');
    break;
}

// Print title and header
$PAGE->set_pagelayout('standard');
$PAGE->set_title("$course->shortname: $strcalendar: $pagetitle");
$PAGE->set_heading($strcalendar);

$renderer = $PAGE->get_renderer('core_calendar');
$calendar->add_sidecalendar_blocks($renderer, true, $view);

echo $OUTPUT->header();
echo $renderer->start_layout();
echo html_writer::start_tag('div', array('class'=>'heightcontainer'));

$a_date = date("Y/m/d", $time);
$prev_month = strtotime(date("Y-m-t", strtotime($a_date . '-1 month') - 96*3600));
$next_month = strtotime(date("Y-m-t", strtotime($a_date . '+1 month') - 96*3600));

echo '<div class="row mb-3 calendar-controls">';
echo '<a href="'.$CFG->wwwroot.'/mod/zoom/meetingscalendar.php?view=month&amp;time='.$prev_month.'" id="previous-custom" class="arrow_link previous pl-3" title="Mois précédent" data-year="2020" data-month="11" data-drop-zone="nav-link">';
echo '<span class="arrow">◀︎</span>&nbsp;<span class="arrow_text">' . date("F", $prev_month) . '</span>';
echo '</a>';
echo '<span class="hide"> | </span><h2 class="current"></h2><span class="hide"> | </span>';
echo '<a href="'.$CFG->wwwroot.'/mod/zoom/meetingscalendar.php?view=month&amp;time='.$next_month.'" id="next-custom" class="arrow_link next pr-3" title="Mois prochain" data-year="2021" data-month="1" data-drop-zone="nav-link">';
echo '<span class="arrow_text">' . date("F", $next_month) . ' 2021</span>&nbsp;<span class="arrow">▶︎</span></a></div>';

list($data, $template) = sonate_calendar_get_view($calendar, 'month', true, false, $lookahead, $prev_month, $next_month);
echo $renderer->render_from_template($template, $data);

echo html_writer::end_tag('div');

list($data, $template) = calendar_get_footer_options($calendar);
echo $renderer->render_from_template($template, $data);

?>

<script type='text/javascript'>
document.getElementById('calendarviewdropdown').style.display='none';
var links = document.getElementsByClassName('arrow_link');
for (var i = 0; i < links.length; i++) {
    if (links[i].id != 'previous-custom' && links[i].id != 'next-custom') {
        links[i].style.visibility = 'hidden';
    }
}
document.querySelectorAll('[data-action="new-event-button"]')[0].style.display = 'none';
</script>

<?php

echo $renderer->complete_layout();
echo $OUTPUT->footer();