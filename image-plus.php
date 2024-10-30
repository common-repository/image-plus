<?php
/*
Plugin Name:			Image+
Plugin URI:				https://imageplus.ai
Description:			Generate more clicks, leads and sales using AI-powered images.
Requires at least:		6.0
Requires PHP:			7.0
Version:				1.2.5
Tested up to:			6.2.2
Author:					Images With Benefits
License:				GPL-2.0-or-later
License URI:			https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:			image-plus
Domain Path:			/languages
WC requires at least:	2.6
WC tested up to:		7.5
*/

/**
 * Imgwb_Image_Plus
 *
 */
class Imgwb_Image_Plus {
	private $version = '1.2.5';
	private $api;
	private $api_counter_delta = 0;
	private $account;
	private $site;
	private $sw_script        = '?imgwb_page=service_worker';
	private $block_name       = 'images-with-benefits/image-plus';
	private $logo = 'https://imgwb.com/s/imageplus-logo.svg';

	/**
	 * Constructor for the Imgwb_Image_Plus class.
	 *
	 * Sets up various hooks and actions for the plugin.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->api        = get_option('imgwb_api', 'https://api.imgwb.com');
		$this->imgwb_host = get_option('imgwb_host', 'https://imgwb.com');

		// lifecycle
		register_activation_hook(__FILE__, array($this, 'imgwb_activate'));
		register_deactivation_hook(__FILE__, array($this, 'imgwb_deactivate'));

		// admin pages
		add_action('admin_menu', array($this, 'imgwb_admin_menu'));
		add_action('admin_init', array($this, 'imgwb_admin_init'));
		add_filter('plugin_action_links', array($this, 'imgwb_plugin_action_links'), 10, 2);
		add_action('admin_enqueue_scripts', array($this, 'imgwb_admin_scripts'));
		add_action('wp_print_scripts', array($this, 'imgwb_wp_print_scripts'));
		add_action('admin_head', array($this, 'imgwb_admin_head'));
		add_action('in_admin_header', array($this, 'imgwb_in_admin_header'));
		add_filter('post_row_actions', array($this, 'imgwb_post_row_actions'), 10, 2 );

		add_filter('classic_editor_plugin_settings', array($this, 'imgwb_classic_editor_override'), 10, 2);
		add_action('init', array($this, 'imgwb_init'), 10, 0);
		add_filter('query_vars', array($this, 'imgwb_query_vars'));
		add_action('template_redirect', array($this, 'imgwb_template_redirect') );
		add_action('rest_api_init', array($this, 'imgwb_rest_api_init'));
		add_action('wp_after_insert_post', array($this, 'imgwb_batch_update'));

		add_action('filter_block_editor_meta_boxes', array($this, 'imgwb_filter_meta_boxes'));
		add_filter('template_include', array($this, 'imgwb_template'));

		// site-wide replacement of images with Image+
		add_filter('wp_content_img_tag', array($this, 'imgwb_wp_content_img_tag'), 10, 3);

		// viewer experience
		add_filter('wp_headers', array($this, 'imgwb_add_headers'));
		add_action('wp_enqueue_scripts', array($this, 'imgwb_scripts'));

		// downstream events to work out which images are converting best. no personally identifiable data is used
		add_action('plugins_loaded', array($this, 'imgwb_plugins_loaded'));

		// admin bar listens for training beacons
		add_action('admin_bar_menu', array($this, 'imgwb_admin_bar'), 99999);
		add_action('wp_after_admin_bar_render', array($this, 'imgwb_admin_bar_training'));
	}

	/**
	 * Logs a message to the error log if debug mode is enabled.
	 *
	 * @param mixed $msg The message to log.
	 * @return void
	 */
	private function imgwb_log( $msg) {
		if (get_option('imgwb_debug')) {
			error_log( var_export( $msg, 1 ) );
		}
	}

	/**
	 * Adds admin menu and toggle to show real-time AI training data
	 *
	 * @param WP_Admin_Bar $admin_bar The admin bar
	 * @return void
	 */
	public function imgwb_admin_bar ( WP_Admin_Bar $admin_bar ) {
		$admin_bar->add_menu( array(
			'id'    => 'imgwb-admin-menu',
			'parent' => null,
			'group'  => null,
			'title' => 'Image+',
			'href'  => admin_url('edit.php?post_type=image_plus')
		) );

		$admin_bar->add_menu( array(
			'id'    => 'imgwb-admin-menu-training',
			'parent' => 'imgwb-admin-menu',
			'group'  => null,
			'title' => 'Show real-time AI training',
			'href'  => '#'
		) );

		$admin_bar->add_menu( array(
			'id'    => 'imgwb-admin-menu-library',
			'parent' => 'imgwb-admin-menu',
			'group'  => null,
			'title' => 'Library',
			'href'  => admin_url('edit.php?post_type=image_plus')
		) );

		$admin_bar->add_menu( array(
			'id'    => 'imgwb-admin-menu-settings',
			'parent' => 'imgwb-admin-menu',
			'group'  => null,
			'title' => 'Settings',
			'href'  => admin_url('edit.php?post_type=image_plus&page=imgwb-settings')
		) );
	}

	/**
	 * Adds code to admin bar to detect and echo real-time AI training data
	 *
	 * @param WP_Admin_Bar $admin_bar The admin bar
	 * @return void
	 */
	public function imgwb_admin_bar_training() {
		?>
		<style>
			#wp-admin-bar-imgwb-admin-menu .ab-item img {
				width:20px;
				margin: 0 2px;
				vertical-align:middle;
				background-color:#fff;
				opacity: 1;
				animation: fadeOut1 5s forwards;
			}
			#wp-admin-bar-imgwb-admin-menu .ab-item img.toggler {
				animation: fadeOut2 5s forwards;
			}
			@keyframes fadeOut1 {
				0%, 80% { opacity: 1; }
				100% { opacity: 0; }
			}
			@keyframes fadeOut2 {
				0%, 80% { opacity: 1; }
				100% { opacity: 0; }
			}
		</style>
		<script>
			let training_state = 0;
			// toggler state
			if (typeof window.localStorage !== "undefined") {
				training_state = parseInt(window.localStorage.getItem('imgwb-training-state') || '0');
			}

			const admin_bar_el = document.querySelector('#wp-admin-bar-imgwb-admin-menu .ab-item');
			const admin_bar_submenu_el = document.querySelector('#wp-admin-bar-imgwb-admin-menu-training .ab-item');

			function imgwb_render_training(training) {
				if (!training_state) return;

				// remove stale signals
				Object.entries(training).forEach(function([goal_id, ts]) {
					const goal_el = admin_bar_el.querySelector('img[data-goal="' + goal_id + '"]');
					const togo = 5 - (Date.now() - ts)/1000;
					if (togo < 0) {
						// remove
						if (goal_el) {
							goal_el.remove();
						}
						delete(training[goal_id]);
					} else if (training_state) {
						// render
						if (goal_el) {
							goal_el.classList.toggle('toggler');
						} else {
							const signal_html = '<img data-goal="' + goal_id + '" src="https://imgwb.com/s/goals/' + goal_id + '.png" />';
							admin_bar_el.insertAdjacentHTML('beforeend', signal_html);
						}
					}
				});

				// persist training signals
				if (typeof window.localStorage !== "undefined") {
					window.localStorage.setItem('imgwb-training', JSON.stringify(training));
				}
			}

			function imgwb_decorate_training_menu() {

				let _html = '';
				if (training_state) {
					_html = '<strong>&#x2713;</strong> ';
				}

				_html += 'Show real-time AI training';
				admin_bar_submenu_el.innerHTML = _html;
			}

			document.addEventListener('imgwb_training', (_event) => {
				// save alert to localStorage so it's still visible on next page load
				let training;
				if (typeof window.localStorage !== "undefined") {
					training = JSON.parse(window.localStorage.getItem('imgwb-training') || '{}');
				} else {
					training = {};
				}

				if (_event.detail.event == 'cachedviews') {
					training['g001'] = Date.now();
				} else if (_event.detail.time) {
					// click
					training['g101'] = Date.now();
				} else if (_event.detail.events) {
					// e.g. lead_g201
					_event.detail.events.forEach(function(_event) {
						const goal_id = _event.split('_')[1];
						training[goal_id] = Date.now();
					});
				}

				imgwb_render_training(training);
			});

			// page load too
			document.addEventListener('DOMContentLoaded', function() {
				let training;
				if (typeof window.localStorage !== "undefined") {
					training = JSON.parse(window.localStorage.getItem('imgwb-training') || '{}');
				} else {
					training = {};
				}

				imgwb_render_training(training);

				imgwb_decorate_training_menu();
			});

			admin_bar_submenu_el.addEventListener('click', function(_evt) {
				training_state = 1 - training_state;
				imgwb_decorate_training_menu();

				if (typeof window.localStorage !== "undefined") {
					window.localStorage.setItem('imgwb-training-state', training_state);
				}

				_evt.preventDefault();
				return false;
			});

		</script>
		<?php
	}

	/**
	 * Activates the plugin and performs necessary setup tasks.
	 *
	 * @return void
	 */
	public function imgwb_activate() {
		// flag for redirect
		update_option('imgwb_activation_login', true);

		// prepare site
		$current_user = wp_get_current_user();
		$response = $this->imgwb_api_request('POST', '/external/wordpress/activate', array(
			'website' => get_site_url(),
			'sitename' => get_bloginfo('name'),
			'email' => $current_user->user_email,
			'name' => $current_user->display_name,
			'words' => $this->imgwb_get_front_words()
		));

		// so we can retrieve the site goal
		if (isset($response['words_activation_id'])) {
			update_option('imgwb_words_activation_id', $response['words_activation_id']);
		}
	}

	/**
	 * Deactivates the plugin and updates the user status.
	 *
	 * @return void
	 */
	public function imgwb_deactivate() {

		// update site status
		// needs an access_token to update to status:deactivated
		$access_token = get_option('imgwb_access_token');
		if ($access_token) {
			$deactivated = $this->imgwb_api_request('PUT', '/site', array(
				'status' => 'deactivated'
			));
		}

		delete_option('imgwb_access_token');
		delete_option('imgwb_cpt_updated');
		delete_option('imgwb_login_message');
		delete_option('imgwb_plan_id');
		delete_option('imgwb_refresh_token');
		delete_option('imgwb_settings_enhance');
		delete_option('imgwb_settings_general');
		delete_option('imgwb_settings_optimize');
		delete_option('imgwb_updated');
		delete_option('imgwb_user_id');
		delete_option('imgwb_user_email');
		delete_option('imgwb_plan_config');
		delete_option('imgwb_roadmap_token');
		delete_option('imgwb_help_token');
		delete_option('imgwb_wizard_offered');
	}

	/**
	 * Initializes the REST API endpoints for the Imgwb Image Plus plugin.
	 *
	 * @param WP_REST_Server $rest_server The REST server instance.
	 *
	 * @return void
	 */
	public function imgwb_rest_api_init( $rest_server) {
		//$this->imgwb_log('imgwb_rest_api_init...');

		$namespace = 'imgwb_image_plus/api';

		register_rest_route($namespace, 'account', array(
			'methods'   => 'GET',
			'callback'  => array($this, 'imgwb_api_account_get'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'site', array(
			'methods'   => 'PUT',
			'callback'  => array($this, 'imgwb_api_site_put'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'library', array(
			'methods'   => 'GET',
			'callback'  => array($this, 'imgwb_api_library_get'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'image', array(
			'methods'   => 'POST',
			'callback'  => array($this, 'imgwb_api_image_post'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'image/(?P<image_id>[\w]+)', [
			'methods'   => 'GET',
			'callback'  => array($this, 'imgwb_api_image_get'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		]);

		register_rest_route($namespace, 'variant', array(
			'methods'   => 'POST',
			'callback'  => array($this, 'imgwb_api_variant_post'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'variant/(?P<variant_id>[\w]+)', array(
			'methods'   => 'PUT',
			'callback'  => array($this, 'imgwb_api_variant_put'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'generative', array(
			'methods'   => 'GET,POST',
			'callback'  => array($this, 'imgwb_api_generative'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'sideload', array(
			'methods'   => 'POST',
			'callback'  => array($this, 'imgwb_api_sideload_post'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'wizard/(?P<step_id>[\w]+)', array(
			'methods'   => 'GET',
			'callback'  => array($this, 'imgwb_api_wizard_get'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

		register_rest_route($namespace, 'notification', array(
			'methods'   => 'POST',
			'callback'  => array($this, 'imgwb_api_notification_post'),
			'permission_callback' => array($this, 'imgwb_rest_permission')
		));

	}

	/**
	 * Determines if the current user has permission to access the Imgwb REST API.
	 *
	 * @return bool True if the current user has permission to access the Imgwb REST API, false otherwise.
	 */
	public function imgwb_rest_permission() {
		return current_user_can('edit_posts');
	}

	/**
	 * Retrieves account information from the Imgwb API and updates options in the WordPress database.
	 *
	 * @return void
	 */
	public function imgwb_api_account_get( $request ) {
		//$this->imgwb_log('imgwb_api_account_get...');

		$params = $request->get_params();

		$query_params = array();
		if (isset($params['projection'])) {
			$query_params['projection'] = $params['projection'];
		}

		$response = $this->imgwb_api_get('/account', $query_params);

		//$this->imgwb_log(array('account' => $response));

		if (isset($response['account'])) {
			$this->account = $response['account'];

			// persist these so we can use them even if login has expired, on Upgrade and Settings
			update_option('imgwb_plan_id', $this->account['plan_id']);
			update_option('imgwb_plan_config', $this->account['plan_config']);
		}

		if (isset($response['site'])) {
			$this->site = $response['site'];
		}

		// white-label
		$whitelabel = false;
		if (isset($response['account']) && isset($response['site'])) {
			if (isset($this->account['plan_config']['whitelabel']) && $this->account['plan_config']['whitelabel']) {
				if (isset($this->site['logo_dark']) && $this->site['logo_dark'] != $this->logo) {
					$whitelabel = $this->site['logo_dark'];
				} else if (isset($this->site['logo_light']) && $this->site['logo_light'] != $this->logo) {
					$whitelabel = $this->site['logo_light'];
				}
			}
		}
		update_option('imgwb_whitelabel', $whitelabel);

		return $response;
	}

	/**
	 * Updates site information via Imgwb API.
	 *
	 * @param WP_REST_Request $request The REST API request.
	 *
	 * @return array|WP_Error The API response or error.
	 */
	public function imgwb_api_site_put( $request ) {
		//$this->imgwb_log('imgwb_api_site_put...');

		$params   = $request->get_params();
		$resource = '/site';

		return $this->imgwb_api_request('PUT', $resource, $params);
	}

	/**
	 * Retrieves images from the ImageWB library and caches the image data.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return array The library response array containing the image data, with the image data for each image cached.
	 */
	public function imgwb_api_library_get( $request ) {
		//$this->imgwb_log('imgwb_api_library_get...');

		$params = $request->get_params();

		$resource = '/library';

		$query_params = array('images' => $params['images']);
		if (isset($params['projection'])) {
			$query_params['projection'] = $params['projection'];
		}

		$rv = $this->imgwb_api_get($resource, $query_params);

		// cache image data
		if (isset($rv['library'])) {
			foreach ($rv['library'] as $image) {
				$transient_id = 'imgwb_' . $image['image_id'];
				set_transient($transient_id, $image, 60);
			}
		}

		return $rv;
	}

	/**
	 * Creates a new image on the Imgwb API and optionally creates a corresponding custom post type.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return The response from the Imgwb API.
	 */
	public function imgwb_api_image_post( $request ) {
		//$this->imgwb_log('imgwb_api_image_post...');

		$params = $request->get_params();

		$image = $this->imgwb_api_request('POST', '/image', $request->get_params());

		// create CPT too
		if (isset($params['cpt'])) {
			$this->addCustomPost($image);
		}

		return $image;
	}

	/**
	 * Retrieves information about a specific image from the Imgwb API.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return array|WP_Error The response from the Imgwb API, or a WP_Error if the request fails.
	 */
	public function imgwb_api_image_get( $request ) {
		//$this->imgwb_log('imgwb_api_image_get...');

		$params   = $request->get_params();
		$resource = '/image/' . $params['image_id'];

		$query_params = array();
		if (isset($params['projection'])) {
			$query_params['projection'] = $params['projection'];
		}
		return $this->imgwb_api_get($resource, $query_params);
	}

	/**
	 * Sends a POST request to create a new image variant via the Imgwb API.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return array|WP_Error The response from the Imgwb API, or a WP_Error if the request fails.
	 */
	public function imgwb_api_variant_post( $request ) {
		//$this->imgwb_log('imgwb_api_variant_post...');

		return $this->imgwb_api_request('POST', '/variant', $request->get_params());
	}

	/**
	 * Updates a variant and invalidates the image transient cache.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return array|WP_Error The response from the Imgwb API, or a WP_Error if the request fails.
	 */
	public function imgwb_api_variant_put( $request ) {
		//$this->imgwb_log('imgwb_api_variant_put...');

		# invalidate image transient cache
		$params   = $request->get_params();
		$resource = '/variant/' . $params['variant_id'];

		$image_id     = $params['image_id'];
		$transient_id = 'imgwb_' . $image_id;
		delete_transient($transient_id);

		return $this->imgwb_api_request('PUT', $resource, $params);
	}

	/**
	 * Sends a POST request to the Imgwb Generate API with the specified payload.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return array|WP_Error The response from the Imgwb API, or a WP_Error if the request fails.
	 */
	public function imgwb_api_generative( $request ) {
		//$this->imgwb_log('imgwb_api_generative...');

		if ('POST' == $request->get_method()) {
			$payload = $request->get_params();
			$result  = $this->imgwb_api_request('POST', '/generative', $payload);
		} else {
			$query_params = $request->get_query_params();
			$result       = $this->imgwb_api_get('/generative', $query_params);
		}

		# TODO sideload

		return $result;
	}

	/**
	 * Sends a POST request to the ImgWB API to send a notification.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return mixed The response from the ImgWB API.
	 */
	public function imgwb_api_notification_post( $request ) {
		//$this->imgwb_log('imgwb_api_notification_post...');

		$payload = $request->get_params();

		$result = $this->imgwb_api_request('POST', '/notification', $payload);
		return $result;
	}

	/**
	 * Add Authorization header with access token to the request arguments array.
	 *
	 * @param array $args The request arguments.
	 *
	 * @return array The updated request arguments with Authorization header added.
	 */
	private function imgwb_api_auth( &$args) {
		//$this->imgwb_log('imgwb_api_auth...');

		// get access_token
		$imgwb_access_token = get_option('imgwb_access_token');
		if (isset($imgwb_access_token)) {
			if (!isset($args['headers'])) {
				$args['headers'] = array();
			}
			$args['headers']['Authorization'] = 'Bearer ' . $imgwb_access_token;
		}
		return $args;
	}

	/**
	 * Refreshes the API access token using the stored refresh token.
	 *
	 * @return bool True if the token was refreshed successfully, false otherwise.
	 */
	private function imgwb_api_refresh() {
		//$this->imgwb_log('imgwb_api_refresh...');

		$refresh_token = get_option( 'imgwb_refresh_token' );

		if ( 'EXPIRED' == $refresh_token ) {
			return false;
		}

		// POST /session with refresh token?
		$payload  = array(
			'refresh_token' => $refresh_token
		);
		$response = $this->imgwb_api_request('POST', '/session', $payload);

		if (isset($response['error'])) {
			return false;
		}

		update_option( 'imgwb_access_token', $response['session']['access_token'] );
		if (isset($response['session']['refresh_token'])) {
			update_option( 'imgwb_refresh_token', $response['session']['refresh_token'] );
		}
		return true;
	}

	/**
	 * Sends a GET request to the ImgWB API with the specified resource and query parameters.
	 *
	 * @param string $resource The resource to retrieve.
	 * @param array $query_params The query parameters to include in the request.
	 *
	 * @return array|mixed API response as an associative array or an error message.
	 */
	private function imgwb_api_get( $resource, $query_params) {
		//$this->imgwb_log('imgwb_api_get...');

		$query_params['version'] = $this->version;

		$account_id              = get_option('imgwb_account_id');
		if (isset($account_id) && $account_id) {
			$query_params['account_id'] = $account_id;
		}

		$site_id              = get_option('imgwb_site_id');
		if (isset($site_id) && $site_id) {
			$query_params['site_id'] = $site_id;
		}

		$resource .= '?' . http_build_query($query_params);

		$args = array();
		$this->imgwb_api_auth($args);

		$response = wp_remote_get($this->api . $resource, $args);

		if (is_wp_error( $response )) {
			$error_msg = $response->get_error_message();
			$this->imgwb_log($error_msg);
			return array(
				'error' => array(
					'message' => 'Error: ' . $error_msg
				)
			);

		} elseif ( 401 == $response['response']['code'] ) {
			// refresh access_token
			if ($this->imgwb_api_refresh()) {
				$this->imgwb_api_auth($args);

				$response = wp_remote_get($this->api . $resource, $args);

			} else {
				// REFRESH_ERROR - need to login again
				return $this->imgwb_login_again();
			}
		}

		$this->api_counter_delta += 1;
		return json_decode($response['body'], true);
	}

	/**
	 * Makes an API request to the ImgWB API endpoint.
	 *
	 * @param string $method HTTP method (GET, POST, etc.) to use in the API request.
	 * @param string $resource Resource path to the API endpoint.
	 * @param array $payload Payload data to send in the API request.
	 *
	 * @return array|mixed API response as an associative array or an error message.
	 */
	private function imgwb_api_request( $method, $resource, $payload) {
		//$this->imgwb_log('imgwb_api_request...');

		$payload['version'] = $this->version;

		$account_id         = get_option('imgwb_account_id');
		if (isset($account_id) && $account_id) {
			$payload['account_id'] = $account_id;
		}

		$site_id         = get_option('imgwb_site_id');
		if (isset($site_id) && $site_id) {
			$payload['site_id'] = $site_id;
		}

		$args = array(
			'method' => $method,
			'body' => wp_json_encode($payload),
			'headers' => array(
				'Content-Type' => 'application/json'
			),
			'timeout' => 30
		);
		$this->imgwb_api_auth($args);

		$response = wp_remote_post($this->api . $resource, $args);

		if (is_wp_error( $response )) {
			$error_msg = $response->get_error_message();
			$this->imgwb_log($error_msg);
			return array(
				'error' => array(
					'message' => 'Error: ' . $error_msg
				)
			);

		} elseif ( 401 == $response['response']['code'] ) {
			// refresh access_token
			if ($this->imgwb_api_refresh()) {
				$this->imgwb_api_auth($args);
				$response = wp_remote_post($this->api . $resource, $args);
			} else {
				// REFRESH_ERROR - need to login again
				return $this->imgwb_login_again();
			}
		}

		$this->api_counter_delta += 1;
		$result                   = json_decode($response['body'], true);

		if (isset($result['message']) && 'Endpoint request timed out' == $result['message']) {
			// API GATEWAY TIMEOUT
			return array(
				'error' => array(
					'message' => $result['message']
				)
			);
		}

		return $result;
	}

	/**
	 * Logs the user out of the plugin by updating access and refresh tokens and returns an error message.
	 *
	 * @return array An array with an error message and a link to the login page.
	 */
	private function imgwb_login_again() {
		// Update the access and refresh tokens to "EXPIRED".
		update_option('imgwb_access_token', 'EXPIRED');
		update_option('imgwb_refresh_token', 'EXPIRED');

		// Return an error message and a link to the login page.
		return array(
			'error' => array(
				'message' => __('Your session has expired. No worries!', 'image-plus'),
				'actions' => array(
					array(
						'label' => __('Login again', 'image-plus'),
						'url' => admin_url('edit.php?post_type=image_plus&page=imgwb-settings')
					)
				)
			)
		);
	}

	/**
	 * Adds settings and documentation links to the plugin's action list on the plugins page.
	 *
	 * @param array  $plugin_actions An array of plugin action links.
	 * @param string $plugin_file    Path to the plugin file relative to the plugins directory.
	 *
	 * @return array An updated array of plugin action links including the new settings and documentation links.
	 */
	public function imgwb_plugin_action_links( $plugin_actions, $plugin_file) {
		$new_actions = array();

		// Only add links if the plugin is Image Plus.
		if ( basename( plugin_dir_path( __FILE__ ) ) . '/image-plus.php' === $plugin_file ) {
			$new_actions['imgwb_settings'] = sprintf('<a href="%s">' . __('Settings', 'image-plus') . '</a>', esc_url( admin_url( 'edit.php?post_type=image_plus&page=imgwb-settings' ) ) );
			$new_actions['imgwb_docs']     = sprintf('<a href="%s" target="_blank">' . __('Docs', 'image-plus') . '</a>', esc_url( 'https://imageplus.ai/?utm_source=wordpress&utm_medium=web&utm_campaign=plugin' ) );
		}

		// Merge the new actions with the existing actions.
		return array_merge( $new_actions, $plugin_actions );
	}

	/**
	 * Adds submenu pages to the "Image Plus" custom post type.
	 *
	 * @return void
	 */
	public function imgwb_admin_menu() {

		$access_token = get_option( 'imgwb_access_token' );
		if ( $access_token && 'EXPIRED' != $access_token ) {
			// logged in
			add_submenu_page(
				'edit.php?post_type=image_plus',
				__('Get Started', 'image-plus'),
				__('Get Started', 'image-plus'),
				'edit_posts',
				'imgwb-wizard',
				array( $this, 'imgwb_admin_wizard_page' )
			);
		}

		add_submenu_page(
			'edit.php?post_type=image_plus',
			__('Settings', 'image-plus'),
			__('Settings', 'image-plus'),
			'edit_posts',
			'imgwb-settings',
			array( $this, 'imgwb_admin_settings_page' )
		);

		if ( $access_token && 'EXPIRED' != $access_token ) {
			add_submenu_page(
				'edit.php?post_type=image_plus',
				__('Account', 'image-plus'),
				__('Account', 'image-plus'),
				'edit_posts',
				'imgwb-account',
				array( $this, 'imgwb_admin_account_page' )
			);
		}

		$roadmap_token = get_option('imgwb_roadmap_token');
		if ( $roadmap_token && !get_option('imgwb_whitelabel') ) {
			add_submenu_page(
				'edit.php?post_type=image_plus',
				__('Roadmap & Feature Requests', 'image-plus'),
				__('Roadmap', 'image-plus'),
				'edit_posts',
				'imgwb-roadmap',
				array( $this, 'imgwb_admin_roadmap_page' )
			);
		}

	}

	/**
	 * Handle redirects. Register settings.
	 *
	 * @return void
	 */
	public function imgwb_admin_init() {

		// handle external redirect to Account
		if ( isset($_GET['page']) && 'imgwb-account' == $_GET['page'] ) {
			// do not redirect if account.plan_id == free
			$plan_id = get_option('imgwb_plan_id', 'free');
			$whitelabel = get_option('imgwb_whitelabel');
			if ( 'free' != $plan_id && !$whitelabel ) {
				$auth_url = $this->api . '/external/auth';
				wp_redirect($auth_url);
				exit;
			}
		}

		// handle login
		if ( isset($_GET['page']) && 'imgwb-settings' == $_GET['page'] ) {
			if (isset($_POST['code']) && isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'imgwb_login_form')) {
				$this->imgwb_login();
			}
		}

		// redirect to settings if login is needed
		if (get_option('imgwb_activation_login', false)) {
			delete_option('imgwb_activation_login');
			wp_redirect(admin_url('edit.php?post_type=image_plus&page=imgwb-settings'));
			exit;
		}

		// redirect to wizard first time
		if (!get_option('imgwb_wizard_offered') && get_option('access_token')) {
			update_option('imgwb_wizard_offered', true);
			wp_redirect(admin_url('edit.php?post_type=image_plus&page=imgwb-wizard'));
			exit;
		}

		register_setting(
			'imgwb_settings_general', // option_group
			'imgwb_settings_general', // option_name
			array( $this, 'imgwb_settings_validate_general' ) // sanitize_callback
		);

		add_settings_section(
			'general', // id
			null, // title
			null, // callback
			'imgwb_settings_general' // page
		);

		add_settings_field(
			'generator', // id
			__('Generator', 'image-plus'), // title
			array( $this, 'render_setting_generator' ), // callback
			'imgwb_settings_general', // page
			'general' // section
		);

		add_settings_field(
			'generative_batch_size', // id
			__('Batch size', 'image-plus'), // title
			array( $this, 'render_setting_generative_batch_size' ), // callback
			'imgwb_settings_general', // page
			'general' // section
		);

		add_settings_field(
			'generative_save_media', // id
			__('Save images', 'image-plus'), // title
			array( $this, 'render_setting_generative_save_media' ), // callback
			'imgwb_settings_general', // page
			'general' // section
		);

		register_setting(
			'imgwb_settings_enhance', // option_group
			'imgwb_settings_enhance', // option_name
			array( $this, 'imgwb_settings_validate_enhance' ) // sanitize_callback
		);

		add_settings_section(
			'enhance', // id
			null, // title
			null, // callback
			'imgwb_settings_enhance' // page
		);

		register_setting(
			'imgwb_settings_optimize', // option_group
			'imgwb_settings_optimize', // option_name
			array( $this, 'imgwb_settings_validate_optimize' ) // sanitize_callback
		);

		add_settings_section(
			'optimize', // id
			null, // title
			null, // callback
			'imgwb_settings_optimize' // page
		);
	}

	/**
	 * Refresh account details. Style the Library columns
	 *
	 * @return void
	 */
	public function imgwb_admin_head() {
		$screen = get_current_screen();

		if ( 'image_plus' != $screen->post_type ) {
			return;
		}

		// only activated, haven't logged in yet
		$imgwb_access_token = get_option('imgwb_access_token');
		if (!$imgwb_access_token) {
			return;
		}

		// refresh account .plan_id
		$request = new WP_REST_Request();
		$this->imgwb_api_account_get($request);

		if ( !$this->account && 'edit-image_plus' == $screen->id ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__('Your session has expired. No worries!', 'image-plus') . ' <a href="' . esc_attr(admin_url('edit.php?post_type=image_plus&page=imgwb-settings')) . '">' . esc_html__('Sign in', 'image-plus') . '</a>.</p></div>';
			});
		}

		// style Library columns
		if ( 'edit-image_plus' == $screen->id ) {
			?>
<style>
.column-title { width: 150px !important; }
.column-imgwb-original { width: 150px !important; }
.column-imgwb-shortcode { width: 80px !important; }
.column-imgwb-active { min-width: 260px !important; }
.striped>tbody>:nth-child(odd) .column-imgwb-active { background-color:#f0f8f0 !important; }
.striped>tbody>:nth-child(even) .column-imgwb-active { background-color:#f8fff8 !important; }
.column-imgwb-suggested { max-width: 260px !important; }
.column-imgwb-inactive { max-width: 260px !important; }
.column-date { width: 105px !important; }
.column-imgwb-original tr, .column-imgwb-active tr, .column-imgwb-suggested tr, .column-imgwb-inactive tr { font-size: 12px !important; }
</style>
<?php
			add_filter( 'hidden_columns', function( $hidden, $screen) {
				if ( isset( $screen->id ) && 'edit-image_plus' == $screen->id ) {
					//$hidden[] = 'imgwb-shortcode';
					$hidden[] = 'imgwb-inactive';
				}
				return $hidden;
			}, 10, 2 );
		}
	}

	/**
	 * Update Library actions to hide Quick Edit and View
	 *
	 * @param mixed $actions The incoming actions
	 * @param mixed $post The post
	 * @return $actions Updated
	 */
	public function imgwb_post_row_actions( $actions, $post ) {
		if ('image_plus' == $post->post_type) {
			// hide Quick Edit
			unset( $actions['inline hide-if-no-js'] );

			// hide View - each variant can be Previewed separately
			unset( $actions['view'] );
		}
		return $actions;
	}

	/**
	 * Set the custom template to display the custom post type
	 *
	 * @param mixed $template The default template
	 * @return $template The custom template, for image_plus pages
	 */
	public function imgwb_template( $template) {
		// Only change the template for the custom post type
		if (is_singular('image_plus')) {
			// Set the template file path
			return plugin_dir_path(__FILE__) . 'template.php';
		}

		// Default to return the original template
		return $template;
	}

	/**
	 * Remove clutter from Image+ pages
	 *
	 * @param mixed $wp_meta_boxes All the meta boxes
	 * @return $wp_meta_boxes Less meta boxes
	 */
	public function imgwb_filter_meta_boxes( $wp_meta_boxes) {
		// remove clutter from Image+ page
		$wp_meta_boxes['image_plus'] = array(
			'side' => array(),
			'normal' => array(
				'core' => array(),
				'high' => array(),
				'default' => array(
					'image_plus_meta_box' => $wp_meta_boxes['image_plus']['normal']['default']['image_plus_meta_box']
				),
			)
		);

		return $wp_meta_boxes;
	}

	/**
	 * Render Images With Benefits header.
	 * For Image+ Library, generate script to indicate which Image+ are cached vs. expired and needing to refresh data from backend
	 *
	 * @return void
	 */
	public function imgwb_in_admin_header() {
		$screen = get_current_screen();
		if ( 'image_plus' == $screen->post_type && 'image_plus' != $screen->id ) {

			$plan_id        = get_option('imgwb_plan_id', 'free');
			$email = get_option('imgwb_user_email');

			$plan_config = get_option('imgwb_plan_config');


			echo '<div class="imgwb-admin-header">';
			$whitelabel = get_option('imgwb_whitelabel');
			if ($whitelabel) {
				echo '<img src="' . esc_url_raw($whitelabel) . '" width="200"/>';
			} else {
				echo '<img src="' . esc_url_raw($this->logo) . '" width="200"/>';
			}

			$plan_link   = admin_url('edit.php?post_type=image_plus&page=imgwb-account');
			if (isset($email) && isset($plan_config['title'])) {
				echo '<div class="imgwb-admin-header-email">' . esc_html($email) . '<br/>' . esc_html__('Plan', 'image_plus') . ': <a href="' . esc_attr($plan_link) . '">' . esc_attr($plan_config['title']) . '</a></div>';
			}

			echo '</div>';

			// Library; which Image+ need refresh?
			if ( 'edit-image_plus' == $screen->id ) {

				// get transient or API results data
				$images = array();

				while ( have_posts() ) :
					the_post();
					global $post;
					$blocks = parse_blocks($post->post_content);
					if (count($blocks) && isset($blocks[0]['attrs']['image_id'])) {
						$image_id = $blocks[0]['attrs']['image_id'];
						if ($image_id) {
							array_push($images, $image_id);
						}
					}
				endwhile;

				rewind_posts();

				$images_cached  = array();
				$images_expired = array();
				foreach ($images as $image_id) {
					$transient_id = 'imgwb_' . $image_id;
					$image        = get_transient($transient_id);
					if ( false === $image ) {
						// need to get from API
						array_push($images_expired, $image_id);

					} else {
						$images_cached[$image_id] = $image;
					}
				}

				// write cached values
				if (!empty($images_cached)) {
					echo '<script>const imgwb_images_cached = ' . wp_json_encode($images_cached) . ';</script>';
				}

				if (!empty($images_expired)) {
					echo '<script>const imgwb_images_expired = ' . wp_json_encode($images_expired) . ';</script>';
				}

				// for modal popup
				add_thickbox();
				?>
				<div id="imgwb-popup" style="display:none;">
					<p><?php esc_html_e('Here in the Library, compare the views and clicks of your images', 'image-plus'); ?>:
						<br><br><img src="https://imgwb.com/s/wizard-next-steps.png" width="420" style="float:left; margin-right:32px;" />
						<br><span style="font-size:10px"><?php esc_html_e('In this example, "Lab Coat Number 2" is performing best, driving 77% of clicks from only 49% of views.', 'image-plus'); ?>
						<br><br><?php esc_html_e('The original image is under-performing, with no clicks from 20% of views.', 'image-plus'); ?>
						<br><br><?php esc_html_e('5-star ratings indicate which images are over-performing compared to the others.', 'image-plus'); ?>
						</span><br clear="left">
					</p>
					<h2><?php esc_html_e('Other things to try', 'image-plus'); ?></h2>
					<p><?php esc_html_e("On any Page or Post, add an 'Image+' block, or transform an existing Image block into an 'Image+' block", 'image-plus'); ?>:</p>
					<video autoplay muted playsinline loop width="640" src="https://imgwb.com/s/getting-started-web.mp4"></video>
					<p>
					<?php
					printf(
						/* translators: %1$s and %2$s wrap a link to email support */
						esc_html__('Contact us on %1$ssupport@imageswithbenefits.com%2$s if you have any questions.', 'image-plus'),
						'<a href="mailto:support@imageswithbenefits.com">',
						'</a>'
					);
					?>
					</p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Render Get Started Wizard pages.
	 *
	 * @return void
	 */
	public function imgwb_admin_wizard_page() {
		?>
			<section class="imgwb-section" id="imgwb-section-wizard">
				<div id="imgwb_wizard_loading" class="hidden"><?php esc_html_e('Loading...', 'image-plus'); ?></div>
				<div class="imgwb_wizard_step" id="imgwb_wizard_step1">
					<h2><?php esc_html_e('Get Started', 'image-plus'); ?></h2>
					<p><?php esc_html_e("Let's create our first Image+", 'image-plus'); ?></p>
					<div><input type="button" id="imgwb_wizard_step1_button" class="button button-primary" value="<?php esc_attr_e('Start', 'image-plus'); ?> &raquo;"></div>
				</div>
				<div class="imgwb_wizard_step hidden" id="imgwb_wizard_step2">
					<h2><?php esc_html_e('Objective', 'image-plus'); ?></h2>
					<p><?php esc_html_e('Please edit or confirm the objective for using Image+', 'image-plus'); ?></p>
					<div id="imgwb_wizard_goal" contenteditable></div>
					<div><input type="button" id="imgwb_wizard_step2_button" class="button button-primary" value="<?php esc_attr_e('Next', 'image-plus'); ?> &raquo;"></div>
				</div>
				<div class="imgwb_wizard_step hidden" id="imgwb_wizard_step3">
					<h2>Image+</h2>
					<p>
					<?php
					printf(
						/* translators: %1$s and %2$s wrap a link to Add New */
						esc_html__('Please select an image below to enhance and optimize into your first Image+, or %1$sAdd New%2$s.', 'image-plus'),
						sprintf('<a href="%s">', esc_attr(admin_url('post-new.php?post_type=image_plus'))),
						'</a>'
					);
					?>
					</p>
					<div id="imgwb_wizard_candidate_images"></div>
					<p><?php esc_html_e('You can pause at any time before your Image+ is published to the live site.', 'image-plus'); ?></p>
				</div>
				<div class="imgwb_wizard_step hidden" id="imgwb_wizard_step4">
					<h2>Image+ <?php esc_html_e('Library', 'image-plus'); ?></h2>
					<p><?php esc_html_e('Your first Image+ is being automatically generated.', 'image-plus'); ?></p>
					<p><?php esc_html_e("When it's ready, click the Next button below to open the Image+ Library, showing the original image and a suggested enhancement.", 'image-plus'); ?></p>
					<video src="https://imgwb.com/s/wizard-library.mp4" width="540" loop muted autoplay playsinline></video>
					<p><?php esc_html_e('From the Library, change the suggested image to become Active, or edit the enhancements.', 'image-plus'); ?></p>
					<div><input type="button" id="imgwb_wizard_step4_button" class="button button-primary disabled" value="<?php esc_attr_e('Next', 'image-plus'); ?> &raquo;"></div>
				</div>
			</section>
		<?php
	}

	/**
	 * Render default Account page for free account or whitelabel sites.
	 *
	 * @return void
	 */
	public function imgwb_admin_account_page() {
		$account_id     = get_option( 'imgwb_account_id' );
		$email = get_option('imgwb_user_email');

		$whitelabel = get_option('imgwb_whitelabel');
		if ($whitelabel) {
			echo '<p>' . esc_html__('This account is managed by:', 'image-plus') . '</p>';
			echo '<img src="' . esc_url_raw($whitelabel) . '" width="200"/>';
			return;
		}

		?>
			<div>
				<h5><?php esc_html_e('Enjoy a 7-Day Free Trial. Change your plan or cancel any time.', 'image-plus'); ?></h5>
				<h1><?php esc_html_e('Show us the money', 'image-plus'); ?></h1>
				<p>
				<?php
				printf(
					/* translators: %1$s %2$s %3$s %4$s are links to Enhance, The Optimizer, AI-Team and Enterprise plans */
					esc_html__('Learn more about the features of %1$s, %2$s, %3$s and %4$s plans.', 'image-plus'),
					'<a href="https://imageplus.ai/enhance-your-website-images-using-ai-to-boost-engagement/" target="_blank">Enhance</a>',
					'<a href="https://imageplus.ai/the-optimizer-boost-website-sales-and-results-with-ai-optimized-images/" target="_blank">The Optimizer</a>',
					'<a href="https://imageplus.ai/everything-your-team-needs-to-win-with-ai-enhanced-and-ai-optimized-images/" target="_blank">AI-Team</a>',
					'<a href="https://imageplus.ai/lead-your-company-to-harness-the-full-power-of-ai-optimized-images/" target="_blank">Enterprise</a>'
				);
				?>
					</p>
			</div>
			<div style="background-color:#ffffff;margin-left:-20px;padding-top:20px;">
				<stripe-pricing-table id="imgwb-pricing-table" pricing-table-id="prctbl_1MlVn7G5z9RnxTJcXTULxIcF"
				publishable-key="pk_live_51MWUQyG5z9RnxTJcJ0UEqefc9ty2Jots1HwgkHUDSDG7s3T9E75AxP08mFNz8vwa4kFy4YabHn6buyLMt8aBDZ7X00Kxl932nA"
				customer-email="<?php echo esc_html($email); ?>"
				client-reference-id="<?php echo esc_html($account_id); ?>">
				</stripe-pricing-table>
			</div>
			<div style="margin: 12px 0px;"><?php esc_html_e('Free plan includes the same features as Enhance, limited to 10 AI-generated images per month, and 10,000 AI-enhanced image views per month on 1 site.', 'image-plus'); ?></div>
		<?php
	}

	/**
	 * Render Settings pages.
	 *
	 * @return void
	 */
	public function imgwb_admin_settings_page() {
		$access_token = get_option( 'imgwb_access_token' );

		if ( !$access_token || 'EXPIRED' == $access_token || !$this->account) {
			$login_heading = __('Your session has expired. No worries!', 'image-plus');

			$plan_id = get_option( 'imgwb_plan_id' );
			if ( !$plan_id ) {
				$login_heading = __('Activate Image+', 'image-plus');
			}

			$current_user = wp_get_current_user();
			$auth_url = get_option('imgwb_auth_url', 'https://api.imgwb.com/external/wordpress/auth');
			?>
			<div class="wrap">
				<h2><?php echo esc_html($login_heading); ?></h2>
				<div style="width:250px;text-align:center;margin-top:20px;">
					<form id="imgwb_auth_form" method="post" action="<?php echo esc_url_raw($auth_url); ?>" target="auth">
						<input type="hidden" name="account_id" value="<?php echo esc_attr(get_option('imgwb_account_id')); ?>">
						<input type="hidden" name="site_id" value="<?php echo esc_attr(get_option('imgwb_site_id')); ?>">
						<input type="hidden" name="words_activation_id" value="<?php echo esc_attr(get_option('imgwb_words_activation_id')); ?>">
						<input type="hidden" name="email" value="<?php echo esc_attr($current_user->user_email); ?>">
						<input type="hidden" name="name" value="<?php echo esc_attr($current_user->display_name); ?>">
						<input type="hidden" name="website" value="<?php echo esc_url_raw(get_site_url()); ?>">
						<input type="hidden" name="sitename" value="<?php echo esc_attr(get_bloginfo('name')); ?>">
						<input type="hidden" name="admin" value="<?php echo esc_attr(admin_url()); ?>">
						<input type="hidden" name="identity_provider" value="">
						<input type="submit" id="imgwb_auth_form_submit" class="button button-primary" style="width:100%;height:40px;" onclick="this.form.elements['identity_provider'].value='';window.open('', 'auth', 'width=1000,height=720,top=100,left='+((screen.width-1000)/2)+',menubar=no');" value="Sign in &raquo;">
						<p>- or -</p>
						<button type="submit" id="imgwb_auth_form_google_submit" class="btn socialButton-customizable google-button" onclick="this.form.elements['identity_provider'].value='Google';window.open('', 'auth', 'width=620,height=670,top=100,left='+((screen.width-620)/2)+',menubar=no');">
						<span><svg class="social-logo" viewBox="0 0 256 262" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid">
							<path d="M255.878 133.451c0-10.734-.871-18.567-2.756-26.69H130.55v48.448h71.947c-1.45 12.04-9.283 30.172-26.69 42.356l-.244 1.622 38.755 30.023 2.685.268c24.659-22.774 38.875-56.282 38.875-96.027" fill="#4285F4"></path>
							<path d="M130.55 261.1c35.248 0 64.839-11.605 86.453-31.622l-41.196-31.913c-11.024 7.688-25.82 13.055-45.257 13.055-34.523 0-63.824-22.773-74.269-54.25l-1.531.13-40.298 31.187-.527 1.465C35.393 231.798 79.49 261.1 130.55 261.1" fill="#34A853"></path>
							<path d="M56.281 156.37c-2.756-8.123-4.351-16.827-4.351-25.82 0-8.994 1.595-17.697 4.206-25.82l-.073-1.73L15.26 71.312l-1.335.635C5.077 89.644 0 109.517 0 130.55s5.077 40.905 13.925 58.602l42.356-32.782" fill="#FBBC05"></path>
							<path d="M130.55 50.479c24.514 0 41.05 10.589 50.479 19.438l36.844-35.974C195.245 12.91 165.798 0 130.55 0 79.49 0 35.393 29.301 13.925 71.947l42.211 32.783c10.59-31.477 39.891-54.251 74.414-54.251" fill="#EA4335"></path>
						</svg></span>
						<span>Continue with Google</span>
						</button>
					</form>
				</div>
				<form id="imgwb_login_form" method="post">
					<input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('imgwb_login_form')); ?>">
					<input type="hidden" id="imgwb_login_code" name="code">
					<input type="hidden" id="imgwb_login_state" name="state">
					<input type="hidden" id="imgwb_login_tzo" name="tzo">
				</form>
				<p>
				<?php
				printf(
					/* translators: %1$s and %2$s wrap a link to Terms */
					esc_html__('By signing in, you agree to these %1$sTerms of Service%2$s.', 'image-plus'),
					'<a href="https://imageplus.ai/terms/" target="_blank">',
					'</a>'
				);
				?>
				</p>
			</div>
	<?php
		} else {
			$settings_enhance  = get_option('imgwb_settings_enhance');
			$settings_optimize = get_option('imgwb_settings_optimize');
			?>
			<div class="wrap">
				<h2>Settings</h2>
				<div class="nav-tab-wrapper">
					<a class="nav-tab" href="#general"><?php esc_html_e('AI-Generated Images', 'image-plus'); ?></a>
					<a class="nav-tab" href="#enhance"><?php esc_html_e('AI-Enhanced Images', 'image-plus'); ?></a>
					<a class="nav-tab" href="#optimize"><?php esc_html_e('AI-Optimized Images', 'image-plus'); ?></a>
					<a class="nav-tab" href="#speed"><?php esc_html_e('Compression & Delivery', 'image-plus'); ?></a>
				</div>
				<section class="imgwb-section hidden" id="imgwb-section-general">
					<form method="post" action="options.php">
					<?php
						settings_fields( 'imgwb_settings_general' );
						do_settings_sections( 'imgwb_settings_general' );
						submit_button(__('Save', 'image-plus'));
					?>
					</form>
				</section>
				<section class="imgwb-section hidden" id="imgwb-section-enhance">
					<form method="post" action="options.php">
					<?php settings_fields( 'imgwb_settings_enhance' ); ?>
						<p><?php esc_html_e('Create better versions of your images using AI-powered image enhancements.', 'image-plus'); ?></p>
						<table border="0" cellpadding="6">
							<tr><td colspan="3"><b><?php esc_html_e('AI Enhancements', 'image-plus'); ?></b></td></tr>
							<tr>
								<td valign="top"><?php esc_html_e('Generative AI', 'image-plus'); ?></td>
								<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
								<td valign="top" ><?php esc_html_e('Create alternative versions of your images, using AI.', 'image-plus'); ?></td>
							</tr>
							<tr>
								<td valign="top"><?php esc_html_e('Pan & Zoom', 'image-plus'); ?></td>
								<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
								<td valign="top"><?php esc_html_e('Focus the attention of your visitors on the best image features, as identified by AI computer vision.', 'image-plus'); ?></td>
							</tr>
							<tr>
								<td valign="top"><?php esc_html_e('Back Drop', 'image-plus'); ?></td>
								<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
								<td valign="top"><?php esc_html_e('Replace image backgrounds using AI, to make them stand out.', 'image-plus'); ?></td>
							</tr>
							<tr>
								<td valign="top"><?php esc_html_e('Captions', 'image-plus'); ?></td>
								<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
								<td valign="top"><?php esc_html_e('Enhance your images with call-to-action text, using AI to identify the perfect colors.', 'image-plus'); ?>
									<div>
										<b><?php esc_html_e('Custom Fonts', 'image-plus'); ?></b> <input type="text" name="imgwb_settings_enhance[fonts]" value="<?php echo isset($settings_enhance['fonts']) ? esc_attr($settings_enhance['fonts']) : ''; ?>" style="width:450px">
										<br/><em>
										<?php
										printf(
											/* translators: %1$s and %2$s wrap a link to Google Fonts */
											esc_html__('List your favorite %1$sGoogle Fonts%2$s to use, separated by commas.', 'image-plus'),
											'<a href="https://fonts.google.com" target="_blank">',
											'</a>'
										);
										?>
										</em>
									</div>
								</td>
							</tr>
							<tr><td colspan="3"><b><?php esc_html_e('Premium Enhancements', 'image-plus'); ?></b></td></tr>
							<tr>
								<td valign="top"><?php esc_html_e('Slideshow', 'image-plus'); ?></td>
								<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
								<td valign="top"><?php esc_html_e('Combine you best performing images into a slideshow.', 'image-plus'); ?></td>
							</tr>
							<tr>
								<td valign="top"><?php esc_html_e('Cross-Fade', 'image-plus'); ?></td>
								<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
								<td valign="top"><?php esc_html_e('Combine you best performing images using a cross-fade effect.', 'image-plus'); ?></td>
							</tr>
							<tr><td colspan="3"><b><?php esc_html_e('Fun Stuff', 'image-plus'); ?></b></td></tr>
							<tr>
								<td valign="top"><?php esc_html_e('Particles', 'image-plus'); ?></td>
								<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
								<td valign="top"><?php esc_html_e('Let it snow.', 'image-plus'); ?></td>
							</tr>
						</table>
						<?php submit_button(__('Save', 'image-plus')); ?>
						<p>
						<?php
						printf(
							/* translators: %1$s and %2$s wrap a link to roadmap.imageplus.ai */
							esc_html__('More enhancement tools are continually being added. See %1$sRoadmap%2$s for feature requests and voting.', 'image-plus'),
							sprintf('<a href="%s">', esc_attr(admin_url('edit.php?post_type=image_plus&page=imgwb-roadmap'))),
							'</a>'
						);
						?>
						</p>
					</form>
				</section>
				<section class="imgwb-section hidden" id="imgwb-section-optimize">
					<form method="post" action="options.php">
					<?php settings_fields( 'imgwb_settings_optimize' ); ?>
						<p><?php esc_html_e('Automatically show more of the images which result in more clicks, leads or sales.', 'image-plus'); ?></p>
						<table border="0" cellpadding="6">
			<?php

			$cat = null;
			$plan_config = get_option('imgwb_plan_config');
			ksort($plan_config);

			foreach ($plan_config['goal_config'] as $goal_id => $goal) {

				if ($goal['category'] != $cat) {
					echo '<tr><td colspan="3"><b>' . esc_html($goal['category']) . '</b></td></tr>';
					$cat = $goal['category'];
				}

				echo '<tr><td><img src="https://imgwb.com/s/goals/' . esc_html($goal_id) . '.png" width="32" /></td>';
				echo '<td class="imgwb-optimize-goal" data-goal="' . esc_attr($goal_id) . '">';

				// active
				$active = false;
				if (true === $goal['wp_detect']) {
					$active = true;
				} else if ('class' == $goal['wp_detect'][0]) {
					$active = class_exists($goal['wp_detect'][1]);
				} else if ('function' == $goal['wp_detect'][0]) {
					$active = function_exists($goal['wp_detect'][1]);
				}

				// upgrade
				$upgrade = !in_array('*', $plan_config['goals']) && !in_array($goal_id, $plan_config['goals']);

				// cta_inactive onramp
				if (!$active) {
					if ('wp_install' == $goal['onramp'][0]) {
						// wordpress.org plugin
						$cta_inactive_url = admin_url('plugin-install.php?s=' . $goal['onramp'][1] . '&tab=search&type=term');
						/* translators: %s is the name of the integration */
						$cta_inactive_link = sprintf(__('Install %s'), $goal['name']);
						$cta_inactive_target = '_self';

					} else if ('install' == $goal['onramp'][0]) {
						// external premium plugin
						$cta_inactive_url = $goal['onramp'][1];
						/* translators: %s is the name of the integration */
						$cta_inactive_link = sprintf(__('Install %s'), $goal['name']);
						$cta_inactive_target = '_blank';

					} else {
						// setup Shopify
						$cta_inactive_url = $goal['onramp'][1];
						$cta_inactive_link = __($goal['onramp'][0]);
						$cta_inactive_target = '_blank';
					}
				}

				if (!$active && $upgrade) {
					echo '<input type="checkbox" disabled="disabled" /></td><td>';
					printf(
						/* translators: %1$s is a link to the integration site */
						esc_html__('%1$s and %2$s to automatically show more of the images which result in %3$s.', 'image-plus'),
						sprintf('<a href="%s" target="%s">%s</a>', esc_url_raw($cta_inactive_url), esc_attr($cta_inactive_target), esc_html($cta_inactive_link)),
						sprintf(
							/* translators: %1$s and %2$s wrap a link to the Upgrade page */
							esc_html__('%1$supgrade Image+%2$s'),
							sprintf('<a href="%s">', esc_attr(admin_url('edit.php?post_type=image_plus&page=imgwb-account'))),
							'</a>'
						),
						esc_html($goal['benefit'])
					);

				} else if (!$active) {
					echo '<input type="checkbox" disabled="disabled" /></td><td>';
					printf(
						/* translators: %1$s is a link to the integration site */
						esc_html__('%1$s to automatically show more of the images which result in %2$s.', 'image-plus'),
						sprintf('<a href="%s" target="%s">%s</a>', esc_url_raw($cta_inactive_url), esc_attr($cta_inactive_target), esc_html($cta_inactive_link)),
						esc_html($goal['benefit'])
					);

				} else if ($upgrade) {
					echo '<input type="checkbox" disabled="disabled" /></td><td>';
					printf(
						/* translators: %1$s and %2$s wrap a link to the Upgrade page */
						esc_html__('%1$sUpgrade Image+%2$s to automatically show more of the images which result in %3$s.', 'image-plus'),
						sprintf('<a href="%s">', esc_attr(admin_url('edit.php?post_type=image_plus&page=imgwb-account'))),
						'</a>',
						esc_html($goal['benefit'])
					);

				} else {
					# all good
					if (isset($settings_optimize[$goal_id])) {
						$val = $settings_optimize[$goal_id];
					} else {
						$val = true;
					}
					$field_name = 'imgwb_settings_optimize[' . $goal_id . ']';
					echo '<input type="checkbox" id="check_' . esc_attr($goal_id) . '" name="' . esc_attr($field_name) . '"' . ( $val ? ' checked="checked"' : '' ) . ' /></td><td>';

					echo '<label for="check_' . esc_attr($goal_id) . '">';
					printf(
						/* translators: %1$s translates to the benefit e.g. 'more clicks' or 'more sales' */
						esc_html__('Automatically show more of the images which result in %1$s', 'image-plus'),
						esc_html($goal['benefit'])
					);
					echo '</label>';

				}

				echo '</td></tr>';
			}

			?>
						</table>
						<?php submit_button(__('Save', 'image-plus')); ?>
						<p>
						<?php
						printf(
							/* translators: %1$s and %2$s wrap a link to roadmap.imageplus.ai */
							esc_html__('More integrations are continually being added. See %1$sRoadmap%2$s for integration requests and voting.', 'image-plus'),
							sprintf('<a href="%s">', esc_attr(admin_url('edit.php?post_type=image_plus&page=imgwb-roadmap'))),
							'</a>'
						);
						?>
						</p>
					</form>
				</section>
				<section class="imgwb-section hidden" id="imgwb-section-speed">
					<p><?php esc_html_e('Image+ does many things to improve the delivery of your images, compared to regular images.', 'image-plus'); ?></p>
					<table border="0" cellpadding="6">
						<tr>
							<td valign="top"><?php esc_html_e('Local Delivery', 'image-plus'); ?></td>
							<td valign="top" align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
							<td valign="top"><?php esc_html_e('Detect viewer location, and deliver images from nearby servers for optimal page loading speed', 'image-plus'); ?>
								<div>
									<img class="imgwb-speed-flag" width="32" />
									<br/><?php esc_html_e('Status', 'image-plus'); ?>: <span class="imgwb-speed-status"><?php esc_html_e('Loading...', 'image-plus'); ?></span>
								</div>
							</td>
						</tr>
						<tr>
							<td><?php esc_html_e('Screen Size', 'image-plus'); ?></td>
							<td align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
							<td><?php esc_html_e("Detect the screen size and pixel density of each viewer's device, and deliver images of optimal width and height", 'image-plus'); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e('Image Formats', 'image-plus'); ?></td>
							<td align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
							<td><?php esc_html_e('Detect device and browser support for new image formats (e.g. WEBP, AVIF), and deliver the best format for each viewer', 'image-plus'); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e('Image Compression', 'image-plus'); ?></td>
							<td align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
							<td><?php esc_html_e('Compress images by removing unnecessary data, such as metadata or unused color profiles. This can significantly reduce the file size and delivery time of your images without affecting their visual quality.', 'image-plus'); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e('Mobile "Save Data"', 'image-plus'); ?></td>
							<td align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
							<td><?php esc_html_e("Detect mobile devices in 'low data' mode, and reduce image data for those devices", 'image-plus'); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e('Lazy Loading', 'image-plus'); ?></td>
							<td align="center"><input type="checkbox" checked="checked" disabled="disabled"/></td>
							<td><?php esc_html_e('Load images only as they scroll into view, reducing page loading times and saving delivery costs. (This feature is now included with WordPress without any plugins needed.)', 'image-plus'); ?></td>
						</tr>
					</table>
					<p><?php esc_html_e('Image+ works with all image compression/format optimization plugins that do the same things, e.g. EWWW, Smush, Imagify, Optimole, ShortPixel, Akamai.', 'image-plus'); ?></p>
					<p><?php esc_html_e('Compared to those, Image+ additionally enhances your images and optimizes their impact, measured by increased clicks, leads, and sales.', 'image-plus'); ?></p>
				</section>
				</form>
			</div>
	<?php
		}
	}


	/**
	 * Render Roadmap pages.
	 *
	 * @return void
	 */
	public function imgwb_admin_roadmap_page() {
		$roadmap_token = get_option('imgwb_roadmap_token', true);

		?>
<h1><?php esc_html_e('Roadmap & Feature Requests', 'image-plus'); ?></h1>
<p>Please suggest and vote for new image enhancement features and integrations.</p>
<div data-upvoty style="background-color:#fff;margin-left:-20px;"></div>
<script type='text/javascript'>
var script = document.createElement("script");
script.onload = function () {
	upvoty.init('render', {
		'ssoToken': "<?php esc_attr_e($roadmap_token); ?>",
		'baseUrl': 'roadmap.imageplus.ai'
	});
};
document.head.appendChild(script);
script.src = 'https://roadmap.imageplus.ai/javascript/upvoty.embed.js';
</script>
		<?php
	}

	/**
	 * Create session from auth code
	 *
	 * @return void
	 */
	private function imgwb_login() {
		//$this->imgwb_log('imgwb_login...');

		// check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'imgwb_login_form')) {
			return;
		}

		$payload  = array(
			'code' => isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '',
			'state' => isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '',
			'tzo' => isset($_POST['tzo']) ? sanitize_text_field($_POST['tzo']) : ''
		);

		$response = $this->imgwb_api_request('POST', '/session', $payload);

		if ( isset($response['error'])) {
			$this->imgwb_log($response['error']);

			add_action( 'admin_notices', function() use( $response ) {
				$class = 'notice notice-error';
				$message = __( json_encode($response['error']), 'image-plus' );

				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			} );

		} else {
			update_option('imgwb_access_token', $response['session']['access_token']);
			if (isset($response['session']['refresh_token'])) {
				update_option('imgwb_refresh_token', $response['session']['refresh_token']);
			}

			// save these for subsequent API requests
			update_option('imgwb_account_id', $response['account']['account_id']);
			update_option('imgwb_site_id', $response['site']['site_id']);
			update_option('imgwb_user_id', $response['user']['user_id']);
			update_option('imgwb_user_email', $response['user']['email']);

			// jwt token for roadmap
			update_option('imgwb_roadmap_token', $response['user']['roadmap_token']);
			update_option('imgwb_help_token', $response['user']['help_token']);

			$wizard_url = admin_url('edit.php?post_type=image_plus&page=imgwb-wizard');
			wp_redirect($wizard_url);
			exit;
		}

	}

	/**
	 * Validate General settings.
	 *
	 * @param array $input Contains General settings from user
	 *
	 * @return Settings to persist.
	 */
	public function imgwb_settings_validate_general( $input) {

		if (isset($input['generator'])) {
			$generator = sanitize_text_field( $input['generator'] );
		} else {
			$generator = 'stable-diffusion-768-v2-1';
		}

		if (isset($input['generative_batch_size'])) {
			$generative_batch_size = intval( $input['generative_batch_size'] );
		} else {
			$generative_batch_size = 3;
		}

		if (isset($input['generative_save_media'])) {
			$generative_save_media = ( 'on' == $input['generative_save_media'] );
		} else {
			$generative_save_media = false;
		}

		// sync to backend
		$settings_general = array(
			'generator' => $generator,
			'generative_batch_size' => $generative_batch_size,
			'generative_save_media' => $generative_save_media
		);
		$response         = $this->imgwb_api_request('PUT', '/site', $settings_general);

		return $settings_general;
	}

	/**
	 * Validate Enhance settings.
	 *
	 * @param array $input Contains Enhance settings from user, including custom fonts.
	 *
	 * @return Settings to persist.
	 */
	public function imgwb_settings_validate_enhance( $input) {
		$fonts = sanitize_text_field( $input['fonts'] );
		return array(
			'fonts' => $fonts
		);
	}

	/**
	 * Validate Optimize settings, saving to Imgwb API.
	 *
	 * @param array $input Contains Optimize settings from user.
	 *
	 * @return Settings to persist.
	 */
	public function imgwb_settings_validate_optimize( $input) {

		$plan_config = get_option('imgwb_plan_config');

		foreach ($plan_config['goal_config'] as $goal_id => $goal) {

			if ( isset($input[$goal_id]) && 'on' == $input[$goal_id] ) {
				$input[$goal_id] = true;

			} else {
				$input[$goal_id] = false;
			}
		}

		// PUT account
		$payload  = array(
			'optimize' => $input
		);
		$response = $this->imgwb_api_request('PUT', '/site', $payload);

		return $input;
	}


	/**
	 * Render AI generator selector.
	 *
	 * @return void
	 */
	public function render_setting_generator() {
		$settings_general = get_option('imgwb_settings_general');

		if (isset($settings_general['generator'])) {
			$generator = $settings_general['generator'];
		} else {
			$generator = 'stable-diffusion-768-v2-1';
		}

		$generators = array(
			'openai' => 'OpenAI Dall-E-2',
			'stable-diffusion-768-v2-1' => 'Stable Diffusion 768 v2.1',
			'stable-diffusion-xl-beta-v2-2-2' => 'Stable Diffusion XL'
		);

		echo '<select name="imgwb_settings_general[generator]">';
		foreach ($generators as $key => $value) {
			echo '<option value="' . esc_attr($key) . '"' . ( $key == $generator ? ' selected="selected"' : '' ) . '>' . esc_attr($value) . '</option>';
		}
		echo '</select>';
		echo '<p>';
		esc_html_e('Dall-E is currently faster, but limited to square images. Stable Diffusion can be any size and takes more notice of your prompts.');
		echo '</p>';
	}


	/**
	 * Render generative batch size input field.
	 *
	 * @return void
	 */
	public function render_setting_generative_batch_size() {
		$settings_general = get_option('imgwb_settings_general');

		$generative_batch_size = isset($settings_general['generative_batch_size']) ? $settings_general['generative_batch_size'] : '3';

		echo '<select name="imgwb_settings_general[generative_batch_size]">';
		for ( $i = 1; $i <= 5; $i++ ) {
			echo '<option value="' . esc_attr($i) . '"' . ( $generative_batch_size == $i ? ' selected="selected"' : '' ) . '>' . esc_attr($i) . ' image' . ( $i > 1 ? 's' : '' ) . '</option>';
		}
		echo '</select>';

		echo '<p>';
		printf(
			/* translators: %1$s and %2$s wrap a link to the Upgrade page */
			esc_html__('Number of image candidates to generate at a time. Higher numbers will consume your %1$squota%2$s faster.', 'image-plus'),
			sprintf('<a href="%s">', esc_attr(admin_url('edit.php?post_type=image_plus&page=imgwb-account'))),
			'</a>'
		);
		echo '</p>';
	}

	/**
	 * Render generative save media input field.
	 *
	 * @return void
	 */
	public function render_setting_generative_save_media() {
		$settings_general = get_option('imgwb_settings_general');

		// default true
		if (!isset($settings_general['generative_save_media']) || $settings_general['generative_save_media']) {
			$generative_save_media = true;
		} else {
			$generative_save_media = false;
		}

		echo '<label><input type="checkbox" name="imgwb_settings_general[generative_save_media]"' . ( $generative_save_media ? ' checked="checked"' : '' ) . ' />';

		echo esc_html__('Copy all AI-generated images to Media Library', 'image-plus') . '</label>';
	}

	/**
	 * Generate and echo service worker script, including sitewide images to convert into Image+
	 *
	 * @return void
	 */
	private function echo_service_worker_script() {
		$sw_host       = $this->imgwb_host;
		$sw_account_id = get_option('imgwb_account_id');
		$sw_upload_dir = wp_upload_dir()['baseurl'];

		global $wpdb;
		// mapping between attachment url and image_id
		$metas = $wpdb->get_results(
			"SELECT p.guid, m1.meta_value cpt_id, m2.meta_key, m2.meta_value
				FROM $wpdb->posts p
				INNER JOIN $wpdb->postmeta m1 ON p.ID = m1.post_id
				INNER JOIN $wpdb->postmeta m2 ON m1.meta_value = m2.post_id
				WHERE p.post_type = 'attachment'
				AND m1.meta_key = 'imgwb_replace_with_cpt'
				AND m2.meta_key IN ('imgwb_sitewide', 'imgwb_image_id')"
		);

		// image-plus-service-worker.js
		?>
const imgwb_host = '<?php esc_attr_e($sw_host); ?>';
const imgwb_account_id = '<?php esc_attr_e($sw_account_id); ?>';
const imgwb_upload_dir = '<?php esc_attr_e($sw_upload_dir); ?>';
const imgwb_sitewide = {
		<?php
		if (count($metas)) {
			$map_image_id = array();
			$map_sitewide = array();
			foreach ( $metas as $meta ) {
				if ($meta->meta_value) {
					if ( 'imgwb_image_id' == $meta->meta_key ) {
						$map_image_id[$meta->guid] = $meta->meta_value;

					} else {
						// sitewide
						$map_sitewide[$meta->guid] = $meta->meta_value;
					}
				}
			}

			foreach ( $map_image_id as $url => $image_id ) {
				if (array_key_exists($url, $map_sitewide)) {
					?>
"<?php esc_attr_e(str_replace($sw_upload_dir, '', $url)); ?>": "<?php esc_attr_e($image_id); ?>",
<?php
				}
			}
		}
		?>
};

// stay consistent with previous variant
// reset by update of sitewide mapping
let imgwb_variants = {};

addEventListener("install", e => self.skipWaiting());
addEventListener("activate", e => self.clients.claim());

let viewer_id = btoa(Math.random().toString()).substr(10, 10);
let country;
addEventListener("message", e => {
	//console.log('viewer.js says', e.data);
	if (e.data.from == "imgwb") {
		if (e.data.viewer_id) viewer_id = e.data.viewer_id;
		if (e.data.country) country = e.data.country;
	}
});

addEventListener("fetch", e => {
	// detect downstream goal signals that don't touch WordPress hooks
	let msg = {};
	if (e.request.url.includes("hsforms.com") && e.request.url.includes("/counters.gif") && (e.request.url.includes("SUBMISSION_SUCCESS") || e.request.url.includes("submit-event"))) {
		msg.event = "lead_g202"; // Hubspot embed as iframe || collected
	} else if (e.request.url.includes("track.hubspot.com") && e.request.url.includes("__ptq.gif") && e.request.url.includes("k=19")) {
		msg.event = "lead_g202"; // Hubspot popup, slide-in
	} else if (e.request.url.includes("cognitoforms.com") && e.request.method == 'POST') {
		msg.event = "lead_g205"; // Cognito forms submission
	} else if (e.request.url.includes("marketo.com/index.php/leadCapture/save") && e.request.method == 'POST') {
		msg.event = "lead_g206"; // Marketo Forms API submission
	} else if (e.request.url.includes("/bigcommerce/cart/") && e.request.method == 'POST') {
		msg.event = "cart_g404"; // BigCommerce on-site add-to-cart
	} else if (e.request.url.includes("/bigcommerce/v1/cart/") && e.request.method == 'POST') {
		msg.event = "cart_g404"; // BigCommerce on-site-ajax add-to-cart
	} else if (e.request.url.includes("/bigcommerce/buy/") && e.request.method == 'POST') {
		msg.event = "cart_g404"; // BigCommerce off-site add-to-cart
	}

	if (Object.keys(msg).length) {
		msg.from = "imgwb";
		self.clients.matchAll().then(function(clients) {
			clients.forEach(function(client) {
				client.postMessage(msg);
			});
		});
	}

	// focus on attachments that might require sitewide replacement with Image+
	if (e.request.url.indexOf(imgwb_upload_dir) == -1) {
		return;
	}

	// do not replace on edit page because svg sizing not properly detected by wp
	//if (e.request.referrer.indexOf("/wp-admin/post.php") != -1 && e.request.referrer.indexOf("action=edit") != -1) return;
	// do not replace on admin pages - e.g. edit or media search
	if (e.request.referrer.indexOf("/wp-admin/") != -1) return;

	// detect responsive width
	const dimensions = e.request.url.match(/-(\d+)x\d+./);

	let match_url;
	let width = "1920";
	if (dimensions) {
		match_url = e.request.url.replace(dimensions[0], ".");
		width = dimensions[1];
	} else {
		match_url = e.request.url;
	}

	match_url = match_url.replace(imgwb_upload_dir, "");

	// replace background images with Image+
	if (match_url in imgwb_sitewide) {
		const image_id = imgwb_sitewide[match_url];

		let url = `${imgwb_host}/vw:${viewer_id}/g:${country}/a:${imgwb_account_id}/i:${image_id}/w:${width}/view`;

		// stay consistent with previous variant
		if (image_id in imgwb_variants) {
			url += "?variant=" + imgwb_variants[image_id];
		}

		e.respondWith(
			(async () => {
				const rv = await fetch(url, {
					headers: {
						"Accept": e.request.headers.get("accept")
					}
				});

				const variant_id = rv.headers.get("x-imgwb-variant") ? rv.headers.get("x-imgwb-variant") : (image_id + 'BL');

				// record view in history
				self.clients.matchAll().then(function(clients) {
					clients.forEach(function(client) {
						client.postMessage({
							from: "imgwb",
							history: {
								account_id: imgwb_account_id,
								image_id: image_id,
								variant_id: variant_id
							},
							refresh: rv.headers.get("x-imgwb-refresh") ? url.replace("/view", "/refresh") : null
						});
					});
				});

				// stay consistent with previous variant
				imgwb_variants[image_id] = variant_id;

				return rv;
			})()
		);
	}
});
		<?php
	}


	/**
	 * Register service worker, and custom Gutenberg block, and custom post type.
	 *
	 * @return void
	 */
	public function imgwb_init() {
		// service worker for sitewide Image+ replacement and to detect and report certain downstream goal signals
		if (defined('PWA_VERSION')) {
			// register service worker using PWA
			add_action( 'wp_front_service_worker', function( $scripts ) {
				$scripts->register(
					'imgwb-service-worker',
					array(
						'src'  => function() {
							// build script
							ob_start();
							$this->echo_service_worker_script();
							return ob_get_clean();
						}
					)
				);
			});

		} else {
			// manually register service worker
			// generate ver from last change to custom posts
			add_action('wp_print_scripts', function() {
				$cpt_updated = get_option('imgwb_cpt_updated');
				if (!$cpt_updated) {
					$cpt_updated = time();
				}

				echo "<script>
if (navigator.serviceWorker) {
	navigator.serviceWorker.register('/" . esc_html($this->sw_script) . '&ver=' . esc_attr($cpt_updated) . "');
}
</script>";
			});
		}

		// upgrade patch
		$updated = get_option('imgwb_updated');
		if ( !$updated || $updated != $this->version ) {
			$this->imgwb_updated();
			update_option('imgwb_updated', $this->version);
		}

		// custom Gutenberg block
		register_block_type( __DIR__ . '/build' );

		// custom post type
		$this->register_custom_post_type();
	}

	/**
	 * Add query var for service worker rewrite
	 *
	 * @return array Query vars
	 */
	public function imgwb_query_vars( $qvars ) {
		$qvars[] = 'imgwb_page';
		return $qvars;
	}

	/**
	 * Render service worker
	 *
	 * @return array Query vars
	 */
	public function imgwb_template_redirect() {
		if ( get_query_var( 'imgwb_page' ) == 'service_worker' ) {
			header('Content-Type: application/javascript');
			header('Cache-Control: max-age=300');
			//echo $this->service_worker_script();
			$this->echo_service_worker_script();
			exit;
		}
	}

	/**
	 * Update content: scan all blocks and ensure block html validation
	 *
	 * @return void
	 */
	private function imgwb_updated() {

		// v1.2.0 simplify block content for graceful deactivation
		$callback = function( $block) {
			$new_img = sprintf('<img src="%s" data-image-plus="%s" style="%s"/>', $block['attrs']['to_src'], $block['attrs']['image_id'], 'width:100%;height:auto;display:block');

			if ( ! empty($block['innerHTML'])) {
				$block['innerHTML'] = preg_replace('/<img .*\/>/', $new_img, $block['innerHTML']);
			}
			if ( ! empty($block['innerContent']) && is_array($block['innerContent']) ) {
				$block['innerContent'] = array_map(function ( $item) use( $new_img) {
					return preg_replace('/<img .*\/>/', $new_img, $item);
				}, $block['innerContent']);
			}

			return $block;
		};

		global $wpdb;
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM $wpdb->posts WHERE post_type IN ('post', 'page', 'image_plus') AND post_content LIKE %s",
			'%class="wp-block-images-with-benefits-image-plus wp-block-image"%'
		) );
		foreach ( $posts as $post ) {
			// scan for blocks to update
			$blocks = parse_blocks($post->post_content);
			$this->imgwb_scan_block_recursive($blocks, $callback);
			$new_content = serialize_blocks($blocks);

			if ($post->post_content != $new_content) {
				$wpdb->update(
					$wpdb->posts,
					array(
						'post_content' => $new_content,
					),
					array(
						'ID' => $post->ID,
					)
				);
			}
		}
	}


	/**
	 * Scan all blocks recursively, invoking callback to update block content
	 *
	 * @return void
	 */
	private function imgwb_scan_block_recursive( &$blocks, $callback) {
		foreach ($blocks as &$block) {
			if ( !empty( $block['innerBlocks'] ) ) {
				$this->imgwb_scan_block_recursive($block['innerBlocks'], $callback);
			}
			if ($block['blockName'] == $this->block_name) {
				$block = $callback($block);
			}
		}
	}


	/**
	 * Register image-plus custom post type, meta box and shortcode.
	 *
	 * @return void
	 */
	private function register_custom_post_type() {
		$cpt_name = 'Image+';
		if ( 'https://api.imgwb.com' != $this->api ) {
			$cpt_name = 'Image+ [DEV]';
		}

		$labels = array(
			'name' => $cpt_name,
			'singular_name' => 'Image+',
			'all_items' => __('Library', 'image-plus'),
			'search_items' => __('Search', 'image-plus')
		);

		// ref. https://developer.wordpress.org/reference/functions/register_post_type/
		$args = array(
			'labels' => $labels,
			'has_archive' => true,
			'public' => true,
			'exclude_from_search' => true,
			'publicly_queryable' => true,	// enable Preview
			'show_ui' => true,
			'show_in_menu' => true,
			'show_in_rest' => true, 		// enable Gutenberg support
			'supports' => array('title', 'editor'),
			'show_in_nav_menus' => true,
			'menu_icon' => 'https://imgwb.com/s/image-plus.svg',
			'template' => array(
				array($this->block_name, array(
					// attributes
				))
			),
			'template_lock' => 'all',
			'register_meta_box_cb' => array($this, 'add_image_plus_meta_box')
		);

		// disable Add New until account_id is known
		$access_token = get_option('imgwb_access_token');
		if (!$access_token || 'EXPIRED' == $access_token) {
			$args['capabilities'] = array(
				'create_posts' => 'do_not_allow'
			);
		}

		//
		register_post_type('image_plus', $args);

		// Library columns and content
		add_filter( 'manage_image_plus_posts_columns', function( $columns) {
			return array_slice( $columns, 0, 2, true )
				+ array( 'imgwb-original' => __('Original', 'image-plus') )
				+ array( 'imgwb-shortcode' => 'Shortcode' )
				+ array( 'imgwb-active' => __('Image+ (Active)', 'image-plus') )
				+ array( 'imgwb-suggested' => __('Image+ (Suggested)', 'image-plus') )
				+ array( 'imgwb-inactive' => __('Image+ (Inactive)', 'image-plus') )
				+ array( 'date' => __('Date', 'image-plus') );
		}, 99);
		add_action( 'manage_image_plus_posts_custom_column', array($this, 'image_plus_library_content'), 10, 2 );

		// save metabox options
		add_action( 'save_post', array($this, 'save_image_plus_meta_box') );

		// register shortcode
		add_shortcode( 'image-plus', array($this, 'render_shortcode') );
	}

	/**
	 * Override Classic Editor settings for Image+ posts
	 *
	 * @return array editor, allow-users
	 */
	public function imgwb_classic_editor_override() {
		// screen is not yet available so we need to check URL
		// post-new.php?post_type=image_plus
		// post.php?post=123&action=edit

		$editor_override = null;

		if (isset($_SERVER['REQUEST_URI'])) {
			$url = parse_url(esc_url_raw($_SERVER['REQUEST_URI']));
			if ( substr($url['path'], -9) == '/post.php' && $url['query'] ) {
				parse_str($url['query'], $params);
				if (isset($params['action']) && 'edit' == $params['action'] && isset($params['post'])) {
					$post = get_post($params['post']);

					if ( 'image_plus' == $post->post_type ) {
						$editor_override = true;
					}
				}
			} else if ( substr($url['path'], -13) == '/post-new.php' && $url['query'] ) {
				parse_str($url['query'], $params);
				if ( isset($params['post_type']) && 'image_plus' == $params['post_type'] ) {
					$editor_override = true;
				}
			}
		}

		if ( $editor_override ) {
			return array(
				'editor' => 'block',
				'allow-users' => false
			);
		}
	}

	/**
	 * Generate Image+ shortcode including reference to custom post type.
	 *
	 * @param int $cpt_id Post ID of custom post type.
	 *
	 * @return string Shortcode
	 */
	private function get_shortcode( $cpt_id) {
		return '[image-plus id="' . $cpt_id . '"]';
	}

	/**
	 * Renders the "image-plus" shortcode.
	 *
	 * @param array $atts An array of attributes for the shortcode.
	 *
	 * @return string The post content with shortcode filters applied.
	 */
	public function render_shortcode( $atts) {
		$atts = shortcode_atts( array(
			'id' => '',
		), $atts, 'image-plus' );

		$cpt = get_post($atts['id']);

		// render image plus wrapped with figure
		return $this->render_image_plus($cpt, true);
	}

	/**
	 * Renders the Image+ markup
	 * Generated here rather than in Gutenberg.save for graceful deactivation
	 * viewer.js will inline as SVG
	 *
	 * @param 		$cpt 		image-plus Custom Post
	 * @param BOOL 	$figure		wrap image with figure, for shortcode
	 *
	 * @return string The post content with shortcode filters applied.
	 */
	private function render_image_plus( $cpt, $figure ) {

		$blocks = parse_blocks($cpt->post_content);
		if ( !count($blocks) ) {
			return '';
		}

		$block = $blocks[0];

		$aspect_ratio_width  = 1;
		$aspect_ratio_height = 1;
		$img_src			 = '';
		$img_srcset          = '';
		$img_sizes           = '';
		$img_alt             = 'Image With Benefits';

		if (isset($block['attrs']['src']) && isset($block['attrs']['cache_ver'])) {
			$img_src = str_replace('/view', '/cv:' . $block['attrs']['cache_ver'] . '/view', $block['attrs']['src']);
		}

		if (isset($block['attrs']['variants'])) {
			foreach ($block['attrs']['variants'] as $variant) {
				if ('primary' == $variant['variant_type']) {
					$aspect_ratio_width  = $variant['width'];
					$aspect_ratio_height = $variant['height'];

					if (isset($variant['srcset_widths'])) {
						$img_srcset = implode(', ', array_map(function( $width) use( $img_src) {
							return str_replace('/view', '/w:' . $width . '/view ' . $width . 'w', $img_src);
						}, $variant['srcset_widths']));

						$max_width = max($variant['srcset_widths']);
						$img_sizes = '(max-width: ' . $max_width . 'px) 100vw, ' . $max_width . 'px';
					}
				} elseif ('baseline' == $variant['variant_type']) {
					$img_alt = $variant['label'];
				}
			}
		}

		$loading_speed = isset($block['attrs']['loading_speed']) ? $block['attrs']['loading_speed'] : 'lazy';
		$img_loading   = 'lazy' == $loading_speed ? 'lazy' : 'eager';
		$img_class     = 'lazy' == $loading_speed ? 'imgwb-lazy' : 'imgwb-eager';
		$img           = sprintf('<img src="%s" srcset="%s" sizes="%s" loading="%s" class="%s" alt="%s" title="%s" style="%s">',
			$img_src, $img_srcset, $img_sizes, $img_loading, $img_class, $img_alt, $img_alt, 'width:100%;height:auto;display:block'
		);

		// wrap in <figure> and <a>
		if ($figure) {
			if (isset($block['attrs']['link'])) {
				$wrap = sprintf('<a href="%s"', $block['attrs']['link']);
				if (isset($block['attrs']['link_blank'])) {
					$wrap .= ' target="_blank"';
				}
				$img = $wrap . '>' . $img . '</a>';
			}

			$figure_style = sprintf('width:%s;aspect-ratio:%s/%s', '100%', $aspect_ratio_width, $aspect_ratio_height);
			if ('instant' == $loading_speed && isset($block['attrs']['instant_load_src'])) {
				$figure_style .= sprintf(';background=url("%s") center;background-size:cover', $block['attrs']['instant_load_src']);
			}

			$img = sprintf('<figure class="wp-block-images-with-benefits-image-plus wp-block-image" style="%s">%s</figure>',
				$figure_style, $img
			);
		}

		return $img;
	}

	/**
	 * Renders column content for custom post type, including placeholders for image variants populated by admin.js
	 *
	 * @param array $atts An array of attributes for the shortcode.
	 *
	 * @return void
	 */
	public function image_plus_library_content( $column_name, $post_id ) {
		global $post;
		$blocks = parse_blocks($post->post_content);
		if (!count($blocks) || !isset($blocks[0]['attrs']['image_id'])) {
			return;
		}
		$image_id = $blocks[0]['attrs']['image_id'];

		if ( 'imgwb-shortcode' == $column_name ) {
			echo esc_html($this->get_shortcode($post_id));

		} else {
			echo '<div class="imgwb-variant-strip ' . esc_attr($column_name) . '" data-post="' . esc_attr($post_id) . '" data-image="' . esc_attr($image_id) . '">';

			// variant columns
			if (in_array($column_name, array('imgwb-original', 'imgwb-active'))) {
				echo '<div class="imgwb-placeholder"></div>';
			}
			echo '</div>';
		}
	}

	/**
	 * Register meta box for custom post type
	 *
	 * @return void
	 */
	public function add_image_plus_meta_box() {
		add_meta_box(
			'image_plus_meta_box',
			__('Publish this Image+', 'image-plus'),
			array($this, 'display_image_plus_meta_box'),
			'image_plus',
			'normal',
			'default'
		);
	}

	/**
	 * Render meta box for custom post type
	 *
	 * @return void
	 */
	public function display_image_plus_meta_box() {
		global $post;

		// Add an nonce field so we can check for it later.
		wp_nonce_field( 'imgwb_meta_box_nonce', 'imgwb_meta_box_nonce' );

		$post_status = get_post_status($post);
		$here        = preg_replace('(^https?://)', '', get_site_url());

		/**
		 * Stores the contents of the custom post ID supplied in the shortcode.
		 *
		 * @since 1.0.0
		 */
		$code = $this->render_shortcode(array(
			'id' => $post->ID
		));

		// from Wizard: code will not be aware of variants advised by the backend

		?>
		<div class="nav-tab-wrapper">
			<a class="nav-tab" href="#here"><?php echo esc_html($here); ?></a>
			<a class="nav-tab" href="#www"><?php esc_html_e('Other Websites', 'image-plus'); ?></a>
		</div>
		<section class="imgwb-section hidden" id="imgwb-section-here">
			<ul>
				<?php

				if ( 'publish' != $post_status ) {
					echo '<li>';
					printf(
						/* translators: %1$s and %2$s wrap a link to the Publish button */
						esc_html__('When you are ready, click the %1$sPublish%2$s button', 'image-plus'),
						'<a href="#publish" class="imgwb-publish-link">',
						'</a>'
					);
					echo '</li>';
				}

				// Use get_post_meta to retrieve an existing value from the database.
				$imgwb_originating_post_id = get_post_meta( $post->ID, 'imgwb_originating_post_id', true );
				if ($imgwb_originating_post_id) {
					$imgwb_originating_post_title = get_the_title($imgwb_originating_post_id);

					echo '<li>';
					printf(
						/* translators: %s is the link to the page containing the Image+ */
						esc_html__('This Image+ is used on page: %s', 'image-plus'),
						sprintf(
							'<a href="%s">%s</a>',
							esc_html(admin_url('post.php?post=' . $imgwb_originating_post_id . '&action=edit')),
							esc_attr($imgwb_originating_post_title)
						)
					);
					echo '</li>';
				}

				if (metadata_exists('post', $post->ID, 'imgwb_sitewide')) {
					$sitewide = get_post_meta($post->ID, 'imgwb_sitewide', true);
				} else {
					$sitewide = true;
				}
				echo '<li><label><input type="checkbox" name="imgwb_sitewide"' . checked( esc_attr($sitewide), true, false ) . '>';
				printf(
					/* translators: %s is replaced with the website URL */
					esc_html__('Sitewide: use this Image+ to replace the original image everywhere on %s', 'image-plus'),
					esc_html($here)
				);
				echo '</label></li>';

				echo '<li>';
				printf(
					/* translators: %s is replaced with the Image+ shortcode */
					esc_html__('You can also display this Image+ anywhere using shortcode: %s', 'image-plus'),
					esc_html($this->get_shortcode($post->ID))
				);
				echo '</li>';
				?>
			</ul>
		</section>
		<section class="imgwb-section hidden" id="imgwb-section-www">
			<?php if (false !== strpos($code, 'src=""')) { ?>
				<p>Save your Image+ and refresh this page to get the code to display it on other websites.</p>

			<?php } else { ?>
			<ol>
				<li><?php esc_html_e('Add this script tag to your page', 'image-plus'); ?>
					<pre>&lt;script src="https://imgwb.com/s/viewer.js?ver=<?php echo esc_attr($this->version); ?>"&gt;&lt;/script&gt;</pre>
				</li>
				<li><?php esc_html_e('Add this code where you want to display your Image+', 'image-plus'); ?>
					<p><textarea readonly style="font-family:monospace;font-size:10px;width:50%;height:120px"><?php echo esc_textarea($code); ?></textarea></p>
				</li>
				<li>
				<?php
				printf(
					/* translators: %1$s and %2$s wrap a link to the Image+ Library */
					esc_html__('Compare views and clicks in the Image+ %1$sLibrary%2$s.', 'image-plus'),
					sprintf('<a href="%s">', esc_attr(admin_url('edit.php?post_type=image_plus'))),
					'</a>'
				);
				?>
				</li>
			</ol>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * Save meta box input for custom post type
	 *
	 * @return void
	 */
	public function save_image_plus_meta_box( $post_id) {
		if ( ! isset( $_POST['imgwb_meta_box_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field($_POST['imgwb_meta_box_nonce']), 'imgwb_meta_box_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['post_type'] ) && 'image_plus' === $_POST['post_type'] ) {
			if ( isset($_POST['imgwb_sitewide']) && 'on' == $_POST['imgwb_sitewide'] ) {
				update_post_meta($post_id, 'imgwb_sitewide', true);
			} else {
				update_post_meta($post_id, 'imgwb_sitewide', false);
			}
		}
	}

	/**
	 * Add script and css for admin pages, and inline script sharing environmental and user params to Gutenberg block.
	 *
	 * @return void
	 */
	public function imgwb_admin_scripts() {
		$screen = get_current_screen();

		$ver = filemtime( plugin_dir_path( __FILE__ ) . 'admin.js' );
		wp_enqueue_script('imgwb_admin', plugins_url( 'admin.js' , __FILE__ ), array(), $ver, false);

		$hints = array(
			'imgwb_api' => $this->api,
			'rest_api' => esc_url_raw( rest_url( 'imgwb_image_plus/api' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'screen' => $screen->id,
			'account_id' => get_option('imgwb_account_id'),
			'email' => get_option('imgwb_user_email'),
			'general' => get_option('imgwb_settings_general'),
			'enhance' => get_option('imgwb_settings_enhance'),
			'optimize' => get_option('imgwb_settings_optimize'),
			'demo' => get_option('imgwb_demo'),
			'plan_id' => get_option('imgwb_plan_id', 'free'),
			'plan_config' => get_option('imgwb_plan_config'),
			'access_token' => get_option('imgwb_access_token')
		);

		// add general config settings, used by admin screens and Gutenberg custom block
		wp_localize_script( 'imgwb_admin', 'imgwb_admin', $hints );

		// skip when gutenberg editing
		if (!in_array($screen->id, array('image_plus', 'post', 'page'))) {
			$css_ver = filemtime( plugin_dir_path( __FILE__ ) . 'admin.css' );
			wp_enqueue_style('imgwb_admin', plugins_url( 'admin.css' , __FILE__ ), array(), $css_ver, false);
		}

		// pricing
		wp_enqueue_script('imgwb_pricing', 'https://js.stripe.com/v3/pricing-table.js', array(), $ver, false);
	}

	/**
	 * Load help bubble
	 *
	 * @return void
	 */
	public function imgwb_wp_print_scripts() {
		// session timed-out window doesn't expose screen
		if (!function_exists('get_current_screen')) {
			return;
		}

		$screen = get_current_screen();
		if ( !$screen || 'image_plus' != $screen->post_type ) {
			return;
		}

		// tawkto widget
		$current_user = wp_get_current_user();
		// no underscores allowed!
		?>
<script type="text/javascript">
window.Tawk_API = window.Tawk_API || {};
window.Tawk_API.onLoad = function(){
	window.Tawk_API.setAttributes({
		name: '<?php echo esc_attr($current_user->display_name); ?>',
		email: "<?php echo esc_attr(get_option('imgwb_user_email')); ?>",
		hash: "<?php echo esc_attr(get_option('imgwb_help_token')); ?>",
		wpemail: '<?php echo esc_attr($current_user->user_email); ?>',
		account: "<?php echo esc_attr(get_option('imgwb_account_id')); ?>",
		site: "<?php echo esc_attr(get_option('imgwb_site_id')); ?>",
		plan: "<?php echo esc_attr(get_option('imgwb_plan_id')); ?>"
	}, function(error) {
		console.log(error);
	});
};
var Tawk_LoadStart=new Date();
(function(){
var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
s1.async=true;
s1.src='https://embed.tawk.to/646bf9c7ad80445890ee79fe/1h12sfd44';
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
		<?php
	}


	/**
	 * Recursively search for imgwb blocks for syncing with server
	 *
	 * @return array Images found
	 */
	private function imgwb_find_blocks( $post, $blocks, $images = array()) {
		foreach ($blocks as $block) {
			//$this->imgwb_log($block['blockName']);

			if ( !empty( $block['innerBlocks'] ) ) {
				$images = $this->imgwb_find_blocks($post, $block['innerBlocks'], $images);
			}

			if ($block['blockName'] == $this->block_name) {
				//$this->imgwb_log($block);

				if ( !isset($block['attrs']['image_id']) || !$block['attrs']['image_id'] || 'new' == $block['attrs']['image_id'] ) {
					continue;
				}

				$image = array(
					'variants' => array()
				);

				// db attrs doesn't contain defaults, backend needs defaults
				foreach (array('link', 'link_blank', 'loading_speed') as $field) {
					if (isset($block['attrs'][$field])) {
						$image[$field] = $block['attrs'][$field];
					}
				}

				$primary_variant = null;

				foreach ($block['attrs']['variants'] as $variant_id => $variant) {
					if ( 'new' == $variant_id || 'fallback' == $variant['variant_type'] ) {
						continue;
					}

					// save label
					$image['variants'][$variant_id] = array();

					// don't save 'New Image'
					if ( isset($variant['label']) && 'New Image' != $variant['label'] ) {
						$image['variants'][$variant_id]['label'] = $variant['label'];
					}

					if ( isset($variant['svg_title']) && $variant['svg_title'] ) {
						$image['variants'][$variant_id]['svg_title'] = $variant['svg_title'];
					}

					// save variant_status when active/inactive
					// don't save 'loading' because that could over-write server progress
					if ( 'active' == $variant['variant_status'] || 'inactive' == $variant['variant_status'] ) {
						$image['variants'][$variant_id]['variant_status'] = $variant['variant_status'];
					}

					// save any params
					if ( isset($variant['params']) && $variant['params'] ) {
						$image['variants'][$variant_id]['params'] = $variant['params'];
					}

					// skip if nothing to save
					if (!count($image['variants'][$variant_id])) {
						unset($image['variants'][$variant_id]);
					}

					// for attach
					if ( 'primary' == $variant['variant_type'] ) {
						$primary_variant = $variant;
					}
				}

				$images[$block['attrs']['image_id']] = $image;

				// sync with cpt
				if ( 'trash' != $post->post_status ) {
					$this->imgwb_cpt_sync($post, $block, $primary_variant);
				}

				// delete transient, refresh library
				$transient_id = 'imgwb_' . $block['attrs']['image_id'];
				delete_transient($transient_id);
			}
		}

		return $images;
	}

	/**
	 * Save image parameters to Imgwb API. Can be either a page/post or a reusable block or an image-plus custom post type.
	 *
	 * @return void
	 */
	public function imgwb_batch_update( $post_id ) {
		$post = get_post( $post_id );
		if ( 'revision' == $post->post_type ) {
			//$this->imgwb_log('skipping revision');
			return;
		}

		// save params to backend
		$blocks = parse_blocks($post->post_content);
		$images = $this->imgwb_find_blocks($post, $blocks);

		if (count($images)) {
			$payload = array(
				'page' => array(
					// draft or published? server can decide how many assets to create
					'page_status' => $post->post_status,
					// used for default image caption
					'page_title' => $post->post_title,
					// user can retrict image to serving from single page
					'page_url' => $post->guid
				),
				'images' => $images
			);

			//$this->imgwb_log($payload);

			$response = $this->imgwb_api_request('POST', '/batch', $payload);
		}

		// update api counter
		$api_counter = get_option('imgwb_api_counter');
		if (!isset($api_counter)) {
			$api_counter = 0;
		}
		update_option('imgwb_api_counter', intval($api_counter) + $this->api_counter_delta);
		$this->api_counter_delta = 0;
	}

	/**
	 * Sync Image+ with custom post type
	 *
	 * @return void
	 */
	private function imgwb_cpt_sync( $post, $block, $primary_variant) {

		global $wpdb;

		// cpt
		if ( 'image_plus' == $post->post_type ) {
			// update imgwb_image_id when cpt has been created via Add New
			update_post_meta($post->ID, 'imgwb_image_id', $block['attrs']['image_id']);

			// to_src might be -scaled.
			$src = str_replace('-scaled.', '.', $block['attrs']['to_src']);

			// if image.to_src matches with guid, map that attachment to this cpt for viewer replacement
			$attachment_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s", $src ) );

			// add mapping
			if (!is_null($attachment_id)) {
				update_post_meta($attachment_id, 'imgwb_replace_with_cpt', $post->ID);
			}

			// sync to originating post - scan blocks for any matching image_id
			//
			$originating_post_id = get_post_meta($post->ID, 'imgwb_originating_post_id', true);

			if ($originating_post_id) {
				// update local originating copy of block
				$image_id = get_post_meta($post->ID, 'imgwb_image_id', true);

				$originating_post   = get_post($originating_post_id);
				$originating_blocks = parse_blocks( $originating_post->post_content );

				foreach ( $originating_blocks as $index => $originating_block ) {
					if ( $originating_block['blockName'] == $this->block_name && $originating_block['attrs']['image_id'] == $image_id ) {
						// update the block
						$originating_blocks[$index] = $block;
					}
				}

				$originating_post = array(
					'ID'           => $originating_post_id,
					'post_content' => serialize_blocks( $originating_blocks )
				);

				// false prevents update loop
				wp_update_post( $originating_post, false, false );
			}

			// cache-bust service worker version
			update_option('imgwb_cpt_updated', time());

			// default title
			if (!$post->post_title) {
				foreach ($block['attrs']['variants'] as $variant) {
					if ('primary' == $variant['variant_type']) {
						$post->post_title = $variant['label'];
						wp_update_post($post, false, false);
					}
				}
			}

		} else {
			// inline

			// uniquely add to cpt library so we can track results there, and link to this Location for editing
			$cpt_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='imgwb_image_id' AND meta_value=%s", $block['attrs']['image_id'] ) );

			if (is_null($cpt_id)) {
				// new
				$cpt = array(
					'post_name' 	=> $primary_variant['svg_title'],
					'post_title'    => $primary_variant['svg_title'],
					'post_content'  => serialize_block( $block ),
					'post_status'   => 'publish',
					'post_type'     => 'image_plus',
					'meta_input'	=> array(
						'imgwb_image_id' => $block['attrs']['image_id'],
						'imgwb_originating_post_id' => $post->ID,
						'imgwb_sitewide' => false
					)
				);

				$post_id = wp_insert_post( $cpt );

			} else {
				// update
				$cpt = array(
					'ID'			=> $cpt_id,
					'post_content'  => serialize_block( $block )
				);

				// false prevents update loop
				wp_update_post( $cpt, false, false );
			}

		}

	}

	/**
	 * From Wizard, create first Image+
	 *
	 * @return void
	 */
	private function addCustomPost( $data) {
		//$this->imgwb_log($data);

		$block = array(
			'blockName' => $this->block_name,
			'attrs' => array(
				'to_src' => $data['variant']['source_url'],
				'src' => $data['image']['src'],
				'image_id' => $data['image']['image_id'],
				//'lock_aspect_ratio' => true,
				'loading_speed' => 'lazy',
				'variants' => array(),
				'variant_id' => $data['variant']['variant_id']
			),
			'innerContent' => array(),
			'innerHTML' => '',
			'innerBlocks' => array()
		);

		# get originating post id...

		$cpt = array(
			'post_name' 	=> $data['variant']['label'],
			'post_title'    => $data['variant']['label'],
			'post_content'  => serialize_block( $block ),
			'post_status'   => 'publish',
			'post_type'     => 'image_plus',
			'meta_input'	=> array(
				'imgwb_image_id' => $block['attrs']['image_id'],
				'imgwb_originating_post_id' => $this->imgwb_get_front_post_id(),
				'imgwb_sitewide' => true
			)
		);
		//$this->imgwb_log($cpt);

		$post_id = wp_insert_post( $cpt );

		// if image.src matches with guid, map that attachment to this cpt for viewer replacement
		global $wpdb;
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s", $data['image']['src'] ) );

		// add mapping
		if (!is_null($attachment_id)) {
			update_post_meta($attachment_id, 'imgwb_replace_with_cpt', $post_id);
		}
	}

	/**
	 * Replace an image tag with Image+ markup
	 *
	 * @return string Image+ content
	 */
	public function imgwb_wp_content_img_tag( $filtered_image, $context, $attachment_id) {
		global $wpdb;

		$image_id = null;
		$cpt_id   = null;

		// do we need to check the cpt sitewide flag? not if we have data-image-plus
		$requires_sitewide = false;

		// get image_id from: data-image-plus="q1w2e3r4t5"
		if (preg_match( '/data-image-plus="([a-zA-Z0-9]*)"/', $filtered_image, $matches )) {
			$image_id = $matches[1];
		}

		// get attachment_id from class="wp-image-14"
		if (!$image_id && preg_match( '/wp-image-(\d+)/', $filtered_image, $matches )) {
			$attachment_id     = intval($matches[1]);
			$requires_sitewide = true;
		}

		// manual check for attachment_id, removing -wwwxhhh. responsiveness suffix
		if (!$image_id && !$attachment_id && preg_match_all('/http[^\s"]*/', $filtered_image, $matches)) {
			$srcs = array();

			foreach ($matches[0] as $match) {
				if (str_contains($match, 'imgwb.com/v')) {
					continue;
				}
				$src        = preg_replace('/-\d+x\d+./', '.', $match);
				$src        = str_replace('-scaled.', '.', $src);
				$srcs[$src] = 1;
			}

			if (count($srcs)) {
				$sql = "SELECT ID FROM $wpdb->posts WHERE guid IN (" . implode(', ', array_fill(0, count($srcs), '%s')) . ') LIMIT 1';

				// Call $wpdb->prepare passing the values of the array as separate arguments
				$attachment_id = $wpdb->get_var( call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), array_keys($srcs))) );
			}

			$requires_sitewide = true;
		}

		// get cpt_id from image_id or attachment_id
		if ($image_id) {
			$cpt_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_value=%s AND meta_key='imgwb_image_id' LIMIT 1", $image_id ) );

		} elseif ($attachment_id) {
			$cpt_id = get_post_meta( $attachment_id, 'imgwb_replace_with_cpt', true );
		}

		# render image plus from cpt attributes
		if ($cpt_id) {
			if ($requires_sitewide) {
				$proceed = get_post_meta($cpt_id, 'imgwb_sitewide');
			} else {
				$proceed = true;
			}

			if ($proceed) {
				// get post_content from cpt
				$cpt = get_post($cpt_id);

				if ($cpt) {
					// render <img> markup
					return $this->render_image_plus($cpt, false);
				}
			}
		}

		return $filtered_image;
	}

	/**
	 * Add preconnect header for speed
	 *
	 * @return array Updated headers
	 */
	public function imgwb_add_headers( $headers) {
		// special goal detection - here because we add cookie header
		// BigCommerce checkout complete
		$bigcommerce_checkout_complete_page_id = get_option('bigcommerce_checkout_complete_page_id');
		if ($bigcommerce_checkout_complete_page_id && is_singular() && is_page(intval($bigcommerce_checkout_complete_page_id))) {
			$this->imgwb_downstream_event('sale_g404');
		}

		$headers['link'] = sprintf('<%1$s>; rel=preconnect', $this->imgwb_host);
		return $headers;
	}

	/**
	 * Add viewer script
	 *
	 * @return void
	 */
	public function imgwb_scripts() {
		$local_viewer = get_option('imgwb_local_viewer');
		if ($local_viewer) {
			$ver = filemtime( plugin_dir_path( __FILE__ ) . 'viewer-max.js' );
			wp_enqueue_script('imgwb_viewer', plugins_url( 'viewer-max.js' , __FILE__ ), array(), $ver, false);
		} else {
			wp_enqueue_script('imgwb_viewer', 'https://imgwb.com/s/viewer.js', array(), $this->version, false);
		}
	}

	/**
	 * Register hooks for downstream signals from integrated plugins.
	 *
	 * @return void
	 */
	public function imgwb_plugins_loaded() {

		// setup downstream signals

		$plan_config = get_option('imgwb_plan_config');
		if (!$plan_config) {
			return;
		}

		foreach ($plan_config['goal_config'] as $goal_id => $goal) {
			// active
			$active = false;
			if (true === $goal['wp_detect']) {
				$active = true;
			} else if ('class' == $goal['wp_detect'][0]) {
				$active = class_exists($goal['wp_detect'][1]);
			} else if ('function' == $goal['wp_detect'][0]) {
				$active = function_exists($goal['wp_detect'][1]);
			}

			if (!$active || !isset($goal['signals']) || !count($goal['signals'])) {
				continue;
			}

			foreach ($goal['signals'] as $signal) {
				if (isset($signal['wp_action'])) {
					add_action( $signal['wp_action'], function() use ( $signal ) {
						$this->imgwb_downstream_event($signal['goal']);
					});
				}
			}
		}
	}

	/**
	 * Signal detection of downstream signal
	 *
	 * @return void
	 */
	private function imgwb_downstream_event( $event_type) {
		//$this->imgwb_log(array('imgwb_downstream_event' => $event_type));
		setcookie('imgwb_event_' . $event_type, time(), time()+86400, '/');
	}

	/**
	 * Get Wizard content from Imgwb API.
	 *
	 * @return mixed Response from Imgwb API
	 */
	public function imgwb_api_wizard_get( $request) {
		$params = $request->get_params();

		//$this->imgwb_log($params);

		$step = $params['step_id'];

		# get word count
		if ( 'step1' == $step ) {
			// Convert all characters to lowercase to ensure consistency
			$text = strtolower($this->imgwb_get_front_words());

			// Remove any non-word characters (i.e. anything that's not a letter, number, or underscore)
			$text = preg_replace('/[^\w\s]/', '', $text);
			$text = preg_replace('/\d+\s/', '', $text);

			// Split the text into an array of words
			$words = explode(' ', $text);

			// Initialize an empty array to store the word frequencies
			$frequencies = array();

			$stopwords = array(
				'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are', 'aren\'t', 'as', 'at',
				'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'by',
				'can\'t', 'cannot', 'could', 'couldn\'t',
				'did', 'didn\'t', 'do', 'does', 'doesn\'t', 'doing', 'don\'t', 'down', 'during',
				'each',
				'few', 'for', 'from', 'further',
				'had', 'hadn\'t', 'has', 'hasn\'t', 'have', 'haven\'t', 'having', 'he', 'he\'d', 'he\'ll', 'he\'s', 'her', 'here', 'here\'s', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'how\'s',
				'i', 'if', 'in', 'into', 'is', 'isn\'t', 'it', 'it\'s', 'its', 'itself',
				'let\'s',
				'me', 'more', 'most', 'mustn\'t', 'my', 'myself',
				'no', 'nor', 'not',
				'of', 'off', 'on', 'once', 'only', 'or', 'other', 'ought', 'our', 'ours', 'ourselves', 'out', 'over', 'own',
				'same', 'shan\'t', 'she', 'she\'d', 'she\'ll', 'she\'s', 'should', 'shouldn\'t', 'so', 'some', 'such',
				'than', 'that', 'that\'s', 'the', 'their', 'theirs', 'them', 'themselves', 'then', 'there', 'there\'s', 'these', 'they', 'they\'d', 'they\'ll', 'they\'re', 'they\'ve', 'this', 'those', 'through', 'to', 'too',
				'under', 'until', 'up',
				'very',
				'was', 'wasn\'t', 'we', 'we\'d', 'we\'ll', 'we\'re', 'we\'ve', 'were', 'weren\'t', 'what', 'what\'s', 'when', 'when\'s', 'where', 'where\'s', 'which', 'while', 'who', 'who\'s', 'whom', 'why', 'why\'s', 'with', 'won\'t', 'would', 'wouldn\'t',
				'you', 'you\'d', 'you\'ll', 'you\'re', 'you\'ve', 'your', 'yours', 'yourself', 'yourselves'
			);

			// Loop through the array of words, exclude stopwords and increment the frequency for each word
			foreach ($words as $word) {
				if (in_array($word, $stopwords)) {
					continue;
				}

				if (isset($frequencies[$word])) {
					$frequencies[$word]++;
				} else {
					$frequencies[$word] = 1;
				}
			}

			// Sort the array of word frequencies in descending order
			arsort($frequencies);

			// Return only the top 100 entries
			$result = array_keys(array_slice($frequencies, 0, 100));

		} elseif ( 'step2' == $step ) {
			// get images to choose from
			$result = $this->imgwb_get_front_images();
		}

		//$this->imgwb_log($result);
		return $result;
	}

	/**
	 * Get front page of site for Wizard analysis.
	 *
	 * @return int Post/page ID
	 */
	private function imgwb_get_front_post_id() {
		$page_on_front = get_option('page_on_front');

		// if not static page, get recent published post
		if (!$page_on_front) {
			$recent_post_id = null;

			$args = array(
				'post_type' => 'post',
				'post_status' => 'publish',
				'posts_per_page' => 1,
				'orderby' => 'date',
				'order' => 'DESC'
			);

			$query = new WP_Query($args);

			if ($query->have_posts()) {
				$query->the_post();
				$recent_post_id = get_the_ID();
			}

			wp_reset_postdata();
			return $recent_post_id;

		} else {

			return $page_on_front;
		}
	}

	/**
	 * Get front page content for Wizard analysis.
	 *
	 * @return string Content after stripping HTML tags
	 */
	private function imgwb_get_front_words() {

		# for testing
		$wizard_url = get_option('imgwb_wizard_url');

		if ($wizard_url) {
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $wizard_url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_TIMEOUT, 90);
			$content = curl_exec($curl);

		} else {
			$front_post_id = $this->imgwb_get_front_post_id();

			if (!$front_post_id) {
				return;
			}

			$post    = get_post($front_post_id);
			$content = $post->post_title . ' ' . $post->post_content . ' ' . get_post_meta($front_post_id, '_yoast_wpseo_metadesc', true) . ' ' . get_post_meta($front_post_id, '_aioseop_description', true);
		}

		// Remove any shortcode tags
		$content = strip_shortcodes($content);

		// Remove any HTML tags and attributes
		$content = wp_strip_all_tags($content);

		// Remove any non-alphanumeric characters and convert to lowercase
		$content = strtolower(preg_replace('/[^a-zA-Z0-9]/', ' ', $content));

		// Remove extra whitespace
		$content = preg_replace('/\s+/', ' ', $content);

		return $content;
	}

	/**
	 * Get front page images for Wizard analysis.
	 *
	 * @return array Images for the user to choose to create their first Image+
	 */
	private function imgwb_get_front_images() {

		# for testing
		$wizard_url = get_option('imgwb_wizard_url');

		if ($wizard_url) {
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $wizard_url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			$content = curl_exec($curl);

		} else {
			$front_post_id = $this->imgwb_get_front_post_id();

			if (!$front_post_id) {
				return;
			}

			$post    = get_post($front_post_id);
			$content = $post->post_content;
		}

		// Get all images in the post content
		$images = array();
		$dom    = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8')); // load the HTML
		$xpath = new DOMXPath($dom);
		$nodes = $xpath->query('//img[(@data-lazy-src or @data-src or @src) and (not(@width) or number(@width) > 100 or @width="100%")]');
		foreach ($nodes as $image) {
			$img_src = $image->getAttribute('data-lazy-src');
			if (!$img_src) {
				$img_src = $image->getAttribute('data-src');
			}
			if (!$img_src) {
				$img_src = $image->getAttribute('src');
			}

			if ($img_src && !str_starts_with($img_src, 'data:') && !str_contains($img_src, '.svg')) {
				// fix relative links
				if (str_starts_with($img_src, '/')) {
					$img_src = ( $wizard_url ? $wizard_url : get_site_url() ) . $img_src;
				}

				// Get the size of each image
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $img_src);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
				curl_setopt($ch, CURLOPT_NOBODY, true);

				$headers = [];
				curl_setopt($ch, CURLOPT_HEADERFUNCTION,
					function( $curl, $header) use ( &$headers) {
						$len    = strlen($header);
						$header = explode(':', $header, 2);
						if (count($header) < 2) { // ignore invalid headers
							return $len;
						}
						$headers[strtolower(trim($header[0]))][] = trim($header[1]);
						return $len;
					}
				);

				$result = curl_exec($ch);
				curl_close($ch);

				$images[] = array(
					'src' => $img_src,
					'alt' => $image->getAttribute('alt'),
					'size' => isset($headers['content-length']) ? intval($headers['content-length'][0]) : 0
				);
			}
		}

		// Plan B: search for attachments
		if (!count($images)) {

			$args        = array(
				'post_type' => 'attachment',
				'numberposts' => -1,
				'post_status' => null,
				'post_mime_type' => null
			);
			$attachments = get_posts($args);

			$images = array();

			foreach ($attachments as $attachment) {
				$image = wp_get_attachment_image_src($attachment->ID, 'full');

				if ($image[1] < 100) {
					continue;
				}

				//$this->imgwb_log($image);

				$images[] = array(
					'src' => $attachment->guid,
					'alt' => $attachment->post_title,
					'size' => $image[1] * $image[2]
				);
			}
		}

		// Sort images by area in descending order
		usort($images, function( $a, $b) {
			return $b['size'] - $a['size'];
		});

		# return top images
		return array_slice($images, 0, 10);
	}


	/**
	 * Sideload AI-generated image into Media Library.
	 *
	 * @return array Including URL of the new attachment
	 */
	public function imgwb_api_sideload_post( $request) {

		$settings_general = get_option('imgwb_settings_general');

		require_once( ABSPATH . '/wp-admin/includes/image.php');
		require_once( ABSPATH . '/wp-admin/includes/file.php');
		require_once( ABSPATH . '/wp-admin/includes/media.php');

		// default true
		if (!isset($settings_general['generative_save_media']) || $settings_general['generative_save_media']) {
			$payload = $request->get_params();

			$images = $payload['images'];

			for ( $i = 0; $i < count($images); $i++ ) {
				$url = $images[$i]['src'];

				// Download url to a temp file
				$tmp = download_url( $url );
				if ( is_wp_error( $tmp ) ) {
					return false;
				}

				$fileparts = explode('/', $url);
				$filename = end($fileparts);

				// Upload by "sideloading": "the same way as an uploaded file is handled by media_handle_upload"
				$args = array(
					'name' => $filename,
					'tmp_name' => $tmp,
				);

				// Do the upload
				if (isset($payload['prompt'])) {
					$title = sanitize_title($payload['prompt'] . '-' . $filename);
				} else {
					$title = 'ai-generated-image-' . $filename;
				}

				$attachment_id = media_handle_sideload( $args, 0, $title);

				// Cleanup temp file
				@unlink($tmp);
			}
		}

		return array('sideload' => 'done');
	}

}

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// prevent plugin from being loaded more than once
if ( ! defined( 'IMGWB_LOADED' ) ) {
	define( 'IMGWB_LOADED', true );
	$imgwb_image_plus = new Imgwb_Image_Plus();
}


