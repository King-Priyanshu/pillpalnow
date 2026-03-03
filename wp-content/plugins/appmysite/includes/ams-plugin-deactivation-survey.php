<?php
/**
 * AppMySite Plugin
 * @copyright   Copyright (c) 2022, AppMySite
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Give Deactivation Survey Form.
 * Note: only for internal use
 */

function ams_deactivation_popup() {
	
			ob_start();
			?>
			
					<!-- Modal HTML -->
				<div id="ams-form-popup-wrap"><div id="ams-form-popup"><div class="pop-up-content"><div class="container-fluid"><div class="row">
													
					<div class="wb-md-12">
						<div id="ams_modal_box" class="modal-content" >
							<div class="modal-header">
								<h6 class="modal-title">Wait! You are about to disconnect from AppMySite.</h6>
								<div id="ams-form-popup-close">&times;</div>
							</div>
								
							<div class="modal-body">
							
								<form class="deactivation-survey-form" method="POST">
									<h2><?php esc_html_e( 'Why are you deactivating the plugin?', 'ams' ); ?></h2>
									
									<div>
										<label class="ams-field-description" id="ams-survey-radios-1">
											<input type="radio" name="ams_survey_radios" value="1">
											<?php esc_html_e( "I am deactivating temporarily", 'ams' ); ?>
										</label>
									</div>

									<div>
										<label class="ams-field-description" id="ams-survey-radios-2">
											<input type="radio" name="ams_survey_radios" value="2">
											<?php esc_html_e( 'I found a better alternative', 'ams' ); ?>
										</label>
									</div>

									<div>
										<label class="ams-field-description" id="ams-survey-radios-3">
											<input type="radio" name="ams_survey_radios" value="3" data-has-field="true">
											<?php esc_html_e( 'I could not connect my website', 'ams' ); ?>
										</label>

										
									</div>

									<div>
										<label class="ams-field-description" id="ams-survey-radios-4">
											<input type="radio" name="ams_survey_radios" value="4">
											<?php esc_html_e( 'I could not figure out how the plugin works', 'ams' ); ?>
										</label>
									</div>

									<div>
										<label class="ams-field-description" id="ams-survey-radios-5">
											<input type="radio" name="ams_survey_radios" value="5">
											<?php esc_html_e( "I found the service too expensive", 'ams' ); ?>
										</label>
									</div>
									
									<div>
										<label class="ams-field-description" id="ams-survey-radios-6">
											<input type="radio" name="ams_survey_radios" value="6">
											<?php esc_html_e( "There is a conflict with other plugins", 'ams' ); ?>
										</label>
									</div>
									
									<div>
										<label class="ams-field-description" id="ams-survey-radios-7">
											<input type="radio" name="ams_survey_radios" value="7">
											<?php esc_html_e( "I didn’t get the support I require", 'ams' ); ?>
										</label>
									</div>
									
									<div>
										<label class="ams-field-description" id="ams-survey-radios-8">
											<input type="radio" name="ams_survey_radios" value="8">
											<?php esc_html_e( 'Other', 'ams' ); ?>
										</label>
									</div>

									<?php
									$current_user = wp_get_current_user();
									$user_email = $current_user->user_email;
									?>
									<textarea id="user_reason" class="hidetextarea detailedreason" name="user_reason" ></textarea>
									<input type="hidden" name="user_email" value="<?php echo $user_email; ?>">
									<input type="hidden" name="site_url" value="<?php echo esc_url( get_bloginfo( 'url' ) ); ?>">
								</form>
							</div>
							<div class="modal-footer amsbottombuttons">
								<button type="button" class="btn btn-outline-primary" id="ams-cancel-button" data-bs-dismiss="modal">Cancel</button>
								<button type="button" class="btn btn-primary amssubmitbutton" id="ams-deactivate-submit-button">
									<div class="spinner"></div>Deactivate</button>
														
							</div>
						</div>
					</div>
					
				</div></div></div></div></div>	

			<?php
			echo ob_get_clean();
			// wp_die();

		}


/**
 * Ajax callback after the deactivation survey form has been submitted.
 * Note: only for internal use
 */


function ams_deactivation_form_submit() {

	if ( ! check_ajax_referer( 'ajax-nonce', 'nonce', false ) ) {
		wp_send_json_error();
		wp_die();
	}
	
	$form_data   =  wp_parse_args( $_POST['form-data'] ) ; // clean
	
	// Get the selected radio value.
	$radio_value = isset( $form_data['ams_survey_radios'] ) ? sanitize_text_field($form_data['ams_survey_radios']) : 0;

	// Get the reason if any radio button has an optional text field.
	$user_reason = isset( $form_data['user_reason'] ) ? sanitize_text_field($form_data['user_reason']) : '';

	// Get the email of the user who deactivated the plugin.
	$user_email  = isset( $form_data['user_email'] ) ? sanitize_email($form_data['user_email']) : '';

	// Get the URL of the website on which Give plugin is being deactivated.
	$site_url    = isset( $form_data['site_url'] ) ? sanitize_url($form_data['site_url']) : '';

	/**
	 * Make a POST request to the endpoint to send the survey data.
	 */
	$response    = wp_remote_post(
		'https://admin.appmysite.com/api/ams-plugin-deactivation-survey',
		array(
			'body' => array(
				'selected_user_reason'  => $radio_value,
				'user_reason'   => $user_reason,
				'user_email' => $user_email,
				'site_url' => $site_url,
			)
		)
	);

	$response_body = wp_remote_retrieve_body( $response );
	$response_code = wp_remote_retrieve_response_code($response);
	
	if($response_code == 200 || $response_code == 201){
		wp_send_json_success(
			array(
				'success' => true,
			)
		);
	}else{
		wp_send_json_success(
			array(
				'success' => false,
				'msg'	=> $response_body
			)
		);
		//wp_send_json_error();
	}

	//wp_die();
}

