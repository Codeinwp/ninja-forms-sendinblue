<?php
/*
Plugin Name: Ninja Forms - SendinBlue
Description: Sign users up for your SendinBlue newsletter when submitting Ninja Forms
Version: 1.0.0
Author: Ionut Neagu
Author URI: https://sendinblue.com
Contributors: Ionut Neagu
*/



/**
 * Plugin text domain
 *
 * @since       1.0
 * @return      void
 */
function ninja_forms_sb_textdomain() {

	// Set filter for plugin's languages directory
	$edd_lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
	$edd_lang_dir = apply_filters( 'ninja_forms_sb_languages_directory', $edd_lang_dir );

	// Load the translations
	load_plugin_textdomain( 'ninja_forms_sb', false, $edd_lang_dir );
}
add_action( 'init', 'ninja_forms_sb_textdomain' );


/**
 * Add the SendinBlue tab to the Plugin Settings screen
 *
 * @since       1.0
 * @return      void
 */

function ninja_forms_sb_add_tab() {

	if ( ! function_exists( 'ninja_forms_register_tab_metabox_options' ) )
		return;

	$tab_args              = array(
		'name'             => 'SendinBlue',
		'page'             => 'ninja-forms-settings',
		'display_function' => '',
		'save_function'    => 'ninja_forms_save_license_settings',
	);
	ninja_forms_register_tab( 'sendinblue', $tab_args );

}
add_action( 'admin_init', 'ninja_forms_sb_add_tab' );


/**
 * PRegister the settings in the SendinBlue Tab
 *
 * @since       1.0
 * @return      void
 */
function ninja_forms_sb_add_plugin_settings() {

	if ( ! function_exists( 'ninja_forms_register_tab_metabox_options' ) )
		return;

	$mc_args = array(
		'page'     => 'ninja-forms-settings',
		'tab'      => 'sendinblue',
		'slug'     => 'sendinblue',
		'title'    => __( ' SendinBlue', 'ninja_forms_sb' ),
		'settings' => array(
			array(
				'name' => 'ninja_forms_sb_api',
				'label' => __( 'SendinBlue API Key', 'ninja_forms_sb' ),
				'desc' => __( 'Enter your SendinBlue API key', 'ninja_forms_sb' ),
				'type' => 'text',
				'size' => 'regular'
			)
		)
	);
	ninja_forms_register_tab_metabox( $mc_args );
}
add_action( 'admin_init', 'ninja_forms_sb_add_plugin_settings', 100 );


/**
 * Register the form-specific settings
 *
 * @since       1.0
 * @return      void
 */
function ninja_forms_sb_add_form_settings() {

	if ( ! function_exists( 'ninja_forms_register_tab_metabox_options' ) )
		return;

	$args = array();
	$args['page'] = 'ninja-forms';
	$args['tab']  = 'form_settings';
	$args['slug'] = 'basic_settings';
	$args['settings'] = array(
		array(
			'name'      => 'sendinblue_signup_form',
			'type'      => 'checkbox',
			'label'     => __( 'SendinBlue', 'ninja_forms_sb' ),
			'desc'      => __( 'Enable SendinBlue signup for this form?', 'ninja_forms_sb' ),
			'help_text' => __( 'This will cause all email fields in this form to be sent to SendinBlue', 'ninja_forms_sb' ),
		),
		array(
			'name'    => 'ninja_forms_sb_list',
			'label'   => __( 'Choose a list', 'edda' ),
			'desc'    => __( 'Select the list you wish to subscribe buyers to', 'ninja_forms_sb' ),
			'type'    => 'select',
			'options' => ninja_forms_sb_get_sendinblue_lists()
		)
	);
	ninja_forms_register_tab_metabox_options( $args );

}
add_action( 'admin_init', 'ninja_forms_sb_add_form_settings', 100 );


/**
 * Retrieve an array of SendinBlue lists
 *
 * @since       1.0
 * @return      array
 */
function ninja_forms_sb_get_sendinblue_lists() {

	global $pagenow, $edd_settings_page;

	if ( ! isset( $_GET['page'] ) || ! isset( $_GET['tab'] ) || $_GET['page'] != 'ninja-forms' || $_GET['tab'] != 'form_settings' )
		return;
	$options = get_option( "ninja_forms_settings" );

	if ( isset( $options['ninja_forms_sb_api'] ) && strlen( trim( $options['ninja_forms_sb_api'] ) ) > 0 ) {

		$lists = array();
		if ( !class_exists( 'Mailin' ) )
			require_once 'sendinblue/sendinblue.php';
		$api       = new Mailin("https://api.sendinblue.com/v2.0",$options['ninja_forms_sb_api'] );
		$list_data = $api->lists();
		if ( $list_data ) :
			foreach ( $list_data['data'] as $key => $list ) :
				$lists[] = array(
					'value' => $list['id'],
					'name'  => $list['name']
				);
			endforeach;
		endif;
		return $lists;
	}
	return array();
}


/**
 * Subscribe an email address to a SendinBlue list
 *
 * @since       1.0
 * @return      bool
 */
function ninja_forms_sb_subscribe_email( $subscriber = array(), $list_id = '', $double_opt = true ) {

	$options = get_option( "ninja_forms_settings" );

	if ( empty( $list_id ) || empty( $subscriber ) )
		return false;

	if ( !class_exists( 'Mailin' ) )
		require_once 'sendinblue/sendinblue.php';

	$api = new Mailin("https://api.sendinblue.com/v2.0",$options['ninja_forms_sb_api'] );

	$vars = array();

	if ( ! empty( $subscriber['first_name'] ) )
		$vars['FNAME'] = $subscriber['first_name'];

	if ( ! empty( $subscriber['last_name'] ) )
		$vars['SURNAME'] = $subscriber['last_name'];

	if ( $api->listSubscribe( $list_id, $subscriber['email'], $vars, 'html', $double_opt ) === true ) {
		return true;
	}

	return false;
}


/**
 * Check for newsletter signups on form submission
 *
 * @since       1.0
 * @return      void
 */
function ninja_forms_sb_check_for_email_signup() {

	if ( ! function_exists( 'ninja_forms_register_tab_metabox_options' ) )
		return;

	global $ninja_forms_processing;

	$form = $ninja_forms_processing->get_all_form_settings();

	// Check if SendinBlue is enabled for this form
	if ( empty( $form['sendinblue_signup_form'] ) )
		return;

	$double_opt = ! empty( $form['ninja_forms_sb_double_opt_in'] );

	//Get all the user submitted values
	$all_fields = $ninja_forms_processing->get_all_fields();

	if ( is_array( $all_fields ) ) { //Make sure $all_fields is an array.
		//Loop through each of our submitted values.
		$subscriber = array();
		foreach ( $all_fields as $field_id => $value ) {

			$field = $ninja_forms_processing->get_field_settings( $field_id );
			//echo '<pre>'; print_R( $field ); echo '</pre>'; exit;
			if ( ! empty( $field['data']['email'] ) && is_email( $value ) ) {
				$subscriber['email'] = $value;
			}

			if ( ! empty( $field['data']['first_name'] ) ) {
				$subscriber['first_name'] = $value;
			}

			if ( ! empty( $field['data']['last_name'] ) ) {
				$subscriber['last_name'] = $value;
			}

		}
		if ( ! empty( $subscriber ) ) {
			ninja_forms_sb_subscribe_email( $subscriber, $form['ninja_forms_sb_list'], $double_opt );
		}
	}
}


/**
 * Connect our signup check to form processing
 *
 * @since       1.0
 * @return      void
 */
function ninja_forms_sb_hook_into_processing() {
	add_action( 'ninja_forms_post_process', 'ninja_forms_sb_check_for_email_signup' );
}
add_action( 'init', 'ninja_forms_sb_hook_into_processing' );


/**
 * Plugin Updater / licensing
 *
 * @since       1.0.2
 * @return      void
 */

function ninja_forms_sb_extension_setup_license() {
    if ( class_exists( 'NF_Extension_Updater' ) ) {
        $NF_Extension_Updater = new NF_Extension_Updater( '', '1.0.3', ' ', __FILE__, '' );
    }
}
add_action( 'admin_init', 'ninja_forms_sb_extension_setup_license' );