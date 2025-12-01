<?php
/*
Plugin Name: Airo WooCommerce CSV Wizard
Description: Wizard-style CSV importer for WooCommerce products with image checks, progress bar, and logging, tailored for Valley of the Dolls.
Version: 2.0.0
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

    /**
     * Get the airo_uploads directory path dynamically.
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

    /**
     * Enqueue custom styles for the wizard.
     */
    public function enqueue_styles( $hook ) {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
            return;
        }

        wp_enqueue_style(
            'airo-csv-wizard-styles',
            false,
            array(),
            '2.0.0'
        );

        $custom_css = $this->get_custom_css();
        wp_add_inline_style( 'airo-csv-wizard-styles', $custom_css );
    }

    /**
     * Get the custom CSS for the wizard interface.
     */
    private function get_custom_css() {
        return '
        /* =============================================
           AIRO CSV WIZARD - MODERN UI STYLES
           Neutral color palette with great UX
           ============================================= */

        :root {
            --airo-bg: #f8f9fa;
            --airo-card-bg: #ffffff;
            --airo-border: #e5e7eb;
            --airo-border-light: #f0f0f0;
            --airo-text-primary: #1f2937;
            --airo-text-secondary: #6b7280;
            --airo-text-muted: #9ca3af;
            --airo-primary: #4f46e5;
            --airo-primary-hover: #4338ca;
            --airo-primary-light: #eef2ff;
            --airo-success: #10b981;
            --airo-success-light: #d1fae5;
            --airo-warning: #f59e0b;
            --airo-warning-light: #fef3c7;
            --airo-error: #ef4444;
            --airo-error-light: #fee2e2;
            --airo-shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --airo-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --airo-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --airo-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            --airo-radius: 8px;
            --airo-radius-lg: 12px;
            --airo-transition: all 0.2s ease;
        }

        .airo-wizard-wrap {
            max-width: 960px;
            margin: 20px auto;
            padding: 0 20px;
        }

        /* Header */
        .airo-wizard-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .airo-wizard-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--airo-text-primary);
            margin: 0 0 8px 0;
            letter-spacing: -0.5px;
        }

        .airo-wizard-header p {
            color: var(--airo-text-secondary);
            font-size: 15px;
            margin: 0;
        }

        /* Step Progress Navigation */
        .airo-steps-nav {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 40px;
            padding: 0;
            list-style: none;
            gap: 0;
        }

        .airo-step-item {
            display: flex;
            align-items: center;
            position: relative;
        }

        .airo-step-item:not(:last-child)::after {
            content: "";
            width: 60px;
            height: 2px;
            background: var(--airo-border);
            margin: 0 8px;
        }

        .airo-step-item.completed:not(:last-child)::after {
            background: var(--airo-primary);
        }

        .airo-step-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .airo-step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            transition: var(--airo-transition);
            border: 2px solid var(--airo-border);
            background: var(--airo-card-bg);
            color: var(--airo-text-muted);
        }

        .airo-step-item.active .airo-step-number {
            background: var(--airo-primary);
            border-color: var(--airo-primary);
            color: #fff;
            box-shadow: 0 0 0 4px var(--airo-primary-light);
        }

        .airo-step-item.completed .airo-step-number {
            background: var(--airo-primary);
            border-color: var(--airo-primary);
            color: #fff;
        }

        .airo-step-item.completed .airo-step-number::before {
            content: "\\2713";
        }

        .airo-step-label {
            font-size: 12px;
            font-weight: 500;
            color: var(--airo-text-muted);
            text-align: center;
            max-width: 80px;
        }

        .airo-step-item.active .airo-step-label {
            color: var(--airo-primary);
            font-weight: 600;
        }

        .airo-step-item.completed .airo-step-label {
            color: var(--airo-text-secondary);
        }

        /* Cards */
        .airo-card {
            background: var(--airo-card-bg);
            border: 1px solid var(--airo-border);
            border-radius: var(--airo-radius-lg);
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: var(--airo-shadow-sm);
            transition: var(--airo-transition);
        }

        .airo-card:hover {
            box-shadow: var(--airo-shadow);
        }

        .airo-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--airo-border-light);
        }

        .airo-card-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--airo-radius);
            background: var(--airo-primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--airo-primary);
            font-size: 20px;
        }

        .airo-card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--airo-text-primary);
            margin: 0;
        }

        .airo-card-subtitle {
            font-size: 13px;
            color: var(--airo-text-secondary);
            margin: 4px 0 0 0;
        }

        /* Form Elements */
        .airo-form-group {
            margin-bottom: 20px;
        }

        .airo-form-group:last-child {
            margin-bottom: 0;
        }

        .airo-form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--airo-text-primary);
            margin-bottom: 8px;
        }

        .airo-form-label .required {
            color: var(--airo-error);
        }

        .airo-form-hint {
            font-size: 13px;
            color: var(--airo-text-secondary);
            margin-top: 6px;
            line-height: 1.5;
        }

        .airo-form-hint code {
            background: var(--airo-bg);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--airo-text-primary);
        }

        /* File Upload */
        .airo-file-upload {
            position: relative;
            border: 2px dashed var(--airo-border);
            border-radius: var(--airo-radius);
            padding: 40px 24px;
            text-align: center;
            background: var(--airo-bg);
            transition: var(--airo-transition);
            cursor: pointer;
        }

        .airo-file-upload:hover {
            border-color: var(--airo-primary);
            background: var(--airo-primary-light);
        }

        .airo-file-upload input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .airo-file-upload-icon {
            font-size: 48px;
            color: var(--airo-text-muted);
            margin-bottom: 16px;
        }

        .airo-file-upload-text {
            font-size: 15px;
            color: var(--airo-text-primary);
            margin-bottom: 4px;
        }

        .airo-file-upload-hint {
            font-size: 13px;
            color: var(--airo-text-secondary);
        }

        /* Select Dropdowns */
        .airo-select {
            width: 100%;
            padding: 10px 14px;
            font-size: 14px;
            border: 1px solid var(--airo-border);
            border-radius: var(--airo-radius);
            background: var(--airo-card-bg);
            color: var(--airo-text-primary);
            transition: var(--airo-transition);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3E%3Cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3E%3C/svg%3E");
            background-position: right 10px center;
            background-repeat: no-repeat;
            background-size: 20px;
            padding-right: 40px;
        }

        .airo-select:hover {
            border-color: var(--airo-text-muted);
        }

        .airo-select:focus {
            outline: none;
            border-color: var(--airo-primary);
            box-shadow: 0 0 0 3px var(--airo-primary-light);
        }

        /* Text Input */
        .airo-input {
            width: 100%;
            padding: 10px 14px;
            font-size: 14px;
            border: 1px solid var(--airo-border);
            border-radius: var(--airo-radius);
            background: var(--airo-card-bg);
            color: var(--airo-text-primary);
            transition: var(--airo-transition);
        }

        .airo-input:hover {
            border-color: var(--airo-text-muted);
        }

        .airo-input:focus {
            outline: none;
            border-color: var(--airo-primary);
            box-shadow: 0 0 0 3px var(--airo-primary-light);
        }

        .airo-input::placeholder {
            color: var(--airo-text-muted);
        }

        /* Radio & Checkbox Groups */
        .airo-radio-group,
        .airo-checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .airo-radio-item,
        .airo-checkbox-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border: 1px solid var(--airo-border);
            border-radius: var(--airo-radius);
            cursor: pointer;
            transition: var(--airo-transition);
            background: var(--airo-card-bg);
        }

        .airo-radio-item:hover,
        .airo-checkbox-item:hover {
            border-color: var(--airo-primary);
            background: var(--airo-primary-light);
        }

        .airo-radio-item.selected,
        .airo-checkbox-item.selected {
            border-color: var(--airo-primary);
            background: var(--airo-primary-light);
        }

        .airo-radio-item input,
        .airo-checkbox-item input {
            margin-top: 2px;
            accent-color: var(--airo-primary);
        }

        .airo-radio-label,
        .airo-checkbox-label {
            flex: 1;
        }

        .airo-radio-label strong,
        .airo-checkbox-label strong {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--airo-text-primary);
            margin-bottom: 2px;
        }

        .airo-radio-label span,
        .airo-checkbox-label span {
            font-size: 13px;
            color: var(--airo-text-secondary);
        }

        /* Buttons */
        .airo-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            border-radius: var(--airo-radius);
            cursor: pointer;
            transition: var(--airo-transition);
            text-decoration: none;
            border: none;
        }

        .airo-btn-primary {
            background: var(--airo-primary);
            color: #fff;
        }

        .airo-btn-primary:hover {
            background: var(--airo-primary-hover);
            transform: translateY(-1px);
            box-shadow: var(--airo-shadow-md);
        }

        .airo-btn-secondary {
            background: var(--airo-card-bg);
            color: var(--airo-text-primary);
            border: 1px solid var(--airo-border);
        }

        .airo-btn-secondary:hover {
            background: var(--airo-bg);
            border-color: var(--airo-text-muted);
        }

        .airo-btn-lg {
            padding: 14px 32px;
            font-size: 15px;
        }

        .airo-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .airo-btn-group {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }

        /* Mapping Table */
        .airo-mapping-table {
            width: 100%;
            border-collapse: collapse;
        }

        .airo-mapping-table th,
        .airo-mapping-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--airo-border-light);
        }

        .airo-mapping-table th {
            font-size: 13px;
            font-weight: 600;
            color: var(--airo-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--airo-bg);
        }

        .airo-mapping-table td {
            font-size: 14px;
            color: var(--airo-text-primary);
        }

        .airo-mapping-table tr:hover td {
            background: var(--airo-bg);
        }

        .airo-mapping-table .airo-select {
            width: auto;
            min-width: 200px;
        }

        .airo-field-badge {
            display: inline-block;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 500;
            background: var(--airo-bg);
            border-radius: 20px;
            color: var(--airo-text-secondary);
        }

        .airo-field-badge.required {
            background: var(--airo-warning-light);
            color: #92400e;
        }

        /* Divider */
        .airo-divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 32px 0;
            color: var(--airo-text-muted);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .airo-divider::before,
        .airo-divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--airo-border);
        }

        /* Alerts */
        .airo-alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border-radius: var(--airo-radius);
            margin-bottom: 24px;
        }

        .airo-alert-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .airo-alert-content {
            flex: 1;
        }

        .airo-alert-title {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .airo-alert-error {
            background: var(--airo-error-light);
            color: #991b1b;
        }

        .airo-alert-success {
            background: var(--airo-success-light);
            color: #065f46;
        }

        .airo-alert-warning {
            background: var(--airo-warning-light);
            color: #92400e;
        }

        /* Progress Section */
        .airo-progress-section {
            text-align: center;
            padding: 20px 0;
        }

        .airo-progress-bar-container {
            width: 100%;
            height: 12px;
            background: var(--airo-bg);
            border-radius: 100px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        .airo-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--airo-primary), #818cf8);
            border-radius: 100px;
            transition: width 0.3s ease;
            position: relative;
        }

        .airo-progress-bar::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.3),
                transparent
            );
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .airo-progress-text {
            font-size: 14px;
            color: var(--airo-text-secondary);
            margin-bottom: 8px;
        }

        .airo-progress-percent {
            font-size: 32px;
            font-weight: 700;
            color: var(--airo-text-primary);
            margin-bottom: 4px;
        }

        /* Stats Cards */
        .airo-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 32px;
        }

        .airo-stat-card {
            background: var(--airo-card-bg);
            border: 1px solid var(--airo-border);
            border-radius: var(--airo-radius);
            padding: 20px;
            text-align: center;
        }

        .airo-stat-card.created {
            border-color: var(--airo-success);
            border-width: 2px;
        }

        .airo-stat-card.updated {
            border-color: var(--airo-primary);
            border-width: 2px;
        }

        .airo-stat-card.skipped {
            border-color: var(--airo-warning);
            border-width: 2px;
        }

        .airo-stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .airo-stat-card.created .airo-stat-number {
            color: var(--airo-success);
        }

        .airo-stat-card.updated .airo-stat-number {
            color: var(--airo-primary);
        }

        .airo-stat-card.skipped .airo-stat-number {
            color: var(--airo-warning);
        }

        .airo-stat-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--airo-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Product Lists */
        .airo-product-lists {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 24px;
        }

        .airo-product-list {
            background: var(--airo-bg);
            border-radius: var(--airo-radius);
            padding: 16px;
        }

        .airo-product-list-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--airo-border);
        }

        .airo-product-list-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .airo-product-list.created .airo-product-list-dot {
            background: var(--airo-success);
        }

        .airo-product-list.updated .airo-product-list-dot {
            background: var(--airo-primary);
        }

        .airo-product-list.skipped .airo-product-list-dot {
            background: var(--airo-warning);
        }

        .airo-product-list-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--airo-text-secondary);
        }

        .airo-product-list ul {
            list-style: none;
            margin: 0;
            padding: 0;
            max-height: 200px;
            overflow-y: auto;
        }

        .airo-product-list li {
            padding: 8px 0;
            font-size: 13px;
            color: var(--airo-text-primary);
            border-bottom: 1px solid var(--airo-border-light);
        }

        .airo-product-list li:last-child {
            border-bottom: none;
        }

        /* Empty State */
        .airo-empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--airo-text-muted);
        }

        .airo-empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .airo-wizard-wrap {
                padding: 0 12px;
            }

            .airo-steps-nav {
                flex-wrap: wrap;
            }

            .airo-step-item:not(:last-child)::after {
                width: 30px;
            }

            .airo-stats-grid,
            .airo-product-lists {
                grid-template-columns: 1fr;
            }

            .airo-card {
                padding: 20px;
            }

            .airo-btn-group {
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

        echo '<div class="airo-wizard-wrap">';

        // Header
        echo '<div class="airo-wizard-header">';
        echo '<h1>CSV Product Wizard</h1>';
        echo '<p>Import your products into WooCommerce with ease</p>';
        echo '</div>';

        // Check WooCommerce
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->render_alert( 'error', 'WooCommerce Required', 'WooCommerce is not active. Please activate WooCommerce first.' );
            echo '</div>';
            return;
        }

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

    private function render_alert( $type, $title, $message ) {
        $icons = array(
            'error'   => '&#10006;',
            'success' => '&#10004;',
            'warning' => '&#9888;',
        );
        $icon = isset( $icons[ $type ] ) ? $icons[ $type ] : '';

        echo '<div class="airo-alert airo-alert-' . esc_attr( $type ) . '">';
        echo '<span class="airo-alert-icon">' . $icon . '</span>';
        echo '<div class="airo-alert-content">';
        echo '<div class="airo-alert-title">' . esc_html( $title ) . '</div>';
        echo '<div>' . esc_html( $message ) . '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_steps_nav( $current_step ) {
        $steps = array(
            1 => 'Upload',
            2 => 'Map Fields',
            3 => 'Options',
            4 => 'Import',
        );

        echo '<ol class="airo-steps-nav">';
        foreach ( $steps as $step => $label ) {
            $class = 'airo-step-item';
            if ( $step === $current_step ) {
                $class .= ' active';
            } elseif ( $step < $current_step ) {
                $class .= ' completed';
            }

            echo '<li class="' . esc_attr( $class ) . '">';
            echo '<div class="airo-step-content">';
            echo '<div class="airo-step-number">';
            if ( $step < $current_step ) {
                // Checkmark shown via CSS
            } else {
                echo intval( $step );
            }
            echo '</div>';
            echo '<span class="airo-step-label">' . esc_html( $label ) . '</span>';
            echo '</div>';
            echo '</li>';
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

            if ( $has_uploaded_file ) {
                $file = $_FILES['csv_file'];

                if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
                    $msg = $this->human_upload_error( (int) $file['error'] );
                    $this->render_alert( 'error', 'Upload Error', $msg );
                } elseif ( empty( $file['tmp_name'] ) ) {
                    $this->render_alert( 'error', 'Upload Failed', 'Upload failed before reaching WordPress. The file may be larger than the server limit.' );
                } else {
                    if ( ! function_exists( 'wp_handle_upload' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                    }

                    $uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );

                    if ( isset( $uploaded['error'] ) ) {
                        $this->render_alert( 'error', 'Upload Error', $uploaded['error'] );
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
                        echo '<div class="airo-card"><p>Redirecting to mapping step...</p></div>';
                        return;
                    }
                }

            } elseif ( $selected_existing !== '' ) {

                $file_path = $this->build_airo_csv_path( $selected_existing );

                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    $this->render_alert( 'error', 'File Not Found', 'The selected CSV file could not be found.' );
                } else {
                    $url = add_query_arg(
                        array(
                            'page' => self::PAGE_SLUG,
                            'step' => 2,
                            'file' => urlencode( $file_path ),
                        ),
                        admin_url( 'admin.php' )
                    );
                    echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '">';
                    echo '<div class="airo-card"><p>Redirecting to mapping step...</p></div>';
                    return;
                }

            } else {
                $this->render_alert( 'error', 'No File Selected', 'Please upload a CSV file or select one from the server.' );
            }
        }

        $existing_csv_files = $this->get_existing_csv_files();

        ?>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'airo_csv_upload', 'airo_csv_upload_nonce' ); ?>

            <div class="airo-card">
                <div class="airo-card-header">
                    <div class="airo-card-icon">&#128194;</div>
                    <div>
                        <h2 class="airo-card-title">Upload CSV File</h2>
                        <p class="airo-card-subtitle">Drag and drop or click to browse</p>
                    </div>
                </div>

                <div class="airo-file-upload">
                    <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" />
                    <div class="airo-file-upload-icon">&#128206;</div>
                    <div class="airo-file-upload-text">Drop your CSV file here or click to browse</div>
                    <div class="airo-file-upload-hint">Supports .csv files exported from Excel, Google Sheets, etc.</div>
                </div>
            </div>

            <?php if ( ! empty( $existing_csv_files ) ) : ?>
            <div class="airo-divider">or select from server</div>

            <div class="airo-card">
                <div class="airo-card-header">
                    <div class="airo-card-icon">&#128451;</div>
                    <div>
                        <h2 class="airo-card-title">Server Files</h2>
                        <p class="airo-card-subtitle">CSV files already uploaded via FTP/SFTP</p>
                    </div>
                </div>

                <div class="airo-form-group">
                    <label class="airo-form-label">Select a file</label>
                    <select name="existing_csv" class="airo-select">
                        <option value="">Choose a file...</option>
                        <?php foreach ( $existing_csv_files as $csv ) : ?>
                            <option value="<?php echo esc_attr( $csv ); ?>"><?php echo esc_html( $csv ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="airo-form-hint">Files from: <code><?php echo esc_html( $airo_uploads_dir ); ?></code></p>
                </div>
            </div>
            <?php endif; ?>

            <div class="airo-btn-group">
                <button type="submit" class="airo-btn airo-btn-primary airo-btn-lg">
                    Continue to Field Mapping &#8594;
                </button>
            </div>
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

    private function build_airo_csv_path( $basename ) {
        $basename = basename( $basename );
        if ( $basename === '' ) {
            return '';
        }
        $dir = trailingslashit( $this->get_airo_uploads_dir() );
        return $dir . $basename;
    }

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
            $this->render_alert( 'error', 'File Not Found', 'CSV file not found. Please go back and upload again.' );
            echo '<div class="airo-btn-group">';
            echo '<a class="airo-btn airo-btn-secondary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '">&#8592; Back to Upload</a>';
            echo '</div>';
            return;
        }

        $headers = $this->get_csv_headers( $file_path );
        if ( empty( $headers ) ) {
            $this->render_alert( 'error', 'Invalid CSV', 'Could not read header row from CSV file.' );
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
            echo '<div class="airo-card"><p>Saving mapping and moving to options...</p></div>';
            return;
        }

        $headers_for_select = array( '' => '-- Not Mapped --' );
        foreach ( $headers as $index => $h ) {
            $headers_for_select[ $index ] = $h;
        }

        $fields = array(
            'name'              => array( 'label' => 'Product Name', 'required' => true ),
            'sku'               => array( 'label' => 'SKU', 'required' => false ),
            'description'       => array( 'label' => 'Description', 'required' => false ),
            'short_description' => array( 'label' => 'Short Description', 'required' => false ),
            'regular_price'     => array( 'label' => 'Regular Price', 'required' => false ),
            'sale_price'        => array( 'label' => 'Sale Price', 'required' => false ),
            'categories'        => array( 'label' => 'Categories', 'required' => false ),
            'stock_quantity'    => array( 'label' => 'Stock Quantity', 'required' => false ),
            'status'            => array( 'label' => 'Status', 'required' => false ),
            'images'            => array( 'label' => 'Images', 'required' => false ),
            'id'                => array( 'label' => 'Product ID (for updates)', 'required' => false ),
        );

        ?>
        <form method="post">
            <?php wp_nonce_field( 'airo_csv_mapping', 'airo_csv_mapping_nonce' ); ?>
            <input type="hidden" name="file" value="<?php echo esc_attr( $file_path ); ?>" />

            <div class="airo-card">
                <div class="airo-card-header">
                    <div class="airo-card-icon">&#128279;</div>
                    <div>
                        <h2 class="airo-card-title">Map CSV Columns</h2>
                        <p class="airo-card-subtitle">Match your CSV columns to WooCommerce product fields</p>
                    </div>
                </div>

                <table class="airo-mapping-table">
                    <thead>
                        <tr>
                            <th>WooCommerce Field</th>
                            <th>CSV Column</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $fields as $key => $field ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $field['label'] ); ?>
                                <?php if ( $field['required'] ) : ?>
                                    <span class="airo-field-badge required">Required</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="mapping[<?php echo esc_attr( $key ); ?>]" class="airo-select">
                                    <?php foreach ( $headers_for_select as $index => $label ) : ?>
                                        <option value="<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="airo-card">
                <div class="airo-card-header">
                    <div class="airo-card-icon">&#9881;</div>
                    <div>
                        <h2 class="airo-card-title">Custom Meta Fields</h2>
                        <p class="airo-card-subtitle">Map additional fields to product meta (optional)</p>
                    </div>
                </div>

                <table class="airo-mapping-table">
                    <thead>
                        <tr>
                            <th>Meta Key</th>
                            <th>CSV Column</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ( $i = 1; $i <= 3; $i++ ) : ?>
                        <tr>
                            <td>
                                <input type="text" name="custom_meta[<?php echo intval( $i ); ?>][key]" class="airo-input" placeholder="e.g., _custom_field" style="width: 200px;" />
                            </td>
                            <td>
                                <select name="custom_meta[<?php echo intval( $i ); ?>][column]" class="airo-select">
                                    <?php foreach ( $headers_for_select as $index => $label ) : ?>
                                        <option value="<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="airo-btn-group">
                <a class="airo-btn airo-btn-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ); ?>">&#8592; Back</a>
                <button type="submit" class="airo-btn airo-btn-primary airo-btn-lg">
                    Continue to Options &#8594;
                </button>
            </div>
        </form>
        <?php
    }

    /* --------------------------
     * STEP 3: IMPORT OPTIONS
     * -------------------------- */
    private function step_options() {
        $state = $this->get_state_from_request();
        if ( ! $state ) {
            $this->render_alert( 'error', 'Session Lost', 'Wizard state lost. Please start again.' );
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
            echo '<div class="airo-card"><p>Saving options and starting import...</p></div>';
            return;
        }

        ?>
        <form method="post">
            <?php wp_nonce_field( 'airo_csv_options', 'airo_csv_options_nonce' ); ?>
            <input type="hidden" name="state" value="<?php echo esc_attr( base64_encode( wp_json_encode( $state ) ) ); ?>" />

            <div class="airo-card">
                <div class="airo-card-header">
                    <div class="airo-card-icon">&#9881;</div>
                    <div>
                        <h2 class="airo-card-title">Import Mode</h2>
                        <p class="airo-card-subtitle">Choose how products should be handled</p>
                    </div>
                </div>

                <div class="airo-form-group">
                    <label class="airo-form-label">Create / Update Behavior</label>
                    <div class="airo-radio-group">
                        <label class="airo-radio-item selected">
                            <input type="radio" name="mode" value="create_update" checked onclick="this.parentElement.parentElement.querySelectorAll('.airo-radio-item').forEach(el => el.classList.remove('selected')); this.parentElement.classList.add('selected');" />
                            <div class="airo-radio-label">
                                <strong>Create &amp; Update</strong>
                                <span>Create new products and update existing ones</span>
                            </div>
                        </label>
                        <label class="airo-radio-item">
                            <input type="radio" name="mode" value="create_only" onclick="this.parentElement.parentElement.querySelectorAll('.airo-radio-item').forEach(el => el.classList.remove('selected')); this.parentElement.classList.add('selected');" />
                            <div class="airo-radio-label">
                                <strong>Create Only</strong>
                                <span>Only create new products, skip existing ones</span>
                            </div>
                        </label>
                        <label class="airo-radio-item">
                            <input type="radio" name="mode" value="update_only" onclick="this.parentElement.parentElement.querySelectorAll('.airo-radio-item').forEach(el => el.classList.remove('selected')); this.parentElement.classList.add('selected');" />
                            <div class="airo-radio-label">
                                <strong>Update Only</strong>
                                <span>Only update existing products, skip new ones</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="airo-form-group" style="margin-top: 24px;">
                    <label class="airo-form-label">Match Existing Products By</label>
                    <div class="airo-radio-group">
                        <label class="airo-radio-item selected">
                            <input type="radio" name="match_by" value="sku" checked onclick="this.parentElement.parentElement.querySelectorAll('.airo-radio-item').forEach(el => el.classList.remove('selected')); this.parentElement.classList.add('selected');" />
                            <div class="airo-radio-label">
                                <strong>SKU</strong>
                                <span>Match products using their SKU (recommended)</span>
                            </div>
                        </label>
                        <label class="airo-radio-item">
                            <input type="radio" name="match_by" value="id" onclick="this.parentElement.parentElement.querySelectorAll('.airo-radio-item').forEach(el => el.classList.remove('selected')); this.parentElement.classList.add('selected');" />
                            <div class="airo-radio-label">
                                <strong>Product ID</strong>
                                <span>Match by WordPress product ID (requires ID mapped)</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="airo-card">
                <div class="airo-card-header">
                    <div class="airo-card-icon">&#128247;</div>
                    <div>
                        <h2 class="airo-card-title">Categories &amp; Images</h2>
                        <p class="airo-card-subtitle">Configure how categories and images are handled</p>
                    </div>
                </div>

                <div class="airo-form-group">
                    <label class="airo-form-label">Category Delimiter</label>
                    <select name="category_delim" class="airo-select" style="width: 200px;">
                        <option value=",">Comma (,)</option>
                        <option value="|">Pipe (|)</option>
                        <option value=";">Semicolon (;)</option>
                    </select>
                    <p class="airo-form-hint">Used when splitting multiple categories in a single cell</p>
                </div>

                <div class="airo-form-group" style="margin-top: 24px;">
                    <label class="airo-checkbox-item selected">
                        <input type="checkbox" name="download_images" value="1" checked onclick="this.parentElement.classList.toggle('selected', this.checked);" />
                        <div class="airo-checkbox-label">
                            <strong>Download Images</strong>
                            <span>Automatically download and attach images from the Images column</span>
                        </div>
                    </label>
                </div>

                <div class="airo-form-group" style="margin-top: 24px;">
                    <label class="airo-form-label">Image Base URL (for filenames)</label>
                    <input type="text" name="image_base_url" class="airo-input" placeholder="<?php echo esc_attr( site_url( '/wp-content/uploads/airo_uploads/' ) ); ?>" />
                    <p class="airo-form-hint">
                        Leave empty to use the default: <code><?php echo esc_html( site_url( '/wp-content/uploads/airo_uploads/' ) ); ?></code><br />
                        Full URLs (starting with <code>http</code>) in the CSV are always used as-is.
                    </p>
                </div>
            </div>

            <div class="airo-btn-group">
                <a class="airo-btn airo-btn-secondary" href="javascript:history.back()">&#8592; Back</a>
                <button type="submit" class="airo-btn airo-btn-primary airo-btn-lg">
                    Start Import &#8594;
                </button>
            </div>
        </form>
        <?php
    }

    /* --------------------------
     * STEP 4: RUN IMPORT
     * -------------------------- */
    private function step_run() {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'airo_csv_run_import' ) ) {
            $this->render_alert( 'error', 'Security Error', 'Security check failed. Please start again.' );
            echo '<div class="airo-btn-group">';
            echo '<a class="airo-btn airo-btn-secondary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '">&#8592; Start Over</a>';
            echo '</div>';
            return;
        }

        $state = $this->get_state_from_request();
        if ( ! $state ) {
            $this->render_alert( 'error', 'Session Lost', 'Wizard state lost. Please start again.' );
            return;
        }

        $file        = isset( $state['file'] ) ? $state['file'] : '';
        $mapping     = isset( $state['mapping'] ) ? $state['mapping'] : array();
        $custom_meta = isset( $state['custom_meta'] ) ? $state['custom_meta'] : array();
        $options     = isset( $state['options'] ) ? $state['options'] : array();

        if ( ! $file || ! file_exists( $file ) ) {
            $this->render_alert( 'error', 'File Not Found', 'CSV file not found.' );
            return;
        }

        $total_rows = $this->count_csv_rows( $file );

        ?>
        <div class="airo-card">
            <div class="airo-card-header">
                <div class="airo-card-icon">&#128640;</div>
                <div>
                    <h2 class="airo-card-title">Importing Products</h2>
                    <p class="airo-card-subtitle">Please wait while your products are being imported</p>
                </div>
            </div>

            <div class="airo-progress-section">
                <div id="airo-progress-percent" class="airo-progress-percent">0%</div>
                <div class="airo-progress-bar-container">
                    <div id="airo-progress-bar" class="airo-progress-bar" style="width: 0%;"></div>
                </div>
                <div id="airo-progress-text" class="airo-progress-text">Starting import...</div>
            </div>

            <div class="airo-stats-grid">
                <div class="airo-stat-card created">
                    <div id="airo-stat-created" class="airo-stat-number">0</div>
                    <div class="airo-stat-label">Created</div>
                </div>
                <div class="airo-stat-card updated">
                    <div id="airo-stat-updated" class="airo-stat-number">0</div>
                    <div class="airo-stat-label">Updated</div>
                </div>
                <div class="airo-stat-card skipped">
                    <div id="airo-stat-skipped" class="airo-stat-number">0</div>
                    <div class="airo-stat-label">Skipped</div>
                </div>
            </div>

            <div class="airo-product-lists">
                <div class="airo-product-list created">
                    <div class="airo-product-list-header">
                        <span class="airo-product-list-dot"></span>
                        <span class="airo-product-list-title">Created Products</span>
                    </div>
                    <ul id="airo-list-created"></ul>
                </div>
                <div class="airo-product-list updated">
                    <div class="airo-product-list-header">
                        <span class="airo-product-list-dot"></span>
                        <span class="airo-product-list-title">Updated Products</span>
                    </div>
                    <ul id="airo-list-updated"></ul>
                </div>
                <div class="airo-product-list skipped">
                    <div class="airo-product-list-header">
                        <span class="airo-product-list-dot"></span>
                        <span class="airo-product-list-title">Skipped Products</span>
                    </div>
                    <ul id="airo-list-skipped"></ul>
                </div>
            </div>
        </div>

        <script>
        window.airoUpdateProgress = function(done, total, created, updated, skipped, action, productName) {
            var percent = total ? Math.round(done / total * 100) : 0;
            if (percent > 100) percent = 100;

            document.getElementById('airo-progress-bar').style.width = percent + '%';
            document.getElementById('airo-progress-percent').textContent = percent + '%';
            document.getElementById('airo-progress-text').textContent = done + ' of ' + total + ' products processed';

            document.getElementById('airo-stat-created').textContent = created;
            document.getElementById('airo-stat-updated').textContent = updated;
            document.getElementById('airo-stat-skipped').textContent = skipped;

            var listId = '';
            if (action === 'created') listId = 'airo-list-created';
            else if (action === 'updated') listId = 'airo-list-updated';
            else if (action === 'skipped') listId = 'airo-list-skipped';

            if (listId && productName) {
                var list = document.getElementById(listId);
                if (list) {
                    var li = document.createElement('li');
                    li.textContent = productName;
                    list.insertBefore(li, list.firstChild);
                }
            }
        };
        </script>
        <?php

        $this->init_log_file();
        $result = $this->run_import( $file, $mapping, $custom_meta, $options, $total_rows );
        $this->close_log_file();
        $log_url = $this->get_log_file_url();

        ?>
        <div class="airo-card" style="margin-top: 24px;">
            <div class="airo-alert airo-alert-success">
                <span class="airo-alert-icon">&#10004;</span>
                <div class="airo-alert-content">
                    <div class="airo-alert-title">Import Complete!</div>
                    <div>Successfully processed <?php echo intval( $result['created'] + $result['updated'] + $result['skipped'] ); ?> products.</div>
                </div>
            </div>

            <div class="airo-btn-group">
                <?php if ( ! empty( $log_url ) ) : ?>
                <a class="airo-btn airo-btn-secondary" href="<?php echo esc_url( $log_url ); ?>" target="_blank">
                    &#128196; Download Log
                </a>
                <?php endif; ?>
                <a class="airo-btn airo-btn-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ); ?>">
                    &#8635; Start New Import
                </a>
            </div>
        </div>
        <?php
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
            $this->render_alert( 'error', 'File Error', 'Cannot open file.' );
            $this->log_line( 'ERROR: Cannot open CSV file.' );
            return compact( 'created', 'updated', 'skipped' );
        }

        $headers = fgetcsv( $handle, 0, ',' );
        if ( ! $headers ) {
            $this->render_alert( 'error', 'Invalid CSV', 'Empty or invalid CSV.' );
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

                $product = new WC_Product_Simple();
            }

            if ( ! $product ) {
                $processed++;
                $skipped++;
                $this->log_line( 'ERROR: Could not create product object for "' . $name . '".' );
                $this->echo_progress_script( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            $product->set_name( $name );
            $product->set_description( $desc );
            $product->set_short_description( $short_desc );
            $product->set_status( $status );

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

            if ( $categories_raw !== '' ) {
                $this->assign_categories_with_delim( $saved_id, $categories_raw, $category_delim );
            }

            if ( $images_raw !== '' && $download_images ) {
                $this->handle_images_for_product( $saved_id, $images_raw, $image_base_url );
            }

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
    private function sanitize_file_path( $path ) {
        $uploads = wp_get_upload_dir();
        $basedir = realpath( $uploads['basedir'] );

        if ( ! $basedir ) {
            return '';
        }

        $resolved = realpath( $path );

        if ( ! $resolved ) {
            return '';
        }

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

    private function assign_categories_with_delim( $product_id, $categories_raw, $delim ) {
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

    private function handle_images_for_product( $product_id, $images_raw, $base_url ) {
        $separators   = array( ',', '|', ';' );
        $images_clean = str_replace( $separators, ',', $images_raw );
        $parts        = array_filter( array_map( 'trim', explode( ',', $images_clean ) ) );

        if ( empty( $parts ) ) {
            return;
        }

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

            if ( strpos( $img, 'http' ) !== 0 ) {
                $url = trailingslashit( $base_url ) . ltrim( $img, '/\\' );
            }

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

    public function maybe_shim_select2( $hook ) {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
            return;
        }

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

    public function allow_csv_uploads( $mimes ) {
        $mimes['csv'] = 'text/csv';
        return $mimes;
    }

}

new Airo_WC_CSV_Wizard();
