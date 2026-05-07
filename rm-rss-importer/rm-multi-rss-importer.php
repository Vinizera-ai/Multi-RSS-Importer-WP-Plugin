<?php
/**
 * Plugin Name: Multi RSS Importer
 * Description: Importa múltiplos RSS com categoria individual, limite por feed, prevenção de duplicados e imagem destacada.
 * Version: 1.3.0
 * Author: Vinicius Castro 
 * Author URI: https://github.com/Vinizera-ai
 * License: GPL2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class RM_Multi_RSS_Importer
{
    const OPTION_KEY = 'rm_multi_rss_importer_options';
    const LOG_KEY = 'rm_multi_rss_importer_log';
    const CRON_HOOK = 'rm_multi_rss_importer_cron_hook';
    const META_GUID = '_rm_rss_guid';
    const META_LINK = '_rm_rss_link';
    const META_FEED = '_rm_rss_feed_url';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_post_rm_multi_rss_import_now', [$this, 'handle_import_now']);
        add_action('admin_post_rm_multi_rss_test_feeds', [$this, 'handle_test_feeds']);
        add_action('wp_ajax_rm_multi_rss_autosave', [$this, 'handle_autosave']);
        add_action(self::CRON_HOOK, [$this, 'import_all']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    public static function defaults()
    {
        return [
            'frequency' => 'hourly',
            'content_mode' => 'clean_html',
            'download_images' => 0,
            'include_source' => 1,
            'remove_links' => 1,
            'feeds' => [
                [
                    'active' => 1,
                    'url' => 'https://www.sindijorimg.com.br/rss/category/coluna-mg',
                    'category' => 'Coluna MG',
                    'status' => 'publish',
                    'max' => 3,
                    'author' => 1,
                ],
            ],
        ];
    }

    public static function activate()
    {
        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, self::defaults());
        }
        self::reschedule();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function get_options()
    {
        $opts = get_option(self::OPTION_KEY, []);
        return wp_parse_args($opts, self::defaults());
    }

    public static function reschedule()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $opts = self::get_options();
        $freq = !empty($opts['frequency']) ? $opts['frequency'] : 'hourly';
        if ($freq !== 'manual') {
            wp_schedule_event(time() + 120, $freq, self::CRON_HOOK);
        }
    }

    public function cron_schedules($schedules)
    {
        $schedules['rm_30min'] = ['interval' => 1800, 'display' => 'A cada 30 minutos'];
        $schedules['rm_2hours'] = ['interval' => 7200, 'display' => 'A cada 2 horas'];
        return $schedules;
    }

    public function admin_menu()
    {
        add_options_page('RM Multi RSS Importer', 'RM Multi RSS Importer', 'manage_options', 'rm-rss-importer', [$this, 'settings_page']);
    }

    public function admin_init()
    {
        register_setting('rm_multi_rss_group', self::OPTION_KEY, [$this, 'sanitize_options']);
    }

    public function sanitize_options($input)
    {
        $out = self::defaults();
        $out['frequency'] = in_array(($input['frequency'] ?? 'hourly'), ['manual', 'rm_30min', 'hourly', 'twicedaily', 'daily', 'rm_2hours'], true) ? $input['frequency'] : 'hourly';
        $out['content_mode'] = in_array(($input['content_mode'] ?? 'clean_html'), ['clean_html', 'plain_excerpt', 'full_html'], true) ? $input['content_mode'] : 'clean_html';
        $out['download_images'] = !empty($input['download_images']) ? 1 : 0;
        $out['include_source'] = !empty($input['include_source']) ? 1 : 0;
        $out['remove_links'] = !empty($input['remove_links']) ? 1 : 0;
        $out['feeds'] = [];

        if (!empty($input['feeds']) && is_array($input['feeds'])) {
            foreach ($input['feeds'] as $feed) {
                $url = esc_url_raw(trim($feed['url'] ?? ''));
                if (!$url) {
                    continue;
                }
                $out['feeds'][] = [
                    'active' => !empty($feed['active']) ? 1 : 0,
                    'url' => $url,
                    'category' => sanitize_text_field($feed['category'] ?? 'Notícias'),
                    'status' => in_array(($feed['status'] ?? 'draft'), ['publish', 'draft', 'pending'], true) ? $feed['status'] : 'draft',
                    'max' => max(1, min(20, absint($feed['max'] ?? 3))),
                    'author' => max(1, absint($feed['author'] ?? get_current_user_id() ?: 1)),
                ];
            }
        }
        if (empty($out['feeds'])) {
            $out['feeds'] = self::defaults()['feeds'];
        }

        self::log('Configurações salvas.');
        self::reschedule();
        return $out;
    }

    public function settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $opts = self::get_options();
        $log = get_option(self::LOG_KEY, 'Sem logs ainda.');
        $notice = isset($_GET['rm_msg']) ? sanitize_text_field(wp_unslash($_GET['rm_msg'])) : '';
        ?>
        <div class="wrap">
            <h1>RM Multi RSS Importer</h1>
            <?php if ($notice): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($notice); ?></p>
                </div><?php endif; ?>
            <p>Importa múltiplos RSS com categoria individual, limite por feed e prevenção de duplicados.</p>
            <style>
                .rm-toggle {
                    display: inline-flex;
                    align-items: center;
                    gap: 10px;
                    cursor: pointer;
                    user-select: none
                }

                .rm-toggle input {
                    display: none
                }

                .rm-slider {
                    width: 46px;
                    height: 24px;
                    background: #c3c4c7;
                    border-radius: 999px;
                    position: relative;
                    transition: .18s
                }

                .rm-slider:before {
                    content: "";
                    width: 20px;
                    height: 20px;
                    background: #fff;
                    border-radius: 50%;
                    position: absolute;
                    left: 2px;
                    top: 2px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, .25);
                    transition: .18s
                }

                .rm-toggle input:checked+.rm-slider {
                    background: #2271b1
                }

                .rm-toggle input:checked+.rm-slider:before {
                    transform: translateX(22px)
                }

                .rm-autosave-status {
                    margin-left: 10px;
                    color: #646970;
                    font-size: 12px
                }

                .rm-autosave-status.ok {
                    color: #008a20
                }

                .rm-autosave-status.err {
                    color: #b32d2e
                }
            </style>
            <div class="notice notice-info inline" style="display:inline-block;padding:8px 12px;">
             <span id="rm-autosave-status" class="rm-autosave-status">Salvo</span>
            </div>

            <?php
            $last = get_option('rm_multi_rss_last_run');
            $next = wp_next_scheduled(self::CRON_HOOK);

            echo '<div style="background:#fff;padding:10px;border:1px solid #ddd;margin-top:10px;">';

            echo '<strong>Última execução:</strong> ';
            echo $last ? date_i18n('d/m/Y H:i:s', $last) : 'Nunca';

            echo '<br>';

            echo '<strong>Próxima execução:</strong> ';
            echo $next ? date_i18n('d/m/Y H:i:s', $next) : 'Não agendado';

            echo '</div>';
            ?>

            <form method="post" action="options.php" id="rm-rss-settings-form">
                <?php settings_fields('rm_multi_rss_group'); ?>
                <h2>Configurações gerais</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Frequência automática</th>
                        <td><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[frequency]">
                                <?php $freqs = ['manual' => 'Manual apenas', 'rm_30min' => '30 minutos', 'hourly' => '1 hora', 'rm_2hours' => '2 horas', 'twicedaily' => '2x ao dia', 'daily' => 'Diário'];
                                foreach ($freqs as $k => $v): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($opts['frequency'], $k); ?>>
                                        <?php echo esc_html($v); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></td>
                    </tr>
                    <tr>
                        <th>Conteúdo</th>
                        <td><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[content_mode]">
                                <option value="clean_html" <?php selected($opts['content_mode'], 'clean_html'); ?>>HTML limpo
                                </option>
                                <option value="plain_excerpt" <?php selected($opts['content_mode'], 'plain_excerpt'); ?>>Resumo
                                    leve em texto</option>
                                <option value="full_html" <?php selected($opts['content_mode'], 'full_html'); ?>>HTML completo
                                </option>
                            </select></td>
                    </tr>
                    <tr>
                        <th>Imagem destacada</th>
                        <td>
                            <label class="rm-toggle">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[download_images]"
                                    value="1" <?php checked(!empty($opts['download_images'])); ?>>
                                <span class="rm-slider"></span>
                                <span
                                    class="rm-toggle-text"><?php echo !empty($opts['download_images']) ? 'Ligado' : 'Desligado'; ?></span>
                            </label>
                            <p class="description">Ligado baixa a imagem do RSS uma vez para a mídia do WordPress e define como
                                imagem destacada. Desligado é mais rápido.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Incluir fonte</th>
                        <td>
                            <label class="rm-toggle">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_source]"
                                    value="1" <?php checked(!empty($opts['include_source'])); ?>>
                                <span class="rm-slider"></span>
                                <span class="rm-toggle-text"><?php echo !empty($opts['include_source']) ? 'Ligado' : 'Desligado'; ?></span>
                            </label>
                            <p class="description">Adiciona “Fonte” no final do post importado.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Remover links do texto</th>
                        <td>
                            <label class="rm-toggle">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[remove_links]"
                                    value="1" <?php checked(!empty($opts['remove_links'])); ?>>
                                <span class="rm-slider"></span>
                                <span class="rm-toggle-text"><?php echo !empty($opts['remove_links']) ? 'Ligado' : 'Desligado'; ?></span>
                            </label>
                            <p class="description">Remove links externos do conteúdo, mantendo apenas o texto.</p>
                        </td>
                    </tr>
                </table>

                <h2>Feeds RSS</h2>
                <table class="widefat striped" id="rm-feeds-table">
                    <thead>
                        <tr>
                            <th>Ativo</th>
                            <th>URL do RSS</th>
                            <th>Categoria</th>
                            <th>Status</th>
                            <th>Máx.</th>
                            <th>Autor ID</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($opts['feeds'] as $i => $feed):
                            $this->feed_row($i, $feed);
                        endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="rm-add-feed">Adicionar RSS</button></p>
                <?php submit_button('Salvar configurações'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                style="display:inline-block;margin-right:8px;">
                <?php wp_nonce_field('rm_multi_rss_import_now'); ?>
                <input type="hidden" name="action" value="rm_multi_rss_import_now">
                <?php submit_button('Importar agora', 'primary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php wp_nonce_field('rm_multi_rss_test_feeds'); ?>
                <input type="hidden" name="action" value="rm_multi_rss_test_feeds">
                <?php submit_button('Testar feeds', 'secondary', 'submit', false); ?>
            </form>




            <h2>Último log</h2>
            <pre
                style="background:#fff;border:1px solid #ccd0d4;padding:12px;white-space:pre-wrap;max-height:360px;overflow:auto;"><?php echo esc_html($log); ?></pre>
        </div>
        <script>
            (function () {
                const table = document.querySelector('#rm-feeds-table tbody');
                const add = document.getElementById('rm-add-feed');
                const key = <?php echo wp_json_encode(self::OPTION_KEY); ?>;
                function row(i) {
                    return `<tr>
                    <td><input type="checkbox" name="${key}[feeds][${i}][active]" value="1" checked></td>
                    <td><input type="url" style="width:100%;min-width:360px" name="${key}[feeds][${i}][url]" value=""></td>
                    <td><input type="text" name="${key}[feeds][${i}][category]" value="Notícias"></td>
                    <td><select name="${key}[feeds][${i}][status]"><option value="publish">Publicado</option><option value="draft">Rascunho</option><option value="pending">Pendente</option></select></td>
                    <td><input type="number" min="1" max="20" style="width:70px" name="${key}[feeds][${i}][max]" value="3"></td>
                    <td><input type="number" min="1" style="width:80px" name="${key}[feeds][${i}][author]" value="1"></td>
                    <td><button type="button" class="button rm-remove-feed">Remover</button></td>
                </tr>`;
                }
                add.addEventListener('click', function () { table.insertAdjacentHTML('beforeend', row(Date.now())); });
                const form = document.getElementById('rm-rss-settings-form');
                const status = document.getElementById('rm-autosave-status');
                const nonce = <?php echo wp_json_encode(wp_create_nonce('rm_multi_rss_autosave')); ?>;
                let timer = null;
                function setStatus(text, cls) { if (!status) return; status.textContent = text; status.className = 'rm-autosave-status ' + (cls || ''); }
                function updateToggleText(el) { const label = el.closest('.rm-toggle'); if (!label) return; const t = label.querySelector('.rm-toggle-text'); if (t) t.textContent = el.checked ? 'Ligado' : 'Desligado'; }
                function autosave() {
                    clearTimeout(timer);
                    setStatus('Salvando...', '');
                    timer = setTimeout(function () {
                        const fd = new FormData(form);
                        fd.append('action', 'rm_multi_rss_autosave');
                        fd.append('_ajax_nonce', nonce);
                        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(r => r.json())
                            .then(data => { if (data && data.success) { setStatus('Salvo', 'ok'); } else { setStatus('Erro ao salvar', 'err'); } })
                            .catch(() => setStatus('Erro ao salvar', 'err'));
                    }, 500);
                }
                add.addEventListener('click', function () { setTimeout(autosave, 100); });
                table.addEventListener('click', function (e) { if (e.target.classList.contains('rm-remove-feed')) { e.target.closest('tr').remove(); autosave(); } });
                form.addEventListener('change', function (e) { if (e.target.matches('.rm-toggle input')) updateToggleText(e.target); autosave(); });
                form.addEventListener('input', function () { autosave(); });
            })();
        </script>
        <?php
    }

    private function feed_row($i, $feed)
    { ?>
        <tr>
            <td><input type="checkbox"
                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[feeds][<?php echo esc_attr($i); ?>][active]" value="1" <?php checked(!empty($feed['active'])); ?>></td>
            <td><input type="url" style="width:100%;min-width:360px"
                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[feeds][<?php echo esc_attr($i); ?>][url]"
                    value="<?php echo esc_attr($feed['url'] ?? ''); ?>"></td>
            <td><input type="text"
                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[feeds][<?php echo esc_attr($i); ?>][category]"
                    value="<?php echo esc_attr($feed['category'] ?? 'Notícias'); ?>"></td>
            <td><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[feeds][<?php echo esc_attr($i); ?>][status]">
                    <option value="publish" <?php selected($feed['status'] ?? '', 'publish'); ?>>Publicado</option>
                    <option value="draft" <?php selected($feed['status'] ?? '', 'draft'); ?>>Rascunho</option>
                    <option value="pending" <?php selected($feed['status'] ?? '', 'pending'); ?>>Pendente</option>
                </select></td>
            <td><input type="number" min="1" max="20" style="width:70px"
                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[feeds][<?php echo esc_attr($i); ?>][max]"
                    value="<?php echo esc_attr($feed['max'] ?? 3); ?>"></td>
            <td><input type="number" min="1" style="width:80px"
                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[feeds][<?php echo esc_attr($i); ?>][author]"
                    value="<?php echo esc_attr($feed['author'] ?? 1); ?>"></td>
            <td><button type="button" class="button rm-remove-feed">Remover</button></td>
        </tr>
    <?php }

    public function handle_autosave()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão.'], 403);
        }
        check_ajax_referer('rm_multi_rss_autosave');
        $raw = isset($_POST[self::OPTION_KEY]) && is_array($_POST[self::OPTION_KEY]) ? wp_unslash($_POST[self::OPTION_KEY]) : [];
        $sanitized = $this->sanitize_options($raw);
        update_option(self::OPTION_KEY, $sanitized, false);
        wp_send_json_success(['message' => 'Salvo.']);
    }

    public function handle_import_now()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('rm_multi_rss_import_now')) {
            wp_die('Sem permissão.');
        }
        $result = $this->import_all();
        wp_safe_redirect(add_query_arg('rm_msg', rawurlencode('Importação finalizada: ' . $result), admin_url('options-general.php?page=rm-rss-importer')));
        exit;
    }

    public function handle_test_feeds()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('rm_multi_rss_test_feeds')) {
            wp_die('Sem permissão.');
        }
        include_once ABSPATH . WPINC . '/feed.php';
        $opts = self::get_options();
        $lines = ['Teste iniciado em ' . current_time('mysql')];
        foreach ($opts['feeds'] as $feed) {
            if (empty($feed['active']) || empty($feed['url'])) {
                continue;
            }
            $rss = fetch_feed($feed['url']);
            if (is_wp_error($rss)) {
                $lines[] = 'ERRO: ' . $feed['url'] . ' => ' . $rss->get_error_message();
                continue;
            }
            $count = $rss->get_item_quantity((int) ($feed['max'] ?? 3));
            $items = $rss->get_items(0, $count);
            $lines[] = 'OK: ' . $feed['url'] . ' | Itens encontrados: ' . count($items);
            foreach ($items as $item) {
                $lines[] = ' - ' . wp_strip_all_tags($item->get_title()) . ' | ' . $item->get_link();
            }
        }
        self::log(implode("\n", $lines));
        wp_safe_redirect(add_query_arg('rm_msg', rawurlencode('Teste concluído. Veja o log.'), admin_url('options-general.php?page=rm-rss-importer')));
        exit;
    }

    public function import_all()
    {
        include_once ABSPATH . WPINC . '/feed.php';
        $opts = self::get_options();
        $inserted = 0;
        $skipped = 0;
        $errors = 0;
        $lines = ['Importação iniciada em ' . current_time('mysql')];
        foreach ($opts['feeds'] as $feed) {
            if (empty($feed['active']) || empty($feed['url'])) {
                continue;
            }
            $rss = fetch_feed($feed['url']);
            if (is_wp_error($rss)) {
                $errors++;
                $lines[] = 'ERRO feed ' . $feed['url'] . ': ' . $rss->get_error_message();
                continue;
            }
            $max = max(1, min(20, absint($feed['max'] ?? 3)));
            $items = $rss->get_items(0, $rss->get_item_quantity($max));
            $lines[] = 'Feed: ' . $feed['url'] . ' | itens lidos: ' . count($items);
            foreach ($items as $item) {
                $r = $this->import_item($item, $feed, $opts);
                if ($r === 'inserted')
                    $inserted++;
                elseif ($r === 'skipped')
                    $skipped++;
                else
                    $errors++;
            }
        }
        $lines[] = "Resultado: {$inserted} importados, {$skipped} ignorados, {$errors} erros.";
        self::log(implode("\n", $lines));
        update_option('rm_multi_rss_last_run', current_time('timestamp'));
        return "{$inserted} importados, {$skipped} ignorados, {$errors} erros";
    }

    private function import_item($item, $feed, $opts)
    {
        $title = wp_strip_all_tags($item->get_title());
        $link = esc_url_raw($item->get_link());
        $guid = $item->get_id(false) ?: $link ?: md5($title . $feed['url']);
        if ($this->exists($guid, $link)) {
            return 'skipped';
        }

        $raw = $item->get_content() ?: $item->get_description();
        $content = $this->prepare_content($raw, $opts['content_mode']);
        if (!empty($opts['remove_links'])) {
            $content = $this->remove_links_from_content($content);
        }
        
        if (!empty($opts['include_source']) && $link) {
            if (!empty($opts['remove_links'])) {
                $content .= "\n\n<p><strong>Fonte:</strong> Sindijori MG</p>";
            } else {
                $content .= "\n\n<p><strong>Fonte:</strong> <a href=\"" . esc_url($link) . "\" target=\"_blank\" rel=\"nofollow noopener\">Sindijori MG</a></p>";
            }
        }
        $cat_id = $this->get_category_id($feed['category'] ?? 'Notícias');
        $post_status = $feed['status'] ?? 'draft';
        $post_date = $this->safe_item_date($item, $post_status);

        $postarr = [
            'post_title' => $title ?: 'Notícia importada',
            'post_content' => $content,
            'post_status' => $post_status,
            'post_author' => absint($feed['author'] ?? 1),
            'post_category' => [$cat_id],
            'post_date' => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date),
        ];

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) {
            self::log('Erro ao inserir: ' . $post_id->get_error_message());
            return 'error';
        }
        update_post_meta($post_id, self::META_GUID, sanitize_text_field($guid));
        update_post_meta($post_id, self::META_LINK, $link);
        update_post_meta($post_id, self::META_FEED, esc_url_raw($feed['url']));

        if (!empty($opts['download_images'])) {
            $img = $this->extract_image_url($item, $raw);
            if ($img) {
                update_post_meta($post_id, '_rm_external_image_url', esc_url_raw($img));
                $this->set_featured_image($post_id, $img);
            }
        }
        return 'inserted';
    }

    private function safe_item_date($item, $post_status = 'draft')
    {
        // Evita o bug SimplePie/PHP em que get_date('Y-m-d H:i:s') passa float para date().
        // Também evita agendamento indevido quando o feed vem com horário em fuso diferente.
        $candidates = [];
        $pub = $item->get_item_tags('', 'pubDate');
        if (!empty($pub[0]['data'])) {
            $candidates[] = $pub[0]['data'];
        }
        $dc = $item->get_item_tags('http://purl.org/dc/elements/1.1/', 'date');
        if (!empty($dc[0]['data'])) {
            $candidates[] = $dc[0]['data'];
        }

        $now_ts = current_time('timestamp');
        foreach ($candidates as $raw_date) {
            $ts = strtotime(wp_strip_all_tags($raw_date));
            if (!$ts || $ts <= 0) {
                continue;
            }

            // Se o status for publicar e a data vier no futuro (ex.: diferença de fuso UTC-3),
            // publica imediatamente para não cair em "Agendados".
            if ($post_status === 'publish' && $ts > $now_ts) {
                return current_time('mysql');
            }

            return wp_date('Y-m-d H:i:s', (int) $ts, wp_timezone());
        }

        return current_time('mysql');
    }

    private function exists($guid, $link)
    {
        $args = [
            'post_type' => 'post',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => ['relation' => 'OR', ['key' => self::META_GUID, 'value' => sanitize_text_field($guid)], ['key' => self::META_LINK, 'value' => esc_url_raw($link)]],
        ];
        return !empty(get_posts($args));
    }

    private function prepare_content($raw, $mode)
    {
        $raw = (string) $raw;
        if ($mode === 'plain_excerpt') {
            return '<p>' . esc_html(wp_trim_words(wp_strip_all_tags($raw), 120, '...')) . '</p>';
        }
        if ($mode === 'full_html') {
            return wp_kses_post($raw);
        }
        $allowed = [
            'p' => [],
            'br' => [],
            'strong' => [],
            'b' => [],
            'em' => [],
            'i' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'a' => ['href' => [], 'target' => [], 'rel' => []],
            'img' => ['src' => [], 'alt' => [], 'width' => [], 'height' => []],
            'h2' => [],
            'h3' => [],
            'h4' => [],
            'blockquote' => [],
        ];
        $clean = preg_replace('/\sstyle=("|\').*?\1/i', '', $raw);
        $clean = preg_replace('/\sclass=("|\').*?\1/i', '', $clean);
        return wp_kses($clean, $allowed);
    }

    private function remove_links_from_content($content)
    {
        // Remove tags <a>
        $content = preg_replace('#<a[^>]*>(.*?)</a>#is', '$1', $content);
    
        // Remove URLs soltas
        $content = preg_replace(
            '#\bhttps?://[^\s<]+#i',
            '',
            $content
        );
    
        // Remove linhas vazias extras
        $content = preg_replace("/(\r\n|\n|\r){2,}/", "\n\n", $content);
    
        return trim($content);
    }
    
    private function get_category_id($name)
    {
        $name = $name ?: 'Notícias';
        $term = term_exists($name, 'category');
        if (!$term) {
            $term = wp_insert_term($name, 'category');
        }
        if (is_wp_error($term)) {
            return (int) get_option('default_category');
        }
        return (int) (is_array($term) ? $term['term_id'] : $term);
    }

    private function extract_image_url($item, $raw)
    {
        $enclosures = $item->get_enclosures();
        if ($enclosures) {
            foreach ($enclosures as $enc) {
                $link = $enc->get_link();
                $type = $enc->get_type();
                if ($link && (!$type || stripos($type, 'image/') === 0 || preg_match('/\.(jpe?g|png|webp|gif)(\?.*)?$/i', $link))) {
                    return esc_url_raw($link);
                }
            }
        }
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $raw, $m)) {
            return esc_url_raw(html_entity_decode($m[1]));
        }
        return '';
    }

    private function set_featured_image($post_id, $url)
    {
        if (!$url || has_post_thumbnail($post_id)) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($url, 20);
        if (is_wp_error($tmp)) {
            self::log('Imagem não baixada: ' . $url . ' | ' . $tmp->get_error_message());
            return;
        }
        $file_array = ['name' => basename(parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp];
        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            @unlink($tmp);
            self::log('Erro ao anexar imagem: ' . $id->get_error_message());
            return;
        }
        set_post_thumbnail($post_id, $id);
    }

    public static function log($text)
    {
        update_option(self::LOG_KEY, '[' . current_time('mysql') . "]\n" . $text, false);
    }
}

new RM_Multi_RSS_Importer();
