<div class="wrap">
<h2><?php screen_icon( 'users' );?>Simple WP Membership::Membership Levels
<a href="admin.php?page=simple_wp_membership_levels&level_action=add" class="add-new-h2"><?php echo esc_html_x( 'Add New', 'Level' ); ?></a></h2>
    <form method="post">
        <p class="search-box">
            <label class="screen-reader-text" for="search_id-search-input">
            search:</label> 
            <input id="search_id-search-input" type="text" name="s" value="" /> 
            <input id="search-submit" class="button" type="submit" name="" value="search" />
            <input type="hidden" name="page" value="my_list_test" />
        </p>
    </form>
  <?php $this->prepare_items();?> 
  <form method="post">
  <?php $this->display(); ?>
  </form>
</div> 
