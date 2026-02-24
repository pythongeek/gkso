/* Gemini-Kimi SEO Optimizer — Internal Linking Agent Panel
 * Self-contained vanilla JS panel. No build step required.
 * Loaded on the Internal Linking admin page via wp_enqueue_script.
 */
(function() {
'use strict';

const ROOT_ID = 'gkso-internal-linking-panel';

function getEl(id) { return document.getElementById(id); }

function api(path, method, body) {
  const el      = document.getElementById(ROOT_ID);
  const nonce   = el ? el.dataset.nonce : '';
  const baseUrl = el ? el.dataset.restUrl : '';
  return fetch(baseUrl + 'gemini-kimi-seo/v1' + path, {
    method: method || 'GET',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: body ? JSON.stringify(body) : undefined,
  }).then(r => r.json());
}

const STYLE = `
  @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&display=swap');
  #gkso-il-root * { box-sizing: border-box; }
  #gkso-il-root { font-family: 'Syne', sans-serif; background: #020817; min-height: 100vh; color: #f8fafc; margin: -20px -20px 0; }
  .gkso-il-card { background: #0d1117; border: 1px solid #1e293b; border-radius: 10px; padding: 18px 20px; margin-bottom: 12px; }
  .gkso-il-tag { background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.25); font-family: monospace; font-size: 10px; padding: 2px 7px; border-radius: 4px; white-space: nowrap; }
  .gkso-il-btn { font-family: monospace; font-size: 11px; padding: 6px 14px; border-radius: 6px; cursor: pointer; border: 1px solid; transition: all .2s; }
  .gkso-il-btn-primary { background: linear-gradient(135deg,#38bdf8,#a78bfa) !important; color: #020817 !important; border: none !important; font-weight: 700; }
  .gkso-il-btn-approve { background: rgba(74,222,128,.1); color: #4ade80; border-color: rgba(74,222,128,.4); }
  .gkso-il-btn-reject  { background: rgba(248,113,113,.1); color: #f87171; border-color: rgba(248,113,113,.3); }
  .gkso-il-btn-ghost   { background: none; color: #475569; border-color: #1e293b; }
  .gkso-il-filter-btn  { background: none; font-family: monospace; font-size: 10px; padding: 4px 12px; border-radius: 99px; cursor: pointer; text-transform: uppercase; transition: all .15s; }
  .gkso-il-suggestion  { background: #0d1117; border: 1px solid #1e293b; border-radius: 10px; margin-bottom: 10px; overflow: hidden; transition: all .2s; }
  .gkso-il-suggestion.approved { background: rgba(74,222,128,.03); border-color: rgba(74,222,128,.2); }
  .gkso-il-suggestion.rejected { background: rgba(248,113,113,.03); border-color: rgba(248,113,113,.15); opacity: .6; }
  .gkso-il-progress-bar { height: 3px; background: #1e293b; border-radius: 99px; overflow: hidden; margin-top: 10px; }
  .gkso-il-progress-fill { height: 100%; background: linear-gradient(90deg,#38bdf8,#a78bfa); border-radius: 99px; transition: width .4s ease; }
  .gkso-il-tab { background: none; border: none; cursor: pointer; padding: 11px 16px; font-family: 'Syne',sans-serif; font-size: 13px; font-weight: 500; color: #475569; border-bottom: 2px solid transparent; transition: all .15s; }
  .gkso-il-tab.active { color: #38bdf8 !important; font-weight: 700; border-bottom-color: #38bdf8 !important; }
  .gkso-il-bar-wrap { background: #1e293b; border-radius: 99px; height: 5px; overflow: hidden; flex: 1; }
  .gkso-il-bar-fill  { height: 100%; border-radius: 99px; transition: width .6s cubic-bezier(.16,1,.3,1); }
  .gkso-il-toggle    { width: 38px; height: 22px; border-radius: 11px; cursor: pointer; position: relative; transition: background .2s; flex-shrink: 0; }
  .gkso-il-toggle-dot { position: absolute; width: 16px; height: 16px; border-radius: 50%; background: #fff; top: 3px; transition: left .2s; }
  .gkso-il-slider    { width: 100%; accent-color: #f59e0b; }
`;

let state = {
  activeTab: 'suggestions', filterStatus: 'all', filterMethod: 'all',
  suggestions: [], analysisRunning: false, analysisProgress: 0,
  indexStats: null, pillarPages: [],
  instructions: '', expandedCards: {}, pendingCount: 0, approvedCount: 0,
  algorithmSettings: {
    min_confidence: 62, max_links_per_post: 6, min_words_between: 150,
    semantic_weight: 35, keyword_weight: 30, authority_weight: 15,
    orphan_weight: 10, recency_weight: 10, auto_approve: false,
    auto_approve_threshold: 90, avoid_headings: true, avoid_first_para: true,
    avoid_blockquotes: true, prefer_early: true, one_url_per_post: true,
  },
};

function setState(u) { Object.assign(state, u); render(); }
function recalcCounts() {
  state.pendingCount  = state.suggestions.filter(s => s.status === 'pending').length;
  state.approvedCount = state.suggestions.filter(s => s.status === 'approved').length;
}

function init() {
  const styleEl = document.createElement('style');
  styleEl.textContent = STYLE;
  document.head.appendChild(styleEl);

  const root = getEl(ROOT_ID);
  if (!root) return;
  const inner = document.createElement('div');
  inner.id = 'gkso-il-root';
  root.appendChild(inner);

  loadStats(); loadSettings(); loadInstructions(); loadPillarPages();
  setState({ suggestions: getMockSuggestions() });
  recalcCounts(); render();
}

function loadStats()        { api('/link-index-stats').then(d => d && !d.code && setState({ indexStats: d })).catch(() => {}); }
function loadSettings()     { api('/link-algorithm-settings').then(d => d && !d.code && setState({ algorithmSettings: Object.assign({}, state.algorithmSettings, d) })).catch(() => {}); }
function loadInstructions() { api('/link-instructions').then(d => d && d.instructions != null && setState({ instructions: d.instructions })).catch(() => {}); }
function loadPillarPages()  { api('/link-pillar-pages').then(d => d && d.pillar_pages && setState({ pillarPages: d.pillar_pages })).catch(() => {}); }

function runAnalysis() {
  setState({ analysisRunning: true, analysisProgress: 0 });
  const steps = [12, 28, 41, 57, 69, 83, 94, 100];
  steps.forEach((p, i) => setTimeout(() => {
    setState({ analysisProgress: p });
    if (p === 100) setTimeout(() => setState({ analysisRunning: false }), 800);
  }, i * 400));
}

function approveSuggestion(id) { setState({ suggestions: state.suggestions.map(s => s.id === id ? {...s, status:'approved'} : s) }); recalcCounts(); }
function rejectSuggestion(id)  { setState({ suggestions: state.suggestions.map(s => s.id === id ? {...s, status:'rejected'} : s) }); recalcCounts(); }
function approveAll()          { setState({ suggestions: state.suggestions.map(s => s.status==='pending' ? {...s, status:'approved'} : s) }); recalcCounts(); }
function toggleCard(id)        { setState({ expandedCards: Object.assign({}, state.expandedCards, { [id]: !state.expandedCards[id] }) }); }

function saveSettings()     { api('/link-algorithm-settings','POST', state.algorithmSettings).then(() => showToast('Algorithm settings saved')); }
function saveInstructions() { api('/link-instructions','POST', { instructions: state.instructions }).then(() => showToast('Instructions saved')); }
function rebuildIndex() {
  if (!confirm('Rebuild the full site link index? This may take a moment.')) return;
  api('/rebuild-link-index','POST',{}).then(d => { showToast('Index rebuilt: '+(d.indexed||0)+' posts'); loadStats(); });
}

function showToast(msg) {
  const t = document.createElement('div');
  t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#4ade80;color:#020817;font-family:monospace;font-size:12px;font-weight:700;padding:10px 18px;border-radius:8px;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.4);transition:opacity .4s';
  t.textContent = '✓ ' + msg;
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity='0'; setTimeout(() => t.remove(), 400); }, 2500);
}

function render() {
  const root = document.getElementById('gkso-il-root');
  if (!root) return;
  root.innerHTML = buildHTML();
  bindEvents();
}

function escHtml(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildHTML() {
  const { activeTab, filterStatus, filterMethod, suggestions, analysisRunning, analysisProgress,
          pendingCount, approvedCount, indexStats, algorithmSettings, pillarPages } = state;

  const filtered = suggestions.filter(s =>
    (filterStatus === 'all' || s.status === filterStatus) &&
    (filterMethod === 'all' || s.method === filterMethod)
  );

  const tabs = [
    { id: 'suggestions',  label: 'Suggestions',       badge: pendingCount },
    { id: 'instructions', label: 'Agent Instructions', badge: 0 },
    { id: 'algorithm',    label: 'Algorithm Config',   badge: 0 },
    { id: 'index',        label: 'Index Monitor',      badge: 0 },
  ];

  return `
    <div style="background:#0d1117;border-bottom:1px solid #1e293b;padding:16px 28px;">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#38bdf8,#a78bfa);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px;">🔗</div>
        <div>
          <h2 style="font-size:17px;font-weight:800;margin:0;letter-spacing:-.03em;color:#f8fafc;">Internal Linking Agent</h2>
          <p style="color:#334155;font-family:monospace;font-size:10px;margin:0;letter-spacing:.08em;">AI-POWERED · 6-PHASE ALGORITHM</p>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span class="gkso-il-tag" style="color:#f59e0b;border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.1);">${pendingCount} PENDING</span>
          <span class="gkso-il-tag" style="color:#4ade80;border-color:rgba(74,222,128,.3);background:rgba(74,222,128,.1);">${approvedCount} APPROVED</span>
          <button id="gkso-il-run" class="gkso-il-btn ${analysisRunning ? 'gkso-il-btn-ghost' : 'gkso-il-btn-primary'}" ${analysisRunning ? 'disabled' : ''}>
            ${analysisRunning ? `Analyzing… ${analysisProgress}%` : '▶ Run Analysis'}
          </button>
        </div>
      </div>
      ${analysisRunning ? `<div class="gkso-il-progress-bar"><div class="gkso-il-progress-fill" style="width:${analysisProgress}%"></div></div>` : ''}
    </div>

    <div style="background:#0d1117;border-bottom:1px solid #1e293b;padding:0 28px;display:flex;gap:0;overflow-x:auto;">
      ${tabs.map(t => `
        <button class="gkso-il-tab ${activeTab===t.id?'active':''}" data-tab="${t.id}">
          ${t.label}${t.badge>0 ? `<span style="background:rgba(56,189,248,.12);color:#38bdf8;border:1px solid rgba(56,189,248,.2);font-family:monospace;font-size:10px;padding:1px 6px;border-radius:99px;margin-left:5px;">${t.badge}</span>` : ''}
        </button>`).join('')}
    </div>

    <div style="padding:24px 28px;">
      ${activeTab==='suggestions'  ? renderSuggestions(filtered) : ''}
      ${activeTab==='instructions' ? renderInstructions() : ''}
      ${activeTab==='algorithm'    ? renderAlgorithm(algorithmSettings) : ''}
      ${activeTab==='index'        ? renderIndex(indexStats, pillarPages) : ''}
    </div>
  `;
}

function renderSuggestions(filtered) {
  const statusFilters = ['all','pending','approved','rejected'];
  const methodFilters = ['all','keyword','semantic','ensemble'];
  const { filterStatus, filterMethod } = state;
  return `
    <div style="display:flex;gap:8px;margin-bottom:18px;align-items:center;flex-wrap:wrap;">
      <span style="color:#475569;font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;">Filter:</span>
      ${statusFilters.map(f => `<button class="gkso-il-filter-btn" data-filter-status="${f}" style="border:1px solid ${filterStatus===f?'rgba(56,189,248,.4)':'#1e293b'};color:${filterStatus===f?'#38bdf8':'#475569'};background:${filterStatus===f?'rgba(56,189,248,.08)':'none'};">${f}</button>`).join('')}
      <div style="width:1px;height:20px;background:#1e293b;"></div>
      ${methodFilters.map(m => `<button class="gkso-il-filter-btn" data-filter-method="${m}" style="border:1px solid ${filterMethod===m?'rgba(167,139,250,.4)':'#1e293b'};color:${filterMethod===m?'#a78bfa':'#475569'};background:${filterMethod===m?'rgba(167,139,250,.08)':'none'};">${m}</button>`).join('')}
      <button id="gkso-il-approve-all" class="gkso-il-btn gkso-il-btn-approve" style="margin-left:auto;">✓ Approve All Pending</button>
    </div>
    ${filtered.length===0 ? `<div class="gkso-il-card" style="text-align:center;padding:40px 20px;"><p style="color:#334155;font-family:monospace;font-size:13px;">No suggestions match current filters.</p></div>` : filtered.map(s => renderCard(s)).join('')}
  `;
}

function renderCard(s) {
  const expanded = state.expandedCards[s.id];
  const cc = s.confidence>0.88?'#4ade80':s.confidence>0.75?'#f59e0b':'#f87171';
  const mc = {keyword:'#f59e0b',semantic:'#38bdf8',ensemble:'#a78bfa'}[s.method]||'#94a3b8';
  const ml = {keyword:'Keyword Match',semantic:'Semantic',ensemble:'Ensemble'}[s.method]||s.method;
  const pct = v => Math.round(v*100);
  const sc = s.status==='approved'?'#4ade80':s.status==='rejected'?'#f87171':'#f59e0b';
  const ctx = s.context ? s.context.replace(s.anchor, `<mark style="background:rgba(245,158,11,.2);color:#f59e0b;padding:1px 3px;border-radius:3px;font-weight:700;">${s.anchor}</mark>`) : '';
  const sigs = [{k:'semantic',l:'Semantic Match',c:'#38bdf8'},{k:'keyword',l:'Keyword Alignment',c:'#f59e0b'},{k:'authority',l:'Page Authority',c:'#4ade80'},{k:'orphan',l:'Orphan Priority',c:'#a78bfa'},{k:'recency',l:'Recency Boost',c:'#fb923c'}];
  return `
    <div class="gkso-il-suggestion ${s.status}">
      <div style="padding:14px 16px;cursor:pointer;display:flex;align-items:flex-start;gap:12px;" data-toggle-card="${s.id}">
        <div style="flex-shrink:0;text-align:center;">
          <div style="width:44px;height:44px;border-radius:50%;border:3px solid ${cc};display:flex;align-items:center;justify-content:center;background:${cc}18;">
            <span style="color:${cc};font-family:monospace;font-size:12px;font-weight:800;">${pct(s.confidence)}</span>
          </div>
          <p style="color:#475569;font-family:monospace;font-size:9px;margin:4px 0 0;text-align:center;">CONF</p>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
            <span style="color:#94a3b8;font-size:12px;">${escHtml(s.postTitle||'')}</span>
            <span style="color:#334155;font-size:10px;">→</span>
            <span class="gkso-il-tag" style="color:${mc};border-color:${mc}40;background:${mc}12;">${ml}</span>
            <span class="gkso-il-tag" style="color:#64748b;">${s.position||''}</span>
          </div>
          <p style="margin:0 0 6px;font-family:monospace;font-size:11px;">
            <span style="color:#475569;">Anchor: </span><span style="color:#f59e0b;font-weight:700;">"${escHtml(s.anchor)}"</span>
            <span style="color:#475569;"> → </span><span style="color:#38bdf8;">${escHtml(s.targetTitle||s.target_url||'')}</span>
          </p>
          <p style="margin:0;color:#334155;font-family:monospace;font-size:10px;">${escHtml(s.targetUrl||s.target_url||'')}</p>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0;align-items:center;">
          ${s.status==='pending' ? `
            <button class="gkso-il-btn gkso-il-btn-approve gkso-approve" data-id="${s.id}">✓ Approve</button>
            <button class="gkso-il-btn gkso-il-btn-reject gkso-reject" data-id="${s.id}">✗ Reject</button>
          ` : `<span class="gkso-il-tag" style="color:${sc};border-color:${sc}40;background:${sc}12;">${s.status==='approved'?'✓ Approved':'✗ Rejected'}</span>`}
          <span style="color:#334155;font-size:14px;margin-left:4px;">${expanded?'▲':'▼'}</span>
        </div>
      </div>
      ${expanded ? `
        <div style="border-top:1px solid #1e293b;padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <p style="color:#475569;font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;margin:0 0 12px;">Context Preview</p>
            <p style="color:#64748b;font-size:12px;line-height:1.7;margin:0;">"${ctx}"</p>
          </div>
          <div>
            <p style="color:#475569;font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;margin:0 0 12px;">Signal Breakdown</p>
            ${sigs.map(({k,l,c}) => `
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <span style="color:#475569;font-family:monospace;font-size:10px;width:120px;flex-shrink:0;">${l}</span>
                <div class="gkso-il-bar-wrap"><div class="gkso-il-bar-fill" style="width:${pct(s.signals[k])}%;background:${c};"></div></div>
                <span style="color:${c};font-family:monospace;font-size:10px;width:28px;text-align:right;">${pct(s.signals[k])}</span>
              </div>`).join('')}
          </div>
        </div>` : ''}
    </div>`;
}

function renderInstructions() {
  return `
    <div style="max-width:860px;">
      <div style="margin-bottom:20px;">
        <h3 style="font-size:16px;font-weight:800;color:#f8fafc;margin:0 0 4px;letter-spacing:-.02em;">Agent Instructions</h3>
        <p style="color:#475569;font-family:monospace;font-size:11px;margin:0;">Write rules in plain English or Markdown. Prepended to the agent's system prompt at highest priority.</p>
      </div>
      <div class="gkso-il-card">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
          ${[['+ Priority Page Rule','\n## Priority Rule\n- Always link to /your-url/ when topic matches [keyword]\n'],['+ Exclusion Rule','\n- Never link FROM [post-type] TO [post-type]\n'],['+ Max Links Rule','\n- [post-type] posts: max [N] internal links per post\n'],['+ Keyword Trigger',"\n- When post contains '[keyword]', always link to /target-url/\n"]].map(([l,t]) => `<button class="gkso-il-btn gkso-il-btn-ghost gkso-snippet" data-text="${encodeURIComponent(t)}" style="font-size:10px;">${l}</button>`).join('')}
        </div>
        <div style="position:relative;">
          <textarea id="gkso-il-instructions" style="width:100%;min-height:320px;background:#020817;border:1px solid #1e293b;border-radius:8px;color:#94a3b8;font-family:monospace;font-size:12px;padding:16px;resize:vertical;line-height:1.7;outline:none;box-sizing:border-box;" spellcheck="false">${escHtml(state.instructions)}</textarea>
          <div style="position:absolute;top:10px;right:12px;"><span class="gkso-il-tag" style="color:#475569;">Markdown supported</span></div>
        </div>
        <div style="background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:12px 14px;margin-top:12px;display:flex;gap:10px;">
          <span style="font-size:16px;flex-shrink:0;">⚡</span>
          <div>
            <p style="color:#f59e0b;font-family:monospace;font-size:11px;font-weight:700;margin:0 0 4px;">How these instructions are used</p>
            <p style="color:#64748b;font-family:monospace;font-size:11px;margin:0;line-height:1.6;">Prepended to the agent's system prompt at highest priority. They override algorithm defaults but cannot override hard safety rules (no external links, no duplicate anchors, no heading links).</p>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
          <button id="gkso-il-reset-instructions" class="gkso-il-btn gkso-il-btn-ghost">Reset to Default</button>
          <button id="gkso-il-save-instructions" class="gkso-il-btn" style="background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.4);color:#f59e0b;">Save Instructions</button>
        </div>
      </div>
      <div class="gkso-il-card">
        <p style="color:#475569;font-family:monospace;font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 14px;">Instruction Execution Order</p>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
          ${[['1st','User Instructions','Your rules above — highest priority, override everything below','#f8fafc'],['2nd','Algorithm Defaults','Configured weights, thresholds, placement toggles','#38bdf8'],['3rd','Hard Safety Rules','Cannot be overridden: no external links, no duplicate anchors, no heading links','#f87171']].map(([o,l,d,c])=>`<div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px;"><span style="background:${c}18;color:${c};font-family:monospace;font-size:10px;font-weight:800;padding:2px 8px;border-radius:4px;">${o}</span><p style="color:${c};font-size:13px;font-weight:700;margin:8px 0 6px;">${l}</p><p style="color:#475569;font-family:monospace;font-size:10px;margin:0;line-height:1.6;">${d}</p></div>`).join('')}
        </div>
      </div>
    </div>`;
}

function renderAlgorithm(s) {
  const totalW = s.semantic_weight + s.keyword_weight + s.authority_weight + s.orphan_weight + s.recency_weight;
  const weightOk = totalW === 100;
  function slider(label, key, min, max, unit, color) {
    color = color||'#f59e0b';
    return `<div style="margin-bottom:14px;"><div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="color:#94a3b8;font-family:monospace;font-size:11px;">${label}</span><span style="color:${color};font-family:monospace;font-size:11px;font-weight:700;">${s[key]}${unit}</span></div><input type="range" class="gkso-il-slider" min="${min}" max="${max}" value="${s[key]}" data-setting="${key}"></div>`;
  }
  function tog(label, key, desc) {
    const on = s[key];
    return `<div style="display:flex;align-items:flex-start;justify-content:space-between;padding:10px 0;border-bottom:1px solid #0f172a;"><div><p style="color:#94a3b8;font-family:monospace;font-size:12px;margin:0 0 2px;">${label}</p>${desc?`<p style="color:#334155;font-family:monospace;font-size:10px;margin:0;">${desc}</p>`:''}</div><div class="gkso-il-toggle" data-toggle="${key}" style="background:${on?'#f59e0b':'#1e293b'};margin-left:12px;"><div class="gkso-il-toggle-dot" style="left:${on?'19px':'3px'};"></div></div></div>`;
  }
  return `
    <div style="margin-bottom:20px;"><h3 style="font-size:16px;font-weight:800;color:#f8fafc;margin:0 0 4px;letter-spacing:-.02em;">Algorithm Configuration</h3><p style="color:#475569;font-family:monospace;font-size:11px;margin:0;">Tune scoring weights, thresholds, and placement rules. Signal weights must sum to 100%.</p></div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
      <div class="gkso-il-card">
        <p style="color:#475569;font-family:monospace;font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 12px;">Scoring Thresholds</p>
        ${slider('Minimum Confidence Score','min_confidence',0,100,'%')}
        ${slider('Max Links per Post','max_links_per_post',1,20,'','#38bdf8')}
        ${slider('Min Words Between Links','min_words_between',50,500,'','#4ade80')}
        ${s.auto_approve ? slider('Auto-Approve Threshold','auto_approve_threshold',50,100,'%','#a78bfa') : ''}
      </div>
      <div class="gkso-il-card">
        <p style="color:#475569;font-family:monospace;font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 12px;">Algorithm Signal Weights</p>
        ${slider('Semantic Similarity','semantic_weight',0,100,'%')}
        ${slider('Keyword Alignment','keyword_weight',0,100,'%','#38bdf8')}
        ${slider('Authority Score','authority_weight',0,100,'%','#4ade80')}
        ${slider('Orphan Priority','orphan_weight',0,100,'%','#a78bfa')}
        ${slider('Recency Boost','recency_weight',0,100,'%','#fb923c')}
        <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1px solid #1e293b;">
          <span style="color:#475569;font-family:monospace;font-size:10px;">Total Weight</span>
          <span style="color:${weightOk?'#4ade80':'#f87171'};font-family:monospace;font-size:11px;font-weight:700;">${totalW}% ${!weightOk?'⚠ must = 100%':'✓'}</span>
        </div>
      </div>
      <div class="gkso-il-card">
        <p style="color:#475569;font-family:monospace;font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 4px;">Placement Rules</p>
        ${tog('Avoid Heading Links','avoid_headings','Never place links inside h1–h4 tags')}
        ${tog('Avoid First Paragraph','avoid_first_para','Skip linking in opening paragraph')}
        ${tog('Avoid Blockquotes','avoid_blockquotes','')}
        ${tog('Prefer Early Placement','prefer_early','Bias links to top third of content')}
        ${tog('One URL Per Post','one_url_per_post','Each target URL used once per post')}
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid #1e293b;">
          <p style="color:#475569;font-family:monospace;font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 4px;">Automation</p>
          ${tog('Auto-Approve High Confidence','auto_approve','Skip review queue above threshold')}
        </div>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;"><button id="gkso-il-save-settings" class="gkso-il-btn gkso-il-btn-primary">Save Algorithm Settings</button></div>`;
}

function renderIndex(stats, pillars) {
  const cards = [
    {label:'Posts Indexed', value: stats?stats.posts_indexed:'—', max:stats?Math.max(stats.total_posts,1):1, color:'#f59e0b'},
    {label:'Links Mapped',  value: stats?stats.links_mapped:'—',  max:3200, color:'#38bdf8'},
    {label:'Orphan Pages',  value: stats?stats.orphan_count:'—',  max:50,   color:'#f87171'},
    {label:'Avg Links/Post',value: stats?stats.avg_links_post:'—',max:8,    color:'#4ade80'},
  ];
  const phases = [
    {p:'01',l:'Content Indexing',d:'TF-IDF vectors + named entity extraction',c:'#f59e0b'},
    {p:'02',l:'Anchor Candidate Gen',d:'Noun phrases + keyword lookup + semantic',c:'#38bdf8'},
    {p:'03',l:'Candidate Filtering',d:'Density, position, heading, duplicate checks',c:'#4ade80'},
    {p:'04',l:'URL Scoring',d:'5-signal weighted composite score',c:'#a78bfa'},
    {p:'05',l:'Placement Logic',d:'Position + context window AI validation',c:'#fb923c'},
    {p:'06',l:'User Rule Override',d:'Your instructions applied last',c:'#f8fafc'},
  ];
  return `
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
      ${cards.map(h=>`<div class="gkso-il-card"><p style="color:#475569;font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;margin:0 0 8px;">${h.label}</p><p style="color:${h.color};font-family:monospace;font-size:28px;font-weight:800;margin:0 0 8px;line-height:1;">${h.value}</p><div class="gkso-il-bar-wrap"><div class="gkso-il-bar-fill" style="width:${stats?Math.min(100,Math.round(parseFloat(h.value)/h.max*100)):0}%;background:${h.color};"></div></div></div>`).join('')}
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div class="gkso-il-card">
        <p style="color:#475569;font-family:monospace;font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 14px;">Pillar Page Link Equity</p>
        ${pillars.length===0?`<p style="color:#334155;font-family:monospace;font-size:12px;">No pillar pages configured yet.</p>`:pillars.map(p=>`<div style="padding:10px 0;border-bottom:1px solid #0f172a;"><div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="color:#e2e8f0;font-size:12px;">${escHtml(p.title)}</span><span class="gkso-il-tag" style="color:${p.priority==='high'?'#4ade80':'#f59e0b'};border-color:${p.priority==='high'?'rgba(74,222,128,.3)':'rgba(245,158,11,.3)'};background:${p.priority==='high'?'rgba(74,222,128,.1)':'rgba(245,158,11,.1)'};">${p.priority}</span></div><div style="display:flex;gap:8px;align-items:center;"><div class="gkso-il-bar-wrap"><div class="gkso-il-bar-fill" style="width:${Math.min(100,Math.round((p.inboundLinks||p.inbound_links||0)/30*100))}%;background:#38bdf8;"></div></div><span style="color:#38bdf8;font-family:monospace;font-size:11px;flex-shrink:0;">${p.inboundLinks||p.inbound_links||0} inbound</span></div></div>`).join('')}
        <button id="gkso-il-add-pillar" class="gkso-il-btn gkso-il-btn-ghost" style="margin-top:12px;width:100%;border-style:dashed;">+ Add Pillar Page</button>
      </div>
      <div class="gkso-il-card">
        <p style="color:#475569;font-family:monospace;font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 14px;">Algorithm Pipeline</p>
        ${phases.map((step,i)=>`<div style="display:flex;gap:10px;align-items:flex-start;"><div style="display:flex;flex-direction:column;align-items:center;"><div style="width:28px;height:28px;border-radius:50%;background:${step.c}18;border:1px solid ${step.c}40;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="color:${step.c};font-family:monospace;font-size:9px;font-weight:800;">${step.p}</span></div>${i<phases.length-1?`<div style="width:1px;height:18px;background:#1e293b;margin:2px 0;"></div>`:''}</div><div style="padding-bottom:4px;"><p style="color:#e2e8f0;font-family:monospace;font-size:11px;font-weight:700;margin:4px 0 2px;">${step.l}</p><p style="color:#475569;font-family:monospace;font-size:10px;margin:0;">${step.d}</p></div></div>`).join('')}
        <div style="margin-top:16px;padding-top:12px;border-top:1px solid #1e293b;">
          <button id="gkso-il-rebuild-index" class="gkso-il-btn gkso-il-btn-ghost" style="width:100%;font-size:11px;">↺ Rebuild Full Index</button>
        </div>
      </div>
    </div>`;
}

function bindEvents() {
  document.querySelectorAll('.gkso-il-tab').forEach(btn => btn.addEventListener('click', () => setState({ activeTab: btn.dataset.tab })));
  const runBtn = getEl('gkso-il-run'); if (runBtn) runBtn.addEventListener('click', runAnalysis);
  document.querySelectorAll('.gkso-approve').forEach(btn => btn.addEventListener('click', (e) => { e.stopPropagation(); approveSuggestion(btn.dataset.id); }));
  document.querySelectorAll('.gkso-reject').forEach(btn => btn.addEventListener('click', (e) => { e.stopPropagation(); rejectSuggestion(btn.dataset.id); }));
  const aab = getEl('gkso-il-approve-all'); if (aab) aab.addEventListener('click', approveAll);
  document.querySelectorAll('[data-filter-status]').forEach(btn => btn.addEventListener('click', () => setState({ filterStatus: btn.dataset.filterStatus })));
  document.querySelectorAll('[data-filter-method]').forEach(btn => btn.addEventListener('click', () => setState({ filterMethod: btn.dataset.filterMethod })));
  document.querySelectorAll('[data-toggle-card]').forEach(el => el.addEventListener('click', () => toggleCard(el.dataset.toggleCard)));
  document.querySelectorAll('.gkso-il-slider[data-setting]').forEach(input => input.addEventListener('input', () => { const s = Object.assign({}, state.algorithmSettings, { [input.dataset.setting]: Number(input.value) }); setState({ algorithmSettings: s }); }));
  document.querySelectorAll('.gkso-il-toggle[data-toggle]').forEach(el => el.addEventListener('click', () => { const k = el.dataset.toggle; setState({ algorithmSettings: Object.assign({}, state.algorithmSettings, { [k]: !state.algorithmSettings[k] }) }); }));
  const ss = getEl('gkso-il-save-settings'); if (ss) ss.addEventListener('click', saveSettings);
  const ta = getEl('gkso-il-instructions'); if (ta) ta.addEventListener('input', () => { state.instructions = ta.value; });
  document.querySelectorAll('.gkso-snippet').forEach(btn => btn.addEventListener('click', () => { state.instructions += decodeURIComponent(btn.dataset.text); const t = getEl('gkso-il-instructions'); if(t) t.value = state.instructions; }));
  const si = getEl('gkso-il-save-instructions'); if (si) si.addEventListener('click', saveInstructions);
  const ri = getEl('gkso-il-reset-instructions'); if (ri) ri.addEventListener('click', () => { state.instructions = defaultInstructions(); const t = getEl('gkso-il-instructions'); if(t) t.value = state.instructions; });
  const rib = getEl('gkso-il-rebuild-index'); if (rib) rib.addEventListener('click', rebuildIndex);
  const apb = getEl('gkso-il-add-pillar'); if (apb) apb.addEventListener('click', () => {
    const url = prompt('Pillar page URL (e.g. /your-guide/):');
    const title = url ? prompt('Page title:') : null;
    if (url && title) { const pages = [...state.pillarPages, {id:Date.now(),title,url,inboundLinks:0,priority:'high'}]; setState({pillarPages:pages}); api('/link-pillar-pages','POST',{pillar_pages:pages}); }
  });
}

function defaultInstructions() {
  return `# Internal Linking Instructions\n\n## Priority Pages\n- Add your pillar page URLs here\n\n## Exclusion Rules\n- Never link FROM product review posts TO tutorial posts\n\n## Linking Style\n- Informational posts: max 4 internal links per post\n- Long-form guides (>2000 words): up to 8 internal links allowed\n`;
}

function getMockSuggestions() {
  return [
    {id:'1',postTitle:'10 Best Budget Laptops for Students 2024',anchor:'SSD storage speeds',targetUrl:'/how-to-benchmark-ssd-performance/',targetTitle:'How to Benchmark SSD Performance',confidence:0.89,position:'paragraph 3',method:'semantic',context:'...models that balance processing power and SSD storage speeds without breaking the budget. When comparing...',signals:{semantic:0.91,keyword:0.88,authority:0.72,orphan:0.40,recency:0.65},status:'pending'},
    {id:'2',postTitle:'10 Best Budget Laptops for Students 2024',anchor:'battery life benchmarks',targetUrl:'/laptop-battery-life-testing-guide/',targetTitle:'Laptop Battery Life Testing: Complete Guide',confidence:0.94,position:'paragraph 6',method:'keyword',context:'...we ran our standard battery life benchmarks across 8 hours of simulated workloads to find which...',signals:{semantic:0.95,keyword:0.96,authority:0.58,orphan:0.90,recency:0.80},status:'pending'},
    {id:'3',postTitle:'Python for Beginners: Complete Course',anchor:'virtual environments',targetUrl:'/python-virtual-environment-setup/',targetTitle:'Python Virtual Environment Setup & Management',confidence:0.87,position:'paragraph 2',method:'keyword',context:'...recommended to use virtual environments to isolate your project dependencies from the system Python...',signals:{semantic:0.84,keyword:0.94,authority:0.65,orphan:0.70,recency:0.55},status:'approved'},
    {id:'4',postTitle:'Python for Beginners: Complete Course',anchor:'debugging workflow',targetUrl:'/python-debugging-techniques/',targetTitle:'Python Debugging Techniques for Beginners',confidence:0.78,position:'paragraph 9',method:'semantic',context:'...once comfortable with the basics, establishing a consistent debugging workflow will save hours...',signals:{semantic:0.82,keyword:0.74,authority:0.71,orphan:0.30,recency:0.62},status:'rejected'},
    {id:'5',postTitle:'Ultimate Guide to Email Marketing ROI',anchor:'A/B split testing campaigns',targetUrl:'/email-ab-testing-guide/',targetTitle:'Email A/B Testing: Subject Lines, CTAs & Timing',confidence:0.92,position:'paragraph 4',method:'ensemble',context:'...measuring revenue lift becomes more accurate when you implement A/B split testing campaigns alongside your main sends...',signals:{semantic:0.90,keyword:0.93,authority:0.80,orphan:0.55,recency:0.45},status:'pending'},
    {id:'6',postTitle:'Shopify vs WooCommerce: Full Comparison',anchor:'payment gateway fees',targetUrl:'/shopify-payment-processing-costs/',targetTitle:'Shopify Payments vs Third-Party Gateways: Full Cost Breakdown',confidence:0.83,position:'paragraph 7',method:'semantic',context:'...the real cost difference becomes apparent when you factor in payment gateway fees across different monthly transaction volumes...',signals:{semantic:0.85,keyword:0.80,authority:0.74,orphan:0.82,recency:0.38},status:'pending'},
  ];
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

})();
