<?php
class Chimpvine_View_Homework_Submissions {
    public function __construct() {
        // add_action('Chimpvine_View_Homework_Submissions', [$this,'handle_permision']);
        add_shortcode('chimpvine_class_view_homeworks_submissions', [$this, 'handle_teacher_view_homeworks']);
        add_action('wp_ajax_reject_homework_request', [$this, 'reject_homework_request']);
        add_action('wp_ajax_accept_homework_request', [$this, 'accept_homework_request']);
       
        
    }
    public function handle_teacher_view_homeworks($atts) {
         
        global $wpdb;

        $atts = shortcode_atts(['groupid' => null], $atts);
        $groupid = isset($_GET['groupid']) ? intval($_GET['groupid']) : null; // Filter by groupid from dropdown
        $current_user_id = get_current_user_id();

        // Retrieve all groups where the current user is an admin
        
        $all_groups_query = "
            SELECT g.ClassGroupID, g.ClassGroupName
            FROM {$wpdb->prefix}chimpvine_class_groups g
            INNER JOIN {$wpdb->prefix}chimpvine_class_groups_students s ON g.ClassGroupID = s.ClassGroupID
            WHERE s.MemberID = %d AND s.IsAdmin = 1";
        $all_groups = $wpdb->get_results($wpdb->prepare($all_groups_query, $current_user_id));
        $group_query = $all_groups_query;
        $params = [$current_user_id];
        if ($groupid) {
            $group_query .= " AND g.ClassGroupID = %d";
            $params[] = $groupid;
        }
        $groups = $wpdb->get_results($wpdb->prepare($group_query, $params));
        echo '<form method="GET">';
        echo '<label for="group-filter">Filter by Group:</label>';
        echo '<select name="groupid" id="group-filter" onchange="this.form.submit()">';
        echo '<option value="">All Groups</option>';
        foreach ($all_groups as $group_option) {
            $selected = ($groupid == $group_option->ClassGroupID) ? 'selected' : '';
            echo '<option value="' . esc_attr($group_option->ClassGroupID) . '" ' . $selected . '>' . esc_html($group_option->ClassGroupName) . '</option>';
        }
        echo '</select>';
        echo '</form>';
        echo '<table>';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Group Name</th>';
        echo '<th>User Name</th>';
        echo '<th>Homework Title</th>';
        echo '<th>Homework Type</th>';
        echo '<th>Status</th>';
        echo '<th>Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        if (!empty($groups)) {
            foreach ($groups as $group) {

                // Retrieve members who are not admins
                $members_query = "
                    SELECT u.ID as userID, u.user_login
                    FROM {$wpdb->prefix}chimpvine_class_groups_students s
                    INNER JOIN {$wpdb->prefix}users u ON s.MemberID = u.ID
                    WHERE s.ClassGroupID = %d AND s.IsAdmin = 0";
                $members = $wpdb->get_results($wpdb->prepare($members_query, $group->ClassGroupID));

                if (!empty($members)) {
                    foreach ($members as $member) {

                        // Retrieve homework for the current group
                        $homework_query = "
                            SELECT h.*, uh.userHomeID, uh.AttachedUrl, uh.IsRequest, uh.IsReject, uh.ReqDate
                            FROM {$wpdb->prefix}chimpvine_homework h
                            LEFT JOIN {$wpdb->prefix}chimpvine_user_homework uh ON h.HomeWorkID = uh.HomeWorkID AND uh.userID = %d
                            WHERE h.ClassGroupID = %d";
                        $homework_results = $wpdb->get_results($wpdb->prepare($homework_query, $member->userID, $group->ClassGroupID));

                        // Display homework details in table rows, but only show submitted/completed ones
                        foreach ($homework_results as $homework) {
                            $display_row = false; // Flag to determine if the row should be displayed

                            // Check if it's a PDF-based or Quiz-based homework
                            if ($homework->HomePdfUrl) {
                                
                                if ($homework->userHomeID) {
                                    $display_row = true;
                                    if (($homework->IsRequest && $homework->AttachedUrl && !$homework->IsReject) || ($homework->IsRequest && !$homework->AttachedUrl && !$homework->IsReject)) {
                                        $status = 'Requested reupload (Pdf)';
                                    } elseif (!$homework->IsRequest && $homework->AttachedUrl && !$homework->IsReject) {
                                        $status = 'Submmited Homework (Pdf)';
                                    } elseif ($homework->IsRequest && $homework->AttachedUrl && $homework->IsReject) {
                                        $status = 'Rejected upload (Pdf)';
                                    } elseif (!$homework->IsRequest && !$homework->AttachedUrl && !$homework->IsReject) {
                                        $status = 'Request Accepted (Pdf)';
                                    }

                                }
                            } else {
                      
                                $quiz_check = $wpdb->get_var($wpdb->prepare("
                                    SELECT COUNT(*) 
                                    FROM {$wpdb->prefix}aysquiz_reports 
                                    WHERE quiz_id = %d AND user_id = %d 
                                    AND end_date BETWEEN %s AND %s 
                                    AND status = 'finished'",
                                    $homework->ContentID, $member->userID, $homework->HomeWorkAssignedDate,
                                    $homework->HomeWorkDeadlineDate
                                ));
                                if ($quiz_check) {
                                    $display_row = true;
                                    $status = 'Completed (Quiz)';
                                }
                            }

                            // Only display the row if the homework is either submitted or completed
                            if ($display_row) {
                                echo '<tr>';
                                
                                // Group Name
                                echo '<td>' . esc_html($group->ClassGroupName) . '</td>';

                                // User Name
                                echo '<td>' . esc_html($member->user_login) . '</td>';
                                
                                // Homework Title
                                $homework_title = $homework->HomePdfTitle ?: get_the_title($homework->HomePostID);
                                echo '<td>' . esc_html($homework_title) . '</td>';

                                // Homework Type
                                $homework_type = $homework->HomePdfUrl ? 'External Homework' : 'Chimpvine Homework';
                                echo '<td>' . esc_html($homework_type) . '</td>';

                                // Status
                                echo '<td>' . esc_html($status) . '</td>';

                                // Actions (e.g., view PDF or quiz)
                                echo '<td>';
                                if ($homework->HomePdfUrl) {
                                    echo '<a href="' . esc_url($homework->HomePdfUrl) . '" target="_blank">View PDF</a>';
                                    if ($homework->userHomeID) {
                                        if (($homework->IsRequest && $homework->AttachedUrl && !$homework->IsReject && strtotime($homework->HomeWorkDeadlineDate) <= time()) || ($homework->IsRequest && !$homework->AttachedUrl && !$homework->IsReject && strtotime($homework->HomeWorkDeadlineDate) <= time())) {
                                            echo '<button type="button" style="margin-left:10px;" class="request-reject-btn button" data-homework-id="' . $homework->HomeWorkID . '" data-user-home-id="' . $homework->userHomeID . '">Reject Request</button>';
                                            echo '<button type="button" style="margin-left:10px;" class="request-accept-btn button" data-homework-id="' . $homework->HomeWorkID . '" data-user-home-id="' . $homework->userHomeID . '">Accept Request</button>';
                                        } elseif (!$homework->IsRequest && $homework->AttachedUrl && !$homework->IsReject) {
                                            echo '<a href="' . esc_url($homework->AttachedUrl) . '" target="_blank" style="margin-left:10px;">View Submitted</a>';
                                        }
                                    }
                                } else {
                                      $user_id = $member->userID;
                                     echo '<button id="view-report-btn" data-user-id="' . esc_attr($user_id) . '" style="padding: 10px 20px; background-color: #8F48D8; color: white; border: none; cursor: pointer;">View Quiz Report</button>';
                                }
                                echo '</td>';
                                echo '</tr>';
                            }
                        }
                    }
                } else {
                    echo '';
                }
            }
        } else {
            echo '<tr><td colspan="6">No groups found for this user.</td></tr>';
        }
         
        echo '</tbody>';
        echo '</table>';
        
      
        $question_titles = []; 
        
        //  $user_id = $member->userID;

        $query = $wpdb->prepare(
            "SELECT options
             FROM {$wpdb->prefix}aysquiz_reports
             WHERE user_id = %d",
            $user_id
        );
        
        $result = $wpdb->get_var($query);
        
        if ($result) {
            $quiz_data = json_decode($result, true);
        
            if (isset($quiz_data['correctness'])) {
                $correctness = $quiz_data['correctness'];
              
               
                foreach ($correctness as $question_id => $value) {
                  
                    preg_match('/(\d+)$/', $question_id, $matches);
                     
                    if (isset($matches[0])) {
                        $question_id_numeric = $matches[0];
        
                       
                        $query = $wpdb->prepare(
                            "SELECT question 
                             FROM {$wpdb->prefix}aysquiz_questions 
                             WHERE id = %d",
                            $question_id_numeric
                        );
        
                        $question_title = $wpdb->get_var($query);
        
                       
                        if ($question_title) {
                            $question_titles[$question_id_numeric] = esc_html($question_title);
                        } else {
                            $question_titles[$question_id_numeric] = "Question title not found.";
                        }
                    }
                }
                   // Setting global data
        $global_quiz_data = [
            'questions' => $question_titles,
            'correctness' => $correctness
        ];
    }
}


        $query = $wpdb->prepare(
            "SELECT display_name 
             FROM {$wpdb->prefix}users 
             WHERE ID = %d",
            $user_id
        );
        
        $display_name = $wpdb->get_var($query);

        $query_1 = $wpdb->prepare(
            "SELECT ClassGroupName
             FROM {$wpdb->prefix}chimpvine_class_groups
             WHERE ID = %d",
            $user_id
        );
        $group_name = $wpdb->get_var($query_1);
       
$quiz_table = $wpdb->prefix . 'aysquiz_reports';
$assigned_date = $homework->HomeWorkAssignedDate;
$deadline_date = $homework->HomeWorkDeadlineDate;
 
if(!$assigned_date && $deadline_date){
    echo 'do not assigned date ';
}

$query = $wpdb->prepare("
    SELECT  
        user_id,
        user_name,
        score AS first_attempt_score,
        MIN(duration) AS min_duration,
        corrects_count,
        questions_count,
        start_date,
        end_date
    FROM {$quiz_table}
    WHERE 
        status = 'finished' AND 
        start_date BETWEEN %s AND %s
    GROUP BY 
        user_id, user_name
    ORDER BY 
        start_date ASC
    LIMIT 1
", $assigned_date, $deadline_date);

// Fetch quiz report data
$quiz_reports = $wpdb->get_results($query);

// Prepare HTML output for quiz reports
$output = '';
if ($quiz_reports) {
    $output .= '<div id="quiz-report-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000;">
    <div style="background: #fff; padding: 1.25rem; margin: 5% auto; width: 100%; max-width: 80%; border-radius: 0.625rem; height: 80vh; overflow-y: auto; position: relative; box-shadow: 0 0.25rem 0.375rem rgba(0, 0, 0, 0.1);">
        <span id="close-modal" style="position: absolute; top: 1rem; right: 1rem; font-size: 2rem; font-weight: bold; cursor: pointer; color: #333;">&times;</span>
        <h3 style="text-align: center; margin-bottom: 1.25rem; font-family: Arial, sans-serif; font-size: 1.5rem;">Quiz Report</h3>';
    foreach ($quiz_reports as $quiz_report) {
        $startTime = date('F d, Y h:i A', strtotime($quiz_report->start_date));
        $endTime = date('F d, Y h:i A', strtotime($quiz_report->end_date));
        $output .= '<div style="border: 0.063rem solid #ddd; border-radius: 0.625rem; padding: 0.938rem; margin-bottom: 0.938rem; background: #f9f9f9; font-family: Arial, sans-serif;">
        <h2 style="text-align: center; margin-bottom: 0.625rem;">' . esc_html($group->ClassGroupName) . '</h2>
        <h5 style="margin-bottom: 0.625rem;">User Name: ' . esc_html($display_name) . '</h5>
        <hr>
        <table style="width: 100%; border-collapse: collapse; font-family: Arial, sans-serif;">
            <tr>
                <th style="text-align: left; padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Label</th>
                <th style="text-align: left; padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Value</th>
            </tr>
            <tr>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Duration (seconds):</td>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">' . esc_html($quiz_report->min_duration) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Correct Questions:</td>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">' . esc_html($quiz_report->corrects_count) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Start Time:</td>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">' . esc_html($startTime) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">End Time:</td>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">' . esc_html($endTime) . '</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Score:</td>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">' . esc_html($quiz_report->first_attempt_score) . '</td>
            </tr>
        </table>';

        if (!empty($global_quiz_data['questions'])) {
            $output .= '<hr>
        <h4 style="margin-top: 0.625rem; margin-bottom: 0.625rem;">Questions</h4>
        <table style="width: 100%; border-collapse: collapse; font-family: Arial, sans-serif;">
            <tr>
                <th style="text-align: left; padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Question</th>
                <th style="text-align: left; padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">Correctness</th>
            </tr>';
            foreach ($global_quiz_data['questions'] as $question_id => $title) {
                $is_correct = $global_quiz_data['correctness'][$question_id] ?? true;
                $output .= '<tr>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd;">' . esc_html($title) . '</td>
                <td style="padding: 0.5rem; border-bottom: 0.063rem solid #ddd; color: ' . ($is_correct ? '#28a745' : '#dc3545') . ';">' . esc_html($is_correct ? "Correct" : "Wrong") . '</td>
            </tr>';
            }
            $output .= '</table>';
        }
        $output .= '</div>';
    }
    $output .= '</div></div>';
}

 else {
    $output = '<p>No quiz reports found within the specified dates.</p>';
} 
    echo $output;
?>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        const viewButton = document.getElementById('view-report-btn');
        const modal = document.getElementById('quiz-report-modal');
        const closeModal = document.getElementById('close-modal');

        if (viewButton && modal && closeModal) {
            // Open the modal when the button is clicked
            viewButton.addEventListener('click', function () {
                const homeworkId = viewButton.getAttribute('data-user-id');
                if (homeworkId) {
                    console.log('Homework ID:', homeworkId);
                    modal.style.display = 'flex'; 
                } else {
                    console.error('Homework ID is missing!');
                }
            });

            // Close the modal when the close button is clicked
            closeModal.addEventListener('click', function () {
                modal.style.display = 'none'; // Hide the modal
            });

            // Close the modal when clicking outside the modal content
            window.addEventListener('click', function (e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        } else {
            console.error('Required elements are missing from the DOM.');
        }
    });
</script>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    // Handle Reject Request button click
    document.querySelectorAll('.request-reject-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var homeworkID = this.getAttribute('data-homework-id');
            var userHomeID = this.getAttribute('data-user-home-id');

            var formData = new FormData();
            formData.append('action', 'reject_homework_request');
            formData.append('homework_id', homeworkID);
            formData.append('user_home_id', userHomeID);

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Request rejected successfully.');
                        location.reload();
                    } else {
                        alert('Failed to reject the request.');
                    }
                });
        });
    });
   
</script>
 
    
<script type="text/javascript">
    document.querySelectorAll('.request-accept-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var homeworkID = this.getAttribute('data-homework-id');
            var userHomeID = this.getAttribute('data-user-home-id');

            var formData = new FormData();
            formData.append('action', 'accept_homework_request');
            formData.append('homework_id', homeworkID);
            formData.append('user_home_id', userHomeID);

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Request accepted successfully.');
                        location.reload();
                    } else {
                        alert('Failed to accept the request.');
                    }
                });
        });
    });
});
          
</script>
<?php
    }

    // AJAX handler for rejecting a homework reupload request
    public function reject_homework_request() {
        if (!isset($_POST['homework_id']) || !isset($_POST['user_home_id'])) {
            wp_send_json_error('Invalid request.');
        }

        $homework_id = intval($_POST['homework_id']);
        $user_home_id = intval($_POST['user_home_id']);
        global $wpdb;

        // Update the wp_chimpvine_user_homework table to reject the request
        $wpdb->update(
            "{$wpdb->prefix}chimpvine_user_homework",
            [
                'IsRequest' => 1,
                'IsReject' => 1,
                'RejectDate' => current_time('mysql')
            ],
            ['userHomeID' => $user_home_id, 'HomeWorkID' => $homework_id]
        );

        if ($wpdb->last_error) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }

        wp_send_json_success('Homework request rejected successfully.');
    }

    // AJAX handler for accepting a homework reupload request
    public function accept_homework_request() {
        if (!isset($_POST['homework_id']) || !isset($_POST['user_home_id'])) {
            wp_send_json_error('Invalid request.');
        }

        $homework_id = intval($_POST['homework_id']);
        $user_home_id = intval($_POST['user_home_id']);
        global $wpdb;

        // Update the wp_chimpvine_user_homework table to accept the request
        $wpdb->update(
            "{$wpdb->prefix}chimpvine_user_homework",
            [
                'IsRequest' => 0,
                'IsReject' => 0,
                'AttachedUrl' => '',
                'RejectDate' => current_time('mysql')
            ],
            ['userHomeID' => $user_home_id, 'HomeWorkID' => $homework_id]
        );

        if ($wpdb->last_error) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }

        wp_send_json_success('Homework request accepted successfully.');
    }
}

new Chimpvine_View_Homework_Submissions();