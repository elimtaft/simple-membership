<div class="swpm-login-widget-form">
    <form id="swpm-login-form" name="swpm-login-form" method="post" action="">
        <div class="swpm-login-form-inner">
            <div class="swpm-username-label">
                <label for="swpm_user_name" class="swpm-label"><?= BUtils::_('Username') ?></label>
            </div>
            <div class="swpm-username-input">
                <input type="text" class="swpm-text-field swpm-username-field" id="swpm_user_name" value="" size="30" name="swpm_user_name" />
            </div>
            <div class="swpm-password-label">
                <label for="swpm_password" class="swpm-label"><?= BUtils::_('Password') ?></label>
            </div>
            <div class="swpm-password-input">
                <input type="password" class="swpm-text-field swpm-password-field" id="swpm_password" value="" size="30" name="swpm_password" />
            </div>
            <div class="swpm-remember-me">
                <span class="swpm-remember-checkbox"><input type="checkbox" name="rememberme" value="checked='checked'"></span>
                <span class="swpm-rember-label"> <?= BUtils::_('Remember Me') ?></span>
            </div>
            <div class="swpm-login-submit">
                <input type="submit" name="swpm-login" value="<?= BUtils::_('Login') ?>"/>
            </div>
            <div class="swpm-forgot-pass-link">
                <a id="forgot_pass" href="<?= $password_reset_url; ?>"><?= BUtils::_('Forgot Password') ?>?</a>
            </div>
            <div class="swpm-join-us-link">
                <a id="register" class="register_link" href="<?= $join_url; ?>"><?= BUtils::_('Join Us') ?></a>
            </div>
            <div class="swpm-login-action-msg">
                <span class="swpm-login-widget-action-msg"><?= $auth->get_message(); ?></span>
            </div>
        </div>
    </form>
</div>
