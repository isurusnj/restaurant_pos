<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Kitchen';
require_once __DIR__ . '/../config/db.php';
include __DIR__ . '/../layouts/header.php';
?>

<div class="kds-header">
    <h2>Kitchen Display</h2>
    <div class="kds-legend">
        <span class="pill kds-pending">Pending</span>
        <span class="pill kds-progress">In Progress</span>
        <span class="pill kds-ready">Ready</span>
    </div>
</div>

<div id="kds-grid" class="kds-grid">
    <!-- Tiles injected by JS -->
</div>

<script>
    const grid = document.getElementById('kds-grid');
    let lastJson = '';

    function fmtTime(ts) {
        if (!ts) return '';
        return ts.substring(11,16); // HH:MM from 'YYYY-MM-DD HH:MM:SS'
    }

    function tileHtml(o) {
        const status = o.status;
        let cls = 'kds-card';
        if (status === 'pending') cls += ' kds-pending-border';
        else if (status === 'in_progress') cls += ' kds-progress-border';
        else if (status === 'ready') cls += ' kds-ready-border';

        const tableStr = o.table_name ? ('Table ' + o.table_name) : '-';
        const typeStr = (o.order_type || '').replace('_',' ').toUpperCase();

        const items = (o.items || []).map(it =>
            `<div class="kds-item"><span class="kds-qty">${it.qty}×</span> ${escapeHtml(it.name)}</div>`
        ).join('');

        // actions: use existing orders.php GET endpoints
        const actions = `
    <div class="kds-actions">
      <a class="btn-chip small" href="/restaurant_pos/pages/orders.php?id=${o.id}&status=in_progress">In Progress</a>
      <a class="btn-chip small" href="/restaurant_pos/pages/orders.php?id=${o.id}&status=ready">Ready</a>
      <a class="btn-chip small" href="/restaurant_pos/pages/orders.php?id=${o.id}&status=served">Served</a>
      <a class="btn-chip small" href="/restaurant_pos/pages/orders.php?id=${o.id}&status=paid">Paid</a>
    </div>
  `;

        return `
    <div class="${cls}">
      <div class="kds-top">
        <div class="kds-orderno">${escapeHtml(o.order_number)}</div>
        <div class="kds-type">${typeStr}</div>
      </div>
      <div class="kds-sub">
        <div>${tableStr}</div>
        <div class="kds-time">${fmtTime(o.created_at)}</div>
      </div>
      <div class="kds-items">${items || '<em>No items</em>'}</div>
      ${actions}
    </div>
  `;
    }

    function render(data) {
        const html = data.orders.map(tileHtml).join('');
        grid.innerHTML = html || '<div class="card" style="padding:16px;">No active kitchen orders.</div>';
    }

    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    async function poll() {
        try {
            const res = await fetch('orders_feed.php', { cache: 'no-store' });
            const json = await res.text();
            if (json !== lastJson) {
                lastJson = json;
                const data = JSON.parse(json);
                if (data.ok) render(data);
            }
        } catch (e) {
            // ignore transient errors
        } finally {
            setTimeout(poll, 5000); // 5s
        }
    }

    poll();
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
