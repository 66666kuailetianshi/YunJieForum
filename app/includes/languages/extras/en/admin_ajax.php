<?php return [
    // ---------- Common errors / status ----------
    'admin_ajax_forbidden'                       => 'Forbidden',
    'admin_ajax_csrf_failed'                     => 'CSRF validation failed',
    'admin_ajax_csrf_failed_retry'               => 'Security check failed. Please refresh the page and try again.',
    'admin_ajax_post_only'                       => 'Only POST requests are supported',
    'admin_ajax_server_error'                    => 'Server error: ',
    'admin_ajax_server_error_retry'              => 'Internal server error, please try again later',
    'admin_ajax_unknown_action'                  => 'Unknown action',
    'admin_ajax_unknown_action_with_dot'         => 'Unknown action type.',
    'admin_ajax_unknown_error'                   => 'Unknown error',
    'admin_ajax_invalid_param'                   => 'Invalid parameter',
    'admin_ajax_invalid_id'                      => 'Invalid ID',
    'admin_ajax_invalid_config'                  => 'Invalid configuration data',
    'admin_ajax_invalid_post_id'                 => 'Invalid post ID',
    'admin_ajax_invalid_reply_id'                => 'Invalid reply ID',
    'admin_ajax_invalid_user_id'                 => 'Invalid user ID.',
    'admin_ajax_invalid_action'                  => 'Invalid action',
    'admin_ajax_invalid_level'                   => 'Invalid level',
    'admin_ajax_user_not_found'                  => 'User does not exist.',
    'admin_ajax_user_not_found_short'            => 'User does not exist',
    'admin_ajax_gen_failed'                      => 'Failed to generate data',
    'admin_ajax_op_failed'                       => 'Operation failed: ',
    'admin_ajax_no_target_users'                 => 'No target users available (administrators have been excluded automatically).',
    'admin_ajax_select_users_first'              => 'Please select the users to operate on first.',
    'admin_ajax_never'                           => 'Never',
    'admin_ajax_expired'                         => 'Expired',
    'admin_ajax_unlimited'                       => 'Unlimited',
    'admin_ajax_default'                         => 'Default',
    'admin_ajax_on'                              => 'On',
    'admin_ajax_off'                             => 'Off',
    'admin_ajax_unknown'                         => 'Unknown',
    'admin_ajax_unknown_short'                   => 'Unknown',
    'admin_ajax_unknown_with_code'               => 'Unknown(',
    'admin_ajax_no_reason'                       => '(No description)',
    'admin_ajax_no_title'                        => '(No title)',
    'admin_ajax_colon'                           => ': ',
    'admin_ajax_uid_prefix'                      => 'UID:',
    'admin_ajax_risk_normal'                     => 'Normal',

    // ---------- Backup ----------
    'admin_ajax_backup_saved'                    => 'Auto-backup settings saved',

    // ---------- Bounce handling ----------
    'admin_ajax_bounce_saved'                    => 'Bounce handling configuration saved',
    'admin_ajax_bounce_check_done'               => 'Check complete: scanned %d emails, matched %d bounces',
    'admin_ajax_bounce_check_failed'             => 'Check failed: ',

    // ---------- Mail notification ----------
    'admin_ajax_mail_sent'                       => 'Send complete: %d succeeded, %d failed',
    'admin_ajax_mail_subject_content_required'   => 'Please fill in the email subject and content',
    'admin_ajax_mail_target_required'            => 'Please select a send target',
    'admin_ajax_mail_type_verify'                => 'Verification Code',
    'admin_ajax_mail_type_reset'                 => 'Password Reset',
    'admin_ajax_mail_type_appeal'                => 'Appeal Notice',
    'admin_ajax_mail_type_ban'                   => 'Ban Notice',
    'admin_ajax_mail_type_test'                  => 'Test Email',
    'admin_ajax_mail_type_notification'          => 'System Notification',
    'admin_ajax_mail_type_other'                 => 'Other',
    'admin_ajax_no_recipients'                   => 'No eligible users to receive the notification',
    'admin_ajax_username_required'               => 'Please enter at least one username',

    // ---------- User risk detail ----------
    'admin_ajax_reply_label'                     => 'Reply #',
    'admin_ajax_post_label'                      => 'Post #',
    'admin_ajax_content_deleted'                 => 'Content deleted',
    'admin_ajax_appeal_type_ban'                 => 'Ban Appeal',
    'admin_ajax_appeal_type_mute'                => 'Mute Appeal',

    // ---------- Post / Reply ----------
    'admin_ajax_post_not_found'                  => 'Post does not exist',
    'admin_ajax_reply_not_found'                 => 'Reply does not exist',

    // ---------- User bulk operations ----------
    'admin_ajax_bulk_role_limited'               => 'Only "User" or "Moderator" can be set in bulk; administrators cannot be set in bulk.',
    'admin_ajax_bulk_success'                    => 'Operation successful, {affected} users affected.',
    'admin_ajax_pm_content_empty'                => 'The PM content cannot be empty.',
    'admin_ajax_pm_select_recipients'            => 'Please select recipients or specify a filter scope.',
    'admin_ajax_pm_sent_count'                   => 'Sent to {sent} users, skipped {skipped} (yourself).',

    // ---------- Sensitive words ----------
    'admin_ajax_category_required'               => 'Category cannot be empty',

    // ---------- CSV export ----------
    'admin_ajax_csv_username'                    => 'Username',
    'admin_ajax_csv_email'                       => 'Email',
    'admin_ajax_csv_role'                        => 'Role',
    'admin_ajax_csv_status'                      => 'Status',
    'admin_ajax_csv_points'                      => 'Points',
    'admin_ajax_csv_coins'                       => 'Coins',
    'admin_ajax_csv_posts'                       => 'Posts',
    'admin_ajax_csv_replies'                     => 'Replies',
    'admin_ajax_csv_last_active'                 => 'Last Active',
    'admin_ajax_csv_registered_at'               => 'Registered At',

    // ---------- Traffic monitoring ----------
    'admin_ajax_direct_visit'                    => 'Direct Visit',
    'admin_ajax_device_desktop'                  => 'Desktop',
    'admin_ajax_device_mobile'                   => 'Mobile',
    'admin_ajax_device_tablet'                   => 'Tablet',
    'admin_ajax_dev_desktop'                     => 'PC',
    'admin_ajax_dev_mobile'                      => 'Phone',
    'admin_ajax_dev_tablet'                      => 'Tablet',
    'admin_ajax_other_browser'                   => 'Other',
    'admin_ajax_other_os'                        => 'Other',
    'admin_ajax_os_win10'                        => 'Windows 10/11',
    'admin_ajax_os_win7'                         => 'Windows 7/8',
    'admin_ajax_os_windows'                      => 'Windows',

    // ---------- System status: battery / power ----------
    'admin_ajax_battery_charging'                => 'Charging',
    'admin_ajax_battery_discharging'             => 'Discharging',
    'admin_ajax_battery_low'                     => 'Low Battery',
    'admin_ajax_battery_critical'                => 'Critically Low',
    'admin_ajax_battery_full'                    => 'Fully Charged',
    'admin_ajax_battery_not_charging'            => 'Not Charging',
    'admin_ajax_ac_power'                        => 'AC Power',

    // ---------- System status: load / time units ----------
    'admin_ajax_load_high'                       => 'High Load',
    'admin_ajax_load_medium'                     => 'Elevated Load',
    'admin_ajax_load_normal'                     => 'Running Normally',
    'admin_ajax_unit_days'                       => ' day(s)',
    'admin_ajax_unit_hours'                      => ' hour(s)',
    'admin_ajax_unit_minutes'                    => ' minute(s)',
    'admin_ajax_unit_seconds'                    => ' second(s)',

    // ---------- System status: hardware ----------
    'admin_ajax_slot_label'                      => 'Slot ',
    'admin_ajax_unknown_model'                   => 'Unknown Model',
    'admin_ajax_sensor'                          => 'Sensor',
    'admin_ajax_temp_sensor'                     => 'Temperature Sensor',
    'admin_ajax_unknown_gpu'                     => 'Unknown GPU',
    'admin_ajax_shared_or_unknown'               => 'Shared/Unknown',

    // ---------- System status: SAPI ----------
    'admin_ajax_sapi_apache'                     => 'Apache Handler',
    'admin_ajax_sapi_cgi'                        => 'CGI/FastCGI',
    'admin_ajax_sapi_fpm'                        => 'PHP-FPM',
    'admin_ajax_sapi_cli'                        => 'Command Line',
    'admin_ajax_sapi_built_in'                   => 'Built-in Server',

    // ---------- System status: diagnostics / warnings ----------
    'admin_ajax_ss_windows_warning'              => 'Windows system information collection is unavailable: enable the PHP com_dotnet extension, enable FFI in php.ini (ffi.enable=true, requires PHP 7.4+), or make sure shell_exec is not in disable_functions (PowerShell will be used as fallback automatically).',
    'admin_ajax_ss_memory_failed'                => 'Failed to retrieve memory data.',
    'admin_ajax_ss_uptime_failed'                => 'Failed to retrieve uptime.',
    'admin_ajax_ss_no_method'                    => 'Current PHP cannot collect Windows hardware information: the com_dotnet extension is not loaded, FFI is not enabled (ffi.enable must be set to true in php.ini), and shell_exec/proc_open are disabled. Please enable at least one of these methods.',
    'admin_ajax_ss_powershell_fallback'          => 'The com_dotnet extension is not loaded; hardware information has been collected via the PowerShell subprocess (the first load is slow, cached for 1 hour). If some information is still missing, the WMI service may not be running or may be blocked by security software.',
    'admin_ajax_ss_motherboard_failed'           => 'Motherboard/BIOS information could not be retrieved. Try running php-cgi/php-fpm as administrator, or enable com_dotnet / FFI in php.ini.',
    'admin_ajax_ss_batch_failed'                 => 'FAILED: single batch collection failed (WMI service error or timeout)',
    'admin_ajax_ss_shell_disabled'               => 'FAILED: shell_exec is disabled, PowerShell is not in PATH, or subprocess execution is forbidden. Please check disable_functions and php.ini.',
];
