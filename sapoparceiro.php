<?php
/**
 * Plugin Name:  SAPO Parceiro
 * Description:  Opções para parceiros SAPO.
 * Version:      1.1.0
 * Author:       SAPO
 * Author URI:   https://www.sapo.pt/
 * License:      GPLv3
 *
 * @author       SAPO
 * @link         https://www.sapo.pt/
 * @package      sapoparceiro
 * @license      https://www.gnu.org/licenses/gpl-3.0.html
 * @copyright    Copyright (c) 2020 SAPO
 */

class SAPO_Partner {
	private $version = '1.0';
	private $options = [];
	private $default_options = [
		'show_bsu'             => 1,
		'obfuscate_versions'   => 1,
		'secure_restapi'       => 1,
		'disable_xmlrpc'       => 1,
		'override_robots_txt'  => 1,
		'robots_txt'           =>
'User-agent: *
Disallow: /wp-content/plugins/
Disallow: /wp-content/uploads/
Disallow: /wp-admin/
Disallow: /search/
Disallow: /?s=
Disallow: /wp-login.php
Allow: /wp-admin/admin-ajax.php'
	];

	function __construct() {
		$this->default_options['robots_txt'] .= "\nSitemap: ". get_bloginfo('url') .'/sitemap.xml'; // the url is dynamic, so we set the default here

		$this->options = get_option( 'sapo_partner_options', $this->default_options );

		if (! array_key_exists('cache_salt', $this->options) ) {
			$this->options['cache_salt'] = substr(md5(rand()), 0, 8);
			update_option( 'sapo_partner_options', $this->options );
		}

		add_action( 'init', array($this, 'sapo_partner_init') );
	}

	function sapo_partner_init() {
		/* Activate options */
		if ( array_key_exists('show_bsu', $this->options) ) {
			add_action( 'wp_head',   array($this, 'sapo_partner_head_bsu') );
			add_action( 'wp_footer', array($this, 'sapo_partner_footer_bsu') );
		}

		if ( array_key_exists('disable_xmlrpc', $this->options) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
		if ( array_key_exists('secure_restapi', $this->options) ) {
			if ( version_compare(get_bloginfo('version'), '4.7', '>=') ) {
				add_filter( 'rest_authentication_errors', array( $this, 'sapo_partner_secure_restapi') );
			} else {
				// REST API 1.x
				add_filter( 'json_enabled',       '__return_false' );
				add_filter( 'json_jsonp_enabled', '__return_false' );

				// REST API 2.x
				add_filter( 'rest_enabled',       '__return_false' );
				add_filter( 'rest_jsonp_enabled', '__return_false' );
			}

			if ( (!is_admin()) or (!is_user_logged_in()) ) { // deny trivial author listing via WP_Query
				if ( preg_match('/author=([0-9]*)/i', $_SERVER['QUERY_STRING']) ) {
					wp_redirect( home_url() );
					exit();
				}

				add_filter( 'redirect_canonical', array($this, 'sapo_partner_catch_author_query_param_redirect'), 10, 2 );
			}
		}
		if ( array_key_exists('obfuscate_versions', $this->options) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_filter( 'wp_headers',        array($this, 'sapo_partner_clean_response_headers') );
			add_filter( 'script_loader_src', array($this, 'sapo_partner_query_string_version')   );
			add_filter( 'style_loader_src',  array($this, 'sapo_partner_query_string_version')   );
		}
		if ( array_key_exists('override_robots_txt', $this->options) ) {
			add_filter( 'robots_txt', array($this, 'sapo_partner_override_robots_txt') );
		}

		/* Allow access to the options interface */
		if (current_user_can('manage_options')) {
			add_action( 'admin_menu', array($this, 'sapo_partner_add_settings_page') );
			add_action( 'admin_init', array($this, 'sapo_partner_register_settings') );

			add_filter( 'pre_update_option_sapo_partner_options', array($this, 'sapo_partner_handle_update'), 10, 2 );
		}
	}

	/* Plugin actions */

	function sapo_partner_head_bsu() {
		wp_enqueue_style( 'sapo-partner', plugin_dir_url(__FILE__) . 'css/styles.css', array(), $this->version );
	}

	function sapo_partner_bsu_attributes($tag, $handle) {
		if ( 'sapo-bsu' !== $handle ) { return $tag; }

		return str_replace( ' src', ' data-width="1320" data-partner="true" data-country="pt" data-target="#bsu-placeholder" data-bg-color="#222" src', $tag );
	}

	function sapo_partner_footer_bsu() {
		add_filter( 'script_loader_tag', array($this, 'sapo_partner_bsu_attributes'), 10, 2 );

		wp_enqueue_script( 'sapo-bsu', '//js.sapo.pt/Projects/bsuv4/js/bsuv4.min.js', array(), '', true );
		wp_add_inline_script( 'sapo-bsu', '_bsu=document.createElement("div");_bsu.id="bsu-placeholder";document.body.insertBefore(_bsu,document.body.firstChild);', 'before' );
	}

	function sapo_partner_secure_restapi( $access ) {
		if ( ! is_user_logged_in() ) {
			$message = apply_filters('disable_wp_rest_api_error', __('Acesso restrito a utilizadores autenticados.', 'sapo_partner'));

			return new WP_Error('unauthorized', $message, array('status' => rest_authorization_required_code()));
		}

		return $access;
	}

	function sapo_partner_catch_author_query_param_redirect($redirect, $request) {
		if ( preg_match('/\?author=([0-9]*)(\/*)/i', $request) ) {
			wp_redirect( home_url() );
			exit();
		}

		return $redirect;
	}

	function sapo_partner_clean_response_headers( $headers ) {
		header_remove( 'X-Powered-By' );

		return $headers;
	}

	function sapo_partner_query_string_version( $src ) {
		$query_str = parse_url($src, PHP_URL_QUERY);
		parse_str($query_str, $query_params);

		if ( !empty($query_params['ver']) ) {
			$ver = crc32($this->options['cache_salt'] . $query_params['ver']);  // crc32 is faster than md5, and it suffices for this purpose.
			return add_query_arg( 'ver', $ver, remove_query_arg( 'ver', $src ) );
		}
		return $src;
	}

	function sapo_partner_override_robots_txt($output) {
		return $this->options['robots_txt'];
	}

	/* Plugin settings interface & helpers */

	function sapo_partner_add_settings_page() {
		add_options_page( 'SAPO Parceiro', 'SAPO Parceiro', 'manage_options', 'sapo-partner-settings-page', array($this, 'sapo_partner_settings_page') );
	}

	function sapo_partner_settings_page() {
		?>
		<style>.form-table th {padding:10px 10px 10px 0;}</style>
		<div class="wrap">
			<h1><?php echo __( 'SAPO Parceiro', 'sapo_partner' ); ?></h1>
			<p><?php echo __( 'Este plugin disponibiliza uma série de definições, e funcionalidades, necessárias a parceiros da rede SAPO.', 'sapo_partner' ) ?></p>
			<form action="options.php" method="POST">
				<?php
				settings_fields( 'sapo_partner_options' );
				do_settings_sections( 'sapo_partner_settings_page' );
				submit_button(); ?>
			</form>
			<div style='font-size:0.85em;'>
				<hr style='width:95%;' />
				<ol>
					<li id='versions'><?php echo __( 'Por omissão, o WordPress revela abertamente a versão instalada dele próprio e diversos componentes que utiliza. Esta informação deve ser omitida dado que é indexada por motores de pesquisa e, se surgirem vulnerabilidades para estas versões no futuro, atacantes poderão encontrar sites vulneráveis com uma simples pesquisa.', 'sapo_partner' ) ?></li>
					<li id='rest-api'><?php echo __( 'A API REST do WordPress fornece uma interface para aplicações interagirem com seu site enviando e recebendo dados de forma programática. Infelizmente, o seu acesso é concedido sem restrições o que pode originar roubo de conteúdos ou fuga de informação sensível. É recomendado que o seu uso seja restringido apenas a utilizadores autenticados.', 'sapo_partner' ) ?></li>
					<li id='xml-rpc'><?php echo __( 'XML-RPC é uma funcionalidade que abre a porta a dados serem enviados à sua instalação de WordPress permitindo, entre outras coisas, a sua gestão remota através de uma aplicação ou notificações de que o seu conteúdo foi referenciado em sítios externos. No entanto, trata-se de uma componente que pode ser abusada por atacantes provocando fugas de informação sensível ou mesmo períodos de indisponibilidade. Por estes motivos, o seu uso não é recomendado.', 'sapo_partner' ) ?></li>
					<li id='robots-txt'><?php echo __( 'O \'robots.txt\' é um ficheiro na raiz do seu site que informa motores de pesquisa sobre qual a estratégia correta para indexar o seu site. Esta opção modifica a resposta por omissão do WordPress ao pedido por este ficheiro quando ele não existe. Se ele existir o seu conteúdo toma precedência e esta configuração <u>não</u> se aplica.' ) ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	function sapo_partner_register_settings() {
		register_setting( 'sapo_partner_options', 'sapo_partner_options', ['type' => 'array' ] );

		add_settings_section(
			'sapo_partner_branding_settings',
			__( 'Marca', 'sapo_partner' ),
			array($this, 'sapo_partner_branding_section_text'),
			'sapo_partner_settings_page'
		);
		add_settings_field(
			'sapo_partner_show_bsu',
			__( 'Mostrar barra SAPO', 'sapo_partner' ),
			array($this, 'sapo_partner_add_checkbox'),
			'sapo_partner_settings_page',
			'sapo_partner_branding_settings',
			array('show_bsu')
		);

		add_settings_section(
			'sapo_partner_security_settings',
			__( 'Segurança', 'sapo_partner' ),
			array($this, 'sapo_partner_security_section_text'),
			'sapo_partner_settings_page'
		);
		add_settings_field(
			'sapo_partner_obfuscate_versions',
			__( 'Ofuscar versões', 'sapo_partner' ) . ' <sup><a href="#versions">1</a></sup>',
			array($this, 'sapo_partner_add_checkbox'),
			'sapo_partner_settings_page',
			'sapo_partner_security_settings',
			array('obfuscate_versions')
		);
		add_settings_field(
			'sapo_partner_secure_restapi',
			__( 'Proteger API REST', 'sapo_partner' ) . ' <sup><a href="#rest-api">2</a></sup>',
			array($this, 'sapo_partner_add_checkbox'),
			'sapo_partner_settings_page',
			'sapo_partner_security_settings',
			array('secure_restapi')
		);
		add_settings_field(
			'sapo_partner_disable_xmlrpc',
			__( 'Desativar XML-RPC', 'sapo_partner' ) . ' <sup><a href="#xml-rpc">3</a></sup>',
			array($this, 'sapo_partner_add_checkbox'),
			'sapo_partner_settings_page',
			'sapo_partner_security_settings',
			array('disable_xmlrpc')
		);

		add_settings_section(
			'sapo_partner_usability_settings',
			__( 'Usabilidade', 'sapo_partner' ),
			array($this, 'sapo_partner_usability_section_text'),
			'sapo_partner_settings_page'
		);
		add_settings_field(
			'sapo_partner_override_robots_txt',
			__( 'Substituir \'robots.txt\'', 'sapo_partner' ) . ' <sup><a href="#robots-txt">4</a></sup>',
			array($this, 'sapo_partner_add_checkbox'),
			'sapo_partner_settings_page',
			'sapo_partner_usability_settings',
			array('override_robots_txt')
		);
		add_settings_field(
			'sapo_partner_robots_txt',
			__( 'Conteúdo \'robots.txt\'', 'sapo_partner' ),
			array($this, 'sapo_partner_add_textarea'),
			'sapo_partner_settings_page',
			'sapo_partner_usability_settings',
			array('robots_txt')
		);
	}

	function sapo_partner_branding_section_text() { }
	function sapo_partner_security_section_text() {
		echo '<p>' . __( 'As seguintes definições fornecem medidas extra de segurança para a sua instalação WordPress.', 'sapo_partner' ) . '</p>';
	}
	function sapo_partner_usability_section_text() {
		echo '<p>' . __( 'As seguintes definições procuram melhorar a usabilidade do seu site para os seus visitantes, e motores de pesquisa.', 'sapo_partner' ) . '</p>';
	}

	function sapo_partner_add_checkbox($args) {
		$option_value = array_key_exists($args[0], $this->options) ? $this->options[$args[0]] : '0' ;
		$checked  = checked( '1', $option_value, false );
		echo '<input type="checkbox" name="sapo_partner_options['. $args[0] .']" value="1" '. $checked .' />';
	}
	function sapo_partner_add_textarea($args) {
		$disabled = array_key_exists('override_'.$args[0], $this->options) ? '' : 'disabled' ;
		echo '<textarea '. $disabled .' name="sapo_partner_options['. $args[0] .']" type="textarea" cols="50" rows="10">'."\n";
		if ( array_key_exists($args[0], $this->options) && $this->options[$args[0]] != "") {
			echo $this->options[$args[0]];
		}
		else {
			echo $this->default_options[$args[0]];
		}
		echo '</textarea>';
	}

	function sapo_partner_handle_update($new_value, $old_value) {
		if ( isset($new_value['robots_txt']) ) {
			$new_value['robots_txt'] = empty($new_value['robots_txt']) ? $this->default_options['robots_txt'] : $new_value['robots_txt'];
		} else {
			$new_value['robots_txt'] = $old_value['robots_txt'];
		}
		$new_value['cache_salt'] = $this->options['cache_salt'];  // preserve the salt without exposing it
		return $new_value;
	}
}

new sapo_partner();

?>
