<?php

if ( ! defined('WP_UNINSTALL_PLUGIN')) {
    die('Direct access not allowed');
}

class LMCaptchaUninstall
{
    public function __construct()
    {
        $this->lm_captcha_delete();
    }

    private function lm_captcha_delete()
    {
        $array = [
            "site_private_key",
            "show_captcha_label_form",
            "enable",
            "enabled_captcha",
            "script_value",
        ];
        foreach ($array as $item) {
            delete_option(sprintf('lm_captcha_%s', $item));
        }
    }
}

new LMCaptchaUninstall();

