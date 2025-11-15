<?php
/**
 * Plugin Name: Phone Capture Modal
 * Description: Exibe um modal solicitando o número de telefone e registra cada post visualizado pelo usuário no painel do plugin.
 * Version: 1.1
 * Author: RaulRodrigues
 */

if (!defined('ABSPATH')) exit;

class PhoneCaptureModal {
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'phone_capture_views';

        add_action('wp_footer', [$this, 'render_modal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('init', [$this, 'handle_phone_submit']);
        add_action('wp', [$this, 'track_post_view']);

        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_pcm_export_csv', [$this, 'export_csv']);
        add_action('admin_post_pcm_save_settings', [$this, 'save_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
    }

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'phone_capture_views';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(30) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function enqueue_scripts() {
        // Frontend JS and CSS
        wp_enqueue_script('phone-capture-js', plugin_dir_url(__FILE__) . 'script.js', array('jquery'), null, true);
        wp_enqueue_style('phone-capture-css', plugin_dir_url(__FILE__) . 'style.css');

        wp_localize_script('phone-capture-js', 'phoneCaptureData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'cookie_name' => 'phone_capture'
        ));
    }

    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'phone-capture') === false && strpos($hook, 'toplevel_page_phone-capture') === false) return;
        // Chart.js from CDN
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true);
        wp_enqueue_script('phone-capture-admin-js', plugin_dir_url(__FILE__) . 'admin.js', array('jquery', 'chartjs'), null, true);
        wp_enqueue_style('phone-capture-admin-css', plugin_dir_url(__FILE__) . 'admin.css');

        // pass data
        wp_localize_script('phone-capture-admin-js', 'pcmAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pcm_admin')
        ));
    }

    public function render_modal() {

    if (isset($_COOKIE['phone_capture']) && !empty($_COOKIE['phone_capture'])) {
        return;
    }

        // Only show if phone not present in cookie/localStorage
        $options = get_option('pcm_options', array());

         $auto_show = isset($options['auto_show']) ? $options['auto_show'] : 1;

    // impede exibição se admin desativou
    if (!$auto_show) {
        return;
    }


        $title = !empty($options['title']) ? esc_html($options['title']) : 'Digite seu número de WhatsApp';
        $placeholder = !empty($options['placeholder']) ? esc_attr($options['placeholder']) : '(00) 00000-0000';
        $button_text = !empty($options['button_text']) ? esc_html($options['button_text']) : 'Enviar';
        $bg_color = !empty($options['bg_color']) ? esc_attr($options['bg_color']) : '#ffffff';
        $text_color = !empty($options['text_color']) ? esc_attr($options['text_color']) : '#333333';

        echo "
        <div id=\"pcm-overlay\" class=\"pcm-hidden\">
            <div id=\"pcm-modal\" style=\"background:{$bg_color};color:{$text_color};\">
                <button id=\"pcm-close\" aria-label=\"Fechar\">&times;</button>
                <h3 class=\"pcm-title\">{$title}</h3>
                <form id=\"pcm-form\" method=\"post\">
                    <input id=\"pcm-phone\" name=\"phone_capture\" type=\"text\" placeholder=\"{$placeholder}\" aria-label=\"Telefone\" />
                    <input type=\"hidden\" name=\"pcm_nonce\" value=\"" . wp_create_nonce('pcm_submit') . "\" />
                    <button id=\"pcm-submit\">{$button_text}</button>
                </form>
                <p class=\"pcm-note\">" . (!empty($options['note']) ? esc_html($options['note']) : '') . "</p>
            </div>
        </div>
        ";
    }

    public function handle_phone_submit() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (isset($_POST['phone_capture']) && !empty($_POST['phone_capture']) && isset($_POST['pcm_nonce']) && wp_verify_nonce($_POST['pcm_nonce'], 'pcm_submit')) {
            $phone = sanitize_text_field($_POST['phone_capture']);
            // normalization: keep digits only
            $digits = preg_replace('/\D+/', '', $phone);
            if (strlen($digits) < 8) return;

            setcookie('phone_capture', $digits, time() + YEAR_IN_SECONDS, '/');
            $_COOKIE['phone_capture'] = $digits;

            if (is_singular()) {
                $this->insert_view_record($digits, get_queried_object_id());
            }

            wp_safe_redirect(remove_query_arg(array('phone_capture','pcm_nonce')));
            exit;
        }
    }

    public function track_post_view() {
        if (is_single() && isset($_COOKIE['phone_capture'])) {
            $phone = sanitize_text_field($_COOKIE['phone_capture']);
            if (!empty($phone)) {
                $this->insert_view_record($phone, get_queried_object_id());
            }
        }
    }

    private function insert_view_record($phone, $post_id) {
        global $wpdb;
        $table = $this->table;
        $wpdb->insert($table, array(
            'phone' => $phone,
            'post_id' => intval($post_id),
            'viewed_at' => current_time('mysql')
        ));
    }

    public function admin_menu() {
        add_menu_page('Phone Capture', 'Phone Capture', 'manage_options', 'phone-capture', array($this, 'admin_page'), 'dashicons-phone');
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) return;
        $options = get_option('pcm_options', array());

        echo '<div class="wrap">';
        echo '<h1>Phone Capture - Painel</h1>';

        echo '<div class="pcm-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pcm_export_csv" />';
        echo '<button class="button" type="submit">Exportar CSV</button>';
        echo '</form>';
        echo '</div>';

        echo '<h2>Configurações do Modal</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pcm_save_settings" />';
        wp_nonce_field('pcm_save_settings');

        $title = isset($options['title']) ? esc_attr($options['title']) : '';
        $placeholder = isset($options['placeholder']) ? esc_attr($options['placeholder']) : '';
        $button_text = isset($options['button_text']) ? esc_attr($options['button_text']) : '';
        $note = isset($options['note']) ? esc_attr($options['note']) : '';
        $bg_color = isset($options['bg_color']) ? esc_attr($options['bg_color']) : '#ffffff';
        $text_color = isset($options['text_color']) ? esc_attr($options['text_color']) : '#333333';
        $auto_show = isset($options['auto_show']) ? esc_attr($options['auto_show']) : '1';

        echo '<table class="form-table">';
        echo '<tr><th>Título</th><td><input name="pcm_options[title]" value="' . $title . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Placeholder</th><td><input name="pcm_options[placeholder]" value="' . $placeholder . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Texto do botão</th><td><input name="pcm_options[button_text]" value="' . $button_text . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Nota (baixo do botão)</th><td><input name="pcm_options[note]" value="' . $note . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Cor do fundo do modal</th><td><input type="color" name="pcm_options[bg_color]" value="' . $bg_color . '" /></td></tr>';
        echo '<tr><th>Cor do texto</th><td><input type="color" name="pcm_options[text_color]" value="' . $text_color . '" /></td></tr>';
        echo '<tr><th>Auto exibir modal?</th><td><input type="checkbox" name="pcm_options[auto_show]" value="1" ' . checked($auto_show, '1', false) . ' /></td></tr>';
        echo '</table>';

        submit_button('Salvar configurações');
        echo '</form>';

        echo '<h2>Análises</h2>';
        echo '<div id="pcm-analytics">';

        global $wpdb;
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        $unique_phones = $wpdb->get_var("SELECT COUNT(DISTINCT phone) FROM {$this->table}");

        echo '<div class="pcm-cards">';
        echo '<div class="pcm-card"><strong>' . intval($total) . '</strong><span>Total de visualizações</span></div>';
        echo '<div class="pcm-card"><strong>' . intval($unique_phones) . '</strong><span>Telefones únicos</span></div>';
        echo '</div>';

        echo '<canvas id="pcmChart" width="600" height="200"></canvas>';

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY viewed_at DESC LIMIT %d", 100));
        echo '<h3>Últimas 100 visualizações</h3>';
        echo '<table class="widefat fixed"><thead><tr><th>Telefone</th><th>Post</th><th>Data</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $title = get_the_title($r->post_id);
            $permalink = get_permalink($r->post_id);
            echo '<tr>';
            echo '<td>' . esc_html($r->phone) . '</td>';
            echo '<td><a href="' . esc_url($permalink) . '" target="_blank">' . esc_html($title) . '</a></td>';
            echo '<td>' . esc_html($r->viewed_at) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
        echo '</div>';

        // prepare chart data
        $chart_data = $wpdb->get_results("SELECT DATE(viewed_at) as d, COUNT(*) as c FROM {$this->table} GROUP BY DATE(viewed_at) ORDER BY DATE(viewed_at) DESC LIMIT 30");
        $labels = array();
        $data = array();
        if ($chart_data) {
            $chart_data = array_reverse($chart_data);
            foreach ($chart_data as $cd) {
                $labels[] = $cd->d;
                $data[] = intval($cd->c);
            }
        }
        echo "<script>var pcmChartLabels=" . json_encode($labels) . "; var pcmChartData=" . json_encode($data) . ";</script>";
    }

    public function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Permissão negada');
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY viewed_at DESC");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=phone-capture-views.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('id', 'phone', 'post_id', 'post_title', 'viewed_at'));
        foreach ($rows as $r) {
            fputcsv($output, array($r->id, $r->phone, $r->post_id, get_the_title($r->post_id), $r->viewed_at));
        }
        fclose($output);
        exit;
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Permissão negada');
        check_admin_referer('pcm_save_settings');
        $opts = isset($_POST['pcm_options']) ? $_POST['pcm_options'] : array();
        $clean = array();
        $clean['title'] = isset($opts['title']) ? sanitize_text_field($opts['title']) : '';
        $clean['placeholder'] = isset($opts['placeholder']) ? sanitize_text_field($opts['placeholder']) : '';
        $clean['button_text'] = isset($opts['button_text']) ? sanitize_text_field($opts['button_text']) : '';
        $clean['note'] = isset($opts['note']) ? sanitize_text_field($opts['note']) : '';
        $clean['bg_color'] = isset($opts['bg_color']) ? sanitize_text_field($opts['bg_color']) : '#ffffff';
        $clean['text_color'] = isset($opts['text_color']) ? sanitize_text_field($opts['text_color']) : '#333333';
        $clean['auto_show'] = isset($opts['auto_show']) ? 1 : 0;

        update_option('pcm_options', $clean);
        wp_safe_redirect(admin_url('admin.php?page=phone-capture'));
        exit;
    }
}

register_activation_hook(__FILE__, array('PhoneCaptureModal', 'activate'));
new PhoneCaptureModal();
