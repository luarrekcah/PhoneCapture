<?php
/**
 * Plugin Name: Phone Capture Modal
 * Description: Exibe um modal solicitando o número de telefone e registra cada post visualizado pelo usuário no painel do plugin.
 * Version: 1.0
 * Author: RaulRodrigues
 */

if (!defined('ABSPATH')) exit;

class PhoneCaptureModal {

    public function __construct() {
        add_action('wp_footer', [$this, 'render_modal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('init', [$this, 'handle_phone_submit']);
        add_action('wp', [$this, 'track_post_view']);
        add_action('admin_menu', [$this, 'admin_menu']);
    }

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'phone_capture_views';

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function enqueue_scripts() {
        wp_enqueue_script('phone-capture-js', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], null, true);
        wp_localize_script('phone-capture-js', 'phoneCaptureData', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }

    public function render_modal() {
        if (!isset($_COOKIE['phone_capture'])) {
            echo '
            <div id="phone-capture-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;z-index:99999;">
                <div style="background:#fff;padding:20px;border-radius:8px;max-width:350px;text-align:center;">
                    <h3>Digite seu número de WhatsApp</h3>
                    <form method="post">
                        <input type="text" name="phone_capture" placeholder="(00) 00000-0000" style="width:100%;padding:10px;margin:10px 0;">
                        <button type="submit" style="padding:10px 20px;cursor:pointer;background:#0073aa;color:#fff;border:none;">Enviar</button>
                    </form>
                </div>
            </div>';
        }
    }

    public function handle_phone_submit() {
        if (isset($_POST['phone_capture']) && empty($_COOKIE['phone_capture'])) {
            $phone = sanitize_text_field($_POST['phone_capture']);
            setcookie('phone_capture', $phone, time() + 3600*24*365, '/');
            $_COOKIE['phone_capture'] = $phone;
        }
    }

    public function track_post_view() {
        if (is_single() && isset($_COOKIE['phone_capture'])) {
            global $post, $wpdb;
            $table = $wpdb->prefix . 'phone_capture_views';

            $wpdb->insert($table, [
                'phone' => sanitize_text_field($_COOKIE['phone_capture']),
                'post_id' => $post->ID,
                'viewed_at' => current_time('mysql')
            ]);
        }
    }

    public function admin_menu() {
        add_menu_page(
            'Phone Capture', 'Phone Capture', 'manage_options', 'phone-capture', [$this, 'admin_page'], 'dashicons-visibility'
        );
    }

    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'phone_capture_views';
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY viewed_at DESC");

        echo '<h1>Registros de Visualização</h1>';
        echo '<table class="widefat fixed">';
        echo '<thead><tr><th>Telefone</th><th>Post</th><th>Data</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r->phone) . '</td>';
            echo '<td><a href="' . get_permalink($r->post_id) . '" target="_blank">' . get_the_title($r->post_id) . '</a></td>';
            echo '<td>' . esc_html($r->viewed_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}

register_activation_hook(__FILE__, ['PhoneCaptureModal', 'activate']);
new PhoneCaptureModal();
