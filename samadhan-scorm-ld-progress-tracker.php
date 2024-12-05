<?php

/*
 * Plugin Name: Samadhan Scorm xAPI Tracker for Learndash
 * Plugin URI:   https://samadhan.com.bd/
 * Description:  User Scorm xAPI course progress tracking on Learndash
 * Version:      2.2.0
 * Author:       Samadhan Solution Pty Ltd.
 */


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once(dirname(__FILE__) . '/includes/lzw_compress.php');

add_action('init', 'smdn_xapi_progress_tracker_init');

function smdn_xapi_progress_tracker_init()
{
    wp_enqueue_style( 'smdn-custom-progress-bar-styles', plugin_dir_url( __FILE__ ) . 'apps/css/smdn-xapi-scorm-tracker.css', array(), '1.0.0' );
    add_filter('learndash-course-progress-stats', 'samadhan_learndash_course_progress', 10, 1);
    add_filter('learndash-topic-progress-stats', 'samadhan_learndash_course_progress', 10, 1);
    add_filter('learndash-focus-progress-stats', 'samadhan_learndash_course_progress', 10, 1);
}


add_filter('learndash_template','smdn_learndash_template',1200,5);
function smdn_learndash_template($filepath,$name,$args,$echo,$return_file_path ){

       $path= plugin_dir_path( __FILE__ ).'learndash/ld30/' ;

        if($name==='lesson/partials/row.php'){
            return $path.$name;
        }
        if($name==='widgets/navigation/lesson-row.php'){
            return $path.$name;
        }


    return $filepath;
}

function samadhan_learndash_course_progress($progress)
{
    global $post;

    // SCORM progress calculation (from old plugin)
    $course_id = learndash_get_course_id($post->ID);
    $lesson_count = count(learndash_get_course_lessons_list($course_id));

    if($lesson_count>1){
        if($post->post_type=='sfwd-courses'){
            $course_id=$post->ID;
            $progress=smdn_course_progress($progress, $course_id);
        }

        if($post->post_type=='sfwd-lessons'){
            $course_id=get_post_meta($post->ID,'course_id',true);
            $progress=smdn_course_progress($progress, $course_id);
        }

        if($post->post_type=='sfwd-topic'){
            $course_id=get_post_meta($post->ID,'course_id',true);
            $progress=smdn_course_progress($progress, $course_id);
        }
        if($post->post_type=='sfwd-quiz'){
            $course_id=get_post_meta($post->ID,'course_id',true);
            $progress=smdn_course_progress($progress, $course_id);
        }
    }
    else {
        $progress = samadhan_rise_progress_translate($course_id, $progress);
    }


    return $progress;
}

function smdn_course_progress($progress, $course_id)
{
    $user_id = get_current_user_id();
    $total_steps = learndash_get_course_lessons_list($course_id, $user_id);
    $completed_steps_count = 0;

    foreach ($total_steps as $step) {
        if ($step['status'] == 'completed') {
            $completed_steps_count++;
        }
    }

    $course_progress = get_user_meta($user_id, '_sfwd-course_progress', true);
    if (!empty($course_progress)) {
        $course_completed = $course_progress[$course_id]['completed'];
        if ($course_completed != $progress['completed']) {
            $progress['completed'] = $completed_steps_count;
            $progress['percentage'] = ceil(($completed_steps_count / count($total_steps)) * 100);
        }

        if ($progress['percentage'] > 100) {
            $progress['percentage'] = 100;
        }
    }

    return $progress;
}
/****
 * @param $progress
 * @param $course_id
 * @return mixed
 * @template override into child theme.
 * plugin file path is " wp-content/plugins/sfwd-lms/themes/ld30/templates/shortcodes/profile/course-row.php"
 * theme file path is "learndash/ld30/shortcodes/profile/course-row.php"
 * the function will include 24 number line of course-row.php below here.
 * if(function_exists('samadhan_learndash_user_profile_course_progress')){
 * $progress=samadhan_learndash_user_profile_course_progress( $progress,$course_id );
 * }
 *
 */
function samadhan_learndash_user_profile_course_progress($progress, $course_id)
{
    return samadhan_rise_progress_translate($course_id, $progress);
}
function samadhan_rise_progress_translate($course_id,$progress = array(),$lesson_id = 0)
{

    global $post;
    global $wpdb;
    $user_id = get_current_user_id();
    $post_type=$post->post_type;

    // Retrieve the dynamically updated total steps
    $courses_total_steps = smdn_get_total_steps();
    $total_steps = isset($courses_total_steps[$course_id]) ? $courses_total_steps[$course_id] : 60;

    $new_courses = array_keys($courses_total_steps); // List of courses with defined total steps

    // Check if the course is one of the new courses
    if (in_array($course_id, $new_courses)) {
        $manual_progress = calculate_manual_progress($course_id, $user_id);
        $progress['percentage'] = ceil($manual_progress);
        $progress['completed'] = ceil($manual_progress * $total_steps / 100);
        $progress['total'] = $total_steps;
        $course_progress[] = $progress['percentage'];
        $progress['course_progress'] = $course_progress; // Add course progress data to the result

        return $progress;
    }
    $lesson_content = get_post_field('post_content', $lesson_id);

//     if (strpos($lesson_content, '[vc_snc') === false && $post_type=='sfwd-lessons') {
//         return $progress;
//     }
	
// 	if (strpos($lesson_content, '[vc_snc') === false && $post_type=='sfwd-quiz') {
//         return $progress;
//     }

    $lesson_query='';
    if ($lesson_id>0){
        $lesson_query = " AND lesson_id=$lesson_id";
    }


    $progress_data = $wpdb->get_results(
        "SELECT value FROM {$wpdb->prefix}uotincan_resume 
        WHERE state = 'suspend_data' AND course_id = $course_id AND user_id = $user_id  $lesson_query",
        OBJECT
    );
	
	if(empty($progress_data)){
        return $progress;
    }
	
    $total_lesson = $wpdb->get_results(
        "SELECT value FROM {$wpdb->prefix}uotincan_resume 
        WHERE state = 'suspend_data' AND course_id = $course_id AND user_id = $user_id  ",
        OBJECT
    );

    $completed_lessons = 0;
    $total_progress = 0;
    $scorm_file_count = count($progress_data);
    $course_progress = array(); // Progress data for each course

    foreach ($progress_data as $data) {


        $data = json_decode($data->value);
        $dec_data = samadhan_decompress($data->d);
        $dec_data = str_replace(':,', ':0,', $dec_data);
        $dec_data = str_replace(':}', ':0}', $dec_data);
        $scorm_progress = json_decode($dec_data, true, 1024, JSON_INVALID_UTF8_SUBSTITUTE);



        $course_progress[] = $scorm_progress['progress']['p']; // Store progress data for each course
    }
    foreach ($total_lesson as $lesson_data) {


        $lesson_data = json_decode($lesson_data->value);
        $dec_data = samadhan_decompress($lesson_data->d);
        $dec_data = str_replace(':,', ':0,', $dec_data);
        $dec_data = str_replace(':}', ':0}', $dec_data);
        $scorm_progress = json_decode($dec_data, true, 1024, JSON_INVALID_UTF8_SUBSTITUTE);

        if (isset($scorm_progress['progress']['lessons']) && is_array($scorm_progress['progress']['lessons'])) {
            $completed_lessons += count($scorm_progress['progress']['lessons']);

           if(!empty($progress['percentage']) && $progress['percentage']==100){
               $total_progress += $scorm_progress['progress']['p']=100;
           }else{
               $total_progress += $scorm_progress['progress']['p'];
           }

        }

    }

    $posts = learndash_get_lesson_list( $course_id, array( 'num' => 0 ) );
    if(count($posts)>1){
        $total_lesson=$posts;
    }
    if ($completed_lessons > 0) {
        $average_progress = $total_progress / count($total_lesson);
        $percentage = min(100, $average_progress);
    } else {
        $percentage = 0;
    }

    $progress['percentage'] = ceil($percentage);
    $progress['completed'] = $completed_lessons;
    $progress['total'] = $percentage>0 ? ceil($completed_lessons * 100 / $percentage) : 0;
    $progress['scorm_files'] = $scorm_file_count;
    $progress['lesson_id'] = $lesson_id;
    $progress['course_progress'] = $course_progress; // Add course progress data to the result

    return $progress;
}

// Initialize total steps for new courses
function smdn_get_total_steps()
{
    $default_steps = 60;
    $courses_total_steps = get_option('smdn_courses_total_steps', [
        27335 => 137,
        27337 => 108,
        27339 => 108,
        27341 => 137,
        27439 => 205,
    ]);
    return $courses_total_steps;
}

// Update total steps dynamically
function smdn_update_total_steps($course_id, $completed_steps)
{
    $courses_total_steps = smdn_get_total_steps();
    if (isset($courses_total_steps[$course_id]) && $completed_steps > $courses_total_steps[$course_id]) {
        $courses_total_steps[$course_id] = $completed_steps;
        update_option('smdn_courses_total_steps', $courses_total_steps);
    }
}

// Calculate manual progress with dynamic total steps
function calculate_manual_progress($course_id, $user_id)
{
    global $wpdb;

    // Get total steps for the course
    $courses_total_steps = smdn_get_total_steps();
    $total_steps = isset($courses_total_steps[$course_id]) ? $courses_total_steps[$course_id] : 60;

    // Get unique leave counts for the user and course
    $leave_count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT target_name) 
             FROM {$wpdb->prefix}uotincan_reporting 
             WHERE course_id = %d AND user_id = %d AND verb = 'leave'",
            $course_id,
            $user_id
        )
    );

    // Check if 'Course Exit' exists
    $course_exit_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$wpdb->prefix}uotincan_reporting 
             WHERE course_id = %d AND user_id = %d AND verb = 'leave' AND target_name = 'Course Exit'",
            $course_id,
            $user_id
        )
    );

    // Calculate progress
    $progress = ($leave_count / $total_steps) * 100;

    // Adjust progress if 'Course Exit' is missing
    if ($progress == 100 && !$course_exit_exists) {
        $progress = 98;
    }

    // Update total steps dynamically if leave_count exceeds total_steps
    smdn_update_total_steps($course_id, $leave_count);

    return $progress;
}

/*
function samadhan_learndash_user_profile_lesson_progress($user_id, $lesson_id, $progress = [])
{

    global $post, $wpdb;


    $progress_data = $wpdb->get_row("SELECT value FROM {$wpdb->prefix}uotincan_resume WHERE state = 'suspend_data' and lesson_id = $lesson_id and user_id=$user_id", OBJECT);

    if (isset($progress_data)) {
        $data = json_decode($progress_data->value);
        $dec_data = samadhan_decompress($data->d);
        $progress = json_decode($dec_data, true);
        $percentage = $progress['progress']['p'];
        $completed_lessons = count($progress['progress']['lessons']);
        $progress["percentage"] = $percentage;
        $progress["completed"] = $completed_lessons;
        $progress["total"] = ceil($completed_lessons * 100 / $percentage);

    }
    return $progress;


}
*/

add_filter('learndash_process_mark_complete', 'smdn_get_learndash_process_mark_complete', 10, 3);
function smdn_get_learndash_process_mark_complete($true, $post, $current_user)
{

    $lesson_id = $post->ID;
    $user_id = $current_user->ID;
    $course_id = learndash_get_course_id($post->ID);
    //$progress = samadhan_learndash_user_profile_lesson_progress($user_id, $lesson_id);

    $progress = samadhan_rise_progress_translate($course_id);
    $totalProgress = $progress["percentage"];

    if (get_post_type($lesson_id) === 'sfwd-courses') {
        $categories = smdn_get_category_lesson_id($lesson_id, 'ld_course_category');
    }
    if (get_post_type($lesson_id) === 'sfwd-lessons') {
        $categories = smdn_get_category_lesson_id($lesson_id, 'ld_lesson_category');
    }
    if (get_post_type($lesson_id) === 'sfwd-topic') {
        $categories = smdn_get_category_lesson_id($lesson_id, 'ld_topic_category');
    }

    $category_lesson_id = $categories->lesson_id;

    $user_meta = get_userdata($user_id);

    $user_roles = $user_meta->roles;

    if (in_array('administrator', $user_roles, true)) {
        return true;
    }

    if ($category_lesson_id == $lesson_id) {
        if ($totalProgress >= 100) {
            return true;
        } else {
            return false;
        }
    } else {
        return true;
    }


}


function smdn_get_category_lesson_id($lesson_id, $taxonomy = '')
{

    global $post, $wpdb;
    $query = "SELECT tr.object_id as lesson_id FROM {$wpdb->prefix}terms as t
                INNER JOIN {$wpdb->prefix}term_taxonomy as tt
                ON t.term_id=tt.term_id and tt.taxonomy='$taxonomy'
                INNER JOIN {$wpdb->prefix}term_relationships as tr
                ON t.term_id=tt.term_taxonomy_id
                WHERE  t.slug='scrom-lesson'  and tr.object_id=$lesson_id";
    return $wpdb->get_row($query);
}


function smdn_custom_progress_bar($progress_percentage){	
    $progress_bar_html = '<div class="smdn-custom-progress-bar" title="'.($progress_percentage*10).'% ">';
    $progress_bar_html .= '<svg >';
    $progress_bar_html .= '<circle class="progress-circle-background" cx="12" cy="12.5" r="10"></circle>';
    $progress_bar_html .= '<circle  class="progress-circle-progress" cx="12" cy="12.5" r="10" style="--percent: ' . $progress_percentage . '"></circle>';
    $progress_bar_html .= '</svg>';
    $progress_bar_html .= '</div>';
    echo $progress_bar_html;
}