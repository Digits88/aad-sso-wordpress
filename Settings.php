<?php

/**
 * Class containing all settings used by the AADSSO plugin.
 *
 * Installation-specific configuration settings should be kept in a JSON file and loaded with the
 * loadSettingsFromJSON() method rather than hard-coding here.
 */
class AADSSO_Settings {

	/**
	 * @var \AADSSO_Settings $instance The settings instance.
	 */
	private static $instance = NULL;

	/**
	 * @var string The OAuth 2.0 client type. Either 'confidential' or 'public'. (Not in use yet.)
	 */
	public $clientType = 'confidential';

	/**
	 * @var string The client ID obtained after registering an application in AAD.
	 */
	public $client_id = '';

	/**
	 * @var string The client secret key, which is generated on the app configuration page in AAD.
	 * Required if $clientType is 'public'.
	 */
	public $client_secret = '';

	/**
	 * @var string The display name of the organization, used only in the link in the login page.
	 */
	public $org_display_name = '';

	/**
	 * @var string Provides a hint about the tenant or domain that the user should use to sign in.
	 * The value of the domain_hint is a registered domain for the tenant. If the tenant is federated
	 * to an on-premises directory, AAD redirects to the specified tenant federation server.
	 */
	public $org_domain_hint = '';

	/**
	 * @var string the tenant domain used to connect to the API.
	 */
	public $tenant_domain = '';

	/**
	 * @var boolean Whether or not to use AAD group memberships to set WordPress roles.
	 */
	public $enable_aad_group_to_wp_role = false;

	/**
	 * @var string[] The AAD group to WordPress role map.
	 * An associative array user to match up AAD group object ids (key) to WordPress roles (value).
	 * Since the user will be given the first role with a matching group, the order of this array
	 * is important!
	 */
	// TODO: A user-friendly method of specifying the groups.
	public $aad_group_to_wp_role_map = array();

	/**
	 * @var string The default WordPress role to assign a user when not a member of defined AAD groups.
	 * This is only used if $enable_aad_group_to_wp_role is TRUE. NULL means that access will be denied
	 * to users who are not members of the groups defined in $aad_group_to_wp_role_map.
	 */
	public $default_wp_role = NULL;

	/**
	 * @var string The OpenID Connect configuration discovery endpoint.
	 */
	public $openid_configuration_endpoint = 'https://login.windows.net/common/.well-known/openid-configuration';

	// These are the common endpoints that always work, but don't have tenant branding.
	/**
	 * @var string The OAuth 2.0 authorization endpoint.
	 */
	public $authorization_endpoint = '';

	/**
	 * @var string The OAuth 2.0 token endpoint.
	 */
	public $token_endpoint = '';

	/**
	 * @var string The OpenID Connect JSON Web Key Set endpoint.
	 */
	public $jwks_uri = '';

	/**
	 * @var string The sign out endpoint.
	 */
	public $end_session_endpoint = '';

	/**
	 * @var string The URI of the Azure Active Directory Graph API.
	 */
	public $resourceURI = 'https://graph.windows.net';

	/**
	 * @var string The version of the AAD Graph API to use.
	 */
	public $graphVersion = '2013-11-08';

	/**
	 * @var boolean allows site admins to set the plugin to override the user registration
	 * settings in Settings > General to allow the plugin to create new users
	 **/
	public $override_user_registration = false;

	protected function __construct() {

		if ( is_admin() ) {
			// Setup stuff only needed in wp-admin
			add_action( 'admin_menu', array( $this, 'add_menus' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
		}
	}

	/**
	 * @return self The (only) instance of the class.
	 */
	public static function getInstance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	* This method loads the settins from a JSON file and uses the contents to overwrite
	* any properties in the settings class.
	*
	* @param string $jsonFile The path to the JSON file.
	*
	* @return self Returns the (only) instance of the class.
	*/
	public static function loadSettings() {
		$settings = self::getInstance();

		// Import from Settings.json
		$settings->importSettingsFromDB();

		// Import from openid-configuration
		$settings->importSettingsFromJSON($settings->openid_configuration_endpoint);

		return $settings;
	}

	function importSettingsFromJSON($jsonFile) {
		// Load the JSON settings
		//$jsonSettings = file_get_contents($jsonFile);
		if( file_exists( $jsonFile ) ) {
			$f = fopen( $jsonFile, "r" ) or die( "Unable to open settings file" );
			$jsonSettings = fread( $f, filesize( $jsonFile ) );
			fclose( $f );
		} else {
			$response = wp_remote_get( $jsonFile );
			$jsonSettings = wp_remote_retrieve_body( $response );
		}
		$tmpSettings = json_decode($jsonSettings, TRUE);

		// Overwrite any properties defined in the JSON
		foreach ( (array) $tmpSettings as $key => $value) {
			if (property_exists($this, $key)) {
				$this->{$key} = $value;
			}
		}

		return $this;
	}

	private function importSettingsFromDB() {
		$defaults = array(
			'org_display_name'     => $this->org_display_name,
			'org_domain_hint'      => $this->org_domain_hint,
			'client_id'            => $this->client_id,
			'client_secret'        => $this->client_secret,
			'enable_group_to_role' => $this->aad_group_to_wp_role_map,
			'custom_roles'         => '',
			'group_map'            => array(),
		);

		$settings = get_option( 'aad-settings', array() );
		$settings = wp_parse_args( (array) $settings, $defaults );

		$group_map = array();
		// If custom roles exist
		if ( ( isset( $settings['custom_roles'] ) && $settings['custom_roles'] && is_string( $settings['custom_roles'] ) ) ) {

			// Get rows
			$custom_roles = explode( "\n", trim( $settings['custom_roles'] ) );
			// Let's add them to our group map
			foreach( (array) $custom_roles as $role ) {
				if ( $role ) {
					// get role/aad-role
					$role = explode( ' ', trim( $role ) );
					if ( isset( $role[0], $role[1] ) ) {
						// Add to our role map
						$group_map[ $role[0] ] = $role[1];
					}
				}
			}
		}

		// Ensure we have all the group mapping parts
		$settings['group_map'] = wp_parse_args( array_unique( array_merge( $settings['group_map'], $group_map ) ), array(
			'administrator' => '',
			'editor'        => '',
			'author'        => '',
			'contributor'   => '',
			'subscriber'    => '',
		) );

		// Store the whole chunk of settings
		$this->settings = $settings;
		// Load the individual class properties
		// Note: Legacy hack
		foreach( $settings as $k => $v ) {
			$this->$k = $v;
		}

		// Create group to role map
		// Note: Legacy hack
		foreach( $settings['group_map'] as $k => $v ) {
			$this->aad_group_to_wp_role_map[ $v ] = $k;
		}

		// Hack to preserve original functionality
		if( !empty( $settings['group_map_enabled'] ) ) {
			$this->enable_aad_group_to_wp_role = true;
		}
		if( false == get_option( 'aad-group-map-set' ) ){
			update_option( 'aad-group-map-set', 1 );
			$this->enable_aad_group_to_wp_role = true;
		}

		$this->default_wp_role = isset( $settings['default_wp_role'] ) ? $settings['default_wp_role'] : $this->default_wp_role;
	}

	public function add_menus() {
		add_options_page( 'AAD Settings', 'AAD Settings', 'manage_options', 'aad-settings', array( $this, 'render_admin_settings' ) );
	}

	public function render_admin_settings() {
		require_once( 'views/admin/settings.php' );
	}

	public function register_settings() {

		register_setting( 'aad-settings', 'aad-settings' );

		/*
		 * Directory Settings
		 * - Org name
		 * - Org domain
		 * - Secret keys
		 */
		add_settings_section(
			'aad-directory-settings',
			__( 'Directory Settings' ),
			array( $this, 'render_directory_settings_section' ),
			'aad-settings'
		);

		add_settings_field(
			'org_display_name',
			__( 'Organization Display Name' ),
			array( $this, 'render_org_display_name' ),
			'aad-settings',
			'aad-directory-settings'
		);

		add_settings_field(
			'org_domain_hint',
			__( 'Organization Domain Hint' ),
			array( $this, 'render_org_domain_hint' ),
			'aad-settings',
			'aad-directory-settings'
		);

		add_settings_field(
			'tenant_domain',
			__( 'Organization Tenant Domain' ),
			array( $this, 'render_org_tenant_domain' ),
			'aad-settings',
			'aad-directory-settings'
		);

		add_settings_field(
			'client_id',
			__( 'Client ID' ),
			array( $this, 'render_client_id' ),
			'aad-settings',
			'aad-directory-settings'
		);

		add_settings_field(
			'client_secret',
			__( 'Client Secret' ),
			array( $this, 'render_client_secret' ),
			'aad-settings',
			'aad-directory-settings'
		);

		add_settings_field(
			'override_user_registration',
			__( 'Override User Registration' ),
			array( $this, 'render_override_user_registration' ),
			'aad-settings',
			'aad-directory-settings'
		);
		/*
		 * Map of group hash from Azure to local groups
		 */
		add_settings_section(
			'aad-group-settings',
			__( 'Group Map' ),
			array( $this, 'render_group_settings_section' ),
			'aad-settings'
		);

		add_settings_field(
			'group_map_enabled',
			__( 'Enable role mapping' ),
			array( $this, 'render_group_map_enabled' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_admin',
			__( 'Administrator' ),
			array( $this, 'render_group_map_admin' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_editor',
			__( 'Editor' ),
			array( $this, 'render_group_map_editor' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_author',
			__( 'Author' ),
			array( $this, 'render_group_map_author' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_contributor',
			__( 'Contributor' ),
			array( $this, 'render_group_map_contributor' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_subscriber',
			__( 'Subscriber' ),
			array( $this, 'render_group_map_subscriber' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'default_wp_role',
			__( 'Default role' ),
			array( $this, 'render_default_wp_role' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'custom_roles',
			__( 'Custom role mapping' ),
			array( $this, 'render_custom_roles' ),
			'aad-settings',
			'aad-group-settings'
		);
	}

	public function render_directory_settings_section() {}

	public function render_org_display_name() {
		echo '<input type="text" id="org_display_name" name="aad-settings[org_display_name]" value="' . $this->org_display_name . '" class="widefat" />';
		echo '<p class="description">I.E. <strong>Microsoft</strong>. Will be displayed on login page. Optional.</p>';
	}

	public function render_org_domain_hint() {
		echo '<input type="text" id="org_domain_hint" name="aad-settings[org_domain_hint]" value="' . $this->org_domain_hint . '" class="widefat" />';
		echo '<p class="description">I.E. <strong>microsoft.com</strong>. Sent to AAD to prepopulate AD server. Optional.</p>';
	}

	public function render_org_tenant_domain() {
		echo '<input type="text" id="tenant_domain" name="aad-settings[tenant_domain]" value="' . $this->tenant_domain . '" class="widefat" />';
		echo '<p class="description">I.E. <strong>microsoft.com</strong>. Used for querying the API. Optional.</p>';
	}

	public function render_client_id() {
		echo '<input type="text" id="client_id" name="aad-settings[client_id]" value="' . $this->client_id . '" class="widefat" />';
	}

	public function render_client_secret() {
		echo '<input type="text" id="client_secret" name="aad-settings[client_secret]" value="' . $this->client_secret . '" class="widefat" />';
	}

	public function render_override_user_registration() {
		echo '<input type="checkbox" name="aad-settings[override_user_registration]" ' . checked( $this->override_user_registration, 1, false ) . ' value="1" class="widefat" />';
		echo '<p class="description">Allow new users access to the site regardless of site registration settings</p>';
	}

	public function render_group_settings_section() {}

	public function render_group_map_enabled() {
		echo '<input type="checkbox" name="aad-settings[group_map_enabled]" '
			. checked( $this->enable_aad_group_to_wp_role, 1, false )
			. ' value="1" class="widefat" />';
		echo '<p class="description">Match WordPress user role with role from AAD</p>';
	}

	public function render_default_wp_role() {
		echo '<select style="min-width: 200px;" name="aad-settings[default_wp_role]" id="new_role">';
		echo '<option value="">No Role</option>';
		wp_dropdown_roles( $this->default_wp_role );
		echo '</select>';
		// echo '<p class="description">If no role is selected, a user will not be added.</p>';
	}

	public function render_group_map_admin() {
		$this->group_dropdown_or_input( 'administrator' );
	}

	public function render_group_map_editor() {
		$this->group_dropdown_or_input( 'editor' );
	}

	public function render_group_map_author() {
		$this->group_dropdown_or_input( 'author' );
	}

	public function render_group_map_contributor() {
		$this->group_dropdown_or_input( 'contributor' );
	}

	public function render_group_map_subscriber() {
		$this->group_dropdown_or_input( 'subscriber' );
	}

	public function render_custom_roles() {
		echo '<textarea id="custom_roles" class="widefat" name="aad-settings[custom_roles]" rows="10">';
		echo $this->custom_roles;
		echo '</textarea>';
		echo '<p class="description">Additional custom roles that should be mapped in style "[wp_role] [aad_group]". One role per line.</p>';
	}

	public function group_dropdown_or_input( $key ) {
		$groups = $this->get_groups();
		// if no groups OR the number of groups is 199+, then use an input
		if ( ! $groups || empty( $groups->value ) || 199 <= count( $groups->value ) ) {
			echo '<input type="text" id="group_map_'. $key .'" name="aad-settings[group_map]['. $key .']" value="' . $this->settings['group_map'][ $key ] . '" class="widefat" />';
		} else {
			echo '<select style="min-width: 200px;" id="group_map_'. $key .'" name="aad-settings[group_map]['. $key .']">';
			echo '<option value="" ', selected( $this->settings['group_map'][ $key ] ), '>No Mapping</option>';
			foreach ( $groups->value as $group ) {
				printf( '<option value="%s" %s>%s</option>', $group->objectId, selected( $this->settings['group_map'][ $key ], $group->objectId, false ), $group->displayName );
			}
			echo '</select>';
		}
	}

	public function get_groups() {
		static $groups = null;

		if ( ! $this->tenant_domain ) {
			return;
		}

		if ( is_null( $groups ) ) {
			AADSSO_GraphHelper::$tenant_id = $this->tenant_domain;
			AADSSO_GraphHelper::$settings = $this;
			$groups = AADSSO_GraphHelper::getGroups();
		}

		return $groups;
	}

}
