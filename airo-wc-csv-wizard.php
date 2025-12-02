<?php
/*
Plugin Name: Airo WooCommerce Product Wizard
Description: Wizard-style product importer for WooCommerce with CSV import and URL scraping (External Importer style). Supports structured data extraction, affiliate products, and logging.
Version: 3.0.0
Author: Airo Studio
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * URL Product Extractor - External Importer Style
 * Extracts product data from URLs using structured data (JSON-LD, Open Graph, meta tags)
 */
class Airo_URL_Product_Extractor {

    /**
     * User agents to rotate through for better success rate
     */
    private $user_agents = array(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    );

    /**
     * Extract product data from a URL
     * @param string $url The product URL to extract data from
     * @return array|WP_Error Product data array or error
     */
    public function extract( $url ) {
        $url = esc_url_raw( $url );
        if ( empty( $url ) ) {
            return new WP_Error( 'invalid_url', 'Invalid URL provided.' );
        }

        // Try to fetch with different strategies
        $html = $this->fetch_url( $url );

        if ( is_wp_error( $html ) ) {
            return $html;
        }

        // Try extraction methods in order of reliability
        $product = $this->extract_json_ld( $html );

        if ( empty( $product['name'] ) ) {
            $product = array_merge( $product, $this->extract_open_graph( $html ) );
        }

        if ( empty( $product['name'] ) ) {
            $product = array_merge( $product, $this->extract_meta_tags( $html ) );
        }

        if ( empty( $product['name'] ) ) {
            $product = array_merge( $product, $this->extract_html_fallback( $html ) );
        }

        // Store the source URL
        $product['source_url'] = $url;

        // Ensure we have at least a name
        if ( empty( $product['name'] ) ) {
            return new WP_Error( 'no_product_data', 'Could not extract product data from URL.' );
        }

        return $product;
    }

    /**
     * Fetch URL content with anti-bot measures
     */
    private function fetch_url( $url ) {
        $parsed = parse_url( $url );
        $host = isset( $parsed['host'] ) ? $parsed['host'] : '';

        // Randomize user agent
        $user_agent = $this->user_agents[ array_rand( $this->user_agents ) ];

        // Build comprehensive headers to mimic real browser
        $headers = array(
            'Accept'             => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language'    => 'en-US,en;q=0.9',
            'Accept-Encoding'    => 'gzip, deflate, br',
            'Cache-Control'      => 'max-age=0',
            'Connection'         => 'keep-alive',
            'DNT'                => '1',
            'Sec-CH-UA'          => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'Sec-CH-UA-Mobile'   => '?0',
            'Sec-CH-UA-Platform' => '"Windows"',
            'Sec-Fetch-Dest'     => 'document',
            'Sec-Fetch-Mode'     => 'navigate',
            'Sec-Fetch-Site'     => 'none',
            'Sec-Fetch-User'     => '?1',
            'Upgrade-Insecure-Requests' => '1',
        );

        // First attempt with full headers
        $response = wp_remote_get( $url, array(
            'timeout'     => 30,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => $user_agent,
            'headers'     => $headers,
        ) );

        // Check for success
        if ( ! is_wp_error( $response ) ) {
            $status_code = wp_remote_retrieve_response_code( $response );
            if ( $status_code === 200 ) {
                $body = wp_remote_retrieve_body( $response );
                if ( ! empty( $body ) ) {
                    return $body;
                }
            }

            // Handle specific status codes
            if ( $status_code === 403 ) {
                // Try again with different approach - simpler headers
                return $this->fetch_url_simple( $url );
            }

            if ( $status_code === 503 ) {
                return new WP_Error( 'service_unavailable', 'Site returned 503. May have rate limiting or anti-bot protection (Cloudflare).' );
            }

            if ( $status_code >= 400 ) {
                return new WP_Error( 'http_error', "Failed to fetch URL. HTTP Status: {$status_code}. Site may block automated requests." );
            }
        }

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'fetch_error', 'Connection failed: ' . $response->get_error_message() );
        }

        return new WP_Error( 'empty_response', 'Empty response from URL.' );
    }

    /**
     * Simpler fetch attempt for sites that reject complex headers
     */
    private function fetch_url_simple( $url ) {
        // Try with minimal headers - some servers reject too many headers
        $response = wp_remote_get( $url, array(
            'timeout'     => 30,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'headers'     => array(
                'Accept' => 'text/html',
            ),
        ) );

        if ( ! is_wp_error( $response ) ) {
            $status_code = wp_remote_retrieve_response_code( $response );
            if ( $status_code === 200 ) {
                $body = wp_remote_retrieve_body( $response );
                if ( ! empty( $body ) ) {
                    return $body;
                }
            }
        }

        // Final attempt - try as a generic crawler
        $response = wp_remote_get( $url, array(
            'timeout'     => 30,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; WooCommerce Product Importer)',
        ) );

        if ( ! is_wp_error( $response ) ) {
            $status_code = wp_remote_retrieve_response_code( $response );
            if ( $status_code === 200 ) {
                return wp_remote_retrieve_body( $response );
            }

            // Provide helpful error message
            $error_msg = "Site blocks automated requests (HTTP {$status_code}). ";
            $error_msg .= "This site may use Cloudflare, bot protection, or require JavaScript. ";
            $error_msg .= "Try: 1) Use a CSV export instead, 2) Contact site for API access, ";
            $error_msg .= "3) Manually copy product data.";

            return new WP_Error( 'blocked', $error_msg );
        }

        return new WP_Error( 'fetch_failed', 'Could not connect to URL: ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'Unknown error' ) );
    }

    /**
     * Extract product data from JSON-LD structured data
     */
    private function extract_json_ld( $html ) {
        $product = array();

        // Find all JSON-LD scripts
        if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
            return $product;
        }

        foreach ( $matches[1] as $json_string ) {
            $json_string = trim( $json_string );
            if ( empty( $json_string ) ) continue;

            $data = json_decode( $json_string, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) continue;

            // Handle @graph arrays
            if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
                foreach ( $data['@graph'] as $item ) {
                    $product = $this->parse_json_ld_product( $item, $product );
                }
            } else {
                $product = $this->parse_json_ld_product( $data, $product );
            }

            // If we found a product, stop searching
            if ( ! empty( $product['name'] ) ) break;
        }

        return $product;
    }

    /**
     * Parse a JSON-LD product object
     */
    private function parse_json_ld_product( $data, $product ) {
        if ( ! is_array( $data ) ) return $product;

        $type = isset( $data['@type'] ) ? $data['@type'] : '';

        // Handle array of types
        if ( is_array( $type ) ) {
            $type = in_array( 'Product', $type ) ? 'Product' : ( $type[0] ?? '' );
        }

        if ( $type !== 'Product' ) return $product;

        // Basic product info
        if ( ! empty( $data['name'] ) ) {
            $product['name'] = sanitize_text_field( $data['name'] );
        }

        if ( ! empty( $data['description'] ) ) {
            $product['description'] = wp_kses_post( $data['description'] );
        }

        if ( ! empty( $data['sku'] ) ) {
            $product['sku'] = sanitize_text_field( $data['sku'] );
        }

        if ( ! empty( $data['brand'] ) ) {
            $brand = is_array( $data['brand'] ) ? ( $data['brand']['name'] ?? '' ) : $data['brand'];
            $product['brand'] = sanitize_text_field( $brand );
        }

        // Images
        if ( ! empty( $data['image'] ) ) {
            $product['images'] = $this->normalize_images( $data['image'] );
        }

        // Category/breadcrumb
        if ( ! empty( $data['category'] ) ) {
            $product['categories'] = is_array( $data['category'] )
                ? array_map( 'sanitize_text_field', $data['category'] )
                : array( sanitize_text_field( $data['category'] ) );
        }

        // Offers (pricing)
        if ( ! empty( $data['offers'] ) ) {
            $offers = $data['offers'];

            // Handle array of offers (take first one)
            if ( isset( $offers[0] ) ) {
                $offers = $offers[0];
            }

            if ( ! empty( $offers['price'] ) ) {
                $product['regular_price'] = $this->normalize_price( $offers['price'] );
            } elseif ( ! empty( $offers['lowPrice'] ) ) {
                $product['regular_price'] = $this->normalize_price( $offers['lowPrice'] );
            }

            if ( ! empty( $offers['priceCurrency'] ) ) {
                $product['currency'] = sanitize_text_field( $offers['priceCurrency'] );
            }

            if ( ! empty( $offers['availability'] ) ) {
                $availability = $offers['availability'];
                $product['in_stock'] = (
                    stripos( $availability, 'InStock' ) !== false ||
                    stripos( $availability, 'PreOrder' ) !== false
                );
            }

            if ( ! empty( $offers['url'] ) ) {
                $product['buy_url'] = esc_url_raw( $offers['url'] );
            }
        }

        // Reviews/Rating
        if ( ! empty( $data['aggregateRating'] ) ) {
            $rating = $data['aggregateRating'];
            if ( ! empty( $rating['ratingValue'] ) ) {
                $product['rating'] = floatval( $rating['ratingValue'] );
            }
            if ( ! empty( $rating['reviewCount'] ) ) {
                $product['review_count'] = intval( $rating['reviewCount'] );
            }
        }

        return $product;
    }

    /**
     * Extract product data from Open Graph meta tags
     */
    private function extract_open_graph( $html ) {
        $product = array();

        // Common OG properties
        $og_mappings = array(
            'og:title'       => 'name',
            'og:description' => 'description',
            'og:image'       => 'images',
            'og:url'         => 'canonical_url',
            'product:price:amount'   => 'regular_price',
            'product:price:currency' => 'currency',
            'product:availability'   => 'availability',
            'product:brand'          => 'brand',
        );

        foreach ( $og_mappings as $property => $key ) {
            $pattern = '/<meta[^>]*property=["\']' . preg_quote( $property, '/' ) . '["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i';
            $pattern2 = '/<meta[^>]*content=["\']([^"\']*)["\'][^>]*property=["\']' . preg_quote( $property, '/' ) . '["\'][^>]*>/i';

            if ( preg_match( $pattern, $html, $match ) || preg_match( $pattern2, $html, $match ) ) {
                $value = $match[1];

                if ( $key === 'images' ) {
                    $product['images'] = array( esc_url_raw( $value ) );
                } elseif ( $key === 'regular_price' ) {
                    $product['regular_price'] = $this->normalize_price( $value );
                } else {
                    $product[ $key ] = sanitize_text_field( $value );
                }
            }
        }

        return $product;
    }

    /**
     * Extract product data from standard meta tags
     */
    private function extract_meta_tags( $html ) {
        $product = array();

        // Title from meta or title tag
        if ( preg_match( '/<meta[^>]*name=["\']title["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $html, $match ) ) {
            $product['name'] = sanitize_text_field( $match[1] );
        } elseif ( preg_match( '/<title[^>]*>([^<]*)<\/title>/i', $html, $match ) ) {
            $product['name'] = sanitize_text_field( trim( $match[1] ) );
        }

        // Description
        if ( preg_match( '/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $html, $match ) ) {
            $product['description'] = sanitize_text_field( $match[1] );
        }

        return $product;
    }

    /**
     * Fallback HTML extraction for common patterns
     */
    private function extract_html_fallback( $html ) {
        $product = array();

        // Try to find product name in common elements
        $name_patterns = array(
            '/<h1[^>]*class=["\'][^"\']*product[^"\']*["\'][^>]*>([^<]*)<\/h1>/i',
            '/<h1[^>]*id=["\'][^"\']*product[^"\']*["\'][^>]*>([^<]*)<\/h1>/i',
            '/<h1[^>]*>([^<]*)<\/h1>/i',
        );

        foreach ( $name_patterns as $pattern ) {
            if ( preg_match( $pattern, $html, $match ) ) {
                $name = trim( strip_tags( $match[1] ) );
                if ( ! empty( $name ) && strlen( $name ) < 300 ) {
                    $product['name'] = sanitize_text_field( $name );
                    break;
                }
            }
        }

        // Try to find price
        $price_patterns = array(
            '/class=["\'][^"\']*price[^"\']*["\'][^>]*>\s*[\$\€\£]?\s*([\d,\.]+)/i',
            '/data-price=["\']?([\d\.]+)["\']?/i',
            '/itemprop=["\']price["\'][^>]*content=["\']?([\d\.]+)["\']?/i',
        );

        foreach ( $price_patterns as $pattern ) {
            if ( preg_match( $pattern, $html, $match ) ) {
                $product['regular_price'] = $this->normalize_price( $match[1] );
                break;
            }
        }

        return $product;
    }

    /**
     * Normalize image data to array of URLs
     */
    private function normalize_images( $images ) {
        if ( is_string( $images ) ) {
            return array( esc_url_raw( $images ) );
        }

        if ( is_array( $images ) ) {
            $urls = array();
            foreach ( $images as $img ) {
                if ( is_string( $img ) ) {
                    $urls[] = esc_url_raw( $img );
                } elseif ( is_array( $img ) && ! empty( $img['url'] ) ) {
                    $urls[] = esc_url_raw( $img['url'] );
                } elseif ( is_array( $img ) && ! empty( $img['@id'] ) ) {
                    $urls[] = esc_url_raw( $img['@id'] );
                }
            }
            return array_filter( $urls );
        }

        return array();
    }

    /**
     * Normalize price to a clean decimal
     */
    private function normalize_price( $price ) {
        if ( is_numeric( $price ) ) {
            return number_format( floatval( $price ), 2, '.', '' );
        }

        // Remove currency symbols and normalize
        $price = preg_replace( '/[^\d.,]/', '', $price );
        $price = str_replace( ',', '.', $price );

        // Handle European format (1.234,56 -> 1234.56)
        if ( preg_match( '/\.(\d{3})/', $price ) ) {
            $price = str_replace( '.', '', $price );
            $price = substr_replace( $price, '.', -2, 0 );
        }

        return is_numeric( $price ) ? number_format( floatval( $price ), 2, '.', '' ) : '';
    }

    /**
     * Bulk extract products from multiple URLs
     */
    public function bulk_extract( $urls ) {
        $results = array();

        foreach ( $urls as $url ) {
            $url = trim( $url );
            if ( empty( $url ) ) continue;

            $result = $this->extract( $url );

            if ( is_wp_error( $result ) ) {
                $results[] = array(
                    'url'     => $url,
                    'success' => false,
                    'error'   => $result->get_error_message(),
                );
            } else {
                $results[] = array(
                    'url'     => $url,
                    'success' => true,
                    'data'    => $result,
                );
            }
        }

        return $results;
    }
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

    private function render_steps( $current, $mode = 'csv' ) {
        $steps = array(
            1 => 'Choose Source',
            2 => $mode === 'url' ? 'Review Products' : 'Map Fields',
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

    /* STEP 1: Upload/Source Selection */
    private function step_upload() {
        $airo_uploads_dir = $this->get_airo_uploads_dir();
        $import_type = isset( $_POST['import_type'] ) ? sanitize_text_field( $_POST['import_type'] ) : 'csv';

        // Handle CSV upload
        if ( isset( $_POST['airo_csv_upload_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_upload_nonce'], 'airo_csv_upload' ) ) {

            if ( $import_type === 'url' ) {
                // Handle URL import
                $urls_raw = isset( $_POST['product_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['product_urls'] ) ) : '';
                $urls = array_filter( array_map( 'trim', explode( "\n", $urls_raw ) ) );

                if ( empty( $urls ) ) {
                    echo '<div class="wpai-notice wpai-notice-error"><p>Please enter at least one product URL.</p></div>';
                } else {
                    // Store URLs in state and proceed
                    $state = array(
                        'mode' => 'url',
                        'urls' => $urls,
                    );
                    $encoded = base64_encode( wp_json_encode( $state ) );
                    wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'state' => urlencode( $encoded ), 'mode' => 'url' ), admin_url( 'admin.php' ) ) );
                    exit;
                }
            } else {
                // Handle CSV import
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
                            wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'file' => urlencode( $uploaded['file'] ), 'mode' => 'csv' ), admin_url( 'admin.php' ) ) );
                            exit;
                        }
                    }
                } elseif ( $selected_existing !== '' ) {
                    $file_path = $this->build_airo_csv_path( $selected_existing );
                    if ( $file_path && file_exists( $file_path ) ) {
                        wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 2, 'file' => urlencode( $file_path ), 'mode' => 'csv' ), admin_url( 'admin.php' ) ) );
                        exit;
                    } else {
                        echo '<div class="wpai-notice wpai-notice-error"><p>Selected file not found.</p></div>';
                    }
                } else {
                    echo '<div class="wpai-notice wpai-notice-error"><p>Please upload a file or select one from the server.</p></div>';
                }
            }
        }

        $existing_csv_files = $this->get_existing_csv_files();
        ?>
        <div class="wpai-header">
            <h2>Step 1: Choose Your Import Source</h2>
        </div>
        <div class="wpai-body">
            <form method="post" enctype="multipart/form-data" id="wpai-source-form">
                <?php wp_nonce_field( 'airo_csv_upload', 'airo_csv_upload_nonce' ); ?>
                <input type="hidden" name="import_type" id="import_type" value="csv" />

                <!-- Import Type Selection -->
                <div class="wpai-options" id="wpai-import-options">
                    <div class="wpai-option selected" data-type="csv" onclick="wpaiSelectType('csv')">
                        <span class="wpai-option-icon">📄</span>
                        <div class="wpai-option-title">Import from CSV</div>
                        <div class="wpai-option-desc">Upload a CSV file with product data</div>
                    </div>
                    <div class="wpai-option" data-type="url" onclick="wpaiSelectType('url')">
                        <span class="wpai-option-icon">🔗</span>
                        <div class="wpai-option-title">Import from URLs</div>
                        <div class="wpai-option-desc">Paste product URLs to extract data automatically</div>
                    </div>
                </div>

                <!-- CSV Upload Section -->
                <div id="wpai-csv-section">
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
                </div>

                <!-- URL Import Section -->
                <div id="wpai-url-section" style="display: none;">
                    <div class="wpai-section">
                        <div class="wpai-section-title">Product URLs</div>
                        <p style="margin-bottom: 15px; color: #666;">
                            Paste product URLs below (one per line). The importer will automatically extract product data using
                            <strong>structured data</strong> (JSON-LD, Open Graph) from each page.
                        </p>
                        <textarea name="product_urls" id="product_urls" rows="10"
                            style="width: 100%; font-family: monospace; font-size: 13px; padding: 10px; border: 1px solid #8c8f94; border-radius: 4px;"
                            placeholder="https://example.com/product/item-1&#10;https://example.com/product/item-2&#10;https://another-store.com/products/widget"></textarea>
                        <p class="wpai-help">
                            <strong>Supported:</strong> Most e-commerce sites including Shopify, WooCommerce, Magento, BigCommerce, and any site with schema.org Product markup.
                        </p>
                    </div>

                    <div class="wpai-section">
                        <div class="wpai-section-title">How It Works</div>
                        <div style="display: flex; gap: 30px; margin-top: 10px;">
                            <div style="flex: 1;">
                                <strong>1. Structured Data</strong>
                                <p style="color: #666; font-size: 13px; margin: 5px 0 0;">Extracts JSON-LD Product schema with name, price, images, description, SKU, and availability.</p>
                            </div>
                            <div style="flex: 1;">
                                <strong>2. Open Graph</strong>
                                <p style="color: #666; font-size: 13px; margin: 5px 0 0;">Falls back to Open Graph meta tags (og:title, og:image, product:price).</p>
                            </div>
                            <div style="flex: 1;">
                                <strong>3. HTML Parsing</strong>
                                <p style="color: #666; font-size: 13px; margin: 5px 0 0;">Last resort: extracts data from common HTML patterns and meta tags.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="wpai-buttons">
            <div></div>
            <button type="submit" form="wpai-source-form" class="wpai-btn wpai-btn-primary wpai-btn-large">
                Continue &rarr;
            </button>
        </div>

        <script>
        function wpaiSelectType(type) {
            document.getElementById('import_type').value = type;

            // Update option styling
            document.querySelectorAll('.wpai-option').forEach(function(opt) {
                opt.classList.remove('selected');
            });
            document.querySelector('.wpai-option[data-type="' + type + '"]').classList.add('selected');

            // Show/hide sections
            document.getElementById('wpai-csv-section').style.display = type === 'csv' ? 'block' : 'none';
            document.getElementById('wpai-url-section').style.display = type === 'url' ? 'block' : 'none';
        }

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

    /* STEP 2: Mapping (CSV) or Review (URL) */
    private function step_mapping() {
        $mode = isset( $_GET['mode'] ) ? sanitize_text_field( $_GET['mode'] ) : 'csv';

        if ( $mode === 'url' ) {
            $this->step_url_review();
            return;
        }

        // Original CSV mapping logic
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
                'mode'        => 'csv',
                'file'        => $file_path,
                'mapping'     => isset( $_POST['mapping'] ) ? (array) $_POST['mapping'] : array(),
                'custom_meta' => isset( $_POST['custom_meta'] ) ? (array) $_POST['custom_meta'] : array(),
            );
            $encoded = base64_encode( wp_json_encode( $state ) );
            wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 3, 'state' => urlencode( $encoded ), 'mode' => 'csv' ), admin_url( 'admin.php' ) ) );
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

    /* STEP 2 (URL Mode): Review extracted products */
    private function step_url_review() {
        $state = $this->get_state_from_request();

        if ( ! $state || empty( $state['urls'] ) ) {
            echo '<div class="wpai-header"><h2>Error</h2></div>';
            echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>No URLs provided. Please go back and try again.</p></div></div>';
            echo '<div class="wpai-buttons"><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '" class="wpai-btn wpai-btn-secondary">&larr; Back</a><div></div></div>';
            return;
        }

        // Handle form submission - proceed to options
        if ( isset( $_POST['airo_url_review_nonce'] ) && wp_verify_nonce( $_POST['airo_url_review_nonce'], 'airo_url_review' ) ) {
            $selected = isset( $_POST['selected_products'] ) ? (array) $_POST['selected_products'] : array();
            $products_json = isset( $_POST['products_data'] ) ? wp_unslash( $_POST['products_data'] ) : '[]';
            $all_products = json_decode( $products_json, true );

            // Filter to only selected products
            $selected_products = array();
            foreach ( $all_products as $idx => $product ) {
                if ( in_array( $idx, $selected ) ) {
                    $selected_products[] = $product;
                }
            }

            if ( empty( $selected_products ) ) {
                echo '<div class="wpai-notice wpai-notice-error"><p>Please select at least one product to import.</p></div>';
            } else {
                $new_state = array(
                    'mode'     => 'url',
                    'products' => $selected_products,
                );
                $encoded = base64_encode( wp_json_encode( $new_state ) );
                wp_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 3, 'state' => urlencode( $encoded ), 'mode' => 'url' ), admin_url( 'admin.php' ) ) );
                exit;
            }
        }

        // Extract products from URLs
        $extractor = new Airo_URL_Product_Extractor();
        $results = $extractor->bulk_extract( $state['urls'] );

        $success_count = 0;
        $error_count = 0;
        $products = array();

        foreach ( $results as $result ) {
            if ( $result['success'] ) {
                $success_count++;
                $products[] = $result['data'];
            } else {
                $error_count++;
            }
        }
        ?>
        <div class="wpai-header">
            <h2>Step 2: Review Extracted Products</h2>
        </div>
        <div class="wpai-body">
            <form method="post">
                <?php wp_nonce_field( 'airo_url_review', 'airo_url_review_nonce' ); ?>
                <input type="hidden" name="products_data" value="<?php echo esc_attr( wp_json_encode( $products ) ); ?>" />

                <!-- Summary -->
                <div class="wpai-results" style="margin-bottom: 30px;">
                    <div class="wpai-result-box created">
                        <div class="wpai-result-num"><?php echo $success_count; ?></div>
                        <div class="wpai-result-label">Extracted</div>
                    </div>
                    <div class="wpai-result-box skipped">
                        <div class="wpai-result-num"><?php echo $error_count; ?></div>
                        <div class="wpai-result-label">Failed</div>
                    </div>
                </div>

                <?php if ( $error_count > 0 ) : ?>
                <div class="wpai-notice wpai-notice-error" style="margin-bottom: 20px;">
                    <p><strong>Some URLs could not be processed:</strong></p>
                    <ul style="margin: 10px 0 0 20px;">
                        <?php foreach ( $results as $result ) : ?>
                            <?php if ( ! $result['success'] ) : ?>
                                <li style="margin-bottom: 8px;">
                                    <code><?php echo esc_html( $result['url'] ); ?></code><br>
                                    <span style="color: #666; font-size: 12px;"><?php echo esc_html( $result['error'] ); ?></span>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-top: 15px; padding: 12px; background: #fff8e5; border-radius: 4px;">
                        <strong>Tips for blocked sites:</strong>
                        <ul style="margin: 8px 0 0 20px; font-size: 13px;">
                            <li>Try specific product URLs instead of category/homepage</li>
                            <li>Sites with Cloudflare/bot protection may not work with server-side extraction</li>
                            <li>Check if the site offers a product feed, CSV export, or API</li>
                            <li>For WooCommerce sites, try: <code>/wp-json/wc/v3/products</code> (requires auth)</li>
                            <li>For Shopify sites, try: <code>/products.json</code></li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $products ) ) : ?>
                <div class="wpai-section">
                    <div class="wpai-section-title">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="select-all" checked onchange="wpaiToggleAll(this.checked);" />
                            Select products to import
                        </label>
                    </div>

                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid #ddd;">
                                <th style="width: 30px; padding: 10px 5px;"></th>
                                <th style="width: 60px; padding: 10px 5px;">Image</th>
                                <th style="text-align: left; padding: 10px 5px;">Product</th>
                                <th style="text-align: left; padding: 10px 5px; width: 100px;">Price</th>
                                <th style="text-align: left; padding: 10px 5px; width: 120px;">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $products as $idx => $product ) : ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 10px 5px; text-align: center;">
                                    <input type="checkbox" name="selected_products[]" value="<?php echo $idx; ?>" checked class="product-checkbox" />
                                </td>
                                <td style="padding: 10px 5px;">
                                    <?php if ( ! empty( $product['images'][0] ) ) : ?>
                                        <img src="<?php echo esc_url( $product['images'][0] ); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;" />
                                    <?php else : ?>
                                        <div style="width: 50px; height: 50px; background: #f0f0f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #999;">?</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 5px;">
                                    <strong><?php echo esc_html( $product['name'] ); ?></strong>
                                    <?php if ( ! empty( $product['sku'] ) ) : ?>
                                        <br><small style="color: #666;">SKU: <?php echo esc_html( $product['sku'] ); ?></small>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $product['description'] ) ) : ?>
                                        <br><small style="color: #999;"><?php echo esc_html( wp_trim_words( $product['description'], 15 ) ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 5px;">
                                    <?php if ( ! empty( $product['regular_price'] ) ) : ?>
                                        <strong><?php echo esc_html( ( $product['currency'] ?? '$' ) . $product['regular_price'] ); ?></strong>
                                    <?php else : ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 5px;">
                                    <a href="<?php echo esc_url( $product['source_url'] ); ?>" target="_blank" style="font-size: 12px; color: #007cba;">
                                        <?php echo esc_html( parse_url( $product['source_url'], PHP_URL_HOST ) ); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else : ?>
                <div class="wpai-notice wpai-notice-error">
                    <p>No products could be extracted from the provided URLs. Please check the URLs and try again.</p>
                </div>
                <?php endif; ?>
        </div>
        <div class="wpai-buttons">
            <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 1 ), admin_url( 'admin.php' ) ) ); ?>" class="wpai-btn wpai-btn-secondary">&larr; Back</a>
            <?php if ( ! empty( $products ) ) : ?>
            <button type="submit" class="wpai-btn wpai-btn-primary wpai-btn-large">Continue &rarr;</button>
            <?php endif; ?>
        </div>
            </form>

        <script>
        function wpaiToggleAll(checked) {
            document.querySelectorAll('.product-checkbox').forEach(function(cb) {
                cb.checked = checked;
            });
        }
        </script>
        <?php
    }

    /* STEP 3: Options */
    private function step_options() {
        $state = $this->get_state_from_request();
        $mode = isset( $state['mode'] ) ? $state['mode'] : 'csv';

        if ( ! $state ) {
            echo '<div class="wpai-header"><h2>Error</h2></div>';
            echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>Session lost. Please start again.</p></div></div>';
            return;
        }

        if ( isset( $_POST['airo_csv_options_nonce'] ) && wp_verify_nonce( $_POST['airo_csv_options_nonce'], 'airo_csv_options' ) ) {
            $state['options'] = array(
                'import_mode'     => isset( $_POST['import_mode'] ) ? sanitize_text_field( $_POST['import_mode'] ) : 'create_update',
                'match_by'        => isset( $_POST['match_by'] ) ? sanitize_text_field( $_POST['match_by'] ) : 'sku',
                'category_delim'  => isset( $_POST['category_delim'] ) ? sanitize_text_field( $_POST['category_delim'] ) : ',',
                'download_images' => isset( $_POST['download_images'] ) ? 1 : 0,
                'image_base_url'  => isset( $_POST['image_base_url'] ) ? esc_url_raw( $_POST['image_base_url'] ) : '',
                // URL import specific options
                'product_type'    => isset( $_POST['product_type'] ) ? sanitize_text_field( $_POST['product_type'] ) : 'simple',
                'affiliate_id'    => isset( $_POST['affiliate_id'] ) ? sanitize_text_field( $_POST['affiliate_id'] ) : '',
                'price_markup'    => isset( $_POST['price_markup'] ) ? floatval( $_POST['price_markup'] ) : 0,
            );
            $encoded = base64_encode( wp_json_encode( $state ) );
            $url = wp_nonce_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'step' => 4, 'state' => urlencode( $encoded ), 'mode' => $mode ), admin_url( 'admin.php' ) ), 'airo_csv_run_import' );
            wp_redirect( $url );
            exit;
        }

        $is_url_mode = ( $mode === 'url' );
        ?>
        <div class="wpai-header">
            <h2>Step 3: Import Settings</h2>
        </div>
        <div class="wpai-body">
            <form method="post">
                <?php wp_nonce_field( 'airo_csv_options', 'airo_csv_options_nonce' ); ?>
                <input type="hidden" name="state" value="<?php echo esc_attr( base64_encode( wp_json_encode( $state ) ) ); ?>" />

                <?php if ( $is_url_mode ) : ?>
                <!-- URL Import Specific Options -->
                <div class="wpai-section">
                    <div class="wpai-section-title">Product Type</div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Create as:</div>
                        <div class="wpai-field-input">
                            <div class="wpai-radio-list">
                                <div class="wpai-radio-item">
                                    <input type="radio" name="product_type" value="external" id="type_external" checked />
                                    <label for="type_external">
                                        <strong>External/Affiliate Product</strong><br>
                                        <span>Links to external site. Best for affiliate marketing.</span>
                                    </label>
                                </div>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="product_type" value="simple" id="type_simple" />
                                    <label for="type_simple">
                                        <strong>Simple Product</strong><br>
                                        <span>Regular WooCommerce product. Good for dropshipping.</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Price markup:</div>
                        <div class="wpai-field-input">
                            <input type="number" name="price_markup" value="0" step="0.01" style="width: 100px;" /> %
                            <p class="wpai-help">Add percentage to extracted prices (e.g., 20 for 20% markup)</p>
                        </div>
                    </div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Affiliate ID:</div>
                        <div class="wpai-field-input">
                            <input type="text" name="affiliate_id" placeholder="e.g., ?ref=yourcode" style="width: 250px;" />
                            <p class="wpai-help">Appended to product URLs for affiliate tracking</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="wpai-section">
                    <div class="wpai-section-title">Import Behavior</div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">What to do:</div>
                        <div class="wpai-field-input">
                            <div class="wpai-radio-list">
                                <div class="wpai-radio-item">
                                    <input type="radio" name="import_mode" value="create_update" id="mode_cu" checked />
                                    <label for="mode_cu"><strong>Create new & update existing</strong><br><span>Most common option</span></label>
                                </div>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="import_mode" value="create_only" id="mode_c" />
                                    <label for="mode_c"><strong>Create new products only</strong><br><span>Skip existing products</span></label>
                                </div>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="import_mode" value="update_only" id="mode_u" />
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
                                <?php if ( $is_url_mode ) : ?>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="match_by" value="url" id="match_url" />
                                    <label for="match_url">Source URL</label>
                                </div>
                                <?php endif; ?>
                                <div class="wpai-radio-item">
                                    <input type="radio" name="match_by" value="id" id="match_id" />
                                    <label for="match_id">Product ID</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ( ! $is_url_mode ) : ?>
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
                <?php else : ?>
                <div class="wpai-section">
                    <div class="wpai-section-title">Images</div>

                    <div class="wpai-field-row">
                        <div class="wpai-field-label">Image handling:</div>
                        <div class="wpai-field-input">
                            <div class="wpai-radio-item">
                                <input type="checkbox" name="download_images" value="1" id="dl_images" checked />
                                <label for="dl_images">Download images to your server</label>
                            </div>
                            <p class="wpai-help">Uncheck to display images directly from source (saves disk space but relies on external hosting)</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
        $mode = isset( $state['mode'] ) ? $state['mode'] : 'csv';

        // Validate state based on mode
        if ( $mode === 'url' ) {
            if ( ! $state || empty( $state['products'] ) ) {
                echo '<div class="wpai-header"><h2>Error</h2></div>';
                echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>Session lost or no products found.</p></div></div>';
                return;
            }
            $total_rows = count( $state['products'] );
        } else {
            if ( ! $state || empty( $state['file'] ) || ! file_exists( $state['file'] ) ) {
                echo '<div class="wpai-header"><h2>Error</h2></div>';
                echo '<div class="wpai-body"><div class="wpai-notice wpai-notice-error"><p>Session lost or file not found.</p></div></div>';
                return;
            }
            $total_rows = $this->count_csv_rows( $state['file'] );
        }

        $options = isset( $state['options'] ) ? $state['options'] : array();
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

        if ( $mode === 'url' ) {
            $result = $this->run_url_import( $state['products'], $options, $total_rows );
        } else {
            $file        = $state['file'];
            $mapping     = isset( $state['mapping'] ) ? $state['mapping'] : array();
            $custom_meta = isset( $state['custom_meta'] ) ? $state['custom_meta'] : array();
            $result = $this->run_import( $file, $mapping, $custom_meta, $options, $total_rows );
        }

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

    /**
     * Run URL-based import (External Importer style)
     */
    private function run_url_import( $products, $options, $total_rows ) {
        $created = $updated = $skipped = $processed = 0;

        $import_mode     = isset( $options['import_mode'] ) ? $options['import_mode'] : 'create_update';
        $match_by        = isset( $options['match_by'] ) ? $options['match_by'] : 'sku';
        $product_type    = isset( $options['product_type'] ) ? $options['product_type'] : 'external';
        $download_images = ! empty( $options['download_images'] );
        $affiliate_id    = isset( $options['affiliate_id'] ) ? $options['affiliate_id'] : '';
        $price_markup    = isset( $options['price_markup'] ) ? floatval( $options['price_markup'] ) : 0;

        $this->log_line( 'Starting URL import: ' . count( $products ) . ' products' );
        $this->log_line( 'Product type: ' . $product_type );

        foreach ( $products as $product_data ) {
            $name = isset( $product_data['name'] ) ? trim( $product_data['name'] ) : '';

            if ( empty( $name ) ) {
                $processed++; $skipped++;
                $this->log_line( 'Skipped: no name' );
                $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', '(no name)' );
                continue;
            }

            $sku        = isset( $product_data['sku'] ) ? trim( $product_data['sku'] ) : '';
            $source_url = isset( $product_data['source_url'] ) ? $product_data['source_url'] : '';

            // Find existing product
            $product_id = 0;
            if ( 'sku' === $match_by && $sku !== '' ) {
                $product_id = wc_get_product_id_by_sku( $sku );
            } elseif ( 'url' === $match_by && $source_url !== '' ) {
                // Match by source URL stored in meta
                global $wpdb;
                $product_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_airo_source_url' AND meta_value = %s LIMIT 1",
                    $source_url
                ) );
                $product_id = $product_id ? intval( $product_id ) : 0;
            }

            $product  = null;
            $action   = 'skipped';
            $existing = false;

            if ( $product_id ) {
                if ( 'create_only' === $import_mode ) {
                    $processed++; $skipped++;
                    $this->log_line( 'Skipped existing: ' . $name );
                    $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                    continue;
                }
                $product  = wc_get_product( $product_id );
                $existing = true;
            } else {
                if ( 'update_only' === $import_mode ) {
                    $processed++; $skipped++;
                    $this->log_line( 'Skipped new: ' . $name );
                    $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                    continue;
                }

                // Create new product based on type
                if ( $product_type === 'external' ) {
                    $product = new WC_Product_External();
                } else {
                    $product = new WC_Product_Simple();
                }
            }

            if ( ! $product ) {
                $processed++; $skipped++;
                $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, 'skipped', $name );
                continue;
            }

            // Set product data
            $product->set_name( $name );

            if ( ! empty( $product_data['description'] ) ) {
                $product->set_description( wp_kses_post( $product_data['description'] ) );
            }

            if ( ! empty( $product_data['sku'] ) ) {
                try {
                    $product->set_sku( $sku );
                } catch ( WC_Data_Exception $e ) {
                    $this->log_line( 'SKU error: ' . $e->getMessage() );
                }
            }

            // Handle price with optional markup
            if ( ! empty( $product_data['regular_price'] ) ) {
                $price = floatval( $product_data['regular_price'] );
                if ( $price_markup > 0 ) {
                    $price = $price * ( 1 + ( $price_markup / 100 ) );
                }
                $product->set_regular_price( number_format( $price, 2, '.', '' ) );
            }

            // For external products, set the product URL
            if ( $product_type === 'external' && $product instanceof WC_Product_External ) {
                $external_url = $source_url;
                if ( ! empty( $affiliate_id ) ) {
                    $separator = ( strpos( $external_url, '?' ) !== false ) ? '&' : '?';
                    $external_url .= $separator . ltrim( $affiliate_id, '?&' );
                }
                $product->set_product_url( $external_url );
                $product->set_button_text( 'Buy Now' );
            }

            $product->set_status( 'publish' );

            // Handle stock status
            if ( isset( $product_data['in_stock'] ) ) {
                $product->set_stock_status( $product_data['in_stock'] ? 'instock' : 'outofstock' );
            }

            // Save product
            try {
                $saved_id = $product->save();
            } catch ( WC_Data_Exception $e ) {
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

            // Store source URL for future matching/syncing
            update_post_meta( $saved_id, '_airo_source_url', $source_url );

            // Store brand if available
            if ( ! empty( $product_data['brand'] ) ) {
                update_post_meta( $saved_id, '_airo_brand', sanitize_text_field( $product_data['brand'] ) );
            }

            // Handle categories
            if ( ! empty( $product_data['categories'] ) ) {
                $cat_names = is_array( $product_data['categories'] ) ? $product_data['categories'] : array( $product_data['categories'] );
                $term_ids = array();
                foreach ( $cat_names as $cat_name ) {
                    $term = term_exists( $cat_name, 'product_cat' );
                    if ( ! $term ) {
                        $term = wp_insert_term( $cat_name, 'product_cat' );
                    }
                    if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
                        $term_ids[] = (int) $term['term_id'];
                    }
                }
                if ( $term_ids ) {
                    wp_set_object_terms( $saved_id, $term_ids, 'product_cat' );
                }
            }

            // Handle images
            if ( ! empty( $product_data['images'] ) ) {
                if ( $download_images ) {
                    $this->handle_images( $saved_id, implode( ',', $product_data['images'] ), '' );
                } else {
                    // Store external image URLs (for display without downloading)
                    update_post_meta( $saved_id, '_airo_external_images', $product_data['images'] );

                    // Set the first image as the featured image URL (if supported by theme)
                    if ( ! empty( $product_data['images'][0] ) ) {
                        update_post_meta( $saved_id, '_airo_featured_image_url', $product_data['images'][0] );
                    }
                }
            }

            if ( $existing ) {
                $updated++;
                $action = 'updated';
                $this->log_line( 'Updated: ' . $name );
            } else {
                $created++;
                $action = 'created';
                $this->log_line( 'Created: ' . $name );
            }

            $processed++;
            $this->echo_progress( $processed, $total_rows, $created, $updated, $skipped, $action, $name );
        }

        return compact( 'created', 'updated', 'skipped' );
    }

    /* Core import logic */
    private function run_import( $file_path, $mapping, $custom_meta, $options, $total_rows = null ) {
        $created = $updated = $skipped = $processed = 0;

        $mode            = isset( $options['import_mode'] ) ? $options['import_mode'] : 'create_update';
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
