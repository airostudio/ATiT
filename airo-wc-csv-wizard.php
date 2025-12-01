<?php
/*
Plugin Name: Airo WooCommerce CSV Wizard
Description: Wizard-style CSV importer for WooCommerce products with image checks, progress bar, and logging.
Version: 2.1.0
Author: Airo Studio
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Airo_WC_CSV_Wizard {

    const PAGE_SLUG = 'airo-wc-csv-wizard';

    private $log_file_path = '';
    private $log_handle    = null;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'maybe_shim_select2' ) );
        add_filter( 'upload_mimes', array( $this, 'allow_csv_uploads' ) );
    }

    private function get_airo_uploads_dir() {
        $uploads = wp_get_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . 'airo_uploads';
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'CSV Product Wizard',
            'CSV Product Wizard',
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( $this, 'render_wizard' )
        );
    }

    public function enqueue_styles( $hook ) {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
            return;
        }

        wp_enqueue_style( 'airo-csv-wizard-styles', false, array(), '2.1.0' );
        wp_add_inline_style( 'airo-csv-wizard-styles', $this->get_custom_css() );
    }

    /**
     * Simple, clean CSS inspired by WP All Import
     */
    private function get_custom_css() {
        return '
        /* Reset and base - WP All Import inspired clean design */
        .wpai-wrap {
            max-width: 900px;
            margin: 20px 20px 20px 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }

        .wpai-wrap * {
            box-sizing: border-box;
        }

        /* Step indicator - simple numbered circles */
        .wpai-steps {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 0;
            border-bottom: 1px solid #ddd;
        }

        .wpai-step {
            display: flex;
            align-items: center;
            color: #999;
            font-size: 14px;
        }

        .wpai-step-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            margin-right: 8px;
        }

        .wpai-step.active .wpai-step-num {
            background: #46b450;
            color: #fff;
        }

        .wpai-step.done .wpai-step-num {
            background: #46b450;
            color: #fff;
        }

        .wpai-step.active {
            color: #23282d;
            font-weight: 500;
        }

        .wpai-step-sep {
            width: 40px;
            height: 2px;
            background: #ddd;
            margin: 0 15px;
        }

        .wpai-step.done + .wpai-step-sep {
            background: #46b450;
        }

        /* Main content area */
        .wpai-content {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }

        .wpai-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e5e5e5;
            padding: 15px 20px;
        }

        .wpai-header h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: #23282d;
        }

        .wpai-body {
            padding: 30px;
        }

        /* Big option boxes - WP All Import style */
        .wpai-options {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }

        .wpai-option {
            flex: 1;
            border: 2px solid #ddd;
            border-radius: 4px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s ease;
            background: #fff;
        }

        .wpai-option:hover {
            border-color: #007cba;
            background: #f0f6fc;
        }

        .wpai-option.selected {
            border-color: #007cba;
            background: #f0f6fc;
        }

        .wpai-option-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .wpai-option-title {
            font-size: 16px;
            font-weight: 600;
            color: #23282d;
            margin-bottom: 5px;
        }

        .wpai-option-desc {
            font-size: 13px;
            color: #666;
        }

        .wpai-option input[type="radio"],
        .wpai-option input[type="file"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        /* File upload area */
        .wpai-upload-area {
            border: 2px dashed #c3c4c7;
            border-radius: 4px;
            padding: 40px;
            text-align: center;
            background: #fafafa;
            margin-bottom: 20px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .wpai-upload-area:hover,
        .wpai-upload-area.dragover {
            border-color: #007cba;
            background: #f0f6fc;
        }

        .wpai-upload-area input[type="file"] {
            display: none;
        }

        .wpai-upload-icon {
            font-size: 64px;
            color: #c3c4c7;
            margin-bottom: 15px;
        }

        .wpai-upload-text {
            font-size: 16px;
            color: #50575e;
            margin-bottom: 5px;
        }

        .wpai-upload-hint {
            font-size: 13px;
            color: #999;
        }

        .wpai-file-selected {
            background: #f0f6fc;
            border-color: #007cba;
            border-style: solid;
        }

        .wpai-file-name {
            font-size: 14px;
            color: #007cba;
            font-weight: 500;
        }

        /* Server files select */
        .wpai-server-files {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .wpai-server-files label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #23282d;
            margin-bottom: 8px;
        }

        .wpai-server-files select {
            width: 100%;
            max-width: 400px;
            padding: 8px 12px;
            font-size: 14px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            background: #fff;
        }

        /* Form sections */
        .wpai-section {
            margin-bottom: 30px;
        }

        .wpai-section:last-child {
            margin-bottom: 0;
        }

        .wpai-section-title {
            font-size: 14px;
            font-weight: 600;
            color: #23282d;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        /* Field mapping - simple rows */
        .wpai-field-row {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .wpai-field-row:last-child {
            border-bottom: none;
        }

        .wpai-field-label {
            width: 200px;
            font-size: 13px;
            color: #23282d;
            flex-shrink: 0;
        }

        .wpai-field-label .required {
            color: #d63638;
            margin-left: 3px;
        }

        .wpai-field-input {
            flex: 1;
        }

        .wpai-field-input select {
            width: 100%;
            max-width: 300px;
            padding: 6px 10px;
            font-size: 13px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
        }

        .wpai-field-input input[type="text"] {
            width: 100%;
            max-width: 300px;
            padding: 6px 10px;
            font-size: 13px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
        }

        /* Radio options - simple list */
        .wpai-radio-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .wpai-radio-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .wpai-radio-item input[type="radio"],
        .wpai-radio-item input[type="checkbox"] {
            margin-top: 3px;
        }

        .wpai-radio-item label {
            font-size: 13px;
            color: #23282d;
            cursor: pointer;
        }

        .wpai-radio-item label strong {
            display: block;
            margin-bottom: 2px;
        }

        .wpai-radio-item label span {
            color: #666;
            font-size: 12px;
        }

        /* Buttons */
        .wpai-buttons {
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .wpai-btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            border: 1px solid;
            transition: all 0.1s ease;
        }

        .wpai-btn-primary {
            background: #007cba;
            border-color: #007cba;
            color: #fff;
        }

        .wpai-btn-primary:hover {
            background: #006ba1;
            border-color: #006ba1;
            color: #fff;
        }

        .wpai-btn-secondary {
            background: #f6f7f7;
            border-color: #8c8f94;
            color: #50575e;
        }

        .wpai-btn-secondary:hover {
            background: #f0f0f1;
            border-color: #8c8f94;
            color: #50575e;
        }

        .wpai-btn-large {
            padding: 12px 24px;
            font-size: 14px;
        }

        /* Progress bar */
        .wpai-progress {
            margin-bottom: 30px;
        }

        .wpai-progress-bar {
            height: 24px;
            background: #e5e5e5;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .wpai-progress-fill {
            height: 100%;
            background: #46b450;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            font-weight: 500;
        }

        .wpai-progress-text {
            font-size: 13px;
            color: #666;
        }

        /* Results summary */
        .wpai-results {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }

        .wpai-result-box {
            flex: 1;
            text-align: center;
            padding: 20px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }

        .wpai-result-box.created {
            background: #edfaef;
            border-color: #46b450;
        }

        .wpai-result-box.updated {
            background: #f0f6fc;
            border-color: #007cba;
        }

        .wpai-result-box.skipped {
            background: #fcf9e8;
            border-color: #dba617;
        }

        .wpai-result-num {
            font-size: 36px;
            font-weight: 600;
            line-height: 1;
            margin-bottom: 5px;
        }

        .wpai-result-box.created .wpai-result-num { color: #46b450; }
        .wpai-result-box.updated .wpai-result-num { color: #007cba; }
        .wpai-result-box.skipped .wpai-result-num { color: #dba617; }

        .wpai-result-label {
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Log output */
        .wpai-log {
            background: #23282d;
            color: #ccc;
            padding: 15px;
            border-radius: 4px;
            max-height: 300px;
            overflow-y: auto;
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
            line-height: 1.6;
        }

        .wpai-log-item {
            padding: 3px 0;
        }

        .wpai-log-item.created { color: #46b450; }
        .wpai-log-item.updated { color: #00a0d2; }
        .wpai-log-item.skipped { color: #dba617; }
        .wpai-log-item.error { color: #dc3232; }

        /* Notice boxes */
        .wpai-notice {
            padding: 12px 15px;
            border-left: 4px solid;
            margin-bottom: 20px;
            background: #fff;
        }

        .wpai-notice-error {
            border-color: #dc3232;
            background: #fcf0f1;
        }

        .wpai-notice-success {
            border-color: #46b450;
            background: #edfaef;
        }

        .wpai-notice p {
            margin: 0;
            font-size: 13px;
        }

        /* Helper text */
        .wpai-help {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        /* Inline options */
        .wpai-inline-options {
            display: flex;
            gap: 20px;
        }

        .wpai-inline-options .wpai-radio-item {
            flex-direction: row;
            align-items: center;
        }

        /* Responsive */
        @media (max-width: 782px) {
            .wpai-options {
                flex-direction: column;
            }

            .wpai-field-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .wpai-field-label {
                width: 100%;
            }

            .wpai-results {
                flex-direction: column;
            }
        }
        ';
    }

    public function render_wizard() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        $step = isset( $_GET['step'] ) ? intval( $_GET['step'] ) : 1;
        $step = max( 1, min( 4, $step ) );

        echo '<div class="wpai-wrap">';

        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="wpai-notice wpai-notice-error"><p><strong>WooCommerce Required:</strong> Please activate WooCommerce first.</p></div>';
            echo '</div>';
            return;
        }

        $this->render_steps( $step );

        echo '<div class="wpai-content">';

        switch ( $step ) {
            case 1:
                $this->step_upload();
                break;
            case 2:
                $this->step_mapping();
                break;
            case 3:
                $this->step_options();
                break;
            case 4:
                $this->step_run();
                break;
        }

        echo '</div>';
        echo '</div>';
    }

    private function render_steps( $current ) {
        $steps = array(
            1 => 'Upload File',
            2 => 'Map Fields',
            3 => 'Settings',
            4 => 'Import',
        );

        echo '<div class="wpai-steps">';
        $i = 0;
        foreach ( $steps as $num => $label ) {
            if ( $i > 0 ) {
                $sep_class = ( $num <= $current ) ? 'done' : '';
                echo '<div class="wpai-step-sep"></div>';
            }

            $class = 'wpai-step';
            if ( $num === $current ) {
                $class .= ' active';
            } elseif ( $num < $current ) {
                $class .= ' done';
            }

            echo '<div class="' . esc_attr( $class ) . '">';
            echo '<span class="wpai-step-num">' . ( $num < $current ? '&#10003;' : $num ) . '</span>';
            echo esc_html( $label );
            echo '</div>';
            $i++;
        }
        echo '</div>';
    }

    /* STEP 1: Upload */
    private function step_upload() {
        $airo_uploads_dir = $this->get_airo_uploads_dir();

        if ( isset( $_POST['airo_csv_upload_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_upload_nonce'], 'airo_csv_upload' ) ) {
            $has_uploaded_file = isset( $_FILES['csv_file'] ) && ! empty( $_FILES['csv_file']['name'] );
            $selected_existing = isset( $_POST['existing_csv'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_csv'] ) ) : '';

            if ( $has_uploaded_file ) {
                $file = $_FILES['csv_file'];
                if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
                    echo '<div class="wpai-notice wpai-notice-error"><p>' . esc_html( $this->human_upload_error( (int) $file['error'] ) ) . '</p></div>';
                } elseif ( empty( $file['tmp_name'] ) ) {
                    echo '<div class="wpai-notice wpai-notice-error"><p>Upload failed. File may be too large.</p></div>';
                } else {
                    if ( ! function_exists( 'wp_handle_upload' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                    }
                    $uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
                    if ( isset( $uploaded['error'] ) ) {
                        echo '<div class="wpai-notice wpai-notice-error"><p>' . esc_html( $uploaded['error'] ) . '</p></div>';
                    } else {
                        wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'file' => urlencode( $uploaded['file'] ) ), admin_url( 'admin.php' ) ) );
                        exit;
                    }
                }
            } elseif ( $selected_existing !== '' ) {
                $file_path = $this->build_airo_csv_path( $selected_existing );
                if ( $file_path && file_exists( $file_path ) ) {
                    wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'file' => urlencode( $file_path ) ), admin_url( 'admin.php' ) ) );
                    exit;
                } else {
                    echo '<div class="wpai-notice wpai-notice-error"><p>Selected file not found.</p></div>';
                }
            } else {
                echo '<div class="wpai-notice wpai-notice-error"><p>Please upload a file or select one from the server.</p></div>';
            }
        }

        $existing_csv_files = $this->get_existing_csv_files();
        ?>
        <div class="wpai-header">
            <h2>Step 1: Choose Your Import File</h2>
        </div>
        <div class="wpai-body">
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'airo_csv_upload', 'airo_csv_upload_nonce' ); ?>

                <div class="wpai-upload-area" id="wpai-upload-area" onclick="document.getElementById('csv_file').click();">
                    <div class="wpai-upload-icon">&#128196;</div>
                    <div class="wpai-upload-text" id="wpai-upload-text">Click to select a file or drag it here</div>
                    <div class="wpai-upload-hint">CSV files exported from Excel, Google Sheets, etc.</div>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" onchange="wpaiFileSelected(this);" />
                </div>

                <?php if ( ! empty( $existing_csv_files ) ) : ?>
                <div class="wpai-server-files">
                    <label>Or choose a file already on your server:</label>
                    <select name="existing_csv">
                        <option value="">— Select a file —</option>
                        <?php foreach ( $existing_csv_files as $csv ) : ?>
                            <option value="<?php echo esc_attr( $csv ); ?>"><?php echo esc_html( $csv ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="wpai-help">Files in: <?php echo esc_html( $airo_uploads_dir ); ?></p>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <div class="wpai-buttons">
            <div></div>
            <button type="submit" form="wpai-form" class="wpai-btn wpai-btn-primary wpai-btn-large" onclick="document.querySelector('form').submit();">
                Continue &rarr;
            </button>
        </div>

        <script>
        function wpaiFileSelected(input) {
            var area = document.getElementById('wpai-upload-area');
            var text = document.getElementById('wpai-upload-text');
            if (input.files && input.files[0]) {
                area.classList.add('wpai-file-selected');
                text.innerHTML = '<span class="wpai-file-name">' + input.files[0].name + '</span><br><small>Click to change</small>';
            }
        }

        var area = document.getElementById('wpai-upload-area');
        area.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        area.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        area.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            var input = document.getElementById('csv_file');
            input.files = e.dataTransfer.files;
            wpaiFileSelected(input);
        });
        </script>
        <?php
    }

    /* STEP 2: Mapping */
    private function step_mapping() {
        $file_path = isset( $_GET['file'] ) ? wp_unslash( $_GET['file'] ) : '';
        $file_path = $this->sanitize_file_path( $file_path );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            echo '<div class="wpai-header"><h2>Error</h2></div>';
            echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>File not found. Please go back and try again.</p></div></div>';
            echo '<div class="wpai-buttons"><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '" class="wpai-btn wpai-btn-secondary">&larr; Back</a><div></div></div>';
            return;
        }

        $headers = $this->get_csv_headers( $file_path );
        if ( empty( $headers ) ) {
            echo '<div class="wpai-header"><h2>Error</h2></div>';
            echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>Could not read CSV headers.</p></div></div>';
            return;
        }

        if ( isset( $_POST['airo_csv_mapping_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_mapping_nonce'], 'airo_csv_mapping' ) ) {
            $state = array(
                'file'        => $file_path,
                'mapping'     => isset( $_POST['mapping'] ) ? (array) $_POST['mapping'] : array(),
                'custom_meta' => isset( $_POST['custom_meta'] ) ? (array) $_POST['custom_meta'] : array(),
            );
            $encoded = base64_encode( wp_json_encode( $state ) );
            wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 3, 'state' => urlencode( $encoded ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $options = array( '' => '— Don\'t import —' );
        foreach ( $headers as $i => $h ) {
            $options[ $i ] = $h;
        }

        $fields = array(
            'name'              => array( 'Product Name', true ),
            'sku'               => array( 'SKU', false ),
            'description'       => array( 'Description', false ),
            'short_description' => array( 'Short Description', false ),
            'regular_price'     => array( 'Regular Price', false ),
            'sale_price'        => array( 'Sale Price', false ),
            'categories'        => array( 'Categories', false ),
            'stock_quantity'    => array( 'Stock Quantity', false ),
            'status'            => array( 'Status (publish/draft)', false ),
            'images'            => array( 'Images', false ),
            'id'                => array( 'Product ID (for updates)', false ),
        );
        ?>
        <div class="wpai-header">
            <h2>Step 2: Map Your CSV Columns</h2>
        </div>
        <div class="wpai-body">
            <form method="post">
                <?php wp_nonce_field( 'airo_csv_mapping', 'airo_csv_mapping_nonce' ); ?>

                <p style="margin-bottom: 20px; color: #666;">Your CSV has <strong><?php echo count( $headers ); ?> columns</strong>. Map them to WooCommerce fields below.</p>

                <div class="wpai-section">
                    <div class="wpai-section-title">Product Fields</div>
                    <?php foreach ( $fields as $key => $field ) : ?>
                    <div class="wpai-field-row">
                        <div class="wpai-field-label">
                            <?php echo esc_html( $field[0] ); ?>
                            <?php if ( $field[1] ) : ?><span class="required">*</span><?php endif; ?>
                        </div>
                        <div class="wpai-field-input">
                            <select name="mapping[<?php echo esc_attr( $key ); ?>]">
                                <?php foreach ( $options as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="wpai-section">
                    <div class="wpai-section-title">Custom Meta Fields (Optional)</div>
                    <?php for ( $i = 1; $i <= 3; $i++ ) : ?>
                    <div class="wpai-field-row">
                        <div class="wpai-field-label">
                            <input type="text" name="custom_meta[<?php echo $i; ?>][key]" placeholder="Meta key..." style="width: 150px;" />
                        </div>
                        <div class="wpai-field-input">
                            <select name="custom_meta[<?php echo $i; ?>][column]">
                                <?php foreach ( $options as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
        </div>
        <div class="wpai-buttons">
            <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ); ?>" class="wpai-btn wpai-btn-secondary">&larr; Back</a>
            <button type="submit" class="wpai-btn wpai-btn-primary wpai-btn-large">Continue &rarr;</button>
        </div>
            </form>
        <?php
    }

    /* STEP 3: Options */
    private function step_options() {
        $state = $this->get_state_from_request();
        if ( ! $state ) {
            echo '<div class="wpai-header"><h2>Error</h2></div>';
            echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>Session lost. Please start again.</p></div></div>';
            return;
        }

        if ( isset( $_POST['airo_csv_options_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_options_nonce'], 'airo_csv_options' ) ) {
            $state['options'] = array(
                'mode'            => isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'create_update',
                'match_by'        => isset( $_POST['match_by'] ) ? sanitize_text_field( $_POST['match_by'] ) : 'sku',
                'category_delim'  => isset( $_POST['category_delim'] ) ? sanitize_text_field( $_POST['category_delim'] ) : ',',
                'download_images' => isset( $_POST['download_images'] ) ? 1 : 0,
                'image_base_url'  => isset( $_POST['image_base_url'] ) ? esc_url_raw( $_POST['image_base_url'] ) : '',
            );
            $encoded = base64_encode( wp_json_encode( $state ) );
            $url = wp_nonce_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 4, 'state' => urlencode( $encoded ) ), admin_url( 'admin.php' ) ), 'airo_csv_run_import' );
            wp_redirect( $url );
            exit;
        }
        ?>
        <div class="wpai-header">
            <h2>Step 3: Import Settings</h2>
        </div>
        <div class="wpai-body">
            <form method="post">
                <?php wp_nonce_field( 'airo_csv_options', 'airo_csv_options_nonce' ); ?>
                <input type="hidden" name="state" value="<?php echo esc_attr( base64_encode( wp_json_encode( $state ) ) ); ?>" />

                <div class="wpai-section">
                    <div class="wpai-section-title">Import Behavior</div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">What to do:</div>
                        <div class="wpai-field-input">
                            <div class="wpai-radio-list">
                                <div class="wpai-radio-item">
                                    <input type="radio" name="mode" value="create_update" id="mode_cu" checked />
                                    <label for="mode_cu"><strong>Create new & update existing</strong><br><span>Most common option</span></label>
                                </div>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="mode" value="create_only" id="mode_c" />
                                    <label for="mode_c"><strong>Create new products only</strong><br><span>Skip existing products</span></label>
                                </div>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="mode" value="update_only" id="mode_u" />
                                    <label for="mode_u"><strong>Update existing only</strong><br><span>Don't create new products</span></label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Match products by:</div>
                        <div class="wpai-field-input">
                            <div class="wpai-inline-options">
                                <div class="wpai-radio-item">
                                    <input type="radio" name="match_by" value="sku" id="match_sku" checked />
                                    <label for="match_sku">SKU</label>
                                </div>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="match_by" value="id" id="match_id" />
                                    <label for="match_id">Product ID</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wpai-section">
                    <div class="wpai-section-title">Categories & Images</div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Category separator:</div>
                        <div class="wpai-field-input">
                            <select name="category_delim" style="width: 150px;">
                                <option value=",">Comma (,)</option>
                                <option value="|">Pipe (|)</option>
                                <option value=";">Semicolon (;)</option>
                            </select>
                            <p class="wpai-help">For multiple categories in one cell</p>
                        </div>
                    </div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Images:</div>
                        <div class="wpai-field-input">
                            <div class="wpai-radio-item">
                                <input type="checkbox" name="download_images" value="1" id="dl_images" checked />
                                <label for="dl_images">Download images from URLs in the CSV</label>
                            </div>
                        </div>
                    </div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Image base URL:</div>
                        <div class="wpai-field-input">
                            <input type="text" name="image_base_url" placeholder="<?php echo esc_attr( site_url( '/wp-content/uploads/airo_uploads/' ) ); ?>" />
                            <p class="wpai-help">For image filenames (not full URLs). Leave empty for default.</p>
                        </div>
                    </div>
                </div>
        </div>
        <div class="wpai-buttons">
            <a href="javascript:history.back();" class="wpai-btn wpai-btn-secondary">&larr; Back</a>
            <button type="submit" class="wpai-btn wpai-btn-primary wpai-btn-large">Run Import &rarr;</button>
        </div>
            </form>
        <?php
    }

    /* STEP 4: Run Import */
    private function step_run() {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'airo_csv_run_import' ) ) {
            echo '<div class="wpai-header"><h2>Error</h2></div>';
            echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>Security check failed.</p></div></div>';
            echo '<div class="wpai-buttons"><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '" class="wpai-btn wpai-btn-secondary">&larr; Start Over</a><div></div></div>';
            return;
        }

        $state = $this->get_state_from_request();
        if ( ! $state || empty( $state['file'] ) || ! file_exists( $state['file'] ) ) {
            echo '<div class="wpai-header"><h2>Error</h2></div>';
            echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>Session lost or file not found.</p></div></div>';
            return;
        }

        $file        = $state['file'];
        $mapping     = isset( $state['mapping'] ) ? $state['mapping'] : array();
        $custom_meta = isset( $state['custom_meta'] ) ? $state['custom_meta'] : array();
        $options     = isset( $state['options'] ) ? $state['options'] : array();
        $total_rows  = $this->count_csv_rows( $file );
        ?>
        <div class="wpai-header">
            <h2>Step 4: Importing...</h2>
        </div>
        <div class="wpai-body">
            <div class="wpai-progress">
                <div class="wpai-progress-bar">
                    <div class="wpai-progress-fill" id="wpai-progress" style="width: 0%;">0%</div>
                </div>
                <div class="wpai-progress-text" id="wpai-status">Starting import...</div>
            </div>

            <div class="wpai-results">
                <div class="wpai-result-box created">
                    <div class="wpai-result-num" id="wpai-created">0</div>
                    <div class="wpai-result-label">Created</div>
                </div>
                <div class="wpai-result-box updated">
                    <div class="wpai-result-num" id="wpai-updated">0</div>
                    <div class="wpai-result-label">Updated</div>
                </div>
                <div class="wpai-result-box skipped">
                    <div class="wpai-result-num" id="wpai-skipped">0</div>
                    <div class="wpai-result-label">Skipped</div>
                </div>
            </div>

            <div class="wpai-log" id="wpai-log"></div>
        </div>

        <script>
        window.wpaiUpdate = function(done, total, created, updated, skipped, action, name) {
            var pct = total ? Math.round(done / total * 100) : 0;
            document.getElementById('wpai-progress').style.width = pct + '%';
            document.getElementById('wpai-progress').textContent = pct + '%';
            document.getElementById('wpai-status').textContent = done + ' of ' + total + ' processed';
            document.getElementById('wpai-created').textContent = created;
            document.getElementById('wpai-updated').textContent = updated;
            document.getElementById('wpai-skipped').textContent = skipped;

            if (name) {
                var log = document.getElementById('wpai-log');
                var item = document.createElement('div');
                item.className = 'wpai-log-item ' + action;
                item.textContent = (action === 'created' ? '+ ' : action === 'updated' ? '~ ' : '- ') + name;
                log.appendChild(item);
                log.scrollTop = log.scrollHeight;
            }
        };
        </script>
        <?php

        $this->init_log_file();
        $result = $this->run_import( $file, $mapping, $custom_meta, $options, $total_rows );
        $this->close_log_file();
        $log_url = $this->get_log_file_url();
        ?>

        <div class="wpai-buttons">
            <?php if ( $log_url ) : ?>
            <a href="<?php echo esc_url( $log_url ); ?>" class="wpai-btn wpai-btn-secondary" target="_blank">Download Log</a>
            <?php else : ?>
            <div></div>
            <?php endif; ?>
            <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ); ?>" class="wpai-btn wpai-btn-primary">New Import</a>
        </div>

        <script>
        document.querySelector('.wpai-header h2').textContent = 'Import Complete!';
        </script>
        <?php
    }

    /* Core import logic */
    private function run_import( $file_path, $mapping, $custom_meta, $options, $total_rows = null ) {
        $created = $updated = $skipped = $processed = 0;

        $mode            = isset( $options['mode'] ) ? $options['mode'] : 'create_update';
        $match_by        = isset( $options['match_by'] ) ? $options['match_by'] : 'sku';
        $category_delim  = isset( $options['category_delim'] ) ? $options['category_delim'] : ',';
        $download_images = ! empty( $options['download_images'] );
        $image_base_url  = isset( $options['image_base_url'] ) ? $options['image_base_url'] : '';

        if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) {
            $this->log_line( 'ERROR: Cannot open CSV file.' );
            return compact( 'created', 'updated', 'skipped' );
        }

        $headers = fgetcsv( $handle, 0, ',' );
        if ( ! $headers ) {
            fclose( $handle );
            $this->log_line( 'ERROR: Empty CSV.' );
            return compact( 'created', 'updated', 'skipped' );
        }

        $this->log_line( 'Headers: ' . implode( ', ', $headers ) );

        while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            if ( count( array_filter( $row, 'strlen' ) ) === 0 ) continue;

            $row_data = array();
            foreach ( $headers as $i => $h ) {
                $row_data[ $i ] = isset( $row[ $i ] ) ? $row[ $i ] : '';
            }

            $name_idx = $this->mapping_index( $mapping, 'name' );
            $name     = $name_idx !== null && isset( $row_data[ $name_idx ] ) ? trim( $row_data[ $name_idx ] ) : '';

            if ( $name === '' ) {
                $processed++; $skipped++;
                $this->log_line( 'Skipped: no name' );
                $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', '(no name)' );
                continue;
            }

            $sku_idx = $this->mapping_index( $mapping, 'sku' );
            $sku     = $sku_idx !== null && isset( $row_data[ $sku_idx ] ) ? trim( $row_data[ $sku_idx ] ) : '';

            $product_id = 0;
            if ( 'sku' === $match_by && $sku !== '' ) {
                $product_id = wc_get_product_id_by_sku( $sku );
            } elseif ( 'id' === $match_by ) {
                $id_idx = $this->mapping_index( $mapping, 'id' );
                if ( $id_idx !== null && isset( $row_data[ $id_idx ] ) ) {
                    $cid = (int) $row_data[ $id_idx ];
                    if ( $cid > 0 && get_post_type( $cid ) === 'product' ) $product_id = $cid;
                }
            }

            $product = null;
            $action  = 'skipped';

            if ( $product_id ) {
                if ( 'create_only' === $mode ) {
                    $processed++; $skipped++;
                    $this->log_line( 'Skipped existing: ' . $name );
                    $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                    continue;
                }
                $product = wc_get_product( $product_id );
            } else {
                if ( 'update_only' === $mode ) {
                    $processed++; $skipped++;
                    $this->log_line( 'Skipped new: ' . $name );
                    $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                    continue;
                }
                $product = new WC_Product_Simple();
            }

            if ( ! $product ) {
                $processed++; $skipped++;
                $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            $product->set_name( $name );

            $desc_idx = $this->mapping_index( $mapping, 'description' );
            if ( $desc_idx !== null && isset( $row_data[ $desc_idx ] ) ) $product->set_description( $row_data[ $desc_idx ] );

            $short_idx = $this->mapping_index( $mapping, 'short_description' );
            if ( $short_idx !== null && isset( $row_data[ $short_idx ] ) ) $product->set_short_description( $row_data[ $short_idx ] );

            $status_idx = $this->mapping_index( $mapping, 'status' );
            $status_raw = $status_idx !== null && isset( $row_data[ $status_idx ] ) ? strtolower( trim( $row_data[ $status_idx ] ) ) : 'publish';
            $product->set_status( in_array( $status_raw, array( 'publish', 'draft', 'pending' ), true ) ? $status_raw : 'publish' );

            if ( $sku !== '' ) {
                try { $product->set_sku( $sku ); } catch ( WC_Data_Exception $e ) { $this->log_line( 'SKU error: ' . $e->getMessage() ); }
            }

            $reg_idx = $this->mapping_index( $mapping, 'regular_price' );
            if ( $reg_idx !== null && isset( $row_data[ $reg_idx ] ) && $row_data[ $reg_idx ] !== '' ) $product->set_regular_price( trim( $row_data[ $reg_idx ] ) );

            $sale_idx = $this->mapping_index( $mapping, 'sale_price' );
            if ( $sale_idx !== null && isset( $row_data[ $sale_idx ] ) && $row_data[ $sale_idx ] !== '' ) $product->set_sale_price( trim( $row_data[ $sale_idx ] ) );

            $stock_idx = $this->mapping_index( $mapping, 'stock_quantity' );
            if ( $stock_idx !== null && isset( $row_data[ $stock_idx ] ) && $row_data[ $stock_idx ] !== '' ) {
                $qty = (int) $row_data[ $stock_idx ];
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $qty );
                $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            }

            $existing = (bool) $product_id;
            try { $saved_id = $product->save(); } catch ( WC_Data_Exception $e ) {
                $processed++; $skipped++;
                $this->log_line( 'Save error: ' . $e->getMessage() );
                $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            if ( ! $saved_id ) {
                $processed++; $skipped++;
                $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            $cat_idx = $this->mapping_index( $mapping, 'categories' );
            if ( $cat_idx !== null && isset( $row_data[ $cat_idx ] ) && $row_data[ $cat_idx ] !== '' ) {
                $this->assign_categories( $saved_id, $row_data[ $cat_idx ], $category_delim );
            }

            $img_idx = $this->mapping_index( $mapping, 'images' );
            if ( $download_images && $img_idx !== null && isset( $row_data[ $img_idx ] ) && $row_data[ $img_idx ] !== '' ) {
                $this->handle_images( $saved_id, $row_data[ $img_idx ], $image_base_url );
            }

            if ( ! empty( $custom_meta ) ) {
                foreach ( $custom_meta as $m ) {
                    if ( empty( $m['key'] ) || $m['column'] === '' ) continue;
                    $col = $m['column'];
                    if ( isset( $row_data[ $col ] ) ) {
                        update_post_meta( $saved_id, sanitize_key( $m['key'] ), sanitize_text_field( $row_data[ $col ] ) );
                    }
                }
            }

            if ( $existing ) { $updated++; $action = 'updated'; $this->log_line( 'Updated: ' . $name ); }
            else { $created++; $action = 'created'; $this->log_line( 'Created: ' . $name ); }

            $processed++;
            $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, $action, $name );
        }

        fclose( $handle );
        return compact( 'created', 'updated', 'skipped' );
    }

    private function echo_progress( $done, $total, $created, $updated, $skipped, $action, $name ) {
        echo '<script>if(window.wpaiUpdate)wpaiUpdate(' . intval($done) . ',' . intval($total) . ',' . intval($created) . ',' . intval($updated) . ',' . intval($skipped) . ',"' . esc_js($action) . '","' . esc_js($name) . '");</script>';
        if ( ob_get_level() > 0 ) ob_flush();
        flush();
    }

    /* Helper methods */
    private function human_upload_error( $code ) {
        $errors = array(
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit).',
            UPLOAD_ERR_FORM_SIZE  => 'File too large.',
            UPLOAD_ERR_PARTIAL    => 'Partial upload.',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'No temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension.',
        );
        return isset( $errors[ $code ] ) ? $errors[ $code ] : 'Unknown error.';
    }

    private function build_airo_csv_path( $basename ) {
        $basename = basename( $basename );
        return $basename ? trailingslashit( $this->get_airo_uploads_dir() ) . $basename : '';
    }

    private function get_existing_csv_files() {
        $files = array();
        $dir   = $this->get_airo_uploads_dir();
        if ( is_dir( $dir ) && is_readable( $dir ) ) {
            foreach ( glob( trailingslashit( $dir ) . '*.csv' ) as $path ) {
                if ( is_file( $path ) ) $files[] = basename( $path );
            }
        }
        sort( $files, SORT_NATURAL | SORT_FLAG_CASE );
        return $files;
    }

    private function sanitize_file_path( $path ) {
        $uploads = wp_get_upload_dir();
        $basedir = realpath( $uploads['basedir'] );
        if ( ! $basedir ) return '';
        $resolved = realpath( $path );
        if ( ! $resolved || strpos( $resolved, $basedir ) !== 0 ) return '';
        return $resolved;
    }

    private function get_csv_headers( $file_path ) {
        if ( ( $h = fopen( $file_path, 'r' ) ) === false ) return array();
        $headers = fgetcsv( $h, 0, ',' );
        fclose( $h );
        return $headers ?: array();
    }

    private function count_csv_rows( $file_path ) {
        $count = 0;
        if ( ( $h = fopen( $file_path, 'r' ) ) === false ) return 0;
        fgetcsv( $h, 0, ',' );
        while ( ( $row = fgetcsv( $h, 0, ',' ) ) !== false ) {
            if ( count( array_filter( $row, 'strlen' ) ) > 0 ) $count++;
        }
        fclose( $h );
        return $count;
    }

    private function mapping_index( $mapping, $key ) {
        return isset( $mapping[ $key ] ) && $mapping[ $key ] !== '' ? (int) $mapping[ $key ] : null;
    }

    private function get_state_from_request() {
        $encoded = isset( $_POST['state'] ) ? wp_unslash( $_POST['state'] ) : ( isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '' );
        if ( ! $encoded ) return null;
        $json = base64_decode( $encoded );
        if ( ! $json ) return null;
        $state = json_decode( $json, true );
        return is_array( $state ) ? $state : null;
    }

    private function assign_categories( $product_id, $raw, $delim ) {
        $parts = array_filter( array_map( 'trim', explode( $delim, $raw ) ) );
        if ( empty( $parts ) ) return;
        $term_ids = array();
        foreach ( $parts as $name ) {
            $term = term_exists( $name, 'product_cat' );
            if ( ! $term ) $term = wp_insert_term( $name, 'product_cat' );
            if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) $term_ids[] = (int) $term['term_id'];
        }
        if ( $term_ids ) wp_set_object_terms( $product_id, $term_ids, 'product_cat' );
    }

    private function handle_images( $product_id, $raw, $base_url ) {
        $parts = array_filter( array_map( 'trim', explode( ',', str_replace( array( '|', ';' ), ',', $raw ) ) ) );
        if ( empty( $parts ) ) return;
        if ( ! $base_url ) $base_url = site_url( '/wp-content/uploads/airo_uploads/' );

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ids = array();
        foreach ( $parts as $img ) {
            $url = strpos( $img, 'http' ) === 0 ? $img : trailingslashit( $base_url ) . ltrim( $img, '/\\' );
            $response = wp_remote_head( $url, array( 'timeout' => 10 ) );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                $this->log_line( 'Image not found: ' . $url );
                continue;
            }
            $tmp = download_url( $url, 30 );
            if ( is_wp_error( $tmp ) ) { $this->log_line( 'Download failed: ' . $url ); continue; }
            $attach_id = media_handle_sideload( array( 'name' => basename( parse_url( $url, PHP_URL_PATH ) ), 'tmp_name' => $tmp ), $product_id );
            if ( is_wp_error( $attach_id ) ) { @unlink( $tmp ); continue; }
            $ids[] = $attach_id;
            $this->log_line( 'Image attached: ' . $url );
        }
        if ( $ids ) {
            set_post_thumbnail( $product_id, $ids[0] );
            if ( count( $ids ) > 1 ) update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_slice( $ids, 1 ) ) );
        }
    }

    /* Logging */
    private function init_log_file() {
        $uploads = wp_get_upload_dir();
        $log_dir = trailingslashit( $uploads['basedir'] ) . 'airo_logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            file_put_contents( trailingslashit( $log_dir ) . 'index.php', '<?php // Silence' );
            file_put_contents( trailingslashit( $log_dir ) . '.htaccess', 'deny from all' );
        }
        $this->log_file_path = trailingslashit( $log_dir ) . 'import-' . gmdate( 'Y-m-d-H-i-s' ) . '.log';
        $this->log_handle = fopen( $this->log_file_path, 'a' );
        if ( $this->log_handle ) $this->log_line( '=== Import started ===' );
    }

    private function log_line( $msg ) {
        if ( $this->log_handle ) fwrite( $this->log_handle, '[' . gmdate( 'H:i:s' ) . '] ' . $msg . PHP_EOL );
    }

    private function close_log_file() {
        if ( $this->log_handle ) { $this->log_line( '=== Import finished ===' ); fclose( $this->log_handle ); $this->log_handle = null; }
    }

    private function get_log_file_url() {
        if ( ! $this->log_file_path ) return '';
        $uploads = wp_get_upload_dir();
        $basedir = trailingslashit( $uploads['basedir'] );
        if ( strpos( $this->log_file_path, $basedir ) !== 0 ) return '';
        return trailingslashit( $uploads['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', ltrim( str_replace( $basedir, '', $this->log_file_path ), '/\\' ) );
    }

    public function maybe_shim_select2( $hook ) {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) return;
        wp_add_inline_script( 'jquery', 'jQuery(function($){if(!$.fn.select2)$.fn.select2=function(){return this;};});' );
    }

    public function allow_csv_uploads( $mimes ) {
        $mimes['csv'] = 'text/csv';
        return $mimes;
    }
}

new Airo_WC_CSV_Wizard();
