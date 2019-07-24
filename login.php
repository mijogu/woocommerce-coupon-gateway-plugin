<?php ?><!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>

<meta http-equiv="Content-Type" content="<?php bloginfo( 'html_type' ); ?>; charset=<?php bloginfo( 'charset' ); ?>" />
<title><?php echo get_bloginfo( 'name' ); ?></title>

<style type="text/css" media="screen">
#login_error, .login .message, 
#loginform { margin-bottom: 20px; }
</style>

<?php

/*
if ( $is_iphone ) {
	?>
	<meta name="viewport" content="width=320; initial-scale=0.9; maximum-scale=1.0; user-scalable=0;" />
	<style type="text/css" media="screen">
	.login form, .login .message, #login_error { margin-left: 0px; }
	.login #nav, .login #backtoblog { margin-left: 8px; }
	.login h1 a { width: auto; }
	#login { padding: 20px 0; }
	</style>
	<?php
}
*/
// do_action( 'login_enqueue_scripts' );
// do_action( 'password_protected_login_head' );

?>

</head>
<body class="login login-password-protected login-action-password-protected-login wp-core-ui">

<div id="login">
	<h1><a href="<?php echo esc_url( apply_filters( 'password_protected_login_headerurl', home_url( '/' ) ) ); ?>" title="<?php echo esc_attr( apply_filters( 'password_protected_login_headertitle', get_bloginfo( 'name' ) ) ); ?>"><?php bloginfo( 'name' ); ?></a></h1>

	<?php //do_action( 'password_protected_login_messages' ); ?>
	<?php //do_action( 'password_protected_before_login_form' ); ?>

    <p>Please enter a valid coupon code to enter site.</p>

	<form name="loginform" id="loginform" action="/?woocommerce" method="post">

		<p>
			<label for="woocommerce_coupon_pass"><?php echo __('Please Enter Your WooCommerce Code Below', 'password-protected'); ?><br />
			<input type="text" name="woocommerce_coupon_pass" id="woocommerce_coupon_pass" class="input" value="" size="20" tabindex="21" /></label>
		</p>

		<?php /* if ( $Password_Protected->allow_remember_me() ) : ?>
			<p class="forgetmenot">
				<label for="password_protected_rememberme"><input name="password_protected_rememberme" type="checkbox" id="password_protected_rememberme" value="1" tabindex="90" /> <?php esc_attr_e( 'Remember Me' ); ?></label>
			</p>
		<?php endif;*/ ?>

		<p class="submit">
			<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Claim My Token' ); ?>" tabindex="100" />
			<input type="hidden" name="testcookie" value="1" />
			<input type="hidden" name="password-protected" value="login" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $_REQUEST['redirect_to'] ); ?>" />
		</p>
	</form>

	<?php //do_action( 'password_protected_after_login_form' ); ?>

</div>

<?php /* ?>
<script type="text/javascript">
try{document.getElementById('password_protected_pass').focus();}catch(e){}
if(typeof wpOnload=='function')wpOnload();
</script>
<?php //*/ ?>

<?php do_action( 'login_footer' ); ?>

<div class="clear"></div>

</body>
</html>