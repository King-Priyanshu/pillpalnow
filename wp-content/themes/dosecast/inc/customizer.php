<?php
/**
 * PillPalNow Theme Customizer
 *
 * @package PillPalNow
 */

function pillpalnow_customize_register($wp_customize)
{
	// Add Section for Dashboard Settings
	$wp_customize->add_section('pillpalnow_dashboard_section', array(
		'title' => __('Dashboard Settings', 'pillpalnow'),
		'priority' => 30,
	));

	// Greeting Text
	$wp_customize->add_setting('pillpalnow_greeting_text', array(
		'default' => 'Good Morning',
		'transport' => 'refresh',
		'sanitize_callback' => 'sanitize_text_field',
	));

	$wp_customize->add_control('pillpalnow_greeting_text', array(
		'label' => __('Greeting Text (Morning)', 'pillpalnow'),
		'section' => 'pillpalnow_dashboard_section',
		'type' => 'text',
	));

	// FAB Link
	$wp_customize->add_setting('pillpalnow_fab_link', array(
		'default' => '/add-medication',
		'transport' => 'refresh',
		'sanitize_callback' => 'esc_url_raw',
	));

	$wp_customize->add_control('pillpalnow_fab_link', array(
		'label' => __('Floating Action Button Link', 'pillpalnow'),
		'description' => __('Link for the floating + button in footer', 'pillpalnow'),
		'section' => 'pillpalnow_dashboard_section',
		'type' => 'text',
	));

}
add_action('customize_register', 'pillpalnow_customize_register');
