<?php
// upload.php
/*
 * Plugin Name: User File Upload and Approval System
 * Plugin URI: https://rebelsofgaming.de/
 * Description: A WordPress plugin allowing users to upload files to a predefined directory. Admins approve uploads and decide the target directory.
 * Author: wahke
 * Author URI: https://wahke.lu
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Version: 1.6.1
 * Requires at least: 6.6
 * Requires PHP: 7.4

*/

// Register custom post type for uploads
function upload_register_post_type() {
    register_post_type('user_upload', [
        'labels' => [
            'name' => __('User Uploads'),
            'singular_name' => __('User Upload')
        ],
        'public' => false,
        'show_ui' => true,
        'supports' => ['title', 'editor', 'custom-fields'],
        'has_archive' => false,
        'rewrite' => false,
    ]);
}
add_action('init', 'upload_register_post_type');

// Add form shortcode
function upload_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to upload files.</p>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_upload_nonce'])) {
        if (!wp_verify_nonce($_POST['user_upload_nonce'], 'user_upload_form')) {
            return '<p>Nonce verification failed.</p>';
        }

        $title = sanitize_text_field($_POST['upload_title']);
        $description = wp_kses_post($_POST['upload_description']);
        $thumbnail = esc_url_raw($_POST['upload_thumbnail']);

        if (empty($title)) {
            return '<p>Please provide a title for the upload.</p>';
        }

        $file_url = '';
        if (!empty($_FILES['upload_file']['name'])) {
            $uploaded_file = $_FILES['upload_file'];

            // Use predefined upload directory
            $upload_dir = wp_upload_dir();
            $custom_dir = $upload_dir['basedir'] . '/user-uploads';

            if (!file_exists($custom_dir)) {
                mkdir($custom_dir, 0755, true);
            }

            $file_path = $custom_dir . '/' . basename($uploaded_file['name']);

            if (move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
                $file_url = $upload_dir['baseurl'] . '/user-uploads/' . basename($uploaded_file['name']);
            } else {
                return '<p>File upload failed. Please try again.</p>';
            }
        } else {
            return '<p>Please upload a file.</p>';
        }

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $description,
            'post_type' => 'user_upload',
            'post_status' => 'pending',
            'meta_input' => [
                '_upload_thumbnail' => $thumbnail,
                '_upload_file_url' => $file_url,
                '_upload_file_path' => $file_path,
            ],
        ]);

        if ($post_id) {
            return '<p>Your upload has been submitted and is pending approval.</p>';
        } else {
            return '<p>There was an error processing your upload.</p>';
        }
    }

    ob_start();
    ?>
    <form method="post" enctype="multipart/form-data">
        <label for="upload_title">Title:</label>
        <input type="text" name="upload_title" id="upload_title" required><br><br>

        <label for="upload_description">Description:</label><br>
        <textarea name="upload_description" id="upload_description" rows="5" cols="40"></textarea><br><br>

        <label for="upload_thumbnail">Thumbnail URL (optional):</label>
        <input type="url" name="upload_thumbnail" id="upload_thumbnail"><br><br>

        <label for="upload_file">File:</label>
        <input type="file" name="upload_file" id="upload_file" required><br><br>

        <input type="hidden" name="user_upload_nonce" value="<?php echo wp_create_nonce('user_upload_form'); ?>">
        <input type="submit" value="Submit Upload">
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('upload_form', 'upload_form_shortcode');

// Admin column for approval
function upload_admin_columns($columns) {
    $columns['approval_status'] = __('Approval Status');
    $columns['uploaded_file'] = __('Uploaded File');
    return $columns;
}
add_filter('manage_user_upload_posts_columns', 'upload_admin_columns');

function upload_admin_columns_content($column, $post_id) {
    if ($column === 'approval_status') {
        $approved = get_post_meta($post_id, '_approved', true);
        echo $approved ? 'Approved' : 'Pending';
    } elseif ($column === 'uploaded_file') {
        $file_url = get_post_meta($post_id, '_upload_file_url', true);
        if ($file_url) {
            echo '<a href="' . esc_url($file_url) . '" target="_blank">Download</a>';
        } else {
            echo 'No file uploaded';
        }
    }
}
add_action('manage_user_upload_posts_custom_column', 'upload_admin_columns_content', 10, 2);

function upload_add_meta_boxes() {
    add_meta_box(
        'upload_approval',
        __('Approve Upload'),
        'upload_approval_meta_box',
        'user_upload',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'upload_add_meta_boxes');

function upload_get_subdirectories_recursive($directory, $base = '') {
    $subdirs = [];
    $base_path = ABSPATH . trim($directory, '/') . $base;

    // Prevent invalid paths
    if (!is_dir($base_path)) {
        return $subdirs;
    }

    $items = scandir($base_path);
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            $path = $base_path . DIRECTORY_SEPARATOR . $item;
            $relative_path = $base . '/' . $item;
            if (is_dir($path)) {
                $subdirs[] = $directory . $relative_path;
                $subdirs = array_merge($subdirs, upload_get_subdirectories_recursive($directory, $relative_path));
            }
        }
    }
    return $subdirs;
}

function upload_approval_meta_box($post) {
    $approved = get_post_meta($post->ID, '_approved', true);
    $target_directory = get_option('upload_target_directory', '');
    $subdirectories = upload_get_subdirectories_recursive($target_directory);
    $file_path = get_post_meta($post->ID, '_upload_file_path', true);
    ?>
    <label for="upload_approval">Approval Status:</label>
    <select name="upload_approval" id="upload_approval">
        <option value="0" <?php selected($approved, '0'); ?>>Pending</option>
        <option value="1" <?php selected($approved, '1'); ?>>Approved</option>
    </select><br><br>

    <label for="upload_directory">Target Directory:</label>
    <select name="upload_directory" id="upload_directory">
        <?php foreach ($subdirectories as $dir) : ?>
            <option value="<?php echo esc_attr($dir); ?>"><?php echo esc_html($dir); ?></option>
        <?php endforeach; ?>
    </select><br><br>

    <p><strong>Uploaded File Path:</strong> <?php echo esc_html($file_path); ?></p>
    <?php
}

function upload_save_post_meta($post_id) {
    if (array_key_exists('upload_approval', $_POST)) {
        update_post_meta($post_id, '_approved', sanitize_text_field($_POST['upload_approval']));

        // Move file to target directory if approved
        if ($_POST['upload_approval'] == '1' && !empty($_POST['upload_directory'])) {
            $file_path = get_post_meta($post_id, '_upload_file_path', true);
            $target_dir = ABSPATH . trim(sanitize_text_field($_POST['upload_directory']), '/');

            if (file_exists($file_path) && is_dir($target_dir)) {
                $new_path = rtrim($target_dir, '/') . '/' . basename($file_path);
                if (rename($file_path, $new_path)) {
                    update_post_meta($post_id, '_upload_file_path', $new_path);
                }
            }
        }
    }
}
add_action('save_post', 'upload_save_post_meta');

// Settings page for directories
function upload_add_settings_page() {
    add_options_page(
        'Upload Settings',
        'Upload Settings',
        'manage_options',
        'upload-settings',
        'upload_settings_page'
    );
}
add_action('admin_menu', 'upload_add_settings_page');

function upload_settings_page() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $target_directory = sanitize_text_field($_POST['upload_target_directory']);
        update_option('upload_target_directory', $target_directory);
        echo '<div class="updated"><p>Settings updated successfully!</p></div>';
    }

    $target_directory = get_option('upload_target_directory', '');
    ?>
    <div class="wrap">
        <h1>Upload Settings</h1>
        <form method="post">
            <label for="upload_target_directory">Base Directory for Target Subdirectories (relative to WordPress root):</label><br>
            <input type="text" name="upload_target_directory" id="upload_target_directory" value="<?php echo esc_attr($target_directory); ?>" style="width:100%;"><br><br>

            <input type="submit" value="Save Settings" class="button button-primary">
        </form>
    </div>
    <?php
}
