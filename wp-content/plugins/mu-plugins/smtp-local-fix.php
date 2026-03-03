<?php
add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
});
