<?php

class BMembers extends WP_List_Table{
    function __construct(){
        parent::__construct(array(
            'singular'=>'Member',
            'plural'  => 'Members',
            'ajax'    => false
        ));
    }
    function get_columns(){
        return array(
            'cb' => '<input type="checkbox" />'
            ,'member_id'=>'ID'
            ,'user_name'=>'User Name'
            ,'first_name'=>'First Name'
            ,'last_name'=>'Last Name'
            ,'email'=>'Email'
            ,'alias'=>'Membership Level'
            ,'subscription_starts'=>'Subscription Starts'
            ,'account_state'=>'Account State'
            );
    }
    function get_sortable_columns(){
        return array(
            'user_name'=>array('user_name',true)
        );
    }
    function get_bulk_actions() {
        $actions = array(
            'bulk_delete'    => 'Delete'
        );
        return $actions;
    }
    function column_default($item, $column_name){
    	return $item[$column_name];
    }
    function column_member_id($item){
        $actions = array(
            'edit'  	=> sprintf('<a href="admin.php?page=%s&member_action=edit&member_id=%s">Edit</a>',
									$_REQUEST['page'],$item['member_id']),
            'delete'    => sprintf('<a href="?page=%s&member_action=delete&member_id=%s" 
                                    onclick="return confirm(\'Are you sure you want to delete this entry?\')">Delete</a>',
                                    $_REQUEST['page'],$item['member_id']),
        );
        return $item['member_id'] . $this->row_actions($actions);
    }
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="members[]" value="%s" />', $item['member_id']
        );    
    }
    function prepare_items() {
        global $wpdb; 
        $query  = "SELECT * FROM " .$wpdb->prefix . "wp_eMember_members_tbl";
        $query .= " LEFT JOIN " . $wpdb->prefix . "wp_eMember_membership_tbl";
        $query .= " ON ( membership_level = id ) ";  
        if(isset($_POST['s'])) $query .= " WHERE = user_name = '" . strip_tags($_POST['s']). "' ";    
        $orderby = !empty($_GET["orderby"]) ? mysql_real_escape_string($_GET["orderby"]) : 'ASC';
        $order = !empty($_GET["order"]) ? mysql_real_escape_string($_GET["order"]) : '';
        if(!empty($orderby) & !empty($order)){ $query.=' ORDER BY '.$orderby.' '.$order; }
        $totalitems = $wpdb->query($query); //return the total number of affected rows
        $perpage = 20;
        $paged = !empty($_GET["paged"]) ? mysql_real_escape_string($_GET["paged"]) : '';
        if(empty($paged) || !is_numeric($paged) || $paged<=0 ){ $paged=1; }
        $totalpages = ceil($totalitems/$perpage);
        if(!empty($paged) && !empty($perpage)){
            $offset=($paged-1)*$perpage;
	        $query.=' LIMIT '.(int)$offset.','.(int)$perpage;
        }
        $this->set_pagination_args( array(
            "total_items" => $totalitems,
            "total_pages" => $totalpages,
            "per_page" => $perpage,
        ) );

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);   
        $this->items = $wpdb->get_results($query, ARRAY_A);
    }
    function no_items() {
      _e( 'No Member found, dude.' );
    }
	function process_form_request(){		
		if(isset($_REQUEST['member_id']))
			return $this->edit($_REQUEST['member_id']);
		return $this->add();
		
	}
	function add(){
		global $wpdb; 
	    $member = BTransfer::$default_fields;
		if(isset($_POST['createswpmuser'])){
			$member = $_POST;
		}
		extract($member, EXTR_SKIP);
        $query  = "SELECT * FROM " .$wpdb->prefix . "wp_eMember_membership_tbl WHERE  id !=1 ";
        $levels = $wpdb->get_results($query, ARRAY_A);
		include_once(SIMPLE_WP_MEMBERSHIP_PATH.'views/admin_add.php');
		return false;
	}
	function edit($id){
		global $wpdb;
		$id = absint($id); 
		$query = "SELECT * FROM {$wpdb->prefix}wp_eMember_members_tbl WHERE member_id = $id";
		$member = $wpdb->get_row($query, ARRAY_A);
		if(isset($_POST["editswpmuser"])){
			$_POST['user_name'] =  $member['user_name'];
			$_POST['email']     =  $member['email'];
			$member = $_POST;
		}
		extract($member, EXTR_SKIP);
        $query  = "SELECT * FROM " .$wpdb->prefix . "wp_eMember_membership_tbl WHERE  id !=1 ";
        $levels = $wpdb->get_results($query, ARRAY_A);
		include_once(SIMPLE_WP_MEMBERSHIP_PATH.'views/admin_edit.php');
		return false;
	}
	function delete(){
		global $wpdb;
		if(isset($_REQUEST['member_id'])){
			$id = absint($_REQUEST['member_id']);	
			$query = "DELETE FROM " .$wpdb->prefix . "wp_eMember_members_tbl WHERE member_id = $id";
			$wpdb->query($query);			
		}
		else if (isset($_REQUEST['members'])){
			$members = $_REQUEST['members']; 
			if(!empty($members)){
				$members = array_map('absint', $members);
				$members = implode(',', $members);
				$query = "DELETE FROM " .$wpdb->prefix . "wp_eMember_members_tbl WHERE member_id IN (" . $members . ")";
				$wpdb->query($query);
			}
		}
	}
	function show(){
		include_once(SIMPLE_WP_MEMBERSHIP_PATH.'views/admin_members.php');
	}
}

