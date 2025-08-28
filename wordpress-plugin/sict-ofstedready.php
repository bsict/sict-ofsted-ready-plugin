<?php
/**
 * Plugin Name: SICT OfstedReady
 * Plugin URI: https://sict.co.uk/ofstedready
 * Description: AI-powered tool to help schools generate all required online content using Google Gemini API, covering all requirements from gov.uk guidance
 * Version: 0.2.0
 * Author: SICT
 * License: GPL v2 or later
 * Text Domain: sict-ofstedready
 * Domain Path: /languages
 */
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
// Define plugin constants
define('SICT_OR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SICT_OR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SICT_OR_VERSION', '2.0.0');
define('SICT_OR_DB_VERSION', '2.0');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent');

class SICT_OfstedReady {
    private $db_table_name;
    private $rate_limit = 15; // requests per minute
    
    public function __construct() {
        global $wpdb;
        $this->db_table_name = $wpdb->prefix . 'sict_ofstedready_content';
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('SICT_OfstedReady', 'uninstall'));
    }
    
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('sict-ofstedready', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
            add_action('admin_init', array($this, 'admin_init'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        }
        
        // AJAX handlers
        add_action('wp_ajax_sict_generate_policy', array($this, 'generate_policy_ajax'));
        add_action('wp_ajax_sict_save_content', array($this, 'save_content_ajax'));
        add_action('wp_ajax_sict_delete_content', array($this, 'delete_content_ajax'));
        add_action('wp_ajax_sict_load_content', array($this, 'load_content_ajax'));
        add_action('wp_ajax_sict_export_content', array($this, 'export_content_ajax'));
        add_action('wp_ajax_sict_create_post', array($this, 'create_post_ajax'));
    }
    
    public function activate() {
        $this->create_database_table();
        $this->set_default_options();
        
        // Set activation timestamp for analytics
        update_option('sict_or_activated_at', current_time('timestamp'));
        
        // Create uploads directory for exported files
        $upload_dir = wp_upload_dir();
        $sict_dir = $upload_dir['basedir'] . '/sict-ofstedready';
        if (!file_exists($sict_dir)) {
            wp_mkdir_p($sict_dir);
        }
    }
    
    public function deactivate() {
        // Clean up scheduled events if any
        wp_clear_scheduled_hook('sict_or_cleanup_temp_files');
    }
    
    public static function uninstall() {
        // Remove all plugin data if user chooses to delete plugin
        global $wpdb;
        // Remove database table
        $table_name = $wpdb->prefix . 'sict_ofstedready_content';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        // Remove options
        delete_option('sict_or_api_key');
        delete_option('sict_or_api_model');
        delete_option('sict_or_school_name');
        delete_option('sict_or_headteacher_name');
        delete_option('sict_or_school_type');
        delete_option('sict_or_age_range');
        delete_option('sict_or_activated_at');
        delete_option('sict_or_db_version');
    }
    
    public function admin_init() {
        // Register settings
        register_setting('sict_or_settings', 'sict_or_api_key', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('sict_or_settings', 'sict_or_api_model', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('sict_or_settings', 'sict_or_school_name', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('sict_or_settings', 'sict_or_headteacher_name', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('sict_or_settings', 'sict_or_school_type', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('sict_or_settings', 'sict_or_age_range', array('sanitize_callback' => 'sanitize_text_field'));
    }
    
    private function set_default_options() {
        add_option('sict_or_api_key', '');
        add_option('sict_or_api_model', 'gemini-1.5-flash'); // Default to Gemini Flash
        add_option('sict_or_school_name', '');
        add_option('sict_or_headteacher_name', '');
        add_option('sict_or_school_type', 'primary');
        add_option('sict_or_age_range', '4-11');
        add_option('sict_or_db_version', SICT_OR_DB_VERSION);
    }
    
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            __('SICT OfstedReady', 'sict-ofstedready'),
            __('OfstedReady', 'sict-ofstedready'),
            'manage_options',
            'sict-ofstedready',
            array($this, 'admin_page'),
            'dashicons-admin-page',
            30
        );
        
        // Generator submenu (same as main page)
        add_submenu_page(
            'sict-ofstedready',
            __('Content Generator', 'sict-ofstedready'),
            __('Generator', 'sict-ofstedready'),
            'manage_options',
            'sict-ofstedready'
        );
        
        // Settings submenu
        add_submenu_page(
            'sict-ofstedready',
            __('Settings', 'sict-ofstedready'),
            __('Settings', 'sict-ofstedready'),
            'manage_options',
            'sict-ofstedready-settings',
            array($this, 'settings_page')
        );
        
        // History submenu
        add_submenu_page(
            'sict-ofstedready',
            __('Generated Content', 'sict-ofstedready'),
            __('History', 'sict-ofstedready'),
            'manage_options',
            'sict-ofstedready-history',
            array($this, 'history_page')
        );
    }
    
    // In your admin_scripts method, add jsPDF
public function admin_scripts($hook) {
    // Only load on our plugin pages
    if (strpos($hook, 'sict-ofstedready') === false) {
        return;
    }
    
    // Enqueue jsPDF
    wp_enqueue_script(
        'jspdf',
        'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
        array(),
        '2.5.1',
        true
    );
    
    wp_enqueue_script(
        'jspdf-autotable',
        'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js',
        array('jspdf'),
        '3.5.23',
        true
    );
    
    wp_enqueue_script(
        'sict-or-admin-js',
        SICT_OR_PLUGIN_URL . 'assets/admin.js',
        array('jquery', 'jspdf', 'jspdf-autotable'),
        SICT_OR_VERSION,
        true
    );
    
    wp_enqueue_style(
        'sict-or-admin-css',
        SICT_OR_PLUGIN_URL . 'assets/admin.css',
        array(),
        SICT_OR_VERSION
    );
    
    // Localize script for AJAX
    wp_localize_script('sict-or-admin-js', 'sict_or_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('sict_or_nonce'),
        'strings' => array(
            'generating' => __('⏳ Generating content...', 'sict-ofstedready'),
            'error' => __('Error generating content. Please try again.', 'sict-ofstedready'),
            'saved' => __('Content saved successfully!', 'sict-ofstedready'),
            'copied' => __('Content copied to clipboard!', 'sict-ofstedready'),
            'confirm_delete' => __('Are you sure you want to delete this content?', 'sict-ofstedready'),
            'post_created' => __('WordPress post created successfully!', 'sict-ofstedready'),
            'pdf_generated' => __('PDF generated successfully!', 'sict-ofstedready')
        )
    ));
}
    
    public function admin_page() {
        $api_key = get_option('sict_or_api_key');
        ?>
        <div class="wrap sict-or-wrap">
            <div class="sict-or-header">
                <h1><span class="sict-logo">🎓</span> <?php _e('SICT OfstedReady', 'sict-ofstedready'); ?></h1>
                <p class="sict-or-tagline"><?php _e('AI-powered compliance with all gov.uk requirements for maintained schools', 'sict-ofstedready'); ?></p>
            </div>
            
            <?php if (empty($api_key)): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Setup Required:', 'sict-ofstedready'); ?></strong>
                        <?php printf(
                            __('Please configure your Google Gemini API key in <a href="%s">Settings</a> first.', 'sict-ofstedready'),
                            admin_url('admin.php?page=sict-ofstedready-settings')
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="sict-or-main-container">
                <div class="sict-or-form-section">
                    <div class="sict-or-card">
                        <h2><?php _e('Generate School Website Content', 'sict-ofstedready'); ?></h2>
                        <form id="sict-or-generator-form">
                            <?php wp_nonce_field('sict_or_nonce', 'sict_or_nonce'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="policy_type"><?php _e('Content Type', 'sict-ofstedready'); ?></label>
                                    </th>
                                    <td>
                                        <select id="policy_type" name="policy_type" class="regular-text" required>
                                            <option value=""><?php _e('Select a content type...', 'sict-ofstedready'); ?></option>
                                            <optgroup label="<?php _e('Statutory Policies', 'sict-ofstedready'); ?>">
                                                <option value="safeguarding"><?php _e('Safeguarding Policy', 'sict-ofstedready'); ?></option>
                                                <option value="behaviour"><?php _e('Behaviour Policy', 'sict-ofstedready'); ?></option>
                                                <option value="complaints"><?php _e('Complaints Policy', 'sict-ofstedready'); ?></option>
                                                <option value="charging_remissions"><?php _e('Charging & Remissions Policy', 'sict-ofstedready'); ?></option>
                                                <option value="data_protection"><?php _e('Data Protection Policy', 'sict-ofstedready'); ?></option>
                                            </optgroup>
                                            <optgroup label="<?php _e('Admissions', 'sict-ofstedready'); ?>">
                                                <option value="admission_arrangements"><?php _e('Admission Arrangements', 'sict-ofstedready'); ?></option>
                                                <option value="in_year_admissions"><?php _e('In-Year Admissions', 'sict-ofstedready'); ?></option>
                                                <option value="admission_appeals"><?php _e('Admission Appeals', 'sict-ofstedready'); ?></option>
                                            </optgroup>
                                            <optgroup label="<?php _e('Curriculum & Education', 'sict-ofstedready'); ?>">
                                                <option value="curriculum"><?php _e('Curriculum Information', 'sict-ofstedready'); ?></option>
                                                <option value="phonics"><?php _e('Phonics/Reading Schemes', 'sict-ofstedready'); ?></option>
                                                <option value="ks4_courses"><?php _e('Key Stage 4 Courses', 'sict-ofstedready'); ?></option>
                                                <option value="remote_education"><?php _e('Remote Education Provision', 'sict-ofstedready'); ?></option>
                                                <option value="careers_programme"><?php _e('Careers Programme (Years 7-13)', 'sict-ofstedready'); ?></option>
                                            </optgroup>
                                            <optgroup label="<?php _e('Financial & Governance', 'sict-ofstedready'); ?>">
                                                <option value="financial_info"><?php _e('Financial Information', 'sict-ofstedready'); ?></option>
                                                <option value="governance"><?php _e('Governance Information', 'sict-ofstedready'); ?></option>
                                                <option value="pupil_premium"><?php _e('Pupil Premium Strategy', 'sict-ofstedready'); ?></option>
                                                <option value="pe_sport"><?php _e('PE and Sport Premium', 'sict-ofstedready'); ?></option>
                                                <option value="pay_gap"><?php _e('Pay Gap Reporting', 'sict-ofstedready'); ?></option>
                                            </optgroup>
                                            <optgroup label="<?php _e('Other Required Content', 'sict-ofstedready'); ?>">
                                                <option value="ethos"><?php _e('Ethos and Values', 'sict-ofstedready'); ?></option>
                                                <option value="school_uniform"><?php _e('School Uniform Policy', 'sict-ofstedready'); ?></option>
                                                <option value="school_hours"><?php _e('School Opening Hours', 'sict-ofstedready'); ?></option>
                                                <option value="send_report"><?php _e('SEND Information Report', 'sict-ofstedready'); ?></option>
                                                <option value="test_results"><?php _e('Test, Exam & Assessment Results', 'sict-ofstedready'); ?></option>
                                                <option value="equality_duty"><?php _e('Public Sector Equality Duty', 'sict-ofstedready'); ?></option>
                                                <option value="ofsted_report"><?php _e('Ofsted Reports', 'sict-ofstedready'); ?></option>
                                                <option value="contact_details"><?php _e('Contact Details', 'sict-ofstedready'); ?></option>
                                            </optgroup>
                                        </select>
                                        <p class="description"><?php _e('Choose the type of content you need to generate', 'sict-ofstedready'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="output_format"><?php _e('Output Format', 'sict-ofstedready'); ?></label>
                                    </th>
                                    <td>
                                        <select id="output_format" name="output_format" class="regular-text">
                                            <option value="detailed"><?php _e('Detailed Paragraphs', 'sict-ofstedready'); ?></option>
                                            <option value="bullet_points"><?php _e('Bullet Points', 'sict-ofstedready'); ?></option>
                                            <option value="structured"><?php _e('Structured Sections', 'sict-ofstedready'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="complexity_level"><?php _e('Detail Level', 'sict-ofstedready'); ?></label>
                                    </th>
                                    <td>
                                        <select id="complexity_level" name="complexity_level" class="regular-text">
                                            <option value="basic"><?php _e('Basic Overview', 'sict-ofstedready'); ?></option>
                                            <option value="standard" selected><?php _e('Standard Detail', 'sict-ofstedready'); ?></option>
                                            <option value="comprehensive"><?php _e('Comprehensive', 'sict-ofstedready'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="additional_context"><?php _e('Additional Context', 'sict-ofstedready'); ?></label>
                                    </th>
                                    <td>
                                        <textarea id="additional_context" name="additional_context" class="large-text" rows="4" 
                                                  placeholder="<?php _e('Any specific details, requirements, or focus areas for this content...', 'sict-ofstedready'); ?>"></textarea>
                                        <p class="description"><?php _e('Optional: Add any specific requirements or context for your school', 'sict-ofstedready'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <input type="button" id="generate_btn" class="button-primary button-large" value="<?php _e('Generate Content', 'sict-ofstedready'); ?>" <?php echo empty($api_key) ? 'disabled' : ''; ?> />
                                <span id="loading" class="sict-or-loading" style="display: none;">
                                    <span class="spinner is-active"></span>
                                    <span class="loading-text"><?php _e('Generating content...', 'sict-ofstedready'); ?></span>
                                </span>
                            </p>
                        </form>
                    </div>
                </div>
                <div class="sict-or-output-section" id="output_section" style="display: none;">
                    <div class="sict-or-card">
                        <h2><?php _e('Generated Content', 'sict-ofstedready'); ?></h2>
                        <div class="sict-or-content-header">
                            <div class="sict-or-content-meta">
                                <span class="policy-type-badge" id="generated_policy_type"></span>
                                <span class="generation-time" id="generation_time"></span>
                            </div>
                            <div class="sict-or-content-actions">
                                <button type="button" id="save_content" class="button-secondary">
                                    <span class="dashicons dashicons-saved"></span> <?php _e('Save', 'sict-ofstedready'); ?>
                                </button>
                                <button type="button" id="copy_content" class="button-secondary">
                                    <span class="dashicons dashicons-clipboard"></span> <?php _e('Copy', 'sict-ofstedready'); ?>
                                </button>
                                <div class="sict-or-export-dropdown">
                                    <button type="button" class="button-secondary dropdown-toggle">
                                        <span class="dashicons dashicons-download"></span> <?php _e('Export', 'sict-ofstedready'); ?>
                                    </button>
                                    <div class="sict-or-export-menu" style="display: none;">
                                        <button type="button" class="button-secondary export-option" data-format="pdf" data-action="export">
                                            <span class="dashicons dashicons-media-document"></span> <?php _e('PDF Document', 'sict-ofstedready'); ?>
                                        </button>
                                        <button type="button" class="button-secondary export-option" data-format="doc" data-action="export">
                                            <span class="dashicons dashicons-media-document"></span> <?php _e('Word Document', 'sict-ofstedready'); ?>
                                        </button>
                                        <button type="button" class="button-secondary export-option" data-format="txt" data-action="export">
                                            <span class="dashicons dashicons-media-text"></span> <?php _e('Plain Text', 'sict-ofstedready'); ?>
                                        </button>
                                        <button type="button" class="button-secondary export-option" data-format="post" data-action="create_post">
                                            <span class="dashicons dashicons-admin-post"></span> <?php _e('Create WordPress Post', 'sict-ofstedready'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="generated_content" class="sict-or-generated-content"></div>
                        <div class="sict-or-disclaimer">
                            <p><strong><?php _e('Important:', 'sict-ofstedready'); ?></strong> 
                            <?php _e('This content is AI-generated by Google Gemini and should be reviewed by qualified staff before implementation. Always ensure compliance with current gov.uk requirements and your school\'s specific needs.', 'sict-ofstedready'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            // Verify nonce
            if (wp_verify_nonce($_POST['_wpnonce'], 'sict_or_settings-options')) {
                update_option('sict_or_api_key', sanitize_text_field($_POST['sict_or_api_key']));
                update_option('sict_or_api_model', sanitize_text_field($_POST['sict_or_api_model']));
                update_option('sict_or_school_name', sanitize_text_field($_POST['sict_or_school_name']));
                update_option('sict_or_headteacher_name', sanitize_text_field($_POST['sict_or_headteacher_name']));
                update_option('sict_or_school_type', sanitize_text_field($_POST['sict_or_school_type']));
                update_option('sict_or_age_range', sanitize_text_field($_POST['sict_or_age_range']));
                echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'sict-ofstedready') . '</p></div>';
            }
        }
        ?>
        <div class="wrap sict-or-wrap">
            <div class="sict-or-header">
                <h1><?php _e('SICT OfstedReady Settings', 'sict-ofstedready'); ?></h1>
            </div>
            <div class="sict-or-settings-container">
                <form method="post" action="">
                    <?php settings_fields('sict_or_settings'); ?>
                    <div class="sict-or-card">
                        <h2><?php _e('API Configuration', 'sict-ofstedready'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="sict_or_api_key"><?php _e('Google Gemini API Key', 'sict-ofstedready'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="sict_or_api_key" name="sict_or_api_key" 
                                           value="<?php echo esc_attr(get_option('sict_or_api_key')); ?>" 
                                           class="regular-text" autocomplete="new-password" />
                                    <p class="description">
                                        <?php printf(
                                            __('Enter your Google Gemini API key. Get one from <a href="%s" target="_blank">Google AI Studio</a>', 'sict-ofstedready'),
                                            'https://aistudio.google.com/app/apikey'
                                        ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="sict_or_api_model"><?php _e('AI Model', 'sict-ofstedready'); ?></label>
                                </th>
                                <td>
                                    <select id="sict_or_api_model" name="sict_or_api_model" class="regular-text">
                                        <option value="gemini-1.5-flash" <?php selected(get_option('sict_or_api_model'), 'gemini-1.5-flash'); ?>>
                                            <?php _e('Gemini Flash 1.5 (Fast, Recommended)', 'sict-ofstedready'); ?>
                                        </option>
                                        <option value="gemini-1.5-pro" <?php selected(get_option('sict_or_api_model'), 'gemini-1.5-pro'); ?>>
                                            <?php _e('Gemini Pro 1.5 (More Detailed)', 'sict-ofstedready'); ?>
                                        </option>
                                        <option value="gemini-1.0-pro" <?php selected(get_option('sict_or_api_model'), 'gemini-1.0-pro'); ?>>
                                            <?php _e('Gemini Pro 1.0 (Legacy)', 'sict-ofstedready'); ?>
                                        </option>
                                    </select>
                                    <p class="description"><?php _e('Choose the Gemini model that best fits your needs. Flash is faster and cheaper, Pro is more detailed.', 'sict-ofstedready'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="sict-or-card">
                        <h2><?php _e('School Information', 'sict-ofstedready'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="sict_or_school_name"><?php _e('School Name', 'sict-ofstedready'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="sict_or_school_name" name="sict_or_school_name" 
                                           value="<?php echo esc_attr(get_option('sict_or_school_name')); ?>" 
                                           class="regular-text" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="sict_or_headteacher_name"><?php _e('Headteacher Name', 'sict-ofstedready'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="sict_or_headteacher_name" name="sict_or_headteacher_name" 
                                           value="<?php echo esc_attr(get_option('sict_or_headteacher_name')); ?>" 
                                           class="regular-text" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="sict_or_school_type"><?php _e('School Type', 'sict-ofstedready'); ?></label>
                                </th>
                                <td>
                                    <select id="sict_or_school_type" name="sict_or_school_type" class="regular-text">
                                        <option value="primary" <?php selected(get_option('sict_or_school_type'), 'primary'); ?>><?php _e('Primary School', 'sict-ofstedready'); ?></option>
                                        <option value="secondary" <?php selected(get_option('sict_or_school_type'), 'secondary'); ?>><?php _e('Secondary School', 'sict-ofstedready'); ?></option>
                                        <option value="special" <?php selected(get_option('sict_or_school_type'), 'special'); ?>><?php _e('Special School', 'sict-ofstedready'); ?></option>
                                        <option value="nursery" <?php selected(get_option('sict_or_school_type'), 'nursery'); ?>><?php _e('Nursery School', 'sict-ofstedready'); ?></option>
                                        <option value="all_through" <?php selected(get_option('sict_or_school_type'), 'all_through'); ?>><?php _e('All-Through School', 'sict-ofstedready'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="sict_or_age_range"><?php _e('Age Range', 'sict-ofstedready'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="sict_or_age_range" name="sict_or_age_range" 
                                           value="<?php echo esc_attr(get_option('sict_or_age_range')); ?>" 
                                           class="regular-text" placeholder="e.g., 4-11, 11-16, 3-19" />
                                    <p class="description"><?php _e('Age range of pupils in your school', 'sict-ofstedready'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php submit_button(__('Save Settings', 'sict-ofstedready'), 'primary', 'submit', true, array('class' => 'button-large')); ?>
                </form>
                <div class="sict-or-card sict-or-api-info">
                    <h3><?php _e('About Google Gemini API', 'sict-ofstedready'); ?></h3>
                    <p><?php _e('Google Gemini is a state-of-the-art AI model designed for high-quality text generation with excellent reasoning capabilities. It\'s ideal for creating professional educational content.', 'sict-ofstedready'); ?></p>
                    <ul>
                        <li><?php _e('✅ Fast response times', 'sict-ofstedready'); ?></li>
                        <li><?php _e('✅ Generous free tier (60 requests/minute)', 'sict-ofstedready'); ?></li>
                        <li><?php _e('✅ UK data processing available', 'sict-ofstedready'); ?></li>
                        <li><?php _e('✅ Excellent for long-form content', 'sict-ofstedready'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function history_page() {
        global $wpdb;
        // Handle delete request
        if (isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_content_' . $_GET['delete'])) {
            $deleted = $wpdb->delete($this->db_table_name, array('id' => intval($_GET['delete'])), array('%d'));
            if ($deleted) {
                echo '<div class="notice notice-success"><p>' . __('Content deleted successfully.', 'sict-ofstedready') . '</p></div>';
            }
        }
        
        // Get saved content
        $saved_content = $wpdb->get_results("SELECT * FROM {$this->db_table_name} ORDER BY created_at DESC LIMIT 50");
        ?>
        <div class="wrap sict-or-wrap">
            <div class="sict-or-header">
                <h1><?php _e('Generated Content History', 'sict-ofstedready'); ?></h1>
                <p><?php _e('View and manage your previously generated policies and content.', 'sict-ofstedready'); ?></p>
            </div>
            <div class="sict-or-history-container">
                <?php if (empty($saved_content)): ?>
                    <div class="sict-or-empty-state">
                        <div class="dashicons dashicons-admin-page"></div>
                        <h3><?php _e('No content generated yet', 'sict-ofstedready'); ?></h3>
                        <p><?php _e('Start by generating your first content using the main generator.', 'sict-ofstedready'); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=sict-ofstedready'); ?>" class="button-primary">
                            <?php _e('Generate Content', 'sict-ofstedready'); ?>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="sict-or-history-grid">
                        <?php foreach ($saved_content as $content): ?>
                            <div class="sict-or-history-item">
                                <div class="history-item-header">
                                    <h3><?php echo esc_html(ucwords(str_replace('_', ' ', $content->content_type))); ?></h3>
                                    <span class="history-item-date"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($content->created_at)); ?></span>
                                </div>
                                <div class="history-item-content">
                                    <?php echo wp_trim_words(wp_strip_all_tags($content->content), 30); ?>
                                </div>
                                <div class="history-item-actions">
                                    <button type="button" class="button-secondary view-content" data-content-id="<?php echo $content->id; ?>" data-content-type="<?php echo esc_attr($content->content_type); ?>">
                                        <?php _e('View', 'sict-ofstedready'); ?>
                                    </button>
                                    <a href="<?php echo wp_nonce_url(add_query_arg('delete', $content->id), 'delete_content_' . $content->id); ?>" 
                                       class="button-link-delete" 
                                       onclick="return confirm('<?php _e('Are you sure you want to delete this content?', 'sict-ofstedready'); ?>')">
                                        <?php _e('Delete', 'sict-ofstedready'); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public function generate_policy_ajax() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'sict_or_nonce')) {
            wp_send_json_error(__('Security check failed', 'sict-ofstedready'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sict-ofstedready'));
        }
        
        // Add rate limit check
        $rate_check = $this->check_rate_limit();
        if (is_wp_error($rate_check)) {
            wp_send_json_error($rate_check->get_error_message());
        }
        
        $content_type = sanitize_text_field($_POST['policy_type']);
        $output_format = sanitize_text_field($_POST['output_format']);
        $complexity_level = sanitize_text_field($_POST['complexity_level']);
        $additional_context = sanitize_textarea_field($_POST['additional_context']);
        
        // Validate inputs
        if (empty($content_type)) {
            wp_send_json_error(__('Please select a content type', 'sict-ofstedready'));
        }
        
        // Generate content via Google Gemini
        $generated_content = $this->call_gemini_api($content_type, $output_format, $complexity_level, $additional_context);
        
        if ($generated_content && !is_wp_error($generated_content)) {
            wp_send_json_success(array(
                'content' => $generated_content,
                'content_type' => $content_type,
                'timestamp' => current_time('mysql')
            ));
        } else {
            $error_message = is_wp_error($generated_content) ? $generated_content->get_error_message() : __('Failed to generate content', 'sict-ofstedready');
            wp_send_json_error($error_message);
        }
    }
    
    public function save_content_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'sict_or_nonce')) {
            wp_send_json_error(__('Security check failed', 'sict-ofstedready'));
        }
        
        global $wpdb;
        $content_type = sanitize_text_field($_POST['policy_type']);
        $content = wp_kses_post($_POST['content']);
        
        // Check if table exists and has correct structure
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->db_table_name}'");
        if (!$table_exists) {
            $this->create_database_table();
        }
        
        // Verify table structure
        $columns = $wpdb->get_col("DESCRIBE {$this->db_table_name}");
        if (!in_array('content_type', $columns)) {
            // Recreate table with correct structure
            $this->create_database_table();
        }
        
        $result = $wpdb->insert(
            $this->db_table_name,
            array(
                'content_type' => $content_type,
                'content' => $content,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
        
        if ($result !== false) {
            wp_send_json_success(__('Content saved successfully!', 'sict-ofstedready'));
        } else {
            // Log the error for debugging
            error_log('Failed to save content: ' . $wpdb->last_error);
            wp_send_json_error(__('Failed to save content: ' . $wpdb->last_error, 'sict-ofstedready'));
        }
    }
    
    public function delete_content_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'sict_or_nonce')) {
            wp_send_json_error(__('Security check failed', 'sict-ofstedready'));
        }
        
        global $wpdb;
        $content_id = intval($_POST['content_id']);
        $result = $wpdb->delete($this->db_table_name, array('id' => $content_id), array('%d'));
        
        if ($result !== false) {
            wp_send_json_success(__('Content deleted successfully!', 'sict-ofstedready'));
        } else {
            wp_send_json_error(__('Failed to delete content', 'sict-ofstedready'));
        }
    }
    
    public function load_content_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'sict_or_nonce')) {
            wp_send_json_error(__('Security check failed', 'sict-ofstedready'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sict-ofstedready'));
        }
        
        $content_id = intval($_POST['content_id']);
        global $wpdb;
        
        $content = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->db_table_name} WHERE id = %d",
            $content_id
        ));
        
        if ($content) {
            wp_send_json_success(array(
                'content' => $content->content,
                'content_type' => $content->content_type,
                'created_at' => $content->created_at
            ));
        } else {
            wp_send_json_error(__('Content not found', 'sict-ofstedready'));
        }
    }
    
    public function export_content_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'sict_or_nonce')) {
            wp_send_json_error(__('Security check failed', 'sict-ofstedready'));
        }
        
        $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';
        $content_type = isset($_POST['content_type']) ? sanitize_text_field($_POST['content_type']) : '';
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : '';
        
        // Validate inputs
        if (empty($content)) {
            wp_send_json_error(__('No content to export', 'sict-ofstedready'));
        }
        
        if (empty($format)) {
            wp_send_json_error(__('Invalid export format', 'sict-ofstedready'));
        }
        
        $school_name = get_option('sict_or_school_name', 'Our School');
        $headteacher_name = get_option('sict_or_headteacher_name', 'Headteacher');
        $current_date = date('Y-m-d');
        $filename = sanitize_title($content_type) . '-' . $current_date;
        
        switch ($format) {
            case 'pdf':
                $this->export_to_pdf($content, $content_type, $school_name, $headteacher_name, $current_date);
                break;
                
            case 'doc':
                $this->export_to_word($content, $content_type, $school_name, $headteacher_name, $current_date, $filename);
                break;
                
            case 'txt':
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                echo wp_strip_all_tags($content);
                exit;
                
            default:
                wp_send_json_error(__('Invalid export format', 'sict-ofstedready'));
        }
    }
    
    private function export_to_pdf($content, $content_type, $school_name, $headteacher_name, $current_date) {
        // Create a temporary file
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/sict-ofstedready';
        
        // Ensure directory exists
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $filename = sanitize_title($content_type) . '-' . $current_date . '.html';
        $temp_file = $temp_dir . '/' . $filename;
        
        // Create the HTML content
        $html = $this->get_pdf_html($content, $content_type, $school_name, $headteacher_name, $current_date);
        
        // Write to file
        if (file_put_contents($temp_file, $html) === false) {
            wp_send_json_error(__('Failed to create temporary file', 'sict-ofstedready'));
        }
        
        // Return the URL for the user to open and print as PDF
        $temp_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $temp_file);
        
        wp_send_json_success(array(
            'url' => $temp_url,
            'message' => __('Open the link to save as PDF. Use your browser\'s Print > Save as PDF function.', 'sict-ofstedready')
        ));
    }
    
    private function get_pdf_html($content, $content_type, $school_name, $headteacher_name, $current_date) {
        $content_title = ucwords(str_replace('_', ' ', $content_type));
        return "<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <title>{$school_name} - {$content_title}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #0066cc;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .school-name {
            font-size: 24px;
            font-weight: bold;
            color: #0066cc;
            margin: 0;
        }
        .document-title {
            font-size: 20px;
            margin: 10px 0 5px 0;
            color: #333;
        }
        .info-row {
            font-size: 14px;
            color: #666;
            margin: 5px 0;
        }
        h1, h2, h3 {
            color: #0066cc;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-top: 25px;
        }
        h1 {
            font-size: 22px;
            margin-bottom: 15px;
        }
        h2 {
            font-size: 18px;
            margin-bottom: 12px;
        }
        h3 {
            font-size: 16px;
            margin-bottom: 10px;
        }
        p {
            margin: 10px 0;
        }
        ul, ol {
            margin: 10px 0 10px 20px;
        }
        .footer {
            text-align: center;
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
        .disclaimer {
            background: #f8f9fa;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class='header'>
        <h1 class='school-name'>{$school_name}</h1>
        <div class='document-title'>{$content_title}</div>
        <div class='info-row'>Prepared for: {$headteacher_name}</div>
        <div class='info-row'>Date: {$current_date}</div>
    </div>
    <div>{$content}</div>
    <div class='disclaimer'>
        <strong>Important:</strong> This content is AI-generated and should be reviewed by qualified staff before implementation. 
        Always ensure compliance with current gov.uk requirements and your school's specific needs.
    </div>
    <div class='footer'>
        <p>This document was generated using SICT OfstedReady, an AI-powered tool for schools.</p>
    </div>
</body>
</html>";
    }
    
    private function export_to_word($content, $content_type, $school_name, $headteacher_name, $current_date, $filename) {
        $content_title = ucwords(str_replace('_', ' ', $content_type));
        $html = "<html xmlns:v='urn:schemas-microsoft-com:vml'
                    xmlns:o='urn:schemas-microsoft-com:office:office'
                    xmlns:w='urn:schemas-microsoft-com:office:word'
                    xmlns='http://www.w3.org/TR/REC-html40'>
                  <head>
                    <meta charset='utf-8'>
                    <title>{$school_name} - {$content_title}</title>
                    <xml>
                      <w:WordDocument>
                        <w:View>Print</w:View>
                        <w:Zoom>100</w:Zoom>
                        <w:TrackMoves>false</w:TrackMoves>
                        <w:TrackFormatting/>
                        <w:PunctuationKerning/>
                        <w:DrawingGridVerticalSpacing>6 pt</w:DrawingGridVerticalSpacing>
                        <w:DisplayHorizontalDrawingGridEvery>0</w:DisplayHorizontalDrawingGridEvery>
                        <w:DisplayVerticalDrawingGridEvery>2</w:DisplayVerticalDrawingGridEvery>
                        <w:ValidateAgainstSchemas/>
                        <w:SaveIfXMLInvalid>false</w:SaveIfXMLInvalid>
                        <w:IgnoreMixedContent>false</w:IgnoreMixedContent>
                        <w:AlwaysShowPlaceholderText>false</w:AlwaysShowPlaceholderText>
                        <w:DoNotPromoteQF/>
                        <w:LidThemeOther>EN-US</w:LidThemeOther>
                        <w:LidThemeAsian>X-NONE</w:LidThemeAsian>
                        <w:LidThemeComplexScript>X-NONE</w:LidThemeComplexScript>
                        <w:Compatibility>
                          <w:SpaceForUL/>
                          <w:BalanceSingleByteDoubleByteWidth/>
                          <w:DoNotLeaveBackslashAlone/>
                          <w:ULTrailSpace/>
                          <w:DoNotExpandShiftReturn/>
                          <w:AdjustLineHeightInTable/>
                          <w:BreakWrappedTables/>
                          <w:SnapToGridInCell/>
                          <w:WrapTextWithPunct/>
                          <w:UseAsianBreakRules/>
                          <w:DontGrowAutofit/>
                          <w:SplitPgBreakAndParaMark/>
                          <w:EnableOpenTypeKerning/>
                          <w:DontFlipMirrorIndents/>
                          <w:OverrideTableStyleHps/>
                          <w:UseFELayout/>
                        </w:Compatibility>
                        <w:BrowserLevel>MicrosoftInternetExplorer4</w:BrowserLevel>
                        <m:mathPr>
                          <m:mathFont m:val='Cambria Math'/>
                          <m:brkBin m:val='before'/>
                          <m:brkBinSub m:val='--'/>
                          <m:smallFrac m:val='off'/>
                          <m:dispDef/>
                          <m:lMargin m:val='0'/>
                          <m:rMargin m:val='0'/>
                          <m:defJc m:val='centerGroup'/>
                          <m:wrapIndent m:val='1440'/>
                          <m:intLim m:val='subSup'/>
                          <m:naryLim m:val='undOvr'/>
                        </m:mathPr></xml>
                    <style>
                        body {
                            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                            line-height: 1.6;
                            color: #333;
                            margin: 40px;
                        }
                        .header {
                            text-align: center;
                            border-bottom: 3px solid #0066cc;
                            padding-bottom: 20px;
                            margin-bottom: 30px;
                        }
                        .school-name {
                            font-size: 24px;
                            font-weight: bold;
                            color: #0066cc;
                            margin: 0;
                        }
                        .document-title {
                            font-size: 20px;
                            margin: 10px 0 5px 0;
                            color: #333;
                        }
                        .info-row {
                            font-size: 14px;
                            color: #666;
                            margin: 5px 0;
                        }
                        h1, h2, h3 {
                            color: #0066cc;
                            border-bottom: 1px solid #ddd;
                            padding-bottom: 5px;
                            margin-top: 25px;
                        }
                        h1 {
                            font-size: 22px;
                            margin-bottom: 15px;
                        }
                        h2 {
                            font-size: 18px;
                            margin-bottom: 12px;
                        }
                        h3 {
                            font-size: 16px;
                            margin-bottom: 10px;
                        }
                        p {
                            margin: 10px 0;
                        }
                        ul, ol {
                            margin: 10px 0 10px 20px;
                        }
                        .footer {
                            text-align: center;
                            margin-top: 50px;
                            padding-top: 20px;
                            border-top: 1px solid #ddd;
                            font-size: 12px;
                            color: #666;
                        }
                        .disclaimer {
                            background: #f8f9fa;
                            border-left: 4px solid #ffc107;
                            padding: 15px;
                            margin: 20px 0;
                            font-size: 14px;
                        }
                    </style>
                  </head>
                  <body>
                    <div class='header'>
                        <h1 class='school-name'>{$school_name}</h1>
                        <div class='document-title'>{$content_title}</div>
                        <div class='info-row'>Prepared for: {$headteacher_name}</div>
                        <div class='info-row'>Date: {$current_date}</div>
                    </div>
                    <div>{$content}</div>
                    <div class='disclaimer'>
                        <strong>Important:</strong> This content is AI-generated and should be reviewed by qualified staff before implementation. 
                        Always ensure compliance with current gov.uk requirements and your school's specific needs.
                    </div>
                    <div class='footer'>
                        <p>This document was generated using SICT OfstedReady, an AI-powered tool for schools.</p>
                    </div>
                  </body></html>";
        
        header('Content-Type: application/vnd.ms-word');
        header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $html;
        exit;
    }
    
    public function create_post_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'sict_or_nonce')) {
            wp_send_json_error(__('Security check failed', 'sict-ofstedready'));
        }
        
        if (!current_user_can('publish_posts')) {
            wp_send_json_error(__('Insufficient permissions to create posts', 'sict-ofstedready'));
        }
        
        $content = wp_kses_post($_POST['content']);
        $content_type = sanitize_text_field($_POST['content_type']);
        $school_name = get_option('sict_or_school_name', 'Our School');
        
        $post_title = ucwords(str_replace('_', ' ', $content_type)) . ' - ' . $school_name;
        $post_content = $content . "
<div class='ofstedready-disclaimer'><strong>Important:</strong> This content is AI-generated and should be reviewed by qualified staff before implementation. Always ensure compliance with current gov.uk requirements and your school's specific needs.</div>";
        
        $post_id = wp_insert_post(array(
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_status' => 'draft', // Start as draft for review
            'post_type' => 'post',
            'post_author' => get_current_user_id(),
            'tax_input' => array(
                'post_tag' => array('gov-uk', 'school-content', $content_type)
            )
        ));
        
        if ($post_id && !is_wp_error($post_id)) {
            wp_send_json_success(array(
                'message' => __('WordPress post created successfully!', 'sict-ofstedready'),
                'post_id' => $post_id,
                'edit_url' => get_edit_post_link($post_id, 'url')
            ));
        } else {
            wp_send_json_error(__('Failed to create WordPress post', 'sict-ofstedready'));
        }
    }
    
    private function call_gemini_api($content_type, $output_format, $complexity_level, $additional_context) {
        $api_key = get_option('sict_or_api_key');
        $model = get_option('sict_or_api_model', 'gemini-1.5-flash');
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Google Gemini API key not configured. Please enter your key in Settings.', 'sict-ofstedready'));
        }
        
        // Build the prompt
        $prompt = $this->build_enhanced_prompt($content_type, $output_format, $complexity_level, $additional_context);
        
        // Prepare API request for Gemini
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
        $headers = array(
            'Content-Type' => 'application/json',
        );
        
        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $prompt
                        )
                    )
                )
            ),
            'generationConfig' => array(
                'maxOutputTokens' => $this->get_max_tokens($complexity_level),
                'temperature' => 0.3,
                'topP' => 0.9,
                'stopSequences' => array()
            ),
            'safetySettings' => array(
                array(
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_ONLY_HIGH'
                ),
                array(
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_ONLY_HIGH'
                ),
                array(
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_ONLY_HIGH'
                ),
                array(
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_ONLY_HIGH'
                )
            )
        );
        
        // Make API request
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 60,
            'data_format' => 'body',
            'blocking' => true,
            'redirection' => 5,
            'httpversion' => '1.1',
            'sslverify' => true
        ));
        
        // Handle response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Gemini API Error: ' . $error_message);
            return new WP_Error('api_request_failed', __('API request failed: ', 'sict-ofstedready') . $error_message);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('Gemini API Response Code: ' . $response_code);
        error_log('Gemini API Response Body: ' . $response_body);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 
                            (isset($error_data['error']) ? $error_data['error'] : 
                            __('API request failed with status code: ', 'sict-ofstedready') . $response_code);
            return new WP_Error('api_error', $error_message);
        }
        
        $data = json_decode($response_body, true);
        
        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return new WP_Error('invalid_response', __('Invalid API response format. Response: ', 'sict-ofstedready') . $response_body);
        }
        
        return $data['candidates'][0]['content']['parts'][0]['text'];
    }
    
    private function build_enhanced_prompt($content_type, $output_format, $complexity_level, $additional_context) {
        $school_name = get_option('sict_or_school_name', '[School Name]');
        $headteacher_name = get_option('sict_or_headteacher_name', '[Headteacher Name]');
        $school_type = get_option('sict_or_school_type', 'primary');
        $age_range = get_option('sict_or_age_range', '4-11');
        
        // Enhanced content templates with specific gov.uk guidance references
        $enhanced_content_templates = array(
            'admission_arrangements' => array(
                'title' => 'Admission Arrangements',
                'description' => 'Create comprehensive admission arrangements for parents, including how the school considers applications, published admission numbers, application process, and place allocation criteria.',
                'key_sections' => 'Normal point of entry admissions, Published admission numbers, Application process, Place allocation criteria, Selective admission procedures, Deferred entry requests',
                'gov_uk_requirements' => 'Must comply with School Information (England) Regulations 2008, as amended. Must be published by 15 March each year for September admissions.',
                'quality_criteria' => 'Clear explanation of admission criteria, Specific details on application process, Transparent place allocation methods, Information on appeals process'
            ),
            'in_year_admissions' => array(
                'title' => 'In-Year Admissions',
                'description' => 'Create information about managing in-year applications for places, including application forms and supplementary information requirements.',
                'key_sections' => 'In-year application process, Application form availability, Supplementary information requirements, Local authority coordination scheme',
                'gov_uk_requirements' => 'Must be published by 31 August each year. Must include application form if governing body manages applications.',
                'quality_criteria' => 'Clear application timeline, Complete application requirements, Information on decision timelines, Appeals process details'
            ),
            'admission_appeals' => array(
                'title' => 'Admission Appeals',
                'description' => 'Create a timetable for organizing and hearing admission appeals with all required deadlines and notice periods.',
                'key_sections' => 'Appeal timetable, Deadline for lodging appeals, Evidence submission deadlines, Appeal hearing notice period, Decision notification timeline',
                'gov_uk_requirements' => 'Must be published by 28 February each year. Must include at least 20 school days for appeal preparation and 10 school days notice of hearing.',
                'quality_criteria' => 'Comprehensive timeline, Clear deadlines, Adequate notice periods, Transparent decision process'
            ),
            'curriculum' => array(
                'title' => 'Curriculum Information',
                'description' => 'Create detailed information about the curriculum content for each academic year and subject, including religious education and accessibility plans.',
                'key_sections' => 'Curriculum content by year and subject, Religious education details, Right to withdraw from RE, Accessibility plan, Curriculum enrichment opportunities',
                'gov_uk_requirements' => 'Must publish curriculum content for every subject in each academic year. Must include accessibility plan for disabled pupils.',
                'quality_criteria' => 'Complete subject coverage, Year-by-year breakdown, RE specifics, Accessibility provisions, Parent engagement information'
            ),
            'phonics' => array(
                'title' => 'Phonics and Reading Schemes',
                'description' => 'List the phonics and reading schemes used in the school for early years and key stage 1.',
                'key_sections' => 'Phonics programmes used, Reading schemes available, Assessment methods, Parent support resources',
                'gov_uk_requirements' => 'Required for schools with key stage 1 provision. Must list all phonics and reading schemes used.',
                'quality_criteria' => 'Complete list of programmes, Implementation details, Assessment approach, Parent guidance'
            ),
            'ks4_courses' => array(
                'title' => 'Key Stage 4 Courses',
                'description' => 'List all key stage 4 courses offered by the school, including GCSEs and other qualifications.',
                'key_sections' => 'GCSE subjects offered, Vocational qualifications, BTEC courses, Alternative qualifications, Course selection guidance',
                'gov_uk_requirements' => 'Required for schools with key stage 4 provision. Must list all key stage 4 courses offered.',
                'quality_criteria' => 'Complete course listing, Course descriptions, Assessment methods, Progression opportunities'
            ),
            'financial_info' => array(
                'title' => 'Financial Information',
                'description' => 'Create financial information including high-earning staff counts and links to financial benchmarking service.',
                'key_sections' => 'High-earning staff counts (by £10,000 bandings), Link to schools financial benchmarking service, Financial transparency statement',
                'gov_uk_requirements' => 'Must publish number of employees earning over £100,000 in £10,000 bandings. Must include link to financial benchmarking service.',
                'quality_criteria' => 'Accurate salary band reporting, Direct benchmarking link, Financial transparency'
            ),
            'governance' => array(
                'title' => 'Governance Information',
                'description' => 'Create comprehensive information about the school\'s governing body, including structure, membership, and diversity data.',
                'key_sections' => 'Governing body structure, Governor appointments and terms, Attendance records, Business interests, Diversity data, Committee information',
                'gov_uk_requirements' => 'Must publish information about governing body constitution. Should publish diversity data and detailed governor information.',
                'quality_criteria' => 'Complete governance structure, Transparent appointments, Attendance transparency, Interest declarations, Diversity reporting'
            ),
            'pupil_premium' => array(
                'title' => 'Pupil Premium Strategy',
                'description' => 'Create a strategy statement explaining how pupil premium funding is being spent and the impact on disadvantaged pupils.',
                'key_sections' => 'Funding allocation, Spending priorities, Impact measurement, Review timeline, DfE template compliance',
                'gov_uk_requirements' => 'Must publish strategy statement by 31 December each year using DfE template. Must explain spending and impact.',
                'quality_criteria' => 'Clear funding breakdown, Specific spending plans, Impact measurement methods, Template compliance'
            ),
            'pe_sport' => array(
                'title' => 'PE and Sport Premium',
                'description' => 'Create information about PE and sport premium funding usage, impact on pupil participation, and sustainability plans.',
                'key_sections' => 'Funding amount received, Breakdown of spending, Impact on participation and attainment, Sustainability plans, Swimming attainment data',
                'gov_uk_requirements' => 'Must publish by 31 July each year. Must include swimming attainment data for Year 6 pupils.',
                'quality_criteria' => 'Complete funding details, Detailed spending breakdown, Measurable impact data, Sustainability planning, Swimming standards'
            ),
            'pay_gap' => array(
                'title' => 'Pay Gap Reporting',
                'description' => 'Create gender and ethnicity pay gap information for schools with 250+ employees, including supporting narratives and action plans.',
                'key_sections' => 'Gender pay gap data, Ethnicity pay gap analysis, Supporting narrative, Action plans, Data collection methods',
                'gov_uk_requirements' => 'Required for schools with 250+ employees. Must publish within one year of 31 March snapshot date.',
                'quality_criteria' => 'Accurate pay gap data, Comprehensive analysis, Actionable improvement plans, Transparent methodology'
            ),
            'ethos' => array(
                'title' => 'Ethos and Values',
                'description' => 'Create a statement setting out the school\'s ethos and values that guides its operation and community.',
                'key_sections' => 'School vision and mission, Core values, Ethos implementation, Community engagement, Character development',
                'gov_uk_requirements' => 'Recommended for all schools to publish. Should reflect the school\'s distinctive character and values.',
                'quality_criteria' => 'Clear vision statement, Defined core values, Practical implementation examples, Community focus'
            ),
            'school_uniform' => array(
                'title' => 'School Uniform Policy',
                'description' => 'Create a comprehensive school uniform policy including required items, branding requirements, and purchasing information.',
                'key_sections' => 'Required uniform items, Branded vs generic items, Seasonal variations, Purchasing options, Second-hand availability, Cost considerations',
                'gov_uk_requirements' => 'Recommended for schools with uniform requirements. Should include cost-saving options and accessibility considerations.',
                'quality_criteria' => 'Complete item listing, Branding specifications, Cost transparency, Accessibility provisions, Sustainability options'
            ),
            'school_hours' => array(
                'title' => 'School Opening Hours',
                'description' => 'Create information about the official start and end times of the compulsory school day and total weekly hours.',
                'key_sections' => 'Daily start time, Daily end time, Total weekly hours (including breaks), Term dates, Holiday schedules',
                'gov_uk_requirements' => 'Recommended for all schools to publish. Should include total weekly hours of compulsory education.',
                'quality_criteria' => 'Precise timing information, Complete weekly breakdown, Term structure details, Holiday information'
            ),
            'send_report' => array(
                'title' => 'SEND Information Report',
                'description' => 'Create a comprehensive report on special educational needs and disabilities provision, including admission arrangements and accessibility plans.',
                'key_sections' => 'SEN information as per Schedule 1, Admission arrangements for disabled pupils, Anti-discrimination measures, Accessibility plan, Support services',
                'gov_uk_requirements' => 'Must publish annually. Must contain information specified in Schedule 1 to the SEND Regulations 2014.',
                'quality_criteria' => 'Complete statutory requirements, Clear accessibility provisions, Detailed support information, Parent engagement'
            ),
            'test_results' => array(
                'title' => 'Test, Exam & Assessment Results',
                'description' => 'Create information about student performance in key stage assessments, including links to performance tables and detailed results.',
                'key_sections' => 'Key Stage 2 results, Key Stage 4 results (Progress 8, Attainment 8), Key Stage 5 results, Destination measures, Performance table links',
                'gov_uk_requirements' => 'Must publish link to performance tables. Must publish detailed results for KS2, KS4, and KS5 as published by Secretary of State.',
                'quality_criteria' => 'Accurate performance data, Complete result coverage, Performance table integration, Trend analysis'
            ),
            'equality_duty' => array(
                'title' => 'Public Sector Equality Duty',
                'description' => 'Create information about compliance with the public sector equality duty, including equality objectives and impact assessments.',
                'key_sections' => 'Equality compliance statement, Equality objectives, Impact assessments, Monitoring arrangements, Review timeline',
                'gov_uk_requirements' => 'Must publish details of compliance annually. Must publish equality objectives at least every 4 years.',
                'quality_criteria' => 'Comprehensive compliance statement, Specific equality objectives, Impact assessment methodology, Monitoring framework'
            ),
            'ofsted_report' => array(
                'title' => 'Ofsted Reports',
                'description' => 'Create information about the school\'s Ofsted inspections, including either a copy of the report or a link to it on the Ofsted website.',
                'key_sections' => 'Most recent Ofsted report, Previous inspection reports, Improvement actions, Ofsted rating details, Inspection framework alignment',
                'gov_uk_requirements' => 'Must publish either a copy of the most recent Ofsted report or a link to it on the Ofsted website.',
                'quality_criteria' => 'Complete report information, Clear inspection outcomes, Improvement planning, Historical context'
            ),
            'contact_details' => array(
                'title' => 'Contact Details',
                'description' => 'Create comprehensive contact information for the school, including postal address, phone number, and key staff contacts.',
                'key_sections' => 'Postal address, Telephone number, Main contact person, SENCO details, Department contacts, Emergency procedures',
                'gov_uk_requirements' => 'Must publish postal address, telephone number, and name of staff member who handles queries.',
                'quality_criteria' => 'Complete contact information, Clear staff responsibilities, Multiple contact options, Accessibility considerations'
            ),
            'careers_programme' => array(
                'title' => 'Careers Programme (Years 7-13)',
                'description' => 'Create comprehensive information about the school\'s careers guidance programme for pupils in years 7-13, including the careers lead, programme summary, impact measurement, and provider access policy.',
                'key_sections' => 'Careers lead information, Careers programme overview, Provider access policy, Impact measurement methods, Parent and employer engagement, Annual review date',
                'gov_uk_requirements' => 'Must comply with the School Information (England) Regulations 2008 and Section 42B of the Education Act 1997 (Provider Access Legislation). Must include careers lead details and provider access arrangements.',
                'quality_criteria' => 'Clear careers lead information, Comprehensive programme details, Defined provider access policy, Impact assessment methods, Parent and employer engagement strategies'
            ),
            'remote_education' => array(
                'title' => 'Remote Education Provision',
                'description' => 'Create information about the school\'s remote education provision, including expectations for students and support for parents.',
                'key_sections' => 'Remote learning expectations, Technology requirements, Student support, Parent guidance, Assessment methods, Accessibility provisions',
                'gov_uk_requirements' => 'Recommended to publish information about remote education provision.',
                'quality_criteria' => 'Clear expectations, Technical requirements, Support mechanisms, Accessibility considerations, Assessment approaches'
            ),
            'safeguarding' => array(
                'title' => 'Safeguarding Policy',
                'description' => 'Generate a comprehensive safeguarding policy framework covering statutory requirements, roles and responsibilities, procedures for reporting concerns, and staff training requirements. Note: This must be reviewed by designated safeguarding leads.',
                'key_sections' => 'Statutory framework, Designated Safeguarding Lead roles, Recognition of abuse, Reporting procedures, Record keeping, Staff training, Inter-agency working, Online safety',
                'gov_uk_requirements' => 'Must comply with "Keeping Children Safe in Education" (KCSIE) 2023. Must include specific procedures for online safety and peer-on-peer abuse.',
                'quality_criteria' => 'Clear statutory references; Defined roles and responsibilities; Step-by-step reporting procedures; Staff training requirements; Parent communication protocols'
            ),
            'behaviour' => array(
                'title' => 'Behaviour Policy',
                'description' => 'Create a positive behaviour policy covering expectations, rewards, sanctions, and support strategies. Include approaches for different age groups and consideration of SEND needs.',
                'key_sections' => 'Behaviour expectations, Rewards system, Sanctions and consequences, Support strategies, SEND considerations, Staff responsibilities, Parent engagement',
                'gov_uk_requirements' => 'Must comply with the Education and Inspections Act 2006. Should include strategies for creating a positive learning environment.',
                'quality_criteria' => 'Clear behaviour expectations; Positive reinforcement strategies; Consistent application of sanctions; Support for students with behavioural challenges; Staff training requirements'
            ),
            'complaints' => array(
                'title' => 'Complaints Policy',
                'description' => 'Create a comprehensive complaints policy outlining procedures for handling complaints from parents, carers, and staff.',
                'key_sections' => 'Complaints procedure, Timelines for resolution, Appeals process, Record keeping, Staff responsibilities',
                'gov_uk_requirements' => 'Must comply with the Education Act 2002. Must include arrangements for handling complaints about SEN support.',
                'quality_criteria' => 'Clear complaint process; Defined timelines; Fair appeals process; Proper record keeping; Staff training requirements'
            ),
            'charging_remissions' => array(
                'title' => 'Charging and Remissions Policy',
                'description' => 'Create a policy detailing when the school charges for activities and the circumstances under which charges may be waived.',
                'key_sections' => 'Charging principles, Activities that may incur charges, Remission criteria, Application process, Review procedures',
                'gov_uk_requirements' => 'Must comply with the Education Act 1996. Must clearly state which activities are free and which may incur charges.',
                'quality_criteria' => 'Clear charging principles; Transparent remission criteria; Fair application process; Regular review arrangements'
            ),
            'data_protection' => array(
                'title' => 'Data Protection Policy',
                'description' => 'Generate a GDPR-compliant data protection policy covering data handling, privacy notices, consent, and data subject rights in educational settings.',
                'key_sections' => 'GDPR principles, Lawful basis for processing, Data subject rights, Privacy notices, Data security, Breach procedures, Staff responsibilities',
                'gov_uk_requirements' => 'Must comply with UK GDPR and Data Protection Act 2018. Must include specific procedures for handling pupil data and data breaches.',
                'quality_criteria' => 'GDPR principles; Lawful basis for processing; Data subject rights; Privacy notices; Data security measures; Breach procedures; Staff training'
            )
        );
        
        $content_info = $enhanced_content_templates[$content_type] ?? $enhanced_content_templates['curriculum'];
        
        // Enhanced format instructions
        $format_instructions = array(
            'detailed' => 'Present the content in detailed paragraphs with clear headings and subheadings. Use professional language suitable for official documentation. Include specific examples and implementation guidance.',
            'bullet_points' => 'Format the content primarily as bullet points and numbered lists with brief explanatory text. Make it easy to scan and implement. Include key action points for staff.',
            'structured' => 'Organize the content into clearly defined sections with headings, subheadings, bullet points, and detailed explanations where needed. Include implementation timelines and responsibilities.'
        );
        
        // Enhanced complexity instructions
        $complexity_instructions = array(
            'basic' => 'Provide a concise overview covering essential points only. Keep explanations brief and focus on key requirements. Suitable for initial review.',
            'standard' => 'Include comprehensive coverage of important points with moderate detail. Balance thoroughness with readability. Include specific procedures and examples.',
            'comprehensive' => 'Provide extensive detail covering all aspects thoroughly. Include background information, detailed procedures, implementation timelines, staff responsibilities, and monitoring arrangements.'
        );
        
        // Enhanced prompt with gov.uk guidance references
        $prompt = sprintf(
            "You are an expert in UK education compliance with extensive knowledge of the 'What maintained schools must publish online' guidance. Create high-quality content for a UK %s school (ages %s) called '%s' with headteacher '%s'.
KEY REQUIREMENTS:
- Must be fully compliant with the 'What maintained schools must publish online' guidance (%s)
- Align with relevant statutory requirements (%s)
- Use professional but accessible language suitable for parents and the public
- Include specific implementation details and examples
- Address accessibility and inclusion throughout
- Consider different audience needs (parents, pupils, public)
CONTENT SPECIFICS:
- Title: %s
- Purpose: %s
- Required Sections: %s
- Legal Requirements: %s
- Quality Standards: %s
FORMATTING INSTRUCTIONS:
- Output Format: %s
- Detail Level: %s
- Use clear headings and subheadings
- Include bullet points for key information
- Ensure logical flow and organization
SCHOOL CONTEXT:
- School Type: %s school
- Age Range: %s years
- School Name: %s
- Headteacher: %s
%s
ADDITIONAL INSTRUCTIONS:
1. Begin with a clear introduction and purpose
2. Include all legally required information
3. Structure content for easy navigation
4. Use plain language while maintaining professionalism
5. Include any required disclaimers or notes
6. Ensure the content is practical and implementable
7. Add review date and version control information
8. Include references to relevant legislation and guidance
IMPORTANT: This is AI-generated content that must be reviewed by school staff before publication. Always verify current legal requirements and adapt to your school's specific circumstances.",
            $school_type,
            $age_range,
            $school_name,
            $headteacher_name,
            $content_info['gov_uk_requirements'],
            $this->get_statutory_reference($content_type),
            $content_info['title'],
            $content_info['description'],
            $content_info['key_sections'],
            $content_info['gov_uk_requirements'],
            $content_info['quality_criteria'],
            $format_instructions[$output_format],
            $complexity_instructions[$complexity_level],
            $school_type,
            $age_range,
            $school_name,
            $headteacher_name,
            !empty($additional_context) ? "
ADDITIONAL CONTEXT: " . $additional_context : ""
        );
        
        return $prompt;
    }
    
    private function get_statutory_reference($content_type) {
        $references = array(
            'admission_arrangements' => 'School Information (England) Regulations 2008, School Admissions Code 2022',
            'in_year_admissions' => 'School Information (England) Regulations 2008, School Admissions Code 2022',
            'admission_appeals' => 'School Admissions Appeals Code, School Standards and Framework Act 1998',
            'curriculum' => 'School Information (England) Regulations 2008, Equality Act 2010',
            'phonics' => 'School Information (England) Regulations 2008, National Curriculum framework',
            'ks4_courses' => 'School Information (England) Regulations 2008, National Curriculum framework',
            'financial_info' => 'School Information (England) Regulations 2008, Academies Financial Handbook',
            'governance' => 'Constitution of Governing Bodies of Maintained Schools Regulations 2012',
            'pupil_premium' => 'School Information (England) Regulations 2008, Pupil Premium Conditions of Grant',
            'pe_sport' => 'School Information (England) Regulations 2008, PE and Sport Premium Conditions of Grant',
            'pay_gap' => 'Equality Act 2010 (Gender Pay Gap Information) Regulations 2017',
            'ethos' => 'School Information (England) Regulations 2008, Education Act 2002',
            'school_uniform' => 'School Information (England) Regulations 2008, Guidance on the cost of school uniforms',
            'school_hours' => 'School Information (England) Regulations 2008, Education Act 2002',
            'send_report' => 'Children and Families Act 2014, SEND Regulations 2014',
            'test_results' => 'School Information (England) Regulations 2008, Education Act 2005',
            'equality_duty' => 'Equality Act 2010, Public Sector Equality Duty Regulations 2011',
            'ofsted_report' => 'Education Act 2005, School Inspections Act 1996',
            'contact_details' => 'School Information (England) Regulations 2008, Data Protection Act 2018',
            'careers_programme' => 'School Information (England) Regulations 2008, Education Act 1997 (Section 42B - Provider Access Legislation)',
            'remote_education' => 'Education (Pupil Registration) (England) Regulations 2006',
            'safeguarding' => 'Children Act 1989, Children Act 2004, Keeping Children Safe in Education (KCSIE) 2023',
            'behaviour' => 'Education and Inspections Act 2006, School Discipline and Pupil Exclusions (England) Regulations 2012',
            'complaints' => 'Education Act 2002, Best practice guidance on school complaints procedures',
            'charging_remissions' => 'Education Act 1996, Charging for school activities guidance',
            'data_protection' => 'UK GDPR, Data Protection Act 2018, Freedom of Information Act 2000'
        );
        
        return $references[$content_type] ?? 'Relevant education legislation and statutory guidance';
    }
    
    private function get_max_tokens($complexity_level) {
        switch ($complexity_level) {
            case 'basic':
                return 1000;
            case 'standard':
                return 2000;
            case 'comprehensive':
                return 3000;
            default:
                return 2000;
        }
    }
    
    private function create_database_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->db_table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            content_type varchar(100) NOT NULL,
            content longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY content_type (content_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Update database version
        update_option('sict_or_db_version', SICT_OR_DB_VERSION);
    }
    
    private function check_rate_limit() {
        $transient_key = 'sict_or_rate_limit_' . get_current_user_id();
        $request_count = get_transient($transient_key);
        
        if ($request_count >= $this->rate_limit) {
            return new WP_Error('rate_limit_exceeded', 
                sprintf(__('You have exceeded the maximum number of requests (%d per minute). Please try again later.', 'sict-ofstedready'), 
                $this->rate_limit));
        }
        
        if (false === $request_count) {
            set_transient($transient_key, 1, 60); // 60 seconds
        } else {
            set_transient($transient_key, $request_count + 1, 60);
        }
        
        return true;
    }
    
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=sict-ofstedready-settings'),
            __('Settings', 'sict-ofstedready')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}

// Initialize the plugin
new SICT_OfstedReady();

// Helper functions for template use
if (!function_exists('sict_or_get_content_types')) {
    function sict_or_get_content_types() {
        return array(
            'admission_arrangements' => __('Admission Arrangements', 'sict-ofstedready'),
            'in_year_admissions' => __('In-Year Admissions', 'sict-ofstedready'),
            'admission_appeals' => __('Admission Appeals', 'sict-ofstedready'),
            'curriculum' => __('Curriculum Information', 'sict-ofstedready'),
            'phonics' => __('Phonics/Reading Schemes', 'sict-ofstedready'),
            'ks4_courses' => __('Key Stage 4 Courses', 'sict-ofstedready'),
            'financial_info' => __('Financial Information', 'sict-ofstedready'),
            'governance' => __('Governance Information', 'sict-ofstedready'),
            'pupil_premium' => __('Pupil Premium Strategy', 'sict-ofstedready'),
            'pe_sport' => __('PE and Sport Premium', 'sict-ofstedready'),
            'pay_gap' => __('Pay Gap Reporting', 'sict-ofstedready'),
            'ethos' => __('Ethos and Values', 'sict-ofstedready'),
            'school_uniform' => __('School Uniform Policy', 'sict-ofstedready'),
            'school_hours' => __('School Opening Hours', 'sict-ofstedready'),
            'send_report' => __('SEND Information Report', 'sict-ofstedready'),
            'test_results' => __('Test, Exam & Assessment Results', 'sict-ofstedready'),
            'equality_duty' => __('Public Sector Equality Duty', 'sict-ofstedready'),
            'ofsted_report' => __('Ofsted Reports', 'sict-ofstedready'),
            'contact_details' => __('Contact Details', 'sict-ofstedready'),
            'careers_programme' => __('Careers Programme (Years 7-13)', 'sict-ofstedready'),
            'remote_education' => __('Remote Education Provision', 'sict-ofstedready'),
            'safeguarding' => __('Safeguarding Policy', 'sict-ofstedready'),
            'behaviour' => __('Behaviour Policy', 'sict-ofstedready'),
            'complaints' => __('Complaints Policy', 'sict-ofstedready'),
            'charging_remissions' => __('Charging & Remissions Policy', 'sict-ofstedready'),
            'data_protection' => __('Data Protection Policy', 'sict-ofstedready'),
        );
    }
}
?>