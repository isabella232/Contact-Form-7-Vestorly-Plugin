<?php
/**
 * Plugin Name: Vestorly Contact Form 7 Integration
 * Description: A plugin to integrate Vestorly with Contact Form 7
 * Author: Vestorly
 * Author URI: https://www.vestorly.com
 * Text Domain: vestorly-form-7
 * Version: 0.1.0
 */

defined( 'ABSPATH' ) or die( 'Vestorly Contact Form 7 Integration can only execute as part of Wordpress' );
if ( !defined('WP_VESTORLY_INT_VER') ) {
    define('WP_VESTORLY_INT_VER', '0.1.0' );
}

register_activation_hook(__FILE__, 'wpcf7_vestorly_check_dependency');

function wpcf7_vestorly_check_dependency() {
    if (!file_exists(WP_PLUGIN_DIR.'/contact-form-7/wp-contact-form-7.php')) {
        deactivate_plugins( __FILE__ );
        $error_message = '<div id="message" class="error"><p>';
        $error_message .= __('The Contact Form 7 must be installed for the <b>Vestorly Integration</b> to work.');
        $error_message .= '</p></div>';
        $error_message = esc_html( $error_message );
        echo $error_message;
    } 
}

register_uninstall_hook( __FILE__, 'wpcf7_vestorly_uninstall' );

function wpcf7_vestorly_uninstall() {
    WPCF7::update_option('vestorly', array(
        'publisher_id' => '',
        'auth_token' => '',
        'email_tag' => 'your-email',
        'name_tag' => 'your-name',
    ));
}
add_action( 'wpcf7_init', 'wpcf7_vestorly_integration_register_service');

function wpcf7_vestorly_integration_register_service() {
    $integration = WPCF7_Integration::get_instance();
    $integration->add_category( 'email_marketing',
        __( 'Email Marketing', 'vestorly-form-7' ) );

    $service = WPCF7_Vestorly::get_instance();
    $integration->add_service( 'vestorly', $service );
}

add_action( 'wpcf7_submit', 'wpcf7_vestorly_submit', 10, 2 );

function wpcf7_vestorly_submit( $contact_form, $result ) {
    $service = WPCF7_Vestorly::get_instance();
    if ( ! $service->is_active() ) {
        return;
    }

    if ( $contact_form->in_demo_mode() ) {
        return;
    }

    $do_submit = true;

    $do_submit = apply_filters( 'wpcf7_vestorly_submit', $do_submit,
        $contact_form, $result );
    if ( ! $do_submit ) {
        return;
    }

    $submission = WPCF7_Submission::get_instance();

    $data = $service->parse_form_submission( $submission );
    if ( $data === NULL ) {
        return;
    }
    $service->upload_contact($data);
}

# Extend WPCF7_Service_OAuth2 once OAuth wanted
class WPCF7_Vestorly extends WPCF7_Service {
    const service_name = 'vestorly';

    private static $instance;

    public static function get_instance() {
        if ( empty( self::$instance ) ) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    private function __construct() {

        $option = (array) WPCF7::get_option( self::service_name );
        
        if ( isset( $option['auth_token'] ) ) {
            $this->auth_token = $option['auth_token'];
        } else {
            $this->auth_token = '';
        }

        if ( isset( $option['publisher_id'] ) ) {
            $this->publisher_id = $option['publisher_id'];
        } else {
            $this->publisher_id = '';
        }

        if ( isset( $option['email_tag'] ) ) {
            $this->email_tag = $option['email_tag'];
        } else {
            $this->email_tag = 'your-email';
        }

        if ( isset( $option['name_tag'] ) ) {
            $this->name_tag = $option['name_tag'];
        } else {
            $this->name_tag = 'your-name';
        }
    }
    
    public function is_active() {
        return $this->auth_token != '' && $this->publisher_id != '';
    }

    protected function save_data() {
        $option = array_merge(
            (array) WPCF7::get_option( self::service_name ),
            array(
                'auth_token' => $this->auth_token,
                'publisher_id' => $this->publisher_id,
                'email_tag' => $this->email_tag,
                'name_tag' => $this->name_tag,
            )
        );

        WPCF7::update_option( self::service_name, $option );
    }

    protected function reset_data() {
        $this->auth_token = '';
        $this->publisher_id = '';
        $this->save_data();
    }

    public function get_title() {
        return 'Vestorly';
    }

    public function link() {
        echo sprintf( '<a href="%1$s">%2$s</a>',
            'https://www.vestorly.com',
            'vestorly.com'
        );
    }


    public function get_categories() {
        return array( 'email_marketing' );
    }

    public function icon() {
    }

    protected function log( $url, $request, $response ) {
        wpcf7_log_remote_request( $url, $request, $response );
    }

    protected function menu_page_url( $args = '' ) {
        $args = wp_parse_args( $args, array() );

        $url = menu_page_url( 'wpcf7-integration', false );
        $url = add_query_arg( array( 'service' => self::service_name ), $url );

        if ( ! empty( $args ) ) {
            $url = add_query_arg( $args, $url);
        }

        return $url;
    } 

    public function load( $action = '' ) {
        parent::load ($action);

        if ( 'setup' == $action and 'POST' == $_SERVER['REQUEST_METHOD'] ) {
            check_admin_referer( 'wpcf7-vestorly-setup' );

            if ( ! empty( $_POST['reset'] ) ) {
                $this->reset_data();
            } else {
                $this->auth_token = isset ( $_POST['auth_token'] )
                    // Sanitize removes necessary characters for the token
                    ? trim ( $_POST['auth_token'] ) : '';
                $this->publisher_id = isset( $_POST['publisher_id'] )
                    ? sanitize_text_field ( $_POST['publisher_id'] ) : '';
                $this->email_tag = isset( $_POST['email_tag'] )
                    ? sanitize_text_field ( $_POST['email_tag'] ) : '';
            }
            if ( isset( $_POST['name_tag']) ) {
                $sanitized_tag = sanitize_text_field($_POST['name_tag']);
                if ( substr_count ( $sanitized_tag , ',' ) > 1 ) {
                    wp_safe_redirect( $this->menu_page_url(
                        array(
                            'action' => 'setup',
                            'message' => 'config_error',
                        )
                    ) );
                } else {
                    $this->name_tag = $sanitized_tag;
                }
            } else {
                $this->name_tag = '';
            }
            $this->save_data();

            wp_safe_redirect( $this->menu_page_url( 'action=setup' ) );
            exit();
        }

        if ( 'edit' == $action and 'POST' == $_SERVER['REQUEST_METHOD'] ) {
            check_admin_referer( 'wpcf7-vestorly-setup' );

            wp_safe_redirect( $this->menu_page_url(
                array(
                    'action' => 'setup',
                    'message' => 'updated',
                )
            ) );

            exit();
        }
    }

    public function parse_form_submission( WPCF7_Submission $submission ) {
        $form_data = (array) $submission->get_posted_data(); 
        apply_filters('wpcf7_vestorly_parse_form_tags', $form_data);
        if ( !isset( $form_data[$this->email_tag] ) 
            or ! wpcf7_is_email($form_data[$this->email_tag]) ) {
           return; 
        }

        $user_info = array(
            'username' => $form_data[$this->email_tag],
            'member' => array(
                'email' => $form_data[$this->email_tag],
            ),
        );

        // Name tag may be two tags separated by commas if user has
        // separate inputs for first and last name
        $split_tag = explode( ',', $this->name_tag );
        if ( 
            ((count($split_tag)) === 1 ) 
            && ( isset( $form_data[$this->name_tag])) 
        ) {
            $name = trim( $form_data[$this->name_tag] );
            $parts = explode( " ", $name );
            $user_info['member']['last_name'] = array_pop( $parts );
            $user_info['member']['first_name'] = implode( " ", $parts );
        } elseif ( ( count ( $split_tag ) ) === 2 ) {
            $user_info['member']['last_name'] = array_key_exists(
                $split_tag[1], $form_data
            ) ? $form_data[$split_tag[1]] : '';
            $user_info['member']['first_name'] = array_key_exists(
                $split_tag[0], $form_data
            ) ? $form_data[$split_tag[0]] : '';
        }

        return $user_info;
    }

    public function upload_contact(array $post_data) {
        if ( WP_DEBUG ) {
            $endpoint = 'https://api.oodadev.com/api/v2/members';
        } else {
            $endpoint = 'https://api.vestorly.com/api/v2/members';
        }

        $post_data['publisher_id'] = $this->publisher_id;

        $request = array(
            'method' => 'POST',
            'headers' => array(
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $post_data ),
        );

        $response = $this->remote_request( $endpoint, $request );

        if ( 400 <= (int) wp_remote_retrieve_response_code( $response ) ) {
            if ( WP_DEBUG ) {
                $this->log( $endpoint, $request, $response );
            }
            return false;
        }
    }

    protected function remote_request( $url, $request = array() ) {
        $request = wp_parse_args( $request, array() );

        $request['headers'] = array_merge(
            $request['headers'],
            array(
                'X-Vestorly-Auth' => $this->auth_token,
            )
        );

        $response = wp_remote_request( esc_url_raw( $url ), $request );

        return $response;
    }

    public function admin_notice( $message = '' ) {
		switch ( $message ) {
			case 'success':
				echo sprintf(
					'<div class="updated notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( __( "Connection established.", 'vestorly-form-7' ) )
				);
				break;
			case 'failed':
				echo sprintf(
					'<div class="error notice notice-error is-dismissible"><p><strong>%1$s</strong>: %2$s</p></div>',
					esc_html( __( "ERROR", 'vestorly-form-7' ) ),
					esc_html( __( "Failed to establish connection. Please double-check your configuration.", 'vestorly-form-7' ) )
				);
				break;
            case 'config_error':
				echo sprintf(
					'<div class="error notice notice-error is-dismissible"><p><strong>%1$s</strong>: %2$s</p></div>',
					esc_html( __( "ERROR", 'vestorly-form-7' ) ),
					esc_html( __( "Failed to get name of user name tag. Please double-check your configuration.", 'vestorly-form-7' ) )
				);
				break;
			case 'updated':
				echo sprintf(
					'<div class="updated notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( __( "Configuration updated.", 'vestorly-form-7' ) )
				);
				break;
		}
	}

    public function display( $action = '' ) {
		echo '<p>' . sprintf(
			esc_html( __( 'The Vestorly integration module allows you to upload contact data collected from your contact forms to the Vestorly API. You can view your uploaded contact data on the Vestorly %s.', 'vestorly-form-7' ) ),
			wpcf7_link(
				__(
					'https://www.vestorly.com',
					'vestorly-form-7'
				),
				__( 'website', 'vestorly-form-7' )
			)
		) . '</p>';

		if ( $this->is_active() ) {
			echo sprintf(
				'<p class="dashicons-before dashicons-yes">%s</p>',
				esc_html( __( "This site is connected to the Vestorly API.", 'vestorly-form-7' ) )
			);
		}

		if ( 'setup' == $action ) {
			$this->display_setup();
		} else {
			echo sprintf(
				'<p><a href="%1$s" class="button">%2$s</a></p>',
				esc_url( $this->menu_page_url( 'action=setup' ) ),
				esc_html( __( 'Setup Integration', 'vestorly-form-7' ) )
			);
		}
    }

    private function display_setup() {
?>
<form method="post" action="<?php echo esc_url( $this->menu_page_url( 'action=setup' ) ); ?>">
<?php wp_nonce_field( 'wpcf7-vestorly-setup' ); ?>
<table class="form-table">
<tbody>
<tr>
	<th scope="row"><label for="auth_token"><?php echo esc_html( __( 'Auth Token', 'vestorly-form-7' ) ); ?></label></th>
	<td><?php
		if ( $this->is_active() ) {
            echo esc_html( __('Your token is set', 'vestorly-form-7' ) );
			echo sprintf(
				'<input type="hidden" value="%1$s" required id="auth_token" name="auth_token" />',
				esc_attr( $this->auth_token )
			);
		} else {
			echo sprintf(
				'<input type="text" aria-required="true" required value="%1$s" id="auth_token" name="auth_token" class="regular-text code" />',
				esc_attr( $this->auth_token)
			);
		}
	?></td>
</tr>
<tr>
    <th scope="row"><label for="publisher_id"><?php echo esc_html( __( 'Publisher ID', 'vestorly-form-7' ) ); ?></label></th>
    <td><?php
        if ( $this->is_active() ) {
            echo esc_html( $this->publisher_id );
            echo sprintf(
                '<input type="hidden" required value="%1$s" id="publisher_id" name="publisher_id" />',
                esc_attr( $this->publisher_id )
            );
        } else {
            echo sprintf(
                '<input type="text" aria-required="true" required value="%1$s" id="publisher_id" name="publisher_id" class="regular-text code" />',
                esc_attr( $this->publisher_id )
            );
        }
    ?></td>
</tr>
<tr>
    <th scope="row"><label for="email_tag"><?php echo esc_html( __( 'Contact Email Tag', 'vestorly-form-7' ) ); ?></label></th>
    <td><?php
        echo sprintf(
            '<input type="text" aria-required="true" value="%1$s" id="email_tag" name="email_tag" class="regular-text code" />', 
            esc_attr( $this->email_tag )
        );   
    ?></td>
</tr>
<tr>
    <th scope="row"><label for="name_tag"><?php echo esc_html( __( 'Contact Name Tag', 'vestorly-form-7' ) ); ?></label></th>
    <td><?php
        echo sprintf(
            '<input type="text" aria-required="true" value="%1$s" id="name_tag" name="name_tag" class="regular-text code" />', 
            esc_attr( $this->name_tag )
        );   
    ?>
    <p class="description"><?php echo esc_html( __( 'If you have separate tags for first name and last name, separate them by commas like so: first_name_tag,last_name_tag', 'vestorly-form-7' ) ); ?>
    </td>
</tr>
</tbody>
</table>
<?php
    if ( $this->is_active() ) {
        submit_button(
            _x( 'Reset Token', 'API keys', 'vestorly-form-7' ),
            'small', 'reset'
        );
	}

    submit_button(
        __( 'Save', 'vestorly-form-7' )
    );
?>
</form>


<?php
	}
}
