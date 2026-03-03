<?php
/**
 * Template Name: Magic Login
 * 
 * Legacy template. 
 * Real magic login logic happens in PillPalNow_Magic_Login::listen_for_magic_login() on 'init'.
 * If we ended up here, it means the listener didn't redirect (or wasn't triggered? unlikely).
 */

wp_redirect(home_url('/login'));
exit;