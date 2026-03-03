jQuery(document).ready(function(){
	
	
	
	jQuery('#deactivate-appmysite').on("click",function (e) {		
		e.preventDefault();
		window.deactivationLink = e.target.href;
        jQuery('#ams-form-popup-wrap').css('display', '-webkit-box');
        jQuery('#ams_modal_box').css('display', 'block');
    });
	


	
	jQuery('#ams-deactivate-submit-button').on("click",function (e) {
			
		e.preventDefault();
		const deactivationError = document.querySelectorAll( '.deactivation-error' );
		const checkedRadio = document.querySelectorAll( 'input[name="ams_survey_radios"]:checked' );
		const surveyForm = document.querySelectorAll( '.deactivation-survey-form' );
		const selectedOption = document.querySelector('input[name="ams_survey_radios"]:checked');
		let continueFlag = true;

		if ( deactivationError.length > 0 ) {
			continueFlag = false;
		}

		// If no radio button is selected then throw error.
		if ( 0 === checkedRadio.length && 0 === deactivationError.length ) {
			surveyForm[ 0 ].innerHTML += `
				<div class="notice notice-error deactivation-error" id="deactivation-alert" >
					Please select an option to continue.
				</div>
			`;		
			continueFlag = false;
		}
		else if(selectedOption.value==8){
			const user_reason = document.getElementById("user_reason");
			if (user_reason.value == "") {	
				surveyForm[ 0 ].innerHTML += `
				<div class="notice notice-error deactivation-error" id="deactivation-alert-1" >
					Please write your reason to continue.
				</div>
				`;		
				continueFlag = false;
			}
			
		}
		else{
			continueFlag = true;
		}	 

		if ( continueFlag  ) {
			const formData = jQuery( '.deactivation-survey-form' ).serialize();

			jQuery.ajax( {
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ams_deactivation_form_submit',
					'form-data': formData,
					nonce: frontend_ajax_object.amsDeactivationSurveyNonce,
				},
				beforeSend: function() {
					const spinner = document.querySelectorAll( '.spinner' );  //Block the page
					spinner[ 0 ].style.display = 'block';
					jQuery('#ams-deactivate-submit-button > .spinner').show();
				},
			} ).done( function( responseFromSubmit ) {
					const spinner = document.querySelectorAll( '.spinner' );  //Block the page
					spinner[ 0 ].style.display = 'none';
					jQuery('#ams-form-popup-wrap').hide();
					window.location.replace( window.deactivationLink );
				    				
			} );
		}

	});
	
    // close the modal
    jQuery('#ams-form-popup-close').on("click",function () {
        jQuery('#ams-form-popup-wrap').hide();

    });
	
	// cancel button
	jQuery('#ams-cancel-button').on("click",function () {
        jQuery('#ams-form-popup-wrap').hide();

    });
	
	
});



jQuery(document).on("click", "input[name='ams_survey_radios']", function(e){
	
	jQuery('.deactivation-error').css('display', 'none');
	jQuery('.deactivation-error').remove();
	
	//jQuery('#deactivation-alert-1').css('display', 'none');
	let survey_val = jQuery(this).val();
	if(survey_val == 8){
		jQuery("#user_reason").html('');
		jQuery("#user_reason").attr('placeholder' , 'Write your reason...');
		jQuery("#user_reason").removeClass('hidetextarea');
	}
	else if(survey_val == 3){
		jQuery("#user_reason").html('');
		jQuery("#user_reason").attr('placeholder' , 'What was the issue?');
		jQuery("#user_reason").removeClass('hidetextarea');
	}
	else if(survey_val == 2){
		jQuery("#user_reason").html('');
		jQuery("#user_reason").attr('placeholder' , 'Name of the company?');
		jQuery("#user_reason").removeClass('hidetextarea');
	}
	else if(survey_val == 7){
		jQuery("#user_reason").html('');
		jQuery("#user_reason").attr('placeholder' , 'What was the issue?');
		jQuery("#user_reason").removeClass('hidetextarea');
	}
	else if(survey_val == 6){
		jQuery("#user_reason").html('');
		jQuery("#user_reason").attr('placeholder' , 'Name of the plugin?');
		jQuery("#user_reason").removeClass('hidetextarea');
	}
	else if(survey_val == 5){
		jQuery("#user_reason").html('');
		jQuery("#user_reason").attr('placeholder' , 'What was your budget?');
		jQuery("#user_reason").removeClass('hidetextarea');
	}
	else{
		jQuery("#user_reason").addClass('hidetextarea');
		jQuery('#user_reason').html(jQuery("#ams-survey-radios-" + survey_val ).text().trim());
		jQuery('#user_reason').attr('required', true);
	}
});