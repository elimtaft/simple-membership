<div class="wrap" id="swpm-level-page">
<form action="" method="post" name="swpm-edit-level" id="swpm-edit-level" class="validate"<?php do_action('level_edit_form_tag');?>>
<input name="action" type="hidden" value="editlevel" />
<?php wp_nonce_field( 'edit-swpmlevel', '_wpnonce_edit-swpmlevel' ) ?>
<h3>Edit Membership Level</h3> 
<p><?php _e('Edit membership level.'); ?></p>
<table class="form-table">
    <tbody>
	<tr>
		<th scope="row"><label for="alias"><?php _e('Membership Level Name'); ?> <span class="description"><?php _e('(required)'); ?></span></label></th>
		<td><input class="regular-text validate[required]" name="alias" type="text" id="alias" value="<?echo $alias;?>" aria-required="true" /></td>
	</tr>
	<tr class="form-field form-required">
		<th scope="row"><label for="role"><?php _e('Default WordPress Role'); ?> <span class="description"><?php _e('(required)'); ?></span></label></th>
		<td><select  class="regular-text" name="role"><?php wp_dropdown_roles( $role ); ?></select></td>
	</tr>
    <tr>
        <th scope="row"><label for="subscription_unit"><?php _e('Subscription Duration'); ?> <span class="description"><?php _e('(required)'); ?></span></label>        
        </th>
        <td>
            <fieldset>
            <div class="color-option">
                <input name="subscript_duration_type" id="subscript_duration_noexpire" 
                <?php echo $noexpire?'checked="checked"': ""; ?>   type="radio" value="0" class="tog">
	            <table class="color-palette">
	            <tbody><tr>
		            <td style="width: 60px;"><b>No Expiry</b></td>
		            </tr>
	            </tbody></table>
            </div>
	            <div class="color-option">
                <input name="subscript_duration_type" id="subscript_duration_expire" 
                <?php echo !$noexpire?'checked="checked"': ""; ?> type="radio" value="1" class="tog">
	            <table class="color-palette">
	            <tbody><tr>
		            <td style="background-color: #d1e5ee" title="fresh">
                    <input type="text" class="validate[required]" size="3" id="subscription_period" name="subscription_period" 
                        value="<?php echo $noexpire?'':$subscription_period;?>"></td>
		            <td style="background-color: #cfdfe9" title="fresh">
                    <select id="subscription_unit" name="subscription_unit">
                   <option <?php echo ($subscription_unit =='days')? "selected='selected'": "";?> value="days">Days</option>
                   <option <?php echo ($subscription_unit =='weeks')? "selected='selected'": "";?>value="weeks">Weeks</option>
                   <option <?php echo ($subscription_unit =='months')? "selected='selected'": "";?>value="months">Months</option>
                   <option <?php echo ($subscription_unit =='years')? "selected='selected'": "";?>value="years">Years</option>
                    </select>
                    </td>
		            </tr>
	            </tbody></table>
            </div>
	            </fieldset>

        </td>
    </tr>
<?php //include('admin_member_form_common_part.php');?>
</tbody>
</table>
<?php submit_button( __( 'Edit Membership Level '), 'primary', 'editswpmlevel', true, array( 'id' => 'editswpmlevelsub' ) ); ?>
</form>
</div>
<script>
jQuery(document).ready(function($){
    $('.tog:radio').on('update_deps click',function(){        
        if($(this).attr('checked')){
            $("#swpm-edit-level").validationEngine('detach');
            if($(this).val()==0)
                $('#subscription_period').removeClass('validate[required]');
            else if($(this).val()==1)
                $('#subscription_period').addClass('validate[required]');                
            $("#swpm-edit-level").validationEngine('attach');
        }
    });   
    $('.tog:radio').trigger('update_deps');
});
</script>
