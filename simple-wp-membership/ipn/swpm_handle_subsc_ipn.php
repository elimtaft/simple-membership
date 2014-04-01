<?php

function swpm_handle_subsc_signup_stand_alone($ipn_data,$subsc_ref,$unique_ref,$swpm_id='')
{
    global $wpdb;
    $settings = BSettings::get_instance();
    $members_table_name = $wpdb->prefix . "wp_eMember_members_tbl";
    $membership_level_table = $wpdb->prefix . "wp_eMember_membership_tbl";    

    if(empty($swpm_id))
    {
        //Lets try to find an existing user profile for this payment
        $email = $ipn_data['payer_email'];
        $query_db = $wpdb->get_row("SELECT * FROM $members_table_name WHERE email = '$email'", OBJECT);	    
        if(!$query_db){//try to retrieve the member details based on the unique_ref
            swpm_debug_log_subsc("Could not find any record using the given email address (".$email."). Attempting to query database using the unique reference: ".$unique_ref,true);
            if(!empty($unique_ref)){			
                    $query_db = $wpdb->get_row("SELECT * FROM $members_table_name WHERE subscr_id = '$unique_ref'", OBJECT);
                    $swpm_id = $query_db->member_id;
            }
            else{
                    swpm_debug_log_subsc("Unique reference is missing in the notification so we have to assume that this is not a payment for an existing member.",true);
            }
        }
        else
        {
            $swpm_id = $query_db->member_id;
            swpm_debug_log_subsc("Found a match in the member database. Member ID: ".$swpm_id,true);
        }
    }
    
    if (!empty($swpm_id))
    {
        //This is payment from an existing member/user. Update the existing member account        
        swpm_debug_log_subsc("Modifying the existing membership profile... Member ID: ".$swpm_id,true);
        // upgrade the member account
        $account_state = 'active';
        $membership_level = $subsc_ref;
        $subscription_starts = (date ("Y-m-d"));
        $subscr_id = $unique_ref;

        $resultset = "";
        $resultset = $wpdb->get_row("SELECT * FROM $members_table_name where member_id='$swpm_id'", OBJECT);
        if(!$resultset){
            swpm_debug_log_subsc("ERROR! Could not find a member account record for the given Member ID: ".$swpm_id,false);
            return;
        }
        $old_membership_level = $resultset->membership_level;

        swpm_debug_log_subsc("Not using secondary membership level feature... upgrading the current membership level.",true);
        $updatedb = "UPDATE $members_table_name SET account_state='$account_state',membership_level='$membership_level',subscription_starts='$subscription_starts',subscr_id='$subscr_id' WHERE member_id='$swpm_id'";    	
        $results = $wpdb->query($updatedb);
        do_action('swpm_membership_changed', array('member_id'=>$swpm_id, 'from_level'=>$old_membership_level, 'to_level'=>$membership_level));

//TODO - Update the corresponding WP user object role
//swpm_debug_log_subsc("Updating WordPress user role...",true);
//$resultset = $wpdb->get_row("SELECT * FROM $members_table_name where member_id='$swpm_id'", OBJECT);
//$membership_level = $resultset->membership_level;
//$username = $resultset->user_name;    		
//$membership_level_resultset = $wpdb->get_row("SELECT * FROM $membership_level_table where id='$membership_level'", OBJECT);
//swpm_debug_log_subsc("Calling WP role update function. Current users membership level is: ".$membership_level,true);
//update-role-function($username,$membership_level_resultset->role);
//swpm_debug_log_subsc("Current WP users role updated to: ".$membership_level_resultset->role,true);

        //Set Email details for the account upgrade notification	
        $email = $ipn_data['payer_email'];                          
        $subject = $settings->get_value('upgrade-complete-mail-subject');
        if (empty($subject)){
            $subject = "Member Account Upgraded";
        }
        $body = $settings->get_value('upgrade-complete-mail-body');
        if (empty($body)){
            $body = "Your account has been upgraded successfully";
        }
        $from_address = get_option('admin_email');
        $login_link = $settings->get_value('login-page-url');

        $tags1 = array("{first_name}","{last_name}","{user_name}","{login_link}");			
        $vals1 = array($resultset->first_name,$resultset->last_name,$resultset->user_name,$login_link);			
        $email_body = str_replace($tags1,$vals1,$body);				
        $headers = 'From: '.$from_address . "\r\n";   	    					    	
    }// End of existing user account upgrade
    else
    {
        // create new member account
        $user_name ='';
        $password = '';

        $first_name = $ipn_data['first_name'];
        $last_name = $ipn_data['last_name'];
        $email = $ipn_data['payer_email'];
        $membership_level = $subsc_ref;
        $subscr_id = $unique_ref;
        $gender = 'not specified';

        swpm_debug_log_subsc("Membership level ID: ".$membership_level,true);

        $address_street = $ipn_data['address_street'];
        $address_city = $ipn_data['address_city'];
        $address_state = $ipn_data['address_state'];
        $address_zipcode = $ipn_data['address_zip'];
        $country = $ipn_data['address_country'];

        $date = (date ("Y-m-d"));
        $account_state = 'active';
        $reg_code = uniqid();//rand(10, 1000);
        $md5_code = md5($reg_code);

        $updatedb = "INSERT INTO $members_table_name (user_name,first_name,last_name,password,member_since,membership_level,account_state,last_accessed,last_accessed_from_ip,email,address_street,address_city,address_state,address_zipcode,country,gender,referrer,extra_info,reg_code,subscription_starts,txn_id,subscr_id) VALUES ('$user_name','$first_name','$last_name','$password', '$date','$membership_level','$account_state','$date','IP','$email','$address_street','$address_city','$address_state','$address_zipcode','$country','$gender','','','$reg_code','$date','','$subscr_id')";
        $results = $wpdb->query($updatedb);

        $results = $wpdb->get_row("SELECT * FROM $members_table_name where subscr_id='$subscr_id' and reg_code='$reg_code'", OBJECT);
        $id = $results->member_id; //Alternatively use $wpdb->insert_id;

        $separator='?';
        $url = $settings->get_value('registration-page-url');
        if(strpos($url,'?')!==false){$separator='&';}
        
        $reg_url = $url.$separator.'member_id='.$id.'&code='.$md5_code;
        swpm_debug_log_subsc("Member signup URL :".$reg_url,true);

        $subject = $settings->get_value('reg-complete-mail-subject');
        if (empty($subject)){
            $subject = "Please complete your registration";
        }              
        $body = $settings->get_value('reg-complete-mail-body');
        if (empty($body)){
            $body = "Please use the following link to complete your registration. \n {reg_link}";
        }
        $from_address = get_option('admin_email');
        
        $tags = array("{first_name}","{last_name}","{reg_link}");
        $vals = array($first_name,$last_name,$reg_url);
        $email_body    = str_replace($tags,$vals,$body);
        $headers = 'From: '.$from_address . "\r\n";
    }

    wp_mail($email,$subject,$email_body,$headers);
    swpm_debug_log_subsc("Member signup/upgrade completion email successfully sent",true);
}

function swpm_handle_subsc_cancel_stand_alone($ipn_data,$refund=false)
{
    if($refund)
    {
        $subscr_id = $ipn_data['parent_txn_id'];
        swpm_debug_log_subsc("Refund notification check - check if a member account needs to be deactivated... subscr ID: ".$subscr_id,true); 
    }
    else
    {
        $subscr_id = $ipn_data['subscr_id'];
    }    

    global $wpdb;
    $members_table_name = $wpdb->prefix . "wp_eMember_members_tbl";
    
    swpm_debug_log_subsc("Retrieving member account from the database...",true);
    $resultset = $wpdb->get_row("SELECT * FROM $members_table_name where subscr_id='$subscr_id'", OBJECT);
    if($resultset)
    {
        //Deactivate this account as it is a refund or cancellation
        $account_state = 'inactive';
        $updatedb = "UPDATE $members_table_name SET account_state='$account_state' WHERE subscr_id='$subscr_id'";
        $resultset = $wpdb->query($updatedb);    		
        swpm_debug_log_subsc("Subscription cancellation received! Member account deactivated.",true);
    }
    else
    {
    	swpm_debug_log_subsc("No member found for the given subscriber ID: ".$subscr_id,false);
    	return;
    }      	
}

function swpm_update_member_subscription_start_date_if_applicable($ipn_data)
{
    global $wpdb;
    $members_table_name = $wpdb->prefix . "wp_eMember_members_tbl";
    $membership_level_table = $wpdb->prefix . "wp_eMember_membership_tbl";    
    $email = $ipn_data['payer_email'];
    $subscr_id = $ipn_data['subscr_id'];
    swpm_debug_log_subsc("Updating subscription start date if applicable for this subscription payment. Subscriber ID: ".$subscr_id." Email: ".$email,true);

    //We can also query using the email address
    $query_db = $wpdb->get_row("SELECT * FROM $members_table_name WHERE subscr_id = '$subscr_id'", OBJECT);
    if($query_db){
        $swpm_id = $query_db->member_id;
        $current_primary_level = $query_db->membership_level;
        swpm_debug_log_subsc("Found a record in the member table. The Member ID of the account to check is: ".$swpm_id." Membership Level: ".$current_primary_level,true);

        $level_query = $wpdb->get_row("SELECT * FROM $membership_level_table where id='$current_primary_level'", OBJECT);
        if(!empty($level_query->subscription_period) && !empty($level_query->subscription_unit)){//Duration value is used		
            $account_state = "active";
            $subscription_starts = (date ("Y-m-d"));

            $updatedb = "UPDATE $members_table_name SET account_state='$account_state',subscription_starts='$subscription_starts' WHERE member_id='$swpm_id'";    	    	
            $resultset = $wpdb->query($updatedb);
            swpm_debug_log_subsc("Updated the member profile with current date as the subscription start date.",true);
        }else{
            swpm_debug_log_subsc("This membership level is not using a duration/interval value as the subscription duration.",true);
        }
    }else{
        swpm_debug_log_subsc("Did not find a record in the members table for subscriber ID: ".$subscr_id,true);
    }
}

function swpm_debug_log_subsc($message,$success,$end=false)
{
    // Timestamp
    $text = '['.date('m/d/Y g:i A').'] - '.(($success)?'SUCCESS :':'FAILURE :').$message. "\n";
    if ($end) {
    	$text .= "\n------------------------------------------------------------------\n\n";
    }
    // Write to log
    $fp=fopen("ipn_handle_debug_swpm.log",'a');
    fwrite($fp, $text );
    fclose($fp);  // close file
}
