<?php
/**
 * @package Lemin Cropped Captcha
 * @version 0.0.10
 */
/*
 * Plugin Name:       Lemin Cropped Captcha
 * Plugin URI:        https://dashboard.leminnow.com/
 * Description:       Lemin Cropped Captcha is uniquely playful, robust, and effective. Through gamification, we are curing the pains of traditional CAPTCHA.
 * Version:           0.0.10
 * Stable tag:        0.0.10
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Leminnow
 * Author URI:        https://leminnow.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Direct access not allowed!' );
}

class leminCaptcha {
    const UPDATE = 'update';
    const LM_ACTION = 'lm_action';
    const LM_OPTIONS = 'lm_options';
    const LM_CAPTCHA = 'lm-captcha';
    const LM_PAGE_OPTIONS_QUERY = '?page=lm_options';
    const LM_CAPTCHA_HEADER_ACTION = 'lm_captcha_header_section';
    const LM_CAPTCHA_DISPLAY = 'lm_captcha_display';
    const LM_CAPTCHA_COMMON_VERIFY = 'lm_captcha_common_verify';
    const LM_CAPTCHA_STYLES = 'lm_captcha_styles';
    const LM_CAPTCHA_STYLES_VERSION = '1.1';
    const LM_OPTION_SHOW_CAPTCHA_LABEL = 'lm_captcha_show_captcha_label_form';
    const LM_OPTION_PRIVATE_KEY = 'lm_captcha_site_private_key';
    const LM_OPTION_ENABLE = 'lm_captcha_enable';
    const LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS = 'lm_captcha_enabled_captcha';
    const LM_OPTION_CAPTCHA_SCRIPT_VALUE = 'lm_captcha_script_value';

    const CAPTCHA_VALIDATE_URL = 'https://api.leminnow.com/captcha/v1/cropped/validate';

    private $pluginName;
    private $loginFormCheckbox;
    private $registrationFormCheckbox;
    private $lostPasswordFormCheckbox;
    private $resetPasswordFormCheckbox;
    private $commentFormCheckbox;
    private $privateKey;
    public $captchaLabel;
    public $captchaScript;
    public $enableCaptcha;

    function __construct() {
        add_action( 'init', [ $this, 'run' ] );
        add_action( 'wpcf7_init', [ $this, 'lm_wpcf7_add_lmcaptcha_tag' ], 20 );
        add_action('wp_print_footer_scripts', [ $this, 'wpcf7_script'], 9);
        add_action('admin_init', [$this, 'redirect_to_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);
        register_activation_hook(__FILE__, [$this, 'activation']);
    }

    function wpcf7_script() {
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const captchaResetCF7 = function (event) {
                    if(window.leminCroppedCaptcha.getCaptcha().isReady()){
                        try {
                            window.leminCroppedCaptcha.getCaptcha().resetCaptcha()
                        } catch (e) {
                            try {
                                window.leminCroppedCaptcha.getCaptcha().reloadPuzzle()
                            } catch (e) {
                            }
                        }
                    }
                };
                [...document.querySelectorAll('.wpcf7')].map((form) => {
                    form.addEventListener('wpcf7invalid', captchaResetCF7, false);
                    form.addEventListener('wpcf7spam', captchaResetCF7, false);
                    form.addEventListener('wpcf7mailsent', captchaResetCF7, false);
                    form.addEventListener('wpcf7mailfailed', captchaResetCF7, false);
                    form.addEventListener('wpcf7submit', captchaResetCF7, false);
                    return form;
                });
            });
        </script>
    <?php }

    function lm_wpcf7_add_lmcaptcha_tag() {
        if ( function_exists( 'wpcf7_add_tag_generator' ) ) {
            wpcf7_add_tag_generator( 'lmcaptcha', 'lemin-captcha', 'lmcaptcha',
                array( $this, 'lm_wpcf7_tag_generator_lmcaptcha' ),
                array( 'nameless' => 1 ) );
        }
        wpcf7_add_form_tag( 'lmcaptcha*',
            [ $this, 'lm_wpcf7_lmcaptcha_tag_handler' ], true );
        wpcf7_add_form_tag( 'lmcaptcha',
            [ $this, 'lm_wpcf7_lmcaptcha_tag_handler' ], true );
        add_filter( 'wpcf7_validate_lmcaptcha', [ $this, 'lm_wpcf7_validate' ],
            10, 2 );
        add_filter( 'wpcf7_validate_lmcaptcha*', [ $this, 'lm_wpcf7_validate' ],
            10, 2 );
        add_filter( 'wpcf7_messages',
            array( $this, 'lm_wpcf7_invalid_lmcaptcha_messages' ) );
    }

    function lm_wpcf7_invalid_lmcaptcha_messages( $messages ) {
        return array_merge(
            $messages,
            array(
                'invalid_lmcaptcha' => array(
                    'description' => __( 'Lemin Captcha Invalidated.',
                        'lmcaptcha' ),
                    'default'     => __( 'Lemin Captcha Invalidated.',
                        'lmcaptcha' ),
                ),
            )
        );
    }

    function lm_wpcf7_validate( $result, $tag ) {
        if ( ! $this->lm_captcha_verify() ) {
            $result->invalidate( $tag,
                wpcf7_get_message( 'invalid_lmcaptcha' ) );
        }

        return $result;
    }

    function lm_wpcf7_lmcaptcha_tag_handler( $tag ) {
        if ( empty( $tag->name ) ) {
            return '';
        }

        $captcha_display = '<p>';
        if ( get_option( self::LM_OPTION_SHOW_CAPTCHA_LABEL ) ) {
            $captcha_display .= "<label >Captcha</label>";
        }
        $captcha_display .= "<span class='wpcf7-form-control-wrap "
            . sanitize_html_class( $tag->name ) . "'>";
        $captcha_display .= "<span id='lemin-cropped-captcha' class='wpcf7-form-control'></span></span>";
        $captcha_display .= '</p>';
        $captcha_display .= wp_specialchars_decode(
            html_entity_decode(
                filter_var( get_option( self::LM_OPTION_CAPTCHA_SCRIPT_VALUE ) ),
                ENT_QUOTES
            )
        );

        return $captcha_display;
    }

    function lm_wpcf7_tag_generator_lmcaptcha( $contact_form, $args = '' ) {
        $args = wp_parse_args( $args, array() );

        $description
            = __( "Generate a form-tag for a lemin captcha. For more details, see %s.",
            self::LM_CAPTCHA );

        $desc_link = wpcf7_link( __( 'https://www.leminnow.com/',
            self::LM_CAPTCHA ), __( 'Lemin Captcha', self::LM_CAPTCHA ) );
        ?>
        <div class="control-box">
            <fieldset>
                <legend><?php echo sprintf( esc_html( $description ),
                        $desc_link ); ?></legend>
                <table class="form-table">
                    <tbody>
                    <tr>
                        <th scope="row"><label
                                for="<?php echo esc_attr( $args['content']
                                    . '-name' ); ?>"><?php echo esc_html( __( 'Name',
                                    'contact-form-7' ) ); ?></label></th>
                        <td><input type="text" name="name"
                                   class="tg-name oneline"
                                   id="<?php echo esc_attr( $args['content']
                                       . '-name' ); ?>"/>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </fieldset>
        </div>

        <div class="insert-box">
            <input type="text" name="lmcaptcha" class="tag code"
                   readonly="readonly" onfocus="this.select()"/>

            <div class="submitbox">
                <input type="button" class="button button-primary insert-tag"
                       value="<?php echo esc_attr( __( 'Insert Tag',
                           'contact-form-7' ) ); ?>"/>
            </div>
        </div>
        <?php
    }

    function updateSettings() {
        if ( current_user_can( 'manage_options' ) ) {
            $hash    = null;
            $options = [
                self::LM_OPTION_SHOW_CAPTCHA_LABEL,
                self::LM_OPTION_PRIVATE_KEY,
                self::LM_OPTION_ENABLE,
                self::LM_OPTION_CAPTCHA_SCRIPT_VALUE,
            ];

            foreach ( $options as $option ) {
                $postValue = filter_input(
                    INPUT_POST, $option,
                    FILTER_SANITIZE_SPECIAL_CHARS
                );
                if ( ! $postValue ) {
                    $postValue = '';
                }
                update_option( $option, $postValue );

                if ( substr_count( $option, 'key' ) ) {
                    $hash .= $postValue;
                }
            }

            $enable_captcha_forms = filter_input(
                INPUT_POST,
                self::LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS, FILTER_DEFAULT,
                FILTER_REQUIRE_ARRAY
            );
            update_option(
                self::LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS,
                $enable_captcha_forms
            );
        }
    }

    function action_links( $links ) {
        return array_merge(
            [
                'settings' => sprintf(
                    '<a href="options-general.php%s">%s</a>',
                    self::LM_PAGE_OPTIONS_QUERY,
                    __( 'Settings', self::LM_CAPTCHA )
                )
            ], $links
        );
    }

    function activation() {
        if (!get_option(self::LM_OPTION_PRIVATE_KEY) || !get_option(self::LM_OPTION_ENABLE)) {
            set_transient('lm_redirect_after_activation', true, 30);
        }
    }

    function redirect_to_settings() {
        if (get_transient('lm_redirect_after_activation')) {
            delete_transient('lm_redirect_after_activation');
            if (!is_network_admin() && !isset($_GET['activate-multi'])) {
                wp_safe_redirect(
                    admin_url(
                        sprintf(
                            'options-general.php%s',
                            self::LM_PAGE_OPTIONS_QUERY
                        )
                    )
                );
                exit;
            }
        }
    }

    function admin_notices() {
        if (get_transient('lm_redirect_after_activation')) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>' . __('Lemin Cropped Captcha: Required options are missing. Please configure the plugin settings.', 'text-domain') . '</p>';
            echo '</div>';
        }
    }

    function options_page() {
        echo sprintf(
            '<div class="wrap"><h1>%s - %s</h1><form method="post" action="%s">',
            $this->pluginName, __( 'Settings', self::LM_CAPTCHA ),
            self::LM_PAGE_OPTIONS_QUERY
        );

        settings_fields( self::LM_CAPTCHA_HEADER_ACTION );
        do_settings_sections( self::LM_OPTIONS );

        submit_button();

        echo sprintf(
            '<input type="hidden" name="%s" value="%s">%s</form>%s</div>',
            self::LM_ACTION, self::UPDATE, PHP_EOL, " "
        );
    }

    function menu() {
        add_submenu_page(
            'options-general.php', $this->pluginName,
            'Lemin Captcha',
            'manage_options', self::LM_OPTIONS, [ $this, 'options_page' ]
        );
        add_action( 'admin_init', [ $this, 'display_options' ] );
    }

    function display_lm_captcha_site_private_key() {
        echo sprintf(
            '<input type="text" name="%1$s" class="regular-text" id="%1$s" value="%2$s" /><br/>',
            self::LM_OPTION_PRIVATE_KEY, $this->privateKey
        );
        echo '<p>' . __(
                'The private key given to you when you  ',
                self::LM_CAPTCHA
            ) . __(
                'register for lemin captcha.',
                self::LM_CAPTCHA
            ) . '</p>';
    }

    function display_lm_captcha_enable() {
        function getEnableCaptcha() {
            $lm_captcha_enable      = get_option( "lm_captcha_enable" )
                ? filter_var( get_option( "lm_captcha_enable" ),
                    FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : 'logout';
            $captcha_enable_options = "";
            $enable_options         = array(
                "all"    => "All Users",
                "login"  => "Logged in Users",
                "logout" => "Logged Out Users"
            );
            foreach ( $enable_options as $captcha_enable_option => $en_option )
            {
                if ( $captcha_enable_option === $lm_captcha_enable ) {
                    $captcha_enable_options = $captcha_enable_options
                        . "<option selected value=\"{$captcha_enable_option}\">{$en_option}</option>";
                } else {
                    $captcha_enable_options = $captcha_enable_options
                        . "<option value=\"{$captcha_enable_option}\">{$en_option}</option>";
                }
            }

            return $captcha_enable_options;
        }

        echo "<select name=\"lm_captcha_enable\" id=\"lm_captcha_enable\" > "
            . getEnableCaptcha() . "</select>";
    }

    function display_lm_captcha_enabled_captcha() {
        $checkbox_options
            = ( ! empty( get_option( self::LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS ) ) )
            ? get_option( self::LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS ) : [];
        $wp_forms = array(
            'login'          => __( 'Login Form', self::LM_CAPTCHA ),
            'registration'   => __( 'Registration Form', self::LM_CAPTCHA ),
            'lost_password'  => __( 'Lost Password Form', self::LM_CAPTCHA ),
            'reset_password' => __( 'Reset Password Form', self::LM_CAPTCHA ),
            'comment'        => __( 'Comment Form', self::LM_CAPTCHA ),
        );
        foreach ( $wp_forms as $formId => $formName ) {
            if ( sizeof( $checkbox_options ) == 0 ) {
                $enabledCaptchaFormElements
                    = "<input type=\"checkbox\" name=\"lm_captcha_enabled_captcha[$formId]\" id=\"lm_captcha_{$formId}_check_disable\" value=\""
                    . esc_attr( $formId ) . "\" "
                    . checked( true, false, false ) . "/>";
            } else {
                if ( isset( $checkbox_options[ $formId ] ) ) {
                    $enabledCaptchaFormElements
                        = "<input type=\"checkbox\" name=\"lm_captcha_enabled_captcha[$formId]\" id=\"lm_captcha_{$formId}_check_disable\" value=\""
                        . esc_attr( $formId ) . "\" "
                        . checked(
                            $formId, $checkbox_options[ $formId ],
                            false
                        ) . "/>";
                } else {
                    $enabledCaptchaFormElements
                        = "<input type=\"checkbox\" name=\"lm_captcha_enabled_captcha[$formId]\" id=\"lm_captcha_{$formId}_check_disable\" value=\""
                        . esc_attr( $formId ) . "\" "
                        . checked( true, false, false ) . "/>";
                }
            }
            $enabledCaptchaFormElements .= "<label for=\"lm_captcha_{$formId}_check_disable\">"
                . $formName . "</label></br>";
            echo $enabledCaptchaFormElements;
        }
    }

    function display_lm_captcha_show_captcha_label_form() {
        echo sprintf(
            '<input type="checkbox" name="%1$s" id="%1$s" value="true" %2$s />',
            self::LM_OPTION_SHOW_CAPTCHA_LABEL,
            checked( 'true', $this->captchaLabel, false )
        );
        echo __( "Show or Hide Captcha label in the forms", self::LM_CAPTCHA );
    }

    function display_lm_captcha_script_value() {
        $lm_captcha_script_value
            = filter_var(
            get_option( self::LM_OPTION_CAPTCHA_SCRIPT_VALUE ),
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        echo "<textarea name='" . self::LM_OPTION_CAPTCHA_SCRIPT_VALUE
            . "'  class='regular-text' id='lm_captcha_script_value' rows='5' cols='50'>"
            . $lm_captcha_script_value . "</textarea>";
        echo "<p>" . __(
                'The necessary JavaScript code for lemin captcha.',
                self::LM_CAPTCHA
            ) . "</p>";
    }

    function display_options() {
        $fields = [
            [
                'id'    => self::LM_OPTION_PRIVATE_KEY,
                'label' => __(
                    "Private Key <sup id='private' class='required'>*</sup>",
                    self::LM_CAPTCHA
                )
            ],
            [
                'id'    => self::LM_OPTION_CAPTCHA_SCRIPT_VALUE,
                'label' => __(
                    "Captcha script <sup id='private' class='required'>*</sup>",
                    self::LM_CAPTCHA
                )
            ],
            [
                'id'    => self::LM_OPTION_ENABLE,
                'label' => __( 'Show ' . $this->pluginName . ' for',
                    self::LM_CAPTCHA )
            ],
            [
                'id'    => self::LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS,
                'label' => __( 'Enable ' . $this->pluginName, self::LM_CAPTCHA )
            ],
            [
                'id'    => self::LM_OPTION_SHOW_CAPTCHA_LABEL,
                'label' => __(
                    'Show Captcha label in the form',
                    self::LM_CAPTCHA
                )
            ],
        ];

        add_settings_section(
            self::LM_CAPTCHA_HEADER_ACTION,
            __(
                $this->pluginName . ' Common Settings',
                self::LM_CAPTCHA
            ), [], self::LM_OPTIONS
        );

        foreach ( $fields as $field ) {
            add_settings_field(
                $field['id'], $field['label'],
                [ $this, sprintf( 'display_%s', $field['id'] ) ],
                self::LM_OPTIONS,
                self::LM_CAPTCHA_HEADER_ACTION
            );
            register_setting( self::LM_CAPTCHA_HEADER_ACTION, $field['id'] );
        }
        $plugin_path_styles = plugin_dir_url( __FILE__ )
            . 'lemin-cropped-captcha.css';
        wp_enqueue_style(
            self::LM_CAPTCHA_STYLES, $plugin_path_styles, false,
            self::LM_CAPTCHA_STYLES_VERSION, 'all'
        );
    }

    function lm_captcha_common_verify( $input ) {
        global $message_statement;
        if ( $this->lm_captcha_verify() ) {
            return $input;
        } else {
            $errorTitle         = $this->pluginName;
            $errorParams        = [ 'response' => 403, 'back_link' => 1 ];
            $failedMsg
                = '<p><strong>%s:</strong> Lemin Captcha %s. %s</p>';
            $error              = __( 'Error', self::LM_CAPTCHA );
            $verificationFailed = __( 'verification failed', self::LM_CAPTCHA );
            $message            = $message_statement;
            wp_die(
                sprintf(
                    $failedMsg, $error, $verificationFailed,
                    $message
                ), $errorTitle, $errorParams
            );
        }
    }

    function lm_captcha_verify() {
        global $message_statement;
        if ( $_SERVER["REQUEST_METHOD"] == "POST" ) {
            $challengeId = filter_input(
                INPUT_POST, "lemin_challenge_id",
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
            );
            $answer      = filter_input(
                INPUT_POST, "lemin_answer",
                FILTER_SANITIZE_FULL_SPECIAL_CHARS
            );
            $data        = array(
                'private_key'  => $this->privateKey,
                'challenge_id' => $challengeId,
                'answer'       => $answer
            );

            $response = wp_remote_post(
                self::CAPTCHA_VALIDATE_URL,
                array(
                    'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                    'body'        => json_encode( $data ),
                    'method'      => 'POST',
                    'data_format' => 'POST',
                )
            );
            $response = json_decode( $response["body"], 1 );
            if ( $response["success"] ) {
                return true;
            } else {
                $this->validate_error_token( $response );

                return false;
            }
        } else {
            $message = $message_statement;
            wp_die(
                $message, "Lemin Captcha",
                array( "response" => 403, "back_link" => 1 )
            );
        }
    }

    function validate_error_token( $response ) {
        $error_codes = array(
            'incorrect_answer'        => ( 'Answer is wrong.' ),
            'invalid_parameters'      => ( 'The parameters (private_key, challenge_id, answer) sent are invalid. PARAMETERS.' ),
            'bad-request'             => ( 'The request is invalid or malformed.' ),
            'invalid_challenge_id'    => ( 'There is no challenge with the challenge id sent' ),
            'invalid_cropped_captcha' => ( 'Cropped Captcha is invalid.' ),
            'invalid_private_key'     => ( 'Private key is invalid or None.' ),
            'challenge_is_not_active' => ( 'Challenge is not active.' ),
            'unknown-error'           => ( 'Something went wrong!' )
        );
        foreach ( $response['code'] as $code ) {
            if ( ! isset( $error_codes[ $code ] ) ) {
                $code = 'unknown-error';
            }
            $message = "<p><strong>" . __( "ERROR:", self::LM_CAPTCHA )
                . "</strong> " . __(
                    $error_codes[ $code ],
                    self::LM_CAPTCHA
                )
                . "</p>";
            global $message_statement;
            $message_statement = $message;
        }
    }

    function lm_captcha_display( $type = 'data' ) {
        $captcha_display = '';
        if ( get_option( self::LM_OPTION_SHOW_CAPTCHA_LABEL ) ) {
            $captcha_display .= "<label >Captcha </label>";
        }

        $captcha_display .= "<p id='lemin-cropped-captcha'></p>";
        $captcha_display .= wp_specialchars_decode(
            html_entity_decode(
                filter_var( get_option( self::LM_OPTION_CAPTCHA_SCRIPT_VALUE ) ),
                ENT_QUOTES
            )
        );

        if ( $type == 'html' ) {
            return $captcha_display;
        } else {
            echo $captcha_display;
        }
    }

    function frontend() {
        $plugin_path_styles = plugin_dir_url( __FILE__ )
            . 'lemin-cropped-captcha.css';
        wp_enqueue_style(
            "style", $plugin_path_styles, false,
            self::LM_CAPTCHA_STYLES_VERSION, 'all'
        );
        $lm_display_list        = [ self::LM_CAPTCHA_DISPLAY ];
        $lm_captcha_verify_list = [];
        if ( ( ! empty( $this->loginFormCheckbox ) )
            && ( ( $this->enableCaptcha == "logout" && ! is_user_logged_in() )
                || $this->enableCaptcha == "all"
                || ( $this->enableCaptcha == "login"
                    && is_user_logged_in() ) )
        ) {
            array_push( $lm_display_list, 'login_form' );
            array_push( $lm_captcha_verify_list, "wp_authenticate_user" );
        }

        if ( ( ! empty( $this->lostPasswordFormCheckbox ) )
            && ( ( $this->enableCaptcha == "logout" && ! is_user_logged_in() )
                || $this->enableCaptcha == "all"
                || ( $this->enableCaptcha == "login"
                    && is_user_logged_in() ) )
        ) {
            array_push( $lm_display_list, "lostpassword_form" );
            array_push( $lm_captcha_verify_list, "lostpassword_post" );
        }

        if ( ( ! empty( $this->registrationFormCheckbox ) )
            && ( ( $this->enableCaptcha == "logout" && ! is_user_logged_in() )
                || $this->enableCaptcha == "all"
                || ( $this->enableCaptcha == "login"
                    && is_user_logged_in() ) )
        ) {
            array_push( $lm_display_list, "register_form" );
            array_push( $lm_captcha_verify_list, "registration_errors" );
        }

        if ( ! ( empty( $this->commentFormCheckbox ) )
            && ( ( $this->enableCaptcha == "logout" && ! is_user_logged_in() )
                || $this->enableCaptcha == "all"
                || ( $this->enableCaptcha == "login"
                    && is_user_logged_in() ) )
        ) {
            function custom_extra_comment_fields( $default_fields ) {
                $leminCaptcha                    = new leminCaptcha();
                $default_fields['comment_field'] .= $leminCaptcha->lm_captcha_display(
                    'html'
                );

                return $default_fields;
            }

            add_filter(
                'comment_form_defaults',
                'custom_extra_comment_fields'
            );
            array_push( $lm_captcha_verify_list, 'preprocess_comment' );
        }

        if ( ( ! empty( $this->resetPasswordFormCheckbox ) )
            && ( ( $this->enableCaptcha == "logout" && ! is_user_logged_in() )
                || $this->enableCaptcha == "all"
                || ( $this->enableCaptcha == "login"
                    && is_user_logged_in() ) )
        ) {
            array_push( $lm_display_list, "resetpass_form" );
            array_push( $lm_captcha_verify_list, "resetpass_post" );
        }

        $lm_captcha_display_action = self::LM_CAPTCHA_DISPLAY;
        $lm_captcha_verify_action  = self::LM_CAPTCHA_COMMON_VERIFY;

        foreach ( $lm_display_list as $lm_display ) {
            add_action( $lm_display, [ $this, $lm_captcha_display_action ] );
        }

        foreach ( $lm_captcha_verify_list as $lm_captcha_verify ) {
            add_action(
                $lm_captcha_verify,
                [ $this, $lm_captcha_verify_action ]
            );
        }
    }

    function run() {
        $this->pluginName = get_file_data(
                                __FILE__,
                                [ 'Name' => 'Plugin Name' ]
                            )['Name'];
        $postAction       = filter_input(
            INPUT_POST, self::LM_ACTION,
            FILTER_SANITIZE_SPECIAL_CHARS
        );
        if ( $postAction === self::UPDATE ) {
            $this->updateSettings();
        }
        $checkbox_options
            = ( ! empty( get_option( self::LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS ) ) )
            ? get_option( self::LM_OPTION_ENABLE_CAPTCHA_FOR_FORMS ) : [];
        $this->privateKey
            = filter_var(
            get_option( self::LM_OPTION_PRIVATE_KEY ),
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        $this->enableCaptcha
            = filter_var(
            get_option( self::LM_OPTION_ENABLE ),
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );

        $this->registrationFormCheckbox
            = isset( $checkbox_options['registration'] );
        $this->lostPasswordFormCheckbox
            = isset( $checkbox_options['lost_password'] );
        $this->resetPasswordFormCheckbox
            = isset( $checkbox_options['reset_password'] );
        $this->commentFormCheckbox
            = isset( $checkbox_options['comment'] );
        $this->loginFormCheckbox = isset( $checkbox_options['login'] );

        $this->captchaLabel = get_option( self::LM_OPTION_SHOW_CAPTCHA_LABEL );
        $this->captchaScript
            = filter_var( get_option( self::LM_OPTION_CAPTCHA_SCRIPT_VALUE ) );
        $getAction          = filter_input(
            INPUT_GET, self::LM_ACTION,
            FILTER_SANITIZE_SPECIAL_CHARS
        );

        if ( ! $this->privateKey || ! $this->captchaScript ) {
            function lemin_admin_notice() {
                echo '<div class="notice notice-error"><p>';
                echo __( 'First, you need to <a href="'
                    . esc_url( 'https://dashboard.leminnow.com/auth/signup/' )
                    . '" target=\"blank\" rel=\"external\">register on the site</a>, get a private key from Lemin Captcha, and save the required JavaScript code it below' );
                echo '</p></div>';
            }
            add_action( 'admin_notices', 'lemin_admin_notice' );
        }

        add_filter(
            sprintf(
                'plugin_action_links_%s',
                plugin_basename( __FILE__ )
            ), [ $this, 'action_links' ]
        );
        add_action( 'admin_menu', [ $this, 'menu' ] );

        if ( ! is_admin() ) {
            $this->frontend();
        }
    }
}

new leminCaptcha();

