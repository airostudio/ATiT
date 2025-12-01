<?php
/*
Plugin Name: Airo WooCommerce CSV Wizard
Description: Wizard-style CSV importer for WooCommerce products with image checks, progress bar, and logging, tailored for Valley of the Dolls.
Version: 1.4.0
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
        // Fix Ali2Woo select2 error on this page
        add_action( 'admin_enqueue_scripts', array( $this, 'maybe_shim_select2' ) );
        // Make sure WordPress allows CSV uploads
        add_filter( 'upload_mimes', array( $this, 'allow_csv_uploads' ) );
    }

    /**
     * Get the airo_uploads directory path dynamically.
     *
     * @return string
     */
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

    public function render_wizard() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p>WooCommerce is not active. Please activate WooCommerce first.</p></div>';
            return;
        }

        $step = isset( $_GET['step'] ) ? intval( $_GET['step'] ) : 1;
        $step = max( 1, min( 4, $step ) );

        echo '<div class="wrap">';
        echo '<h1>WooCommerce CSV Product Wizard</h1>';

        $this->render_steps_nav( $step );

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
    }

    private function render_steps_nav( $current_step ) {
        $steps = array(
            1 => 'Upload / Select File',
            2 => 'Map Fields',
            3 => 'Import Options',
            4 => 'Run Import',
        );

        echo '<ol class="aio-wizard-steps" style="display:flex;gap:12px;margin:15px 0;padding:0;list-style:none;">';
        foreach ( $steps as $step => $label ) {
            $style = 'padding:6px 10px;border-radius:4px;';
            if ( $step === $current_step ) {
                $style .= 'background:#2271b1;color:#fff;font-weight:bold;';
            } elseif ( $step < $current_step ) {
                $style .= 'background:#d1e7ff;';
            } else {
                $style .= 'background:#f1f1f1;';
            }
            echo '<li style="' . esc_attr( $style ) . '">' . intval( $step ) . '. ' . esc_html( $label ) . '</li>';
        }
        echo '</ol>';
    }

    /* --------------------------
     * STEP 1: UPLOAD / SELECT CSV
     * -------------------------- */
    private function step_upload() {
        $airo_uploads_dir = $this->get_airo_uploads_dir();

        if ( isset( $_POST['airo_csv_upload_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_upload_nonce'], 'airo_csv_upload' ) ) {

            $has_uploaded_file = isset( $_FILES['csv_file'] ) && ! empty( $_FILES['csv_file']['name'] );
            $selected_existing = isset( $_POST['existing_csv'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_csv'] ) ) : '';

            // If a file was uploaded, prefer that.
            if ( $has_uploaded_file ) {
                $file = $_FILES['csv_file'];

                // If PHP upload error, show a specific message
                if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
                    $msg = $this->human_upload_error( (int) $file['error'] );
                    echo '<div class="notice notice-error"><p>Upload error (code ' . intval( $file['error'] ) . '): ' . esc_html( $msg ) . '</p></div>';
                } elseif ( empty( $file['tmp_name'] ) ) {
                    // No tmp_name usually also means an upload error or too-large file
                    echo '<div class="notice notice-error"><p>Upload failed before reaching WordPress. This is usually caused by the file being larger than the server\'s upload limit.</p></div>';
                } else {
                    if ( ! function_exists( 'wp_handle_upload' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                    }

                    $uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );

                    if ( isset( $uploaded['error'] ) ) {
                        echo '<div class="notice notice-error"><p>Upload error: ' . esc_html( $uploaded['error'] ) . '</p></div>';
                    } else {
                        $file_path = $uploaded['file'];

                        $url = add_query_arg(
                            array(
                                'page' => self::PAGE_SLUG,
                                'step' => 2,
                                'file' => urlencode( $file_path ),
                            ),
                            admin_url( 'admin.php' )
                        );
                        echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '">';
                        echo '<p>Redirecting to mapping step...</p>';
                        return;
                    }
                }

            // If no upload, but an existing CSV was selected from airo_uploads
            } elseif ( $selected_existing !== '' ) {

                $file_path = $this->build_airo_csv_path( $selected_existing );

                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    echo '<div class="notice notice-error"><p>The selected CSV file could not be found in <code>airo_uploads</code>.</p></div>';
                } else {
                    // Go straight to mapping with that file
                    $url = add_query_arg(
                        array(
                            'page' => self::PAGE_SLUG,
                            'step' => 2,
                            'file' => urlencode( $file_path ),
                        ),
                        admin_url( 'admin.php' )
                    );
                    echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '">';
                    echo '<p>Using existing file from airo_uploads and redirecting to mapping step...</p>';
                    return;
                }

            } else {
                echo '<div class="notice notice-error"><p>Please upload a CSV file or select one already in the <code>airo_uploads</code> folder.</p></div>';
            }
        }

        $existing_csv_files = $this->get_existing_csv_files();

        echo '<h2>Step 1: Upload or Select CSV File</h2>';
        ?>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'airo_csv_upload', 'airo_csv_upload_nonce' ); ?>

            <h3>Option A: Upload a new CSV file</h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="csv_file">CSV File</label></th>
                    <td>
                        <input
                            type="file"
                            name="csv_file"
                            id="csv_file"
                            accept=".csv,text/csv"
                        />
                        <p class="description">
                            Save your spreadsheet as <strong>CSV (Comma delimited)</strong> in Excel (or similar),
                            then upload the <code>.csv</code> file here.
                        </p>
                    </td>
                </tr>
            </table>

            <h3>Option B: Use a CSV already on the server</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Existing CSV in <code>wp-content/uploads/airo_uploads/</code></th>
                    <td>
                        <?php if ( ! empty( $existing_csv_files ) ) : ?>
                            <select name="existing_csv">
                                <option value="">— Do not use existing file —</option>
                                <?php foreach ( $existing_csv_files as $csv ) : ?>
                                    <option value="<?php echo esc_attr( $csv ); ?>">
                                        <?php echo esc_html( $csv ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                These are CSV files already present in:<br>
                                <code><?php echo esc_html( $airo_uploads_dir ); ?></code><br>
                                You can upload files via FTP/SFTP/cPanel into that folder and choose them here.
                            </p>
                        <?php else : ?>
                            <p class="description">
                                No <code>.csv</code> files found in:<br>
                                <code><?php echo esc_html( $airo_uploads_dir ); ?></code><br>
                                Upload a CSV above or place one into this folder via FTP/SFTP and refresh.
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Continue to Mapping →' ); ?>
        </form>
        <?php
    }

    private function human_upload_error( $code ) {
        switch ( $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file is too large (server upload limit).';
            case UPLOAD_ERR_PARTIAL:
                return 'The file was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder on the server.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload.';
            default:
                return 'Unknown upload error.';
        }
    }

    /**
     * Build a full path to a CSV file inside the airo_uploads directory,
     * using only the basename from the form (no arbitrary paths).
     */
    private function build_airo_csv_path( $basename ) {
        $basename = basename( $basename ); // strip any path tricks
        if ( $basename === '' ) {
            return '';
        }
        $dir = trailingslashit( $this->get_airo_uploads_dir() );
        return $dir . $basename;
    }

    /**
     * Get a list of CSV filenames in the airo_uploads directory.
     */
    private function get_existing_csv_files() {
        $files = array();
        $dir   = $this->get_airo_uploads_dir();

        if ( is_dir( $dir ) && is_readable( $dir ) ) {
            $pattern = trailingslashit( $dir ) . '*.csv';
            $found   = glob( $pattern );
            if ( $found && is_array( $found ) ) {
                foreach ( $found as $path ) {
                    if ( is_file( $path ) ) {
                        $files[] = basename( $path );
                    }
                }
            }
        }

        sort( $files, SORT_NATURAL | SORT_FLAG_CASE );
        return $files;
    }

    /* --------------------------
     * STEP 2: FIELD MAPPING
     * -------------------------- */
    private function step_mapping() {
        $file_path = isset( $_GET['file'] ) ? wp_unslash( $_GET['file'] ) : '';
        $file_path = $this->sanitize_file_path( $file_path );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            echo '<div class="notice notice-error"><p>CSV file not found. Please go back and upload/select again.</p></div>';
            echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '">← Back to Step 1</a></p>';
            return;
        }

        $headers = $this->get_csv_headers( $file_path );
        if ( empty( $headers ) ) {
            echo '<div class="notice notice-error"><p>Could not read header row from CSV file.</p></div>';
            return;
        }

        if ( isset( $_POST['airo_csv_mapping_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_mapping_nonce'], 'airo_csv_mapping' ) ) {
            $mapping     = isset( $_POST['mapping'] ) ? (array) $_POST['mapping'] : array();
            $custom_meta = isset( $_POST['custom_meta'] ) ? (array) $_POST['custom_meta'] : array();

            $state = array(
                'file'        => $file_path,
                'mapping'     => $mapping,
                'custom_meta' => $custom_meta,
            );

            $encoded = base64_encode( wp_json_encode( $state ) );

            $url = add_query_arg(
                array(
                    'page'  => self::PAGE_SLUG,
                    'step'  => 3,
                    'state' => urlencode( $encoded ),
                ),
                admin_url( 'admin.php' )
            );
            echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '">';
            echo '<p>Saving mapping and moving to options...</p>';
            return;
        }

        echo '<h2>Step 2: Map CSV Columns to WooCommerce Fields</h2>';
        echo '<p>Select which CSV columns correspond to each WooCommerce product field.</p>';

        $this->render_mapping_form( $file_path, $headers );
    }

    private function render_mapping_form( $file_path, $headers ) {
        $headers_for_select = array( '' => '-- Not Mapped --' );
        foreach ( $headers as $index => $h ) {
            $headers_for_select[ $index ] = $h;
        }

        ?>
        <form method="post">
            <?php wp_nonce_field( 'airo_csv_mapping', 'airo_csv_mapping_nonce' ); ?>
            <input type="hidden" name="file" value="<?php echo esc_attr( $file_path ); ?>" />

            <h3>Core Product Fields</h3>
            <table class="form-table">
                <?php
                $this->render_mapping_row( 'Product ID (for updating by ID)', 'id', $headers_for_select );
                $this->render_mapping_row( 'Product Name', 'name', $headers_for_select );
                $this->render_mapping_row( 'Description', 'description', $headers_for_select );
                $this->render_mapping_row( 'Short Description', 'short_description', $headers_for_select );
                $this->render_mapping_row( 'Regular Price', 'regular_price', $headers_for_select );
                $this->render_mapping_row( 'Sale Price', 'sale_price', $headers_for_select );
                $this->render_mapping_row( 'SKU', 'sku', $headers_for_select );
                $this->render_mapping_row( 'Product Categories', 'categories', $headers_for_select );
                $this->render_mapping_row( 'Stock Quantity', 'stock_quantity', $headers_for_select );
                $this->render_mapping_row( 'Status', 'status', $headers_for_select );
                $this->render_mapping_row( 'Images (URLs or filenames, separated by comma/pipe)', 'images', $headers_for_select );
                ?>
            </table>

            <h3>Custom Meta Fields</h3>
            <p>Optionally map up to 3 custom meta fields.</p>
            <table class="form-table">
                <?php for ( $i = 1; $i <= 3; $i++ ) : ?>
                    <tr>
                        <th scope="row">Custom Meta <?php echo intval( $i ); ?></th>
                        <td>
                            <label>Meta Key:
                                <input type="text" name="custom_meta[<?php echo intval( $i ); ?>][key]" style="width:200px;" />
                            </label>
                            &nbsp;&nbsp;
                            <label>Column:
                                <select name="custom_meta[<?php echo intval( $i ); ?>][column]">
                                    <?php foreach ( $headers_for_select as $index => $label ) : ?>
                                        <option value="<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </td>
                    </tr>
                <?php endfor; ?>
            </table>

            <?php submit_button( 'Continue to Import Options →' ); ?>
        </form>
        <?php
    }

    private function render_mapping_row( $label, $field_key, $options ) {
        echo '<tr>';
        echo '<th scope="row"><label>' . esc_html( $label ) . '</label></th>';
        echo '<td><select name="mapping[' . esc_attr( $field_key ) . ']">';
        foreach ( $options as $index => $opt_label ) {
            echo '<option value="' . esc_attr( $index ) . '">' . esc_html( $opt_label ) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';
    }

    /* --------------------------
     * STEP 3: IMPORT OPTIONS
     * -------------------------- */
    private function step_options() {
        $state = $this->get_state_from_request();
        if ( ! $state ) {
            echo '<div class="notice notice-error"><p>Wizard state lost. Please start again.</p></div>';
            return;
        }

        if ( isset( $_POST['airo_csv_options_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_options_nonce'], 'airo_csv_options' ) ) {
            $options = array(
                'mode'              => isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'create_update',
                'match_by'          => isset( $_POST['match_by'] ) ? sanitize_text_field( $_POST['match_by'] ) : 'sku',
                'product_type'      => isset( $_POST['product_type'] ) ? sanitize_text_field( $_POST['product_type'] ) : 'simple',
                'category_delim'    => isset( $_POST['category_delim'] ) ? sanitize_text_field( $_POST['category_delim'] ) : ',',
                'download_images'   => isset( $_POST['download_images'] ) ? 1 : 0,
                'image_base_url'    => isset( $_POST['image_base_url'] ) ? esc_url_raw( $_POST['image_base_url'] ) : '',
            );

            $state['options'] = $options;

            $encoded = base64_encode( wp_json_encode( $state ) );

            // Add nonce for step 4 CSRF protection
            $url = wp_nonce_url(
                add_query_arg(
                    array(
                        'page'  => self::PAGE_SLUG,
                        'step'  => 4,
                        'state' => urlencode( $encoded ),
                    ),
                    admin_url( 'admin.php' )
                ),
                'airo_csv_run_import'
            );
            echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '">';
            echo '<p>Saving options and moving to import...</p>';
            return;
        }

        echo '<h2>Step 3: Import Options</h2>';
        echo '<p>Configure how products should be created/updated.</p>';

        ?>
        <form method="post">
            <?php wp_nonce_field( 'airo_csv_options', 'airo_csv_options_nonce' ); ?>
            <input type="hidden" name="state" value="<?php echo esc_attr( base64_encode( wp_json_encode( $state ) ) ); ?>" />

            <h3>Mode</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Create / Update</th>
                    <td>
                        <label><input type="radio" name="mode" value="create_update" checked /> Create &amp; Update existing products</label><br />
                        <label><input type="radio" name="mode" value="create_only" /> Create new products only</label><br />
                        <label><input type="radio" name="mode" value="update_only" /> Update existing products only</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Match existing products by</th>
                    <td>
                        <label><input type="radio" name="match_by" value="sku" checked /> SKU</label><br />
                        <label><input type="radio" name="match_by" value="id" /> Product ID (requires ID field mapped)</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Product Type</th>
                    <td>
                        <select name="product_type">
                            <option value="simple">Simple Product</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h3>Categories &amp; Images</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Category delimiter</th>
                    <td>
                        <select name="category_delim">
                            <option value=",">Comma (,)</option>
                            <option value="|">Pipe (|)</option>
                            <option value=";">Semicolon (;)</option>
                        </select>
                        <p class="description">Used when splitting multiple categories from the mapped category column.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Images</th>
                    <td>
                        <label>
                            <input type="checkbox" name="download_images" value="1" checked />
                            Download images from the <strong>Images</strong> column
                        </label>
                        <p class="description">
                            • If the value <code>starts with http</code>, it is treated as a full <strong>external URL</strong> and downloaded from there.<br>
                            • If it does <em>not</em> start with <code>http</code>, it is treated as a <strong>filename</strong> under the Image Base URL (or the default <code>/wp-content/uploads/airo_uploads/</code>).<br>
                            • Multiple images per product are supported (comma, pipe or semicolon separated). The first becomes the featured image; the rest go to the gallery.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Image base URL (for filenames)</th>
                    <td>
                        <input type="text" name="image_base_url" style="width:400px;" placeholder="<?php echo esc_attr( site_url( '/wp-content/uploads/airo_uploads/' ) ); ?>" />
                        <p class="description">
                            If left empty, the importer will default to:<br />
                            <code><?php echo esc_html( site_url( '/wp-content/uploads/airo_uploads/' ) ); ?></code><br />
                            Filenames like <code>image1.jpg</code> will be resolved relative to that folder.<br />
                            <strong>Full URLs</strong> (starting with <code>http</code>) are always used as-is and can point to external sites/CDNs.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Run Import →' ); ?>
        </form>
        <?php
    }

    /* --------------------------
     * STEP 4: RUN IMPORT
     * -------------------------- */
    private function step_run() {
        // CSRF protection - verify nonce
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'airo_csv_run_import' ) ) {
            echo '<div class="notice notice-error"><p>Security check failed. Please start again.</p></div>';
            echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '">← Back to Step 1</a></p>';
            return;
        }

        $state = $this->get_state_from_request();
        if ( ! $state ) {
            echo '<div class="notice notice-error"><p>Wizard state lost. Please start again.</p></div>';
            return;
        }

        $file        = isset( $state['file'] ) ? $state['file'] : '';
        $mapping     = isset( $state['mapping'] ) ? $state['mapping'] : array();
        $custom_meta = isset( $state['custom_meta'] ) ? $state['custom_meta'] : array();
        $options     = isset( $state['options'] ) ? $state['options'] : array();

        if ( ! $file || ! file_exists( $file ) ) {
            echo '<div class="notice notice-error"><p>CSV file not found.</p></div>';
            return;
        }

        echo '<h2>Step 4: Run Import</h2>';

        $total_rows = $this->count_csv_rows( $file );

        ?>
        <div id="airo-import-progress" style="max-width:900px;margin-top:20px;">
            <div id="airo-import-progress-bar" style="width:100%;background:#f1f1f1;border-radius:4px;overflow:hidden;">
                <div id="airo-import-progress-bar-inner" style="width:0%;height:22px;background:#2271b1;color:#fff;text-align:center;line-height:22px;font-size:11px;">0%</div>
            </div>
            <p id="airo-import-progress-text" style="margin-top:8px;">Starting import...</p>
            <div style="display:flex;gap:20px;margin-top:15px;">
                <div style="flex:1;">
                    <strong>Created</strong>
                    <ul id="airo-import-created-list" style="max-height:200px;overflow:auto;border:1px solid #ddd;padding:5px;margin-top:5px;background:#fff;"></ul>
                </div>
                <div style="flex:1;">
                    <strong>Updated</strong>
                    <ul id="airo-import-updated-list" style="max-height:200px;overflow:auto;border:1px solid #ddd;padding:5px;margin-top:5px;background:#fff;"></ul>
                </div>
                <div style="flex:1;">
                    <strong>Skipped</strong>
                    <ul id="airo-import-skipped-list" style="max-height:200px;overflow:auto;border:1px solid #ddd;padding:5px;margin-top:5px;background:#fff;"></ul>
                </div>
            </div>
        </div>

        <script>
        window.airoUpdateProgress = function(done, total, created, updated, skipped, action, productName) {
            var barInner = document.getElementById('airo-import-progress-bar-inner');
            var text = document.getElementById('airo-import-progress-text');
            if (!barInner || !text) return;

            var percent = total ? Math.round(done / total * 100) : 0;
            if (percent > 100) percent = 100;
            barInner.style.width = percent + '%';
            barInner.textContent = percent + '%';

            text.textContent = done + ' / ' + total + ' processed – Created: ' + created + ', Updated: ' + updated + ', Skipped: ' + skipped;

            var listId = '';
            if (action === 'created') listId = 'airo-import-created-list';
            else if (action === 'updated') listId = 'airo-import-updated-list';
            else if (action === 'skipped') listId = 'airo-import-skipped-list';

            if (listId && productName) {
                var list = document.getElementById(listId);
                if (list) {
                    var li = document.createElement('li');
                    li.textContent = productName;
                    list.appendChild(li);
                }
            }
        };
        </script>
        <?php

        // Initialize log
        $this->init_log_file();

        // Run import with live progress output
        $result = $this->run_import( $file, $mapping, $custom_meta, $options, $total_rows );

        // Close log and get URL
        $this->close_log_file();
        $log_url = $this->get_log_file_url();

        echo '<div class="notice notice-success" style="margin-top:20px;"><p>Import complete. Created: ' . intval( $result['created'] ) . ', Updated: ' . intval( $result['updated'] ) . ', Skipped: ' . intval( $result['skipped'] ) . '.</p></div>';

        if ( ! empty( $log_url ) ) {
            echo '<p><a class="button" href="' . esc_url( $log_url ) . '" target="_blank">Download Import Log</a></p>';
        }

        echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '">Start New Import</a></p>';
    }

    /* --------------------------
     * CORE IMPORT LOGIC
     * -------------------------- */
    private function run_import( $file_path, $mapping, $custom_meta, $options, $total_rows = null ) {
        $created   = 0;
        $updated   = 0;
        $skipped   = 0;
        $processed = 0;

        $mode            = isset( $options['mode'] ) ? $options['mode'] : 'create_update';
        $match_by        = isset( $options['match_by'] ) ? $options['match_by'] : 'sku';
        $product_type    = isset( $options['product_type'] ) ? $options['product_type'] : 'simple';
        $category_delim  = isset( $options['category_delim'] ) ? $options['category_delim'] : ',';
        $download_images = ! empty( $options['download_images'] );
        $image_base_url  = isset( $options['image_base_url'] ) ? $options['image_base_url'] : '';

        if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) {
            echo '<div class="notice notice-error"><p>Cannot open file.</p></div>';
            $this->log_line( 'ERROR: Cannot open CSV file.' );
            return compact( 'created', 'updated', 'skipped' );
        }

        $headers = fgetcsv( $handle, 0, ',' );
        if ( ! $headers ) {
            echo '<div class="notice notice-error"><p>Empty or invalid CSV.</p></div>';
            fclose( $handle );
            $this->log_line( 'ERROR: CSV has no valid header row.' );
            return compact( 'created', 'updated', 'skipped' );
        }

        $this->log_line( 'Headers detected: ' . implode( ' | ', $headers ) );

        while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            if ( count( array_filter( $row, 'strlen' ) ) === 0 ) {
                continue;
            }

            $row_data = array();
            foreach ( $headers as $i => $h ) {
                $row_data[ $i ] = isset( $row[ $i ] ) ? $row[ $i ] : '';
            }

            $name_index = $this->mapping_index( $mapping, 'name' );
            $name       = $name_index !== null && isset( $row_data[ $name_index ] ) ? trim( $row_data[ $name_index ] ) : '';

            if ( $name === '' ) {
                $processed++;
                $skipped++;
                $this->log_line( 'Skipped row: missing product name.' );
                $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, 'skipped', '(no name)' );
                continue;
            }

            $sku_index = $this->mapping_index( $mapping, 'sku' );
            $sku       = $sku_index !== null && isset( $row_data[ $sku_index ] ) ? trim( $row_data[ $sku_index ] ) : '';

            $status_index = $this->mapping_index( $mapping, 'status' );
            $status_raw   = $status_index !== null && isset( $row_data[ $status_index ] ) ? strtolower( trim( $row_data[ $status_index ] ) ) : 'publish';
            $status       = in_array( $status_raw, array( 'publish', 'draft', 'pending' ), true ) ? $status_raw : 'publish';

            $desc_index = $this->mapping_index( $mapping, 'description' );
            $desc       = $desc_index !== null && isset( $row_data[ $desc_index ] ) ? $row_data[ $desc_index ] : '';

            $short_index = $this->mapping_index( $mapping, 'short_description' );
            $short_desc  = $short_index !== null && isset( $row_data[ $short_index ] ) ? $row_data[ $short_index ] : '';

            $regular_index = $this->mapping_index( $mapping, 'regular_price' );
            $regular_price = $regular_index !== null && isset( $row_data[ $regular_index ] ) ? trim( $row_data[ $regular_index ] ) : '';

            $sale_index = $this->mapping_index( $mapping, 'sale_price' );
            $sale_price = $sale_index !== null && isset( $row_data[ $sale_index ] ) ? trim( $row_data[ $sale_index ] ) : '';

            $categories_index = $this->mapping_index( $mapping, 'categories' );
            $categories_raw   = $categories_index !== null && isset( $row_data[ $categories_index ] ) ? $row_data[ $categories_index ] : '';

            $stock_index = $this->mapping_index( $mapping, 'stock_quantity' );
            $stock_raw   = $stock_index !== null && isset( $row_data[ $stock_index ] ) ? trim( $row_data[ $stock_index ] ) : '';

            $images_index = $this->mapping_index( $mapping, 'images' );
            $images_raw   = $images_index !== null && isset( $row_data[ $images_index ] ) ? $row_data[ $images_index ] : '';

            // find existing product
            $product_id = 0;
            if ( 'sku' === $match_by && $sku !== '' ) {
                $product_id = wc_get_product_id_by_sku( $sku );
            } elseif ( 'id' === $match_by ) {
                if ( isset( $mapping['id'] ) && $mapping['id'] !== '' ) {
                    $id_index = $this->mapping_index( $mapping, 'id' );
                    if ( $id_index !== null && isset( $row_data[ $id_index ] ) ) {
                        $candidate_id = (int) $row_data[ $id_index ];
                        if ( $candidate_id > 0 && get_post_type( $candidate_id ) === 'product' ) {
                            $product_id = $candidate_id;
                        }
                    }
                }
            }

            $product = null;
            $action  = 'skipped';

            if ( $product_id ) {
                if ( 'create_only' === $mode ) {
                    $processed++;
                    $skipped++;
                    $this->log_line( 'Skipped existing product "' . $name . '" (ID ' . $product_id . ') due to create_only mode.' );
                    $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                    continue;
                }
                $product = wc_get_product( $product_id );
            } else {
                if ( 'update_only' === $mode ) {
                    $processed++;
                    $skipped++;
                    $this->log_line( 'Skipped product "' . $name . '" because update_only mode and no existing match.' );
                    $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                    continue;
                }

                if ( 'simple' === $product_type ) {
                    $product = new WC_Product_Simple();
                } else {
                    $product = new WC_Product_Simple();
                }
            }

            if ( ! $product ) {
                $processed++;
                $skipped++;
                $this->log_line( 'ERROR: Could not create product object for "' . $name . '".' );
                $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            // set basic fields
            $product->set_name( $name );
            $product->set_description( $desc );
            $product->set_short_description( $short_desc );
            $product->set_status( $status );

            // Handle SKU with try-catch for duplicate SKU errors
            if ( $sku !== '' ) {
                try {
                    $product->set_sku( $sku );
                } catch ( WC_Data_Exception $e ) {
                    $this->log_line( 'SKU error for "' . $name . '": ' . $e->getMessage() . ' - continuing without setting SKU.' );
                }
            }

            if ( $regular_price !== '' ) {
                $product->set_regular_price( $regular_price );
            }

            if ( $sale_price !== '' ) {
                $product->set_sale_price( $sale_price );
            }

            if ( $stock_raw !== '' ) {
                $stock_qty = (int) $stock_raw;
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock_qty );
                $product->set_stock_status( $stock_qty > 0 ? 'instock' : 'outofstock' );
            }

            $existing = (bool) $product_id;

            try {
                $saved_id = $product->save();
            } catch ( WC_Data_Exception $e ) {
                $processed++;
                $skipped++;
                $this->log_line( 'ERROR: Save failed for product "' . $name . '": ' . $e->getMessage() );
                $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            if ( ! $saved_id ) {
                $processed++;
                $skipped++;
                $this->log_line( 'ERROR: Save failed for product "' . $name . '".' );
                $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            // categories
            if ( $categories_raw !== '' ) {
                $this->assign_categories_with_delim( $saved_id, $categories_raw, $category_delim );
            }

            // images
            if ( $images_raw !== '' && $download_images ) {
                $this->handle_images_for_product( $saved_id, $images_raw, $image_base_url );
            }

            // custom meta
            if ( ! empty( $custom_meta ) ) {
                foreach ( $custom_meta as $meta_item ) {
                    if ( empty( $meta_item['key'] ) || $meta_item['column'] === '' ) {
                        continue;
                    }
                    $meta_key  = sanitize_key( $meta_item['key'] );
                    $col_index = $meta_item['column'];
                    if ( $col_index !== '' && isset( $row_data[ $col_index ] ) ) {
                        $meta_value = sanitize_text_field( $row_data[ $col_index ] );
                        update_post_meta( $saved_id, $meta_key, $meta_value );
                    }
                }
            }

            if ( $existing ) {
                $updated++;
                $action = 'updated';
                $this->log_line( 'Updated product: "' . $name . '" (ID: ' . $saved_id . ', SKU: ' . $sku . ')' );
            } else {
                $created++;
                $action = 'created';
                $this->log_line( 'Created product: "' . $name . '" (ID: ' . $saved_id . ', SKU: ' . $sku . ')' );
            }

            $processed++;
            $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, $action, $name );
        }

        fclose( $handle );

        return compact( 'created', 'updated', 'skipped' );
    }

    private function echo_progress_script( $processed, $total, $created, $updated, $skipped, $action, $name ) {
        $safe_name   = esc_js( $name );
        $safe_action = esc_js( $action );

        echo '<script>';
        echo 'if (window.airoUpdateProgress) { window.airoUpdateProgress('
            . intval( $processed ) . ','
            . intval( $total ) . ','
            . intval( $created ) . ','
            . intval( $updated ) . ','
            . intval( $skipped ) . ','
            . '\'' . $safe_action . '\','
            . '\'' . $safe_name . '\''
            . '); }';
        echo '</script>';

        if ( ob_get_level() > 0 ) {
            ob_flush();
        }
        flush();
    }

    /* --------------------------
     * LOGGING HELPERS
     * -------------------------- */
    private function init_log_file() {
        $uploads = wp_get_upload_dir();
        $log_dir = trailingslashit( $uploads['basedir'] ) . 'airo_logs';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );

            // Add security files to prevent direct access
            $index_file = trailingslashit( $log_dir ) . 'index.php';
            if ( ! file_exists( $index_file ) ) {
                file_put_contents( $index_file, '<?php // Silence is golden' );
            }

            $htaccess_file = trailingslashit( $log_dir ) . '.htaccess';
            if ( ! file_exists( $htaccess_file ) ) {
                file_put_contents( $htaccess_file, 'deny from all' );
            }
        }

        $timestamp           = gmdate( 'Y-m-d-H-i-s' );
        $this->log_file_path = trailingslashit( $log_dir ) . 'import-' . $timestamp . '.log';

        $this->log_handle = fopen( $this->log_file_path, 'a' );
        if ( $this->log_handle ) {
            $this->log_line( '=== Import started at ' . gmdate( 'c' ) . ' ===' );
        }
    }

    private function log_line( $message ) {
        if ( ! $this->log_handle ) {
            return;
        }
        fwrite( $this->log_handle, '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL );
    }

    private function close_log_file() {
        if ( $this->log_handle ) {
            $this->log_line( '=== Import finished at ' . gmdate( 'c' ) . ' ===' );
            fclose( $this->log_handle );
            $this->log_handle = null;
        }
    }

    private function get_log_file_url() {
        if ( ! $this->log_file_path ) {
            return '';
        }

        $uploads = wp_get_upload_dir();
        $basedir = trailingslashit( $uploads['basedir'] );

        if ( strpos( $this->log_file_path, $basedir ) !== 0 ) {
            return '';
        }

        $relative = ltrim( str_replace( $basedir, '', $this->log_file_path ), '/\\' );
        return trailingslashit( $uploads['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
    }

    /* --------------------------
     * MISC HELPERS
     * -------------------------- */

    /**
     * Sanitize and validate file path to prevent path traversal attacks.
     * Uses realpath() to resolve the actual path before comparison.
     *
     * @param string $path The file path to sanitize.
     * @return string The sanitized path, or empty string if invalid.
     */
    private function sanitize_file_path( $path ) {
        $uploads = wp_get_upload_dir();
        $basedir = realpath( $uploads['basedir'] );

        if ( ! $basedir ) {
            return '';
        }

        // Resolve the actual path (handles ../ and symlinks)
        $resolved = realpath( $path );

        // If realpath returns false, file doesn't exist or path is invalid
        if ( ! $resolved ) {
            return '';
        }

        // Ensure the resolved path is within the uploads directory
        if ( strpos( $resolved, $basedir ) !== 0 ) {
            return '';
        }

        return $resolved;
    }

    private function get_csv_headers( $file_path ) {
        if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) {
            return array();
        }
        $headers = fgetcsv( $handle, 0, ',' );
        fclose( $handle );
        if ( ! $headers ) {
            return array();
        }
        return $headers;
    }

    private function count_csv_rows( $file_path ) {
        $count = 0;
        if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) {
            return 0;
        }
        $headers = fgetcsv( $handle, 0, ',' );
        if ( ! $headers ) {
            fclose( $handle );
            return 0;
        }
        while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            if ( count( array_filter( $row, 'strlen' ) ) === 0 ) {
                continue;
            }
            $count++;
        }
        fclose( $handle );
        return $count;
    }

    private function mapping_index( $mapping, $key ) {
        if ( isset( $mapping[ $key ] ) && $mapping[ $key ] !== '' ) {
            return (int) $mapping[ $key ];
        }
        return null;
    }

    private function get_state_from_request() {
        if ( isset( $_POST['state'] ) && ! empty( $_POST['state'] ) ) {
            $encoded = wp_unslash( $_POST['state'] );
        } elseif ( isset( $_GET['state'] ) ) {
            $encoded = wp_unslash( $_GET['state'] );
        } else {
            return null;
        }

        $json = base64_decode( $encoded );
        if ( ! $json ) {
            return null;
        }

        $state = json_decode( $json, true );
        if ( ! is_array( $state ) ) {
            return null;
        }
        return $state;
    }

    /**
     * Assign categories to a product using only the specified delimiter.
     *
     * @param int    $product_id     The product ID.
     * @param string $categories_raw The raw categories string from CSV.
     * @param string $delim          The delimiter to use for splitting.
     */
    private function assign_categories_with_delim( $product_id, $categories_raw, $delim ) {
        // Only use the selected delimiter - do not replace other characters
        $valid_delims = array( ',', '|', ';' );
        if ( ! in_array( $delim, $valid_delims, true ) ) {
            $delim = ',';
        }

        $parts = array_filter( array_map( 'trim', explode( $delim, $categories_raw ) ) );

        if ( empty( $parts ) ) {
            return;
        }

        $term_ids = array();
        foreach ( $parts as $cat_name ) {
            if ( $cat_name === '' ) {
                continue;
            }

            $term = term_exists( $cat_name, 'product_cat' );
            if ( ! $term ) {
                $term = wp_insert_term( $cat_name, 'product_cat' );
            }

            if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
                $term_ids[] = (int) $term['term_id'];
            }
        }

        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $product_id, $term_ids, 'product_cat' );
        }
    }

    /**
     * Handle images with availability checks and default base URL.
     *
     * External images:
     * - If CSV value starts with "http", it is used as-is as an external URL.
     * Filenames:
     * - Otherwise it is treated as a filename under $base_url (or default airo_uploads).
     */
    private function handle_images_for_product( $product_id, $images_raw, $base_url ) {
        $separators   = array( ',', '|', ';' );
        $images_clean = str_replace( $separators, ',', $images_raw );
        $parts        = array_filter( array_map( 'trim', explode( ',', $images_clean ) ) );

        if ( empty( $parts ) ) {
            return;
        }

        // Default base URL if none provided in options
        if ( empty( $base_url ) ) {
            $base_url = site_url( '/wp-content/uploads/airo_uploads/' );
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = array();

        foreach ( $parts as $img ) {
            if ( empty( $img ) ) {
                continue;
            }

            $url = $img;

            // If CSV value is not a full URL, treat it as a filename in the airo_uploads folder (or provided base URL)
            if ( strpos( $img, 'http' ) !== 0 ) {
                $url = trailingslashit( $base_url ) . ltrim( $img, '/\\' );
            }

            // Test if image URL is available and is an image
            $response = wp_remote_head( $url, array( 'timeout' => 10 ) );
            if ( is_wp_error( $response ) ) {
                $this->log_line( 'Image HEAD request error for product ID ' . $product_id . ': ' . $url . ' (' . $response->get_error_message() . ')' );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 !== (int) $code ) {
                $this->log_line( 'Image not reachable for product ID ' . $product_id . ': ' . $url . ' (HTTP ' . $code . ')' );
                continue;
            }

            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            if ( $content_type && strpos( $content_type, 'image' ) === false ) {
                $this->log_line( 'URL is not an image for product ID ' . $product_id . ': ' . $url . ' (Content-Type: ' . $content_type . ')' );
                continue;
            }

            // Download the image
            $tmp = download_url( $url, 30 );
            if ( is_wp_error( $tmp ) ) {
                $this->log_line( 'Image download error for product ID ' . $product_id . ': ' . $url . ' (' . $tmp->get_error_message() . ')' );
                continue;
            }

            $file_array = array(
                'name'     => basename( parse_url( $url, PHP_URL_PATH ) ),
                'tmp_name' => $tmp,
            );

            $attach_id = media_handle_sideload( $file_array, $product_id );
            if ( is_wp_error( $attach_id ) ) {
                @unlink( $tmp );
                $this->log_line( 'Image attach error for product ID ' . $product_id . ': ' . $url . ' (' . $attach_id->get_error_message() . ')' );
                continue;
            }

            $attachment_ids[] = $attach_id;
            $this->log_line( 'Attached image ' . $url . ' to product ID ' . $product_id . ' (attachment ID ' . $attach_id . ')' );
        }

        if ( ! empty( $attachment_ids ) ) {
            set_post_thumbnail( $product_id, $attachment_ids[0] );
            if ( count( $attachment_ids ) > 1 ) {
                update_post_meta(
                    $product_id,
                    '_product_image_gallery',
                    implode( ',', array_slice( $attachment_ids, 1 ) )
                );
            }
        }
    }

    /* --------------------------
     * SELECT2 SHIM (fix Ali2Woo JS error)
     * -------------------------- */
    public function maybe_shim_select2( $hook ) {
        // Only run on our wizard page
        if ( empty( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
            return;
        }

        // Inject a tiny no-op select2 shim after jQuery has loaded
        $inline = <<<JS
jQuery(function($){
    if (!$.fn.select2) {
        $.fn.select2 = function() {
            return this;
        };
    }
});
JS;
        wp_add_inline_script( 'jquery', $inline );
    }

    /* --------------------------
     * ALLOW CSV UPLOADS
     * -------------------------- */
    public function allow_csv_uploads( $mimes ) {
        $mimes['csv'] = 'text/csv';
        return $mimes;
    }

}

new Airo_WC_CSV_Wizard();
