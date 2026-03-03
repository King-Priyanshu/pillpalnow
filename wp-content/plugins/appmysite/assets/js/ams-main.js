( function() {
	//alert("Hello!");

}() );

jQuery(document).ready(function(){
		
jQuery("#ams_check_button").on("click",function(e) {
    e.preventDefault();
	var site_url = jQuery("#ams_site_url").val();
	var ams_license_key = jQuery("#ams_license_key").val();	
	var ams_is_site_woocommerce = jQuery("input[name=ams_is_site_woocommerce]").val();
    jQuery.ajax({
        type: "POST",
		url: "https://wordpress.api.appmysite.com/api/debug-website-connectivity",
		headers: {
			'Content-Type':'application/json',
			'Accept':'application/json'
		},
        data: JSON.stringify({ 
			website_url:site_url,//'https://shop.appmysite.com'
			ams_license_key:ams_license_key,
			is_woocommerce:ams_is_site_woocommerce
        }),
		beforeSend: function () {
			jQuery(".ams-loader").removeClass("ams-hide");
			jQuery('.ams-testing-text').show();			
			jQuery('.ams-loader-image').hide();
			jQuery('.greenstatus').hide();
			jQuery('.redstatus').hide();
			jQuery('.ams-test-result-label').hide();
			jQuery("#ams-health-error").text('');
			jQuery('#ams-health-check-btn-text').html("");
			jQuery('#ams-health-check-btn-loader').addClass("ams-health-check-btn-loader");
			
		},
        success: function(result) {

			jQuery('.ams-connectivity-table').show();	
            var plugin_url = jQuery("#ams_check_button").val();
			if(jQuery('.ams-connectivity-table').children().length == 5){
				for(let i=0;i<result.length;i++){							
					jQuery('.ams-connectivity-table').append("<div class='ams-connectivity-column'><div class='ams-connectivity-row-1'><div><div class='ams-loader ams-hide'></div><img class='ams-loader-image' style='display:none' src="+plugin_url+"/appmysite/assets/images/"+(result[i].status=="success" ? "approved.png alt='approved'": "rejected.png alt='approved'")+"></div><div><h4 class='ams-hide'>Wordpress Test 1</h4><h5>"+result[i].test+"</h5><p>"+result[i].description+"</p></div></div><div class='ams-connectivity-row-3'><div><h4 class='ams-testing-text' style='display:none'>Testing...</h4>"+(result[i].status=="success" ? "<h5 style='display:none;' class='greenstatus'>Success</h5>": "<h5 style='display:none;' class='redstatus'>Failed</h5>")+"</div></div><div class='ams-connectivity-row-2'><p class='ams-test-result-label' style='display:none'>"+result[i].label+"</p></div></div>");
				}
			}
			jQuery('#ams-health-check-btn-text').html("Check");
			jQuery('#ams-health-check-btn-loader').removeClass("ams-health-check-btn-loader");
			jQuery(".ams-loader").addClass("ams-hide");
			jQuery('.ams-testing-text').hide();
			jQuery('.ams-loader-image').show();
			jQuery('.greenstatus').show();
			jQuery('.redstatus').show();
			jQuery('.ams-test-result-label').show();

        },
        error: function(xhr, status, error) {
			jQuery('.ams-connectivity-table').show();
			jQuery('#ams-health-check-btn-text').html("Check");
			jQuery('#ams-health-check-btn-loader').removeClass("ams-health-check-btn-loader");
			jQuery("#ams-health-error").text('Curl Error'+xhr.responseText+'');
			jQuery(".ams-loader").addClass("ams-hide");
			jQuery('.ams-testing-text').hide();
			jQuery('.ams-loader-image').show();
			jQuery('.greenstatus').show();
			jQuery('.redstatus').show();
			jQuery('.ams-test-result-label').show();
        }
    });
});


jQuery('#ams-app-secret-token-form-submit-button').on("click",function (e) {
	e.preventDefault();
	let validLicense = true;
	var site_url = jQuery("#ams_site_url").val();
	jQuery('#ams_license_key').css('border-color', '');
	jQuery('#ams_license_validation_error').html("");
	var ams_license_key = jQuery("input[name=ams_license_key]").val();
	if ( ams_license_key.length <=28 || ams_license_key.length >34 ){ 
		validLicense = false; jQuery('#ams_license_key').css('border-color', '#FF8E8E'); 
		jQuery('#ams_license_validation_error').html("Please enter a valid license key.");
	}

	//Save license if Valid
	if ( validLicense  ) {
		console.log("Sending request with data:", {
            website_url: site_url,
            ams_license_key: ams_license_key
        });
		jQuery.ajax( {
			//url: ajaxurl,
			url: "https://wordpress.api.appmysite.com/api/verify-license",
			type: 'POST',
			headers: {
				'Content-Type':'application/json',
				'Accept':'application/json'
			},
			data: JSON.stringify({ 
				website_url:site_url,//'https://shop.appmysite.com'
				ams_license_key:ams_license_key
			}),
			beforeSend: function() {
				jQuery('#ams_verify_license_status').html("Verifying..");
				jQuery('#ams-license-submit-text').html("");
				jQuery('#ams-license-submit-loader').addClass("ams-license-submit-loader");
			},
			success: function(responseFromSubmit) {
				
				if(responseFromSubmit.is_valid=="yes"){
					jQuery('#ams_verify_license_status').html("Verified");
					jQuery('#ams_verify_license_status').removeClass("license-status-red");
					jQuery('#ams_verify_license_status').addClass("license-status-green");
					jQuery('#ams_license_validation_error').html(responseFromSubmit.msg);
					jQuery('#ams_license_validation_error').removeClass("license-status-red");
					jQuery('#ams_license_validation_error').addClass("license-status-green");

					// Send license key to WordPress to save in wp-config
					jQuery.ajax({
                        url: ajaxurl, // WordPress AJAX URL
                        type: 'POST',
                        data: {
                            action: 'save_ams_license_key',
                            ams_license_key: ams_license_key
                        },
                        success: function (response) {
                            if (response.success) {
                                console.log("License key saved successfully.");
                            } else {
                                console.log("Failed to save the license key.");
                            }
                        },
                        error: function () {
                            console.log("Error while saving the license key.");
                        }
                    });

					jQuery('#input_ams_license_key').val(ams_license_key);
					jQuery('#input_ams_license_status').val("Verified");
					
					jQuery('#ams-license-submit-text').html("Submit");
					jQuery('#ams-license-submit-loader').removeClass("ams-license-submit-loader");
				}
				else{	//if not valid
					jQuery('#ams_verify_license_status').html("Unverified");
					jQuery('#ams_verify_license_status').removeClass("license-status-green");
					jQuery('#ams_verify_license_status').addClass("license-status-red");
					jQuery('#ams_license_key').css('border-color', '#FF8E8E'); 
					jQuery('#ams_license_validation_error').html(responseFromSubmit.msg);	
						
					jQuery('#ams-license-submit-text').html("Submit");
					jQuery('#ams-license-submit-loader').removeClass("ams-license-submit-loader");
				}
			},
			error: function(xhr, status, error) {
				jQuery('#ams_license_validation_error').html("Something went wrong.");
				jQuery('#ams_verify_license_status').removeClass("license-status-green");
				jQuery('#ams_verify_license_status').addClass("license-status-red");
				jQuery('#ams_license_key').css('border-color', '#FF8E8E'); 
				jQuery('#ams-license-submit-text').html("Submit");
				jQuery('#ams-license-submit-loader').removeClass("ams-license-submit-loader");
			}
		});	
	}
});

jQuery('#ams-safe-mode-form-submit-button').on("click",function (e) {
	e.preventDefault();
	var ams_safe_mode = jQuery("input[name=ams_safe_mode]").val();
	
	if(ams_safe_mode=='Currently enabled'){
		//desired value
		ams_safe_mode = 'off';
	}else if(ams_safe_mode=='Currently disabled'){
		ams_safe_mode = 'on';
	}else{
		ams_safe_mode = 'on';
	}
	 
	jQuery.ajax( {
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ams_safe_mode_form_submit',
					'form-data': {'ams_safe_mode':ams_safe_mode},
					nonce: frontend_ajax_object.amsFormNonce,
				},
				beforeSend: function() {
					jQuery('#ams_safe_mode').val("Loading..");
					jQuery('#ams-safe-mode-form-submit-button').text('');
					jQuery('#ams-safe-mode-form-submit-button').append ('<div id="ams-safe-mode-btn-loader" class="ams-license-submit-loader"></div>');
					
					
					
				},
			} ).done( function( responseFromSubmit ) {

					if(responseFromSubmit.success){

						if(responseFromSubmit.data.ams_safe_mode=='on'){ 
							jQuery('#ams_safe_mode').val("Currently enabled");
							jQuery('#ams_safe_mode_div').removeClass("safemode-enabled");
							jQuery('#ams_safe_mode_div').addClass("safemode-disabled");
							jQuery('#ams-safe-mode-form-submit-button').text ("Disable");
							
							/* show validation message on pop-up */	
							jQuery('#ams-sfmd-valiation-msg-heading').text ("Safe mode activated.");
							jQuery('#ams-sfmd-valiation-msg').text (responseFromSubmit.data.msg);
							jQuery('#ams-safemode-validation-popup').addClass("ams-show-popup");
							/* end show pop-up  */
							
						}else{
							jQuery('#ams_safe_mode').val("Currently disabled");
							jQuery('#ams_safe_mode_div').removeClass("safemode-disabled");
							jQuery('#ams_safe_mode_div').addClass("safemode-enabled");
							jQuery('#ams-safe-mode-form-submit-button').text ("Enable");
							
							/* show validation message on pop-up */	
							jQuery('#ams-sfmd-valiation-msg-heading').text ("Safe mode deactivated.");
							jQuery('#ams-sfmd-valiation-msg').text (responseFromSubmit.data.msg);
							jQuery('#ams-safemode-validation-popup').addClass("ams-show-popup");
							/* end show pop-up  */
							
						}
					}else{
						jQuery('#ams_license_validation_error').html("Something went wrong. Please try again. ");
						
						if(responseFromSubmit.data.ams_safe_mode=='on'){ 
							jQuery('#ams_safe_mode').val("Currently enabled");
							jQuery('#ams_safe_mode_div').removeClass("safemode-enabled");
							jQuery('#ams_safe_mode_div').addClass("safemode-disabled");
							jQuery('#ams-safe-mode-form-submit-button').text ("Disable");
							
							/* show validation message on pop-up */	
							jQuery('#ams-sfmd-valiation-msg-heading').text ("Safe mode deactivation failed.");
							jQuery('#ams-sfmd-valiation-msg').text (responseFromSubmit.data.msg);
							jQuery('#ams-safemode-validation-popup').addClass("ams-show-popup");
							/* end show pop-up  */
							
						}else{
							jQuery('#ams_safe_mode').val("Currently disabled");
							jQuery('#ams_safe_mode_div').removeClass("safemode-disabled");
							jQuery('#ams_safe_mode_div').addClass("safemode-enabled");
							jQuery('#ams-safe-mode-form-submit-button').text ("Enable");
							
							
							/* show validation message on pop-up */	
							jQuery('#ams-sfmd-valiation-msg-heading').text ("Safe mode activation failed.");
							jQuery('#ams-sfmd-valiation-msg').text (responseFromSubmit.data.msg);
							jQuery('#ams-safemode-validation-popup').addClass("ams-show-popup");
							/* end show pop-up  */
						}
						
					}					
				    				
			} );
				
	
});
/*#####################################Youtube video####################################*/
	// Get the modal
	var modal = document.getElementById("ams-modal-1");

	// Get the button that opens the modal
	var btn = document.getElementById("ams-button");

	// Get the <span> element that closes the modal
	var span = document.getElementsByClassName("modalclose")[0];

	// When the user clicks the button, open the modal 
	btn.onclick = function() {
	 if(document.getElementById("ams-youtube-video-1")){ document.getElementById("ams-youtube-video-1").setAttribute('src','https://www.youtube.com/embed/CTOpl_d1ef8?autoplay=1');
	  modal.style.display = "block";
	}
	}

	// When the user clicks on <span> (x), close the modal
	span.onclick = function() {
	if(document.getElementById("ams-youtube-video-1")){    
	  document.getElementById("ams-youtube-video-1").removeAttribute('src');    
	  modal.style.display = "none";
	}
	}

	// When the user clicks anywhere outside of the modal, close it
	window.addEventListener("click" , function(event) {
	  if (event.target == modal) {
		document.getElementById("ams-youtube-video-1").removeAttribute('src');   
		modal.style.display = "none";
	  }
	});

/*#####################################Sam Youtube video####################################*/    
	// Get the modal
	var modal2 = document.getElementById("ams-modal-2");

	// Get the button that opens the modal
	var btn2 = document.getElementById("sam-youtube-link");

	// Get the <span> element that closes the modal
	var span2 = document.getElementsByClassName("sam-youtube-modal-close")[0];

	// When the user clicks the button, open the modal 
	btn2.onclick = function() {
	 if(document.getElementById("ams-youtube-video-2")){ document.getElementById("ams-youtube-video-2").setAttribute('src','https://www.youtube.com/embed/SzngP_QWgKA?autoplay=1');
	  modal2.style.display = "block";
	 }
	}

	// When the user clicks on <span> (x), close the modal
	span2.onclick = function() {
	if(document.getElementById("ams-youtube-video-2")){    
	  document.getElementById("ams-youtube-video-2").removeAttribute('src');    
	  modal2.style.display = "none";
	}
	}

	// When the user clicks anywhere outside of the modal, close it
	window.addEventListener("click" , function(event) {
	  if (event.target == modal2) {
		document.getElementById("ams-youtube-video-2").removeAttribute('src');   
		modal2.style.display = "none";
	  }
	});

	
});




