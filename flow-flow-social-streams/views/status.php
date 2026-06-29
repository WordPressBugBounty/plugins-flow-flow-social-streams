<?php

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

// phpcs:disable
/** @var array $context */
$status = isset($context['status']) ? $context['status'] : [];

function ff_bool_label($val) {
    if ($val === null) return '<span class="ff-badge">n/a</span>';
    return $val ? '<span class="ff-badge ff-ok">ON</span>' : '<span class="ff-badge ff-warn">OFF</span>';
}

function ff_time_h($ts){
    if (!$ts) return '—';
    $diff = $ts - time();
    $when = $diff >= 0 ? 'in ' . human_time_diff(time(), $ts) : human_time_diff($ts, time()) . ' ago';
    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts) . ' (' . $when . ')';
}
?>
<div class="section-content" data-tab="status-tab">
<div class="section ff-status">
    <h3>Cron Status</h3>

    <div class="ff-grid">
        <div class="ff-card">
            <h4>Constants</h4>
            <ul class="ff-list">
                <li><strong>FF_USE_WP_CRON</strong> <?php echo ff_bool_label($status['constants']['FF_USE_WP_CRON'] ?? null); ?></li>
                <li><strong>DISABLE_WP_CRON</strong> <?php echo ff_bool_label($status['constants']['DISABLE_WP_CRON'] ?? null); ?></li>
                <li><strong>ALTERNATE_WP_CRON</strong> <?php echo ff_bool_label($status['constants']['ALTERNATE_WP_CRON'] ?? null); ?></li>
            </ul>
        </div>

        <div class="ff-card">
            <h4>Loopback</h4>
            <p>
                <?php
                $loop = $status['loopback_ok'];
                if ($loop === null) {
                    echo '—';
                } else {
                    echo $loop ? '<span class="ff-badge ff-ok">OK</span>' : '<span class="ff-badge ff-error">Failed</span>';
                }
                ?>
            </p>
        </div>
    </div>

    <div class="ff-log">
        <div class="ff-log-actions">
            <button type="button" class="button ff-log-refresh">Refresh</button>
            <button type="button" class="button ff-log-clear">Clear</button>
        </div>
        <textarea class="ff-log-content" readonly rows="12" style="width:100%;font-family:Menlo,Monaco,Consolas,monospace;font-size:12px;"></textarea>
    </div>

    <h4>Flow-Flow Cron Events</h4>
    <table class="widefat ff-table">
        <thead>
            <tr>
                <th>Hook</th>
                <th>Schedule</th>
                <th>Next Run</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (($status['events'] ?? []) as $ev): ?>
            <tr>
                <td><code><?php echo esc_html($ev['hook']); ?></code></td>
                <td><?php echo $ev['schedule'] ? esc_html($ev['schedule']) : '—'; ?></td>
                <td><?php echo ff_time_h($ev['next'] ?? null); ?></td>
                <td><button type="button" class="button ff-run-now" data-hook="<?php echo esc_attr($ev['hook']); ?>">Run manually</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h4>Registered Schedules</h4>
    <table class="widefat ff-table">
        <thead>
            <tr>
                <th>Key</th>
                <th>Display</th>
                <th>Interval</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (($status['schedules'] ?? []) as $sch): ?>
            <tr>
                <td><?php echo esc_html($sch['key']); ?></td>
                <td><?php echo esc_html($sch['display']); ?></td>
                <td><?php echo isset($sch['interval']) ? esc_html(human_time_diff(0, (int)$sch['interval'])) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h4>Troubleshooting</h4>
    <ol class="ff-help">
        <li><strong>Loopback blocked</strong>: If loopback is failed, check BasicAuth, firewalls, or enable <code>ALTERNATE_WP_CRON</code>.</li>
        <li><strong>Schedules missing</strong>: Ensure custom schedules (e.g., <code>minute</code>) appear. They are registered by <code>LAActivatorBase::registerCronActions()</code>.</li>
        <li><strong>Next run is far</strong>: Manually run WP-Cron by visiting <code><?php echo esc_html(site_url('wp-cron.php?doing_wp_cron=1')); ?></code>.</li>
        <li><strong>FastCGI timeouts</strong>: Reduce workload per run or increase server timeouts.</li>
    </ol>

<script>
(function(){
  const slugDown = '<?php echo esc_js(\la\core\LAUtils::slug_down($context)); ?>'
  const vars = window[slugDown + '_vars'] || {}
  const ajaxurl = vars.ajaxurl || (window.ajaxurl || '')
  const nonce = vars.nonce || ''

  function post(action, data){
    const form = new FormData()
    form.append('action', slugDown + '_' + action)
    form.append('nonce', nonce)
    Object.keys(data||{}).forEach(k => form.append(k, data[k]))
    return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: form }).then(r=>r.json())
  }

  function bindRunNow(){
    document.querySelectorAll('.ff-run-now').forEach(btn => {
      btn.addEventListener('click', () => {
        const hook = btn.getAttribute('data-hook')
        btn.disabled = true
        btn.textContent = 'Running…'
        post('cron_run', { hook }).then(resp => {
          alert(resp && resp.success ? (resp.data && resp.data.message || 'Done') : (resp && resp.data && resp.data.message || 'Failed'))
        }).catch(()=>{
          alert('Request failed')
        }).finally(()=>{
          btn.disabled = false
          btn.textContent = 'Run manually'
        })
      })
    })
  }

  function loadLog(){
    const ta = document.querySelector('.ff-log-content')
    if (!ta) return
    post('debug_log', { subaction: 'get' }).then(resp => {
      if (resp && resp.success && resp.data) {
        ta.value = resp.data.content || ''
        ta.scrollTop = ta.scrollHeight
      }
    })
  }

  function bindLogButtons(){
    const refresh = document.querySelector('.ff-log-refresh')
    const clear = document.querySelector('.ff-log-clear')
    refresh && refresh.addEventListener('click', loadLog)
    clear && clear.addEventListener('click', () => {
      if (!confirm('Clear debug log?')) return
      post('debug_log', { subaction: 'clear' }).then(()=> loadLog())
    })
  }

  document.addEventListener('DOMContentLoaded', function(){
    bindRunNow()
    bindLogButtons()
    loadLog()
  })
})()
</script>
</div>
</div>

<?php // phpcs:enable ?>
