<?php
$content = file_get_contents('../templates/whatsapp_bot_manager/index.html.twig');

// 1. New Broadcast Pane
$newBroadcastPane = <<<'EOD'
    {# 3. Official Broadcast Campaigns Content Pane #}
    <div class="manager-content-pane" id="pane-broadcast-campaigns">
        <div class="content-header">
            <div>
                <h1 class="content-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5L6 9H2v6h4l5 4V5z"></path><path d="M15.54 8.46a5 5 0 0 1 0 7.07M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>
                    <span>Official Broadcast Campaigns</span>
                </h1>
                <div class="content-subtitle">Coordinate and review bulk template message marketing broadcast campaigns sent to opted-in subscribers.</div>
            </div>
            <button class="btn-action btn-create" onclick="openCreateBroadcastModal()">+ Create Campaign</button>
        </div>

        <div class="glass-form-card" style="padding:0; overflow:hidden;">
            <div class="table-container">
                <table class="custom-table" id="broadcasts-table">
                    <thead>
                        <tr>
                            <th>Campaign Name</th>
                            <th>Status</th>
                            <th>Processed</th>
                            <th>Delivered</th>
                            <th>Opened</th>
                            <th>Unreached</th>
                            <th>Scheduled at</th>
                            <th style="width: 80px; text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for bc in broadcasts %}
                        <tr class="broadcast-row">
                            <td>
                                <strong>{{ bc.campaignName }}</strong>
                                <div style="font-size: 0.8em; opacity: 0.7;">{{ bc.broadcastType == '24_hours' ? '24 Hours' : 'Anytime (' ~ bc.templateName ~ ')' }}</div>
                            </td>
                            <td>
                                <span class="nav-item-status" style="background: rgba(59, 130, 246, 0.12); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.25);">{{ bc.status }}</span>
                            </td>
                            <td>{{ bc.processedCount }}</td>
                            <td>{{ bc.deliveredCount }}</td>
                            <td>{{ bc.openedCount }}</td>
                            <td>{{ bc.unreachedCount }}</td>
                            <td>{{ bc.scheduledAt ?: 'Immediate' }}</td>
                            <td style="text-align:center;">
                                <button class="btn-row-action delete-action" title="Delete" onclick="deleteBroadcast({{ bc.id }})">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </td>
                        </tr>
                        {% else %}
                        <tr>
                            <td colspan="8" style="text-align:center; padding: 40px; color: rgba(255,255,255,0.4);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width: 48px; height: 48px; margin-bottom: 16px; opacity: 0.5;"><path d="M11 5L6 9H2v6h4l5 4V5z"></path><path d="M15.54 8.46a5 5 0 0 1 0 7.07M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>
                                <div>No broadcast campaigns found. Create one to get started!</div>
                            </td>
                        </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
EOD;

$content = preg_replace('/\{\# 3\. Official Broadcast Campaigns Content Pane \#\}.*?(?=\{\# 4\. Drip Sequences Content Pane \#\})/s', $newBroadcastPane . "\n\n    ", $content);

// 2. New Widget Pane
$newWidgetPane = <<<'EOD'
    {# 11. Growth & Web Widgets Content Pane #}
    <div class="manager-content-pane" id="pane-growth-widgets">
        <div class="content-header">
            <div>
                <h1 class="content-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                    <span>Growth & Web Widgets</span>
                </h1>
                <div class="content-subtitle">Deploy custom floating chat bubbles and click-to-chat QR code plugins on external websites.</div>
            </div>
            <button class="btn-action btn-create" onclick="openCreateWidgetModal()">+ New Widget</button>
        </div>

        <div class="glass-form-card" style="padding:0; overflow:hidden;">
            <div class="table-container">
                <table class="custom-table" id="widgets-table">
                    <thead>
                        <tr>
                            <th>Widget Name</th>
                            <th>Type</th>
                            <th>Code Snippet</th>
                            <th style="width: 80px; text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for w in widgets %}
                        <tr class="broadcast-row">
                            <td><strong>{{ w.widgetName }}</strong></td>
                            <td>{{ w.widgetType == 'floating_bubble' ? 'Floating Chat Bubble' : 'QR Code Plugin' }}</td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="text" class="glass-input" readonly value="<script src='https://opensquadron.io/js/wa-widget.js' data-id='{{ w.id }}'></script>" style="font-size: 0.8rem; padding: 4px 8px; width: 300px; background: rgba(0,0,0,0.2);">
                                    <button class="btn-action btn-secondary" style="padding: 4px 8px; font-size:0.8rem;" onclick="navigator.clipboard.writeText(`<script src='https://opensquadron.io/js/wa-widget.js' data-id='{{ w.id }}'></script>`); showToast('Copied to clipboard', 'success');">Copy</button>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <button class="btn-row-action delete-action" title="Delete" onclick="deleteWidget({{ w.id }})">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </td>
                        </tr>
                        {% else %}
                        <tr>
                            <td colspan="4" style="text-align:center; padding: 40px; color: rgba(255,255,255,0.4);">
                                <div>No widgets found. Create one to embed on your website!</div>
                            </td>
                        </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
EOD;

$content = preg_replace('/\{\# 11\. Growth \& Web Widgets Content Pane \#\}.*?(?=\{\# Settings Modals & Backdrops \#\}|<\/div>\s*<\/div>\s*<style>)/s', $newWidgetPane . "\n\n    ", $content);

// 3. Remove is-disabled and soon
$content = str_replace('<div class="nav-item is-disabled" data-tab-target="broadcast-campaigns">', '<div class="nav-item" data-tab-target="broadcast-campaigns">', $content);
$content = str_replace('<div class="nav-item is-disabled" data-tab-target="growth-widgets">', '<div class="nav-item" data-tab-target="growth-widgets">', $content);
$content = preg_replace('/<span class="nav-item-status" style="background: rgba\(245, 158, 11, 0\.12\); color: #fbbf24; border: 1px solid rgba\(245, 158, 11, 0\.25\);">Soon<\/span>/', '', $content, 2);

$content = str_replace('<span>Broadcast Campaigns</span>
            <span class="nav-item-status" style="background: rgba(245, 158, 11, 0.12); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25);">Soon</span>', '<span>Broadcast Campaigns</span>', $content);
$content = str_replace('<span>Growth & Web Widgets</span>
            <span class="nav-item-status" style="background: rgba(245, 158, 11, 0.12); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25);">Soon</span>', '<span>Growth & Web Widgets</span>', $content);

$content = str_replace('<!-- 3. Broadcast Campaigns -->
                <div class="console-card is-disabled">', '<!-- 3. Broadcast Campaigns -->
                <div class="console-card" onclick="switchTab(\'broadcast-campaigns\')">', $content);
$content = str_replace('<!-- 11. Growth & Web Widgets -->
                <div class="console-card is-disabled">', '<!-- 11. Growth & Web Widgets -->
                <div class="console-card" onclick="switchTab(\'growth-widgets\')">', $content);


// 4. Inject Modals just before the LAST script tag.
$modals = <<<'EOD'

    <!-- Modals for Broadcast & Widgets -->
    <div id="modal-broadcast" class="modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
        <div class="glass-form-card" style="width: 600px; padding: 24px; position:relative;">
            <h3 style="margin-top:0;">Create Broadcast Campaign</h3>
            <div class="form-group">
                <label class="form-label">Campaign Name *</label>
                <input type="text" id="bc-name" class="glass-input" style="width:100%" placeholder="e.g. Summer Sale 2026">
            </div>
            <div class="form-group">
                <label class="form-label">Broadcast Type *</label>
                <select id="bc-type" class="glass-input" style="width:100%" onchange="toggleBcTemplate()">
                    <option value="anytime">Anytime (Requires Template)</option>
                    <option value="24_hours">24 Hours (Free messaging)</option>
                </select>
            </div>
            <div class="form-group" id="bc-template-group">
                <label class="form-label">Message Template *</label>
                <select id="bc-template" class="glass-input" style="width:100%">
                    <option value="">-- Select Template --</option>
                    {% for t in templates %}
                        <option value="{{ t.name }}">{{ t.name }} ({{ t.language }})</option>
                    {% endfor %}
                </select>
            </div>
            <div style="display:flex; justify-content:flex-end; gap: 12px; margin-top:20px;">
                <button class="btn-action" style="background:transparent; border:1px solid rgba(255,255,255,0.2);" onclick="document.getElementById('modal-broadcast').style.display='none'">Cancel</button>
                <button class="btn-action btn-create" onclick="saveBroadcast()">Create Campaign</button>
            </div>
        </div>
    </div>

    <div id="modal-widget" class="modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
        <div class="glass-form-card" style="width: 500px; padding: 24px; position:relative;">
            <h3 style="margin-top:0;">Create Web Widget</h3>
            <div class="form-group">
                <label class="form-label">Widget Name *</label>
                <input type="text" id="widget-name" class="glass-input" style="width:100%" placeholder="e.g. Homepage Bubble">
            </div>
            <div class="form-group">
                <label class="form-label">Widget Type *</label>
                <select id="widget-type" class="glass-input" style="width:100%">
                    <option value="floating_bubble">Floating Chat Bubble</option>
                    <option value="qr_code">QR Code Plugin</option>
                </select>
            </div>
            <div style="display:flex; justify-content:flex-end; gap: 12px; margin-top:20px;">
                <button class="btn-action" style="background:transparent; border:1px solid rgba(255,255,255,0.2);" onclick="document.getElementById('modal-widget').style.display='none'">Cancel</button>
                <button class="btn-action btn-create" onclick="saveWidget()">Create Widget</button>
            </div>
        </div>
    </div>

    <script>
        function openCreateBroadcastModal() {
            document.getElementById('bc-name').value = '';
            document.getElementById('bc-type').value = 'anytime';
            document.getElementById('bc-template').value = '';
            toggleBcTemplate();
            document.getElementById('modal-broadcast').style.display = 'flex';
        }

        function toggleBcTemplate() {
            const type = document.getElementById('bc-type').value;
            const group = document.getElementById('bc-template-group');
            if (type === '24_hours') {
                group.style.display = 'none';
            } else {
                group.style.display = 'block';
            }
        }

        function saveBroadcast() {
            const name = document.getElementById('bc-name').value.trim();
            const type = document.getElementById('bc-type').value;
            const template = document.getElementById('bc-template').value;

            if (!name) return showToast('Campaign Name is required', 'error');
            if (type === 'anytime' && !template) return showToast('Template is required for Anytime broadcasts', 'error');

            fetch('/whatsapp-bot-manager/broadcasts/save', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    connectionId: activeConnectionId,
                    campaignName: name,
                    broadcastType: type,
                    templateName: type === 'anytime' ? template : null
                })
            }).then(r => r.json()).then(res => {
                if (res.success) {
                    showToast('Broadcast created!', 'success');
                    location.reload();
                } else {
                    showToast(res.error, 'error');
                }
            });
        }

        function deleteBroadcast(id) {
            if(!confirm('Delete this broadcast campaign?')) return;
            fetch('/whatsapp-bot-manager/broadcasts/delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id})
            }).then(r=>r.json()).then(res => {
                if(res.success) location.reload();
            });
        }

        function openCreateWidgetModal() {
            document.getElementById('widget-name').value = '';
            document.getElementById('widget-type').value = 'floating_bubble';
            document.getElementById('modal-widget').style.display = 'flex';
        }

        function saveWidget() {
            const name = document.getElementById('widget-name').value.trim();
            const type = document.getElementById('widget-type').value;

            if (!name) return showToast('Widget Name is required', 'error');

            fetch('/whatsapp-bot-manager/widgets/save', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    connectionId: activeConnectionId,
                    widgetName: name,
                    widgetType: type
                })
            }).then(r => r.json()).then(res => {
                if (res.success) {
                    showToast('Widget created!', 'success');
                    location.reload();
                } else {
                    showToast(res.error, 'error');
                }
            });
        }

        function deleteWidget(id) {
            if(!confirm('Delete this widget?')) return;
            fetch('/whatsapp-bot-manager/widgets/delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id})
            }).then(r=>r.json()).then(res => {
                if(res.success) location.reload();
            });
        }
    </script>
EOD;

// We need to inject $modals JUST BEFORE the final `{% endblock %}`
$pos = strrpos($content, '{% endblock %}');
if ($pos !== false) {
    $content = substr_replace($content, $modals . "\n{% endblock %}", $pos, strlen('{% endblock %}'));
}

file_put_contents('../templates/whatsapp_bot_manager/index.html.twig', $content);
echo "SUCCESS";

