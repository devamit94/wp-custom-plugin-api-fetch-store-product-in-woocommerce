<?php
/*
Plugin Name: Product Fetcher with WooCommerce Integration
Plugin URI: https://yourwebsite.com/product-fetcher
Description: A plugin to fetch products from an API and add them to WooCommerce, including a color attribute.
Version: 1.0
Author: Your Name
Author URI: https://yourwebsite.com
*/

// Step 2: Enqueue Scripts for AJAX Handling
function enqueue_product_fetcher_scripts() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#store-products-btn').on('click', function() {
                var button = $(this);
                var messageDiv = $('#product-fetcher-message');

                // Disable the button while processing
                button.prop('disabled', true);

                // Show loading message
                messageDiv.html('<p>Processing...</p>');

                // Send the AJAX request to store the next batch of products
                $.ajax({
                    url: ajaxurl, // WordPress provided AJAX URL
                    method: 'POST',
                    data: {
                        action: 'store_products_in_woocommerce',
                        nonce: '<?php echo wp_create_nonce("product_fetcher_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            messageDiv.html('<p>' + response.data.message + '</p>');

                            // Update the button text and check if more products exist
                            if (response.data.next_index >= 110) {  // Stop once 110 products are stored
                                button.prop('disabled', true);
                                messageDiv.append('<p>Finished storing all 110 products.</p>');
                            } else {
                                button.prop('disabled', false);
                            }
                        } else {
                            messageDiv.html('<p>' + response.data.message + '</p>');
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        messageDiv.html('<p>An error occurred while processing your request.</p>');
                        button.prop('disabled', false);
                    }
                });
            });
        });
    </script>
    <?php
}
add_action('admin_head', 'enqueue_product_fetcher_scripts');

// Step 3: Handle the AJAX Request to Fetch and Store Products
function handle_product_fetcher_ajax() {
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'product_fetcher_nonce')) {
        die('Permission denied');
    }

    // Get the last stored product index from WordPress options
    $last_stored_index = get_option('last_stored_product_index', 0);

    // Set the API URL and authentication token
    $apiUrl = "https://api.promodata.com.au/products?limit=100&supplier_ids[]=88"; // Limit to 100 products per page
    $authToken = "OWVjNGE4MWQ2NzQzY2EzZjpjMzk0N2VkY2QxZjVlODYyN2VmNGM4ZmI3OTMzZjVkMg";

    // Calculate the current page number based on the last stored index
    $page = floor($last_stored_index / 100) + 1;  // 100 products per page

    // Fetch products from the API
    $pagedUrl = $apiUrl . "&page=$page"; 
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pagedUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "x-auth-token: $authToken"
    ));

    // Execute cURL request and fetch response
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        wp_send_json_error(array('message' => 'Curl error: ' . curl_error($ch)));
    }

    // Close cURL session
    curl_close($ch);

    // Decode the JSON response into an associative array
    $data = json_decode($response, true);

    if (!isset($data['data']) || empty($data['data'])) {
        wp_send_json_error(array('message' => 'No more products available.'));
    }

    // Get the products from the current page
    $products = $data['data'];
    $product_count = 0;

    foreach ($products as $product) {
        // Prepare the product data
        $product_name = isset($product['overview']['name']) ? $product['overview']['name'] : 'No name';
        $product_code = isset($product['overview']['code']) ? $product['overview']['code'] : 'No code';
        $product_description = isset($product['product']['description']) ? htmlspecialchars($product['product']['description']) : 'No description';
        $product_sizes = isset($product['details']) ? $product['details'] : [];

        // Get product price
        $product_price = '0'; // Default price
        if (isset($product['product']['prices']['price_groups'])) {
            foreach ($product['product']['prices']['price_groups'] as $priceGroup) {
                // Check if 'base_price' and 'price_breaks' exist
                if (isset($priceGroup['base_price']['price_breaks']) && !empty($priceGroup['base_price']['price_breaks'])) {
                    foreach ($priceGroup['base_price']['price_breaks'] as $priceBreak) {
                        // Set the product price to the first available price
                        $product_price = $priceBreak['price'];
                        break 2;  // Stop once the first price is set
                    }
                }
            }
        }

        // Loop through the list of colors
        $product_colors = [];
        if (isset($product['product']['colours']['list']) && is_array($product['product']['colours']['list'])) {
            foreach ($product['product']['colours']['list'] as $color_option) {
                // Add color name to the product_colors array
                $product_colors[] = $color_option['name'];  // Take the first color
            }
        }

        // Set the WooCommerce product data
        $new_product = array(
            'post_title'   => $product_name,
            'post_content' => $product_description,
            'post_status'  => 'publish',
            'post_type'    => 'product',
            'meta_input'   => array(
                '_regular_price' => $product_price,
                '_price'         => $product_price,
                '_sku'           => $product_code,
                '_product_description' => $product_description,
                'product_sizes'  => $product_sizes,
            ),
            'tax_input'    => array( // Add the color as an attribute
                //'pa_color' => $product_colors_string,  // Add the color(s) as a string
            ),
        );

        // Insert the product into the database
        $post_id = wp_insert_post($new_product);

        // Add product images (hero image and gallery)
        $product_image = isset($product['overview']['hero_image']) ? $product['overview']['hero_image'] : '';
        if ($product_image) {
            $image_id = media_sideload_image($product_image, $post_id, $product_name, 'id');
            if (!is_wp_error($image_id)) {
                set_post_thumbnail($post_id, $image_id); // Set hero image
            }
        }

        // Add product colors as attributes
        foreach($product_colors as $color){
            $colors = ucfirst(strtolower($color));
            wp_set_object_terms( $post_id, $colors, 'pa_colours', true );

            $att_color = Array('pa_color' =>Array(
                'name'=>'pa_colours',
                'value'=>$color,
                'is_visible' => '1',
                'is_taxonomy' => '1'
            ));

            update_post_meta( $post_id, '_product_attributes', $att_color);
        }

        // Count successfully added products
        $product_count++;
    }

    // Update the last stored product index
    $last_stored_index += $product_count;
    update_option('last_stored_product_index', $last_stored_index);

    // Check if we have stored the required 110 products
    if ($last_stored_index >= 110) {
        wp_send_json_success(array(
            'message' => "Successfully stored 110 products in WooCommerce!",
            'next_index' => $last_stored_index
        ));
    }

    // Return the success message and next product index
    wp_send_json_success(array(
        'message' => "Successfully stored $product_count products in WooCommerce!",
        'next_index' => $last_stored_index
    ));
}
add_action('wp_ajax_store_products_in_woocommerce', 'handle_product_fetcher_ajax');

// Step 4: Display the Product Fetcher Page in the Admin
function product_fetcher_page() {
    ?>
    <div class="wrap">
        <h1>Product List</h1>
        <div id="product-fetcher-message"></div>

        <button id="store-products-btn" class="button-primary">Store Next 100 Products</button>
    </div>
    <?php
}

// Step 5: Register Menu Page for the Plugin
function product_fetcher_menu() {
    add_menu_page(
        'Product Fetcher',
        'Product Fetcher',
        'manage_options',
        'product-fetcher',
        'product_fetcher_page'
    );
}
add_action('admin_menu', 'product_fetcher_menu');
?>
