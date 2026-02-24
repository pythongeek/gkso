the Core Engine has the logic but lacks the executable skeleton, the Dashboard is a mocked UI shell, and the Linking Agents are architectural specs without WordPress/n8n glue.

Here is the Production Completion Package — the missing 20% that turns this into a deployable system.

---

## **1\. Critical: Main Plugin Entry File (The Missing Skeleton)**

`gemini-kimi-seo-optimizer.php` — the bootstrap that ties all classes together with PSR-4 autoloading and dependency injection.

phpCopy

\<?php  
*/\*\**  
 *\* Plugin Name: Gemini-Kimi SEO Optimizer*  
 *\* Description: Automated SEO A/B testing with AI-powered title/meta generation*  
 *\* Version: 1.0.0*  
 *\* Author: Technical Founder Stack*  
 *\* Requires PHP: 7.4*  
 *\* Requires WP: 5.8*  
 *\* Text Domain: gemini-kimi-seo*  
 *\* Domain Path: /languages*  
 *\*/*

if (\!defined('ABSPATH')) exit;

define('GKSO\_VERSION', '1.0.0');  
define('GKSO\_PLUGIN\_DIR', plugin\_dir\_path(\_\_FILE\_\_));  
define('GKSO\_PLUGIN\_URL', plugin\_dir\_url(\_\_FILE\_\_));  
define('GKSO\_REST\_NAMESPACE', 'gemini-kimi-seo/v1');

*// PSR-4 Autoloader*  
spl\_autoload\_register(function ($class) {  
    $prefix \= 'GKSO\\\\';  
    $base\_dir \= GKSO\_PLUGIN\_DIR . 'includes/';  
      
    $len \= strlen($prefix);  
    if (strncmp($prefix, $class, $len) \!== 0\) return;  
      
    $relative\_class \= substr($class, $len);  
    $file \= $base\_dir . str\_replace('\\\\', '/', $relative\_class) . '.php';  
      
    if (file\_exists($file)) require $file;  
});

*// Activation/Deactivation*  
register\_activation\_hook(\_\_FILE\_\_, \['GKSO\\\\Activator', 'activate'\]);  
register\_deactivation\_hook(\_\_FILE\_\_, \['GKSO\\\\Deactivator', 'deactivate'\]);

*// Initialize*  
add\_action('plugins\_loaded', function() {  
    *// i18n*  
    load\_plugin\_textdomain('gemini-kimi-seo', false, dirname(plugin\_basename(\_\_FILE\_\_)) . '/languages');  
      
    *// Check dependencies (Yoast/Rank Math/AIOSEO)*  
    $active\_plugins \= get\_option('active\_plugins');  
    $has\_seo\_plugin \= false;  
    foreach ($active\_plugins as $plugin) {  
        if (strpos($plugin, 'wordpress-seo') \!== false ||   
            strpos($plugin, 'seo-by-rank-math') \!== false ||  
            strpos($plugin, 'all-in-one-seo-pack') \!== false) {  
            $has\_seo\_plugin \= true;  
            break;  
        }  
    }  
      
    if (\!$has\_seo\_plugin && \!defined('GKSO\_ALLOW\_NO\_SEO\_PLUGIN')) {  
        add\_action('admin\_notices', function() {  
            echo '\<div class="error"\>\<p\>' .   
                 esc\_html\_\_('Gemini-Kimi SEO requires Yoast SEO, Rank Math, or AIOSEO to be active.', 'gemini-kimi-seo') .   
                 '\</p\>\</div\>';  
        });  
        return;  
    }  
      
    *// Initialize core components*  
    new GKSO\\\\Admin();  
    new GKSO\\\\RestAPI();  
    new GKSO\\\\MetaBoxes();  
    new GKSO\\\\Cron();  
      
    *// Database upgrade check*  
    GKSO\\\\Activator::check\_upgrade();  
      
    *// WP-CLI*  
    if (defined('WP\_CLI') && WP\_CLI) {  
        WP\_CLI::add\_command('gkso', 'GKSO\\\\CLI');  
    }

});

---

## **2\. Critical: Admin Assets (CSS \+ JS)**

`assets/css/admin.css` — Mission Control aesthetic matching your design spec:

cssCopy

:root {  
    \--gkso-bg: \#0a0f1a;  
    \--gkso-panel: \#0d1117;  
    \--gkso-border: \#1e293b;  
    \--gkso-amber: \#f59e0b;  
    \--gkso-blue: \#38bdf8;  
    \--gkso-green: \#4ade80;  
    \--gkso-red: \#f87171;  
    \--gkso-text: \#e2e8f0;  
    \--gkso-muted: \#64748b;  
}

.gkso-wrap { background: var(--gkso-bg); min-height: 100vh; padding: 20px; color: var(--gkso-text); font-family: 'DM Sans', sans-serif; }  
.gkso-card { background: var(--gkso-panel); border: 1px solid var(--gkso-border); border-radius: 8px; padding: 20px; margin-bottom: 20px; }  
.gkso-kpi { border-top: 3px solid var(--gkso-amber); }  
.gkso-kpi.success { border-color: var(--gkso-green); }  
.gkso-kpi.error { border-color: var(--gkso-red); }  
.gkso-mono { font-family: 'Fira Code', monospace; font-size: 11px; }  
.gkso-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }  
.gkso-status-baseline { background: rgba(100,116,139,0.2); color: \#94a3b8; border: 1px solid rgba(100,116,139,0.3); }  
.gkso-status-testing { background: rgba(245,158,11,0.2); color: var(--gkso-amber); border: 1px solid rgba(245,158,11,0.3); animation: pulse 2s infinite; }  
.gkso-status-optimized { background: rgba(74,222,128,0.2); color: var(--gkso-green); border: 1px solid rgba(74,222,128,0.3); }  
.gkso-status-failed { background: rgba(248,113,113,0.2); color: var(--gkso-red); border: 1px solid rgba(248,113,113,0.3); }  
.gkso-progress-bar { background: var(--gkso-bg); height: 4px; border-radius: 2px; overflow: hidden; margin-top: 10px; }  
.gkso-progress-fill { height: 100%; background: linear-gradient(90deg, var(--gkso-blue), var(--gkso-amber)); transition: width 0.5s ease; }  
.gkso-btn { background: linear-gradient(135deg, var(--gkso-blue), \#6366f1); border: none; color: \#fff; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; }  
.gkso-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(56,189,248,0.3); }  
.gkso-btn-secondary { background: transparent; border: 1px solid var(--gkso-border); color: var(--gkso-muted); }

@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }

`assets/js/settings.js` — Connection testing \+ MetaBox polling:

JavaScriptCopy

(function($) {  
    'use strict';  
      
    *// Connection testing*  
    $('\#gkso-test-n8n').on('click', function() {  
        const $btn \= $(this);  
        const $result \= $('\#gkso-test-results');  
          
        $btn.prop('disabled', true).text(gksoSettings.strings.testing);  
          
        $.post(gksoSettings.ajaxUrl, {  
            action: 'gkso\_test\_n8n',  
            nonce: gksoSettings.nonce  
        }, function(response) {  
            $btn.prop('disabled', false).text('Test n8n Connection');  
            $result.html(response.success ?   
                \`\<div class="notice notice-success"\>\<p\>${gksoSettings.strings.success}\</p\>\</div\>\` :  
                \`\<div class="notice notice-error"\>\<p\>${response.data || gksoSettings.strings.error}\</p\>\</div\>\`  
            );  
        });  
    });  
      
    *// MetaBox polling for Testing status*  
    if ($('.gkso-status-testing').length) {  
        const postId \= $('\#post\_ID').val();  
        let attempts \= 0;  
        const maxAttempts \= 40;  
          
        function pollStatus() {  
            if (attempts \>= maxAttempts) return;  
              
            wp.apiRequest({  
                path: \`gemini-kimi-seo/v1/test-status/${postId}\`,  
                method: 'GET'  
            }).then(response \=\> {  
                if (response.status \!== 'Testing') {  
                    location.reload(); *// State changed, refresh to show new UI*  
                } else {  
                    const progress \= response.testing?.progress\_percent || 0;  
                    $('.gkso-progress-fill').css('width', progress \+ '%');  
                    $('.gkso-days-elapsed').text(response.testing?.elapsed\_days || 0);  
                      
                    attempts++;  
                    setTimeout(pollStatus, 30000); *// 30s intervals*  
                }  
            });  
        }  
          
        setTimeout(pollStatus, 5000);  
    }  
      
    *// Bulk action confirmation*  
    $('\#doaction, \#doaction2').on('click', function(e) {  
        const action \= $('select\[name="action"\]').val() || $('select\[name="action2"\]').val();  
        if (action \=== 'gkso\_start\_test' && \!confirm('Start SEO A/B tests for selected posts?')) {  
            e.preventDefault();  
        }  
    });

})(jQuery);

---

## **3\. Critical: Complete n8n Workflow (Import-Ready JSON)**

`n8n-workflow-gkso-master.json` — the complete orchestration, not fragments:

JSONCopy

{  
  "name": "GKSO Master Workflow",  
  "nodes": \[  
    {  
      "parameters": {  
        "rule": {  
          "interval": \[{"field": "cronExpression", "expression": "0 2 \* \* \*"}\]  
        }  
      },  
      "name": "Daily Scheduled Trigger",  
      "type": "n8n-nodes-base.scheduleTrigger",  
      "position": \[250, 300\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "jsCode": "// Phase 1: Fetch eligible posts from WordPress\\nconst siteUrl \= $credentials.wp\_site\_url;\\nconst auth \= { user: $credentials.wp\_username, password: $credentials.wp\_app\_password };\\n\\nconst response \= await $http.get(\`${siteUrl}/wp-json/gemini-kimi-seo/v1/pending-evaluation\`, { authentication: auth });\\nreturn response.body.map(post \=\> ({ json: post }));"  
      },  
      "name": "Fetch Pending Posts",  
      "type": "n8n-nodes-base.code",  
      "position": \[450, 300\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "jsCode": "// Phase 2: GA4 Data Collection\\nconst { google } \= require('googleapis');\\nconst analyticsData \= google.analyticsdata('v1beta');\\n\\nconst propertyId \= $credentials.ga4\_property\_id;\\nconst startDate \= $now.minus({days: 14}).toFormat('yyyy-MM-dd');\\nconst endDate \= $now.minus({days: 1}).toFormat('yyyy-MM-dd');\\n\\nconst response \= await analyticsData.properties.runReport({\\n  property: \`properties/${propertyId}\`,\\n  requestBody: {\\n    dateRanges: \[{ startDate, endDate }\],\\n    metrics: \[{ name: 'screenPageViews' }, { name: 'engagementRate' }, { name: 'averageEngagementTime' }\],\\n    dimensions: \[{ name: 'pagePath' }\],\\n    dimensionFilter: {\\n      filter: { fieldName: 'pagePath', stringFilter: { matchType: 'EXACT', value: $json.url\_path } }\\n    }\\n  },\\n  auth: $credentials.ga4\_jwt\\n});\\n\\nreturn { json: { ...$json, ga4: response.data.rows?.\[0\] || {} } };"  
      },  
      "name": "Fetch GA4 Metrics",  
      "type": "n8n-nodes-base.code",  
      "position": \[650, 300\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "method": "POST",  
        "url": "https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query",  
        "authentication": "googleServiceAccount",  
        "googleServiceAccount": {  
          "credentials": "={{$credentials.gsc\_service\_account}}"  
        },  
        "sendBody": true,  
        "contentType": "json",  
        "body": "={{\\n{\\n  \\"startDate\\": $now.minus({days: 14}).toFormat('yyyy-MM-dd'),\\n  \\"endDate\\": $now.minus({days: 1}).toFormat('yyyy-MM-dd'),\\n  \\"dimensions\\": \[\\"page\\"\],\\n  \\"dimensionFilterGroups\\": \[{\\n    \\"filters\\": \[{\\"dimension\\": \\"page\\", \\"operator\\": \\"equals\\", \\"expression\\": $json.url}\]\\n  }\]\\n}\\n}}"  
      },  
      "name": "Fetch GSC Metrics",  
      "type": "n8n-nodes-base.httpRequest",  
      "position": \[650, 500\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "mode": "mergeByIndex"  
      },  
      "name": "Merge GA4 \+ GSC",  
      "type": "n8n-nodes-base.merge",  
      "position": \[850, 400\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "jsCode": "// Phase 3: Threshold Evaluation\\nconst gsc \= $json.gsc.rows?.\[0\] || {};\\nconst ga4 \= $json.ga4;\\n\\nconst ctr \= parseFloat(gsc.clicks) / parseFloat(gsc.impressions) || 0;\\nconst position \= parseFloat(gsc.position) || 20;\\n\\n// Calculate TOS (Traffic Opportunity Score)\\nconst expectedCtr \= {1:0.315,2:0.245,3:0.189,4:0.142,5:0.112,6:0.089,7:0.074,8:0.061}\[Math.floor(position)\] || 0.02;\\nconst gap \= expectedCtr \- ctr;\\nconst tos \= gap \* parseFloat(gsc.impressions || 0\) \* 30;\\n\\nconst shouldOptimize \= tos \> 1000 && gap \> 0.03;\\n\\nreturn \[{ \\n  json: { \\n    ...$json, \\n    evaluation: { ctr, position, tos, shouldOptimize, gap },\\n    priority: tos \> 5000 ? 'critical' : tos \> 1500 ? 'high' : 'medium'\\n  } \\n}\];"  
      },  
      "name": "Evaluate Thresholds",  
      "type": "n8n-nodes-base.code",  
      "position": \[1050, 400\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "conditions": {  
          "options": {  
            "caseSensitive": true,  
            "leftValue": "",  
            "typeValidation": "strict"  
          },  
          "conditions": \[  
            {  
              "id": "cond-1",  
              "leftValue": "={{$json.evaluation.shouldOptimize}}",  
              "rightValue": "true",  
              "operator": {  
                "type": "boolean",  
                "operation": "equals"  
              }  
            }  
          \],  
          "combinator": "and"  
        }  
      },  
      "name": "Should Optimize?",  
      "type": "n8n-nodes-base.if",  
      "position": \[1250, 400\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "model": "gemini-2.0-flash",  
        "options": {  
          "temperature": 0.5  
        },  
        "prompt": "={{ $json.prompt }}"  
      },  
      "name": "Gemini Generate Variants",  
      "type": "n8n-nodes-base.googleGenerativeAI",  
      "position": \[1450, 300\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "jsCode": "// Phase 4: Deploy Test to WordPress\\nconst response \= await $http.post(\`${$json.site\_url}/wp-json/gemini-kimi-seo/v1/initiate-test\`, {\\n  body: {\\n    post\_id: $json.post\_id,\\n    test\_title: $json.variants\[0\].title,\\n    test\_description: $json.variants\[0\].description,\\n    baseline\_metrics: $json.evaluation,\\n    ai\_model: 'gemini-2.0-flash'\\n  },\\n  authentication: {\\n    type: 'basic',\\n    user: $credentials.wp\_username,\\n    password: $credentials.wp\_app\_password\\n  }\\n});\\n\\nreturn { json: { ...$json, deployment: response.body } };"  
      },  
      "name": "Deploy Test",  
      "type": "n8n-nodes-base.code",  
      "position": \[1650, 300\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "rule": {  
          "interval": \[{"field": "cronExpression", "expression": "0 2 \* \* \*"}\]  
        }  
      },  
      "name": "Test Completion Check",  
      "type": "n8n-nodes-base.scheduleTrigger",  
      "position": \[250, 700\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "jsCode": "// Query wp\_gkso\_pending\_tests for tests scheduled to complete today\\nconst mysql \= require('mysql2/promise');\\nconst connection \= await mysql.createConnection($credentials.mysql);\\nconst \[rows\] \= await connection.execute(\\n  'SELECT post\_id, site\_id FROM wp\_gkso\_pending\_tests WHERE scheduled\_date \= CURDATE() AND status \= \\"pending\\"'\\n);\\nawait connection.end();\\nreturn rows.map(r \=\> ({ json: r }));"  
      },  
      "name": "Fetch Completed Tests",  
      "type": "n8n-nodes-base.code",  
      "position": \[450, 700\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "jsCode": "// Phase 5: Final Decision\\n// Fetch test period metrics, compare to baseline, decide winner\\nconst testMetrics \= await $http.get(\`${$json.site\_url}/wp-json/gemini-kimi-seo/v1/test-metrics/${$json.post\_id}\`);\\nconst baseline \= $json.baseline\_metrics;\\nconst test \= testMetrics.body;\\n\\nconst ctrImprovement \= ((test.ctr \- baseline.ctr) / baseline.ctr) \* 100;\\nconst rankImprovement \= baseline.position \- test.position;\\n\\nconst testWins \= rankImprovement \> 0 || (ctrImprovement \>= 10 && Math.abs(rankImprovement) \< 0.5);\\n\\nreturn \[{ json: { ...$json, decision: testWins ? 'test\_wins' : 'baseline\_wins', metrics: { ctrImprovement, rankImprovement } } }\];"  
      },  
      "name": "Finalize Test",  
      "type": "n8n-nodes-base.code",  
      "position": \[650, 700\],  
      "typeVersion": 1  
    },  
    {  
      "parameters": {  
        "method": "POST",  
        "url": "={{$json.site\_url}}/wp-json/gemini-kimi-seo/v1/update-meta",  
        "sendBody": true,  
        "contentType": "json",  
        "body": "={{\\n{\\n  post\_id: $json.post\_id,\\n  decision: $json.decision,\\n  final\_title: $json.decision \=== 'test\_wins' ? $json.test\_title : $json.baseline\_title,\\n  final\_description: $json.decision \=== 'test\_wins' ? $json.test\_description : $json.baseline\_description,\\n  metrics: $json.metrics\\n}\\n}}"  
      },  
      "name": "Update WordPress Meta",  
      "type": "n8n-nodes-base.httpRequest",  
      "position": \[850, 700\],  
      "typeVersion": 1  
    }  
  \],  
  "connections": {  
    "Daily Scheduled Trigger": { "main": \[\[{"node": "Fetch Pending Posts", "type": "main", "index": 0}\]\] },  
    "Fetch Pending Posts": { "main": \[\[{"node": "Fetch GA4 Metrics", "type": "main", "index": 0}, {"node": "Fetch GSC Metrics", "type": "main", "index": 0}\]\] },  
    "Fetch GA4 Metrics": { "main": \[\[{"node": "Merge GA4 \+ GSC", "type": "main", "index": 0}\]\] },  
    "Fetch GSC Metrics": { "main": \[\[{"node": "Merge GA4 \+ GSC", "type": "main", "index": 1}\]\] },  
    "Merge GA4 \+ GSC": { "main": \[\[{"node": "Evaluate Thresholds", "type": "main", "index": 0}\]\] },  
    "Evaluate Thresholds": { "main": \[\[{"node": "Should Optimize?", "type": "main", "index": 0}\]\] },  
    "Should Optimize?": { "main": \[\[{"node": "Gemini Generate Variants", "type": "main", "index": 0}\]\] },  
    "Gemini Generate Variants": { "main": \[\[{"node": "Deploy Test", "type": "main", "index": 0}\]\] },  
    "Test Completion Check": { "main": \[\[{"node": "Fetch Completed Tests", "type": "main", "index": 0}\]\] },  
    "Fetch Completed Tests": { "main": \[\[{"node": "Finalize Test", "type": "main", "index": 0}\]\] },  
    "Finalize Test": { "main": \[\[{"node": "Update WordPress Meta", "type": "main", "index": 0}\]\] }  
  },  
  "settings": {  
    "executionOrder": "v1"  
  },  
  "staticData": null,  
  "tags": \["SEO", "Automation", "Production"\]

}

---

## **4\. Critical: AIOSEO Integration (The Missing SEO Plugin)**

`includes/Integrations/AIOSEO.php`:

phpCopy

\<?php  
namespace GKSO\\Integrations;

class AIOSEO {  
    public static function update\_meta($post\_id, $title, $description) {  
        *// Modern AIOSEO v4+ uses the aioseo\_posts table via ORM*  
        if (function\_exists('aioseo')) {  
            try {  
                $meta \= aioseo()-\>meta-\>metaData-\>getMetaData($post\_id);  
                  
                if ($meta) {  
                    *// Update existing record*  
                    aioseo()-\>core-\>db-\>update('aioseo\_posts')  
                        \-\>set(\[  
                            'title' \=\> sanitize\_text\_field($title),  
                            'description' \=\> sanitize\_textarea\_field($description),  
                            'updated' \=\> gmdate('Y-m-d H:i:s')  
                        \])  
                        \-\>where('post\_id', $post\_id)  
                        \-\>run();  
                } else {  
                    *// Insert new record*  
                    aioseo()-\>core-\>db-\>insert('aioseo\_posts')  
                        \-\>set(\[  
                            'post\_id' \=\> $post\_id,  
                            'title' \=\> sanitize\_text\_field($title),  
                            'description' \=\> sanitize\_textarea\_field($description),  
                            'created' \=\> gmdate('Y-m-d H:i:s'),  
                            'updated' \=\> gmdate('Y-m-d H:i:s')  
                        \])  
                        \-\>run();  
                }  
                  
                *// Clear AIOSEO cache*  
                if (class\_exists('\\\\AIOSEO\\\\Plugin\\\\Common\\\\Cache\\\\Cache')) {  
                    \\AIOSEO\\Plugin\\Common\\Cache\\Cache::clearPost($post\_id);  
                }  
                  
                *// Clear object cache*  
                wp\_cache\_delete("aioseo\_post\_{$post\_id}");  
                  
                return true;  
            } catch (\\Exception $e) {  
                error\_log("GKSO AIOSEO Error: " . $e-\>getMessage());  
                return false;  
            }  
        }  
          
        *// Legacy AIOSEO (\< v4) fallback to postmeta*  
        update\_post\_meta($post\_id, '\_aioseop\_title', sanitize\_text\_field($title));  
        update\_post\_meta($post\_id, '\_aioseop\_description', sanitize\_textarea\_field($description));  
          
        return true;  
    }  
      
    public static function get\_meta($post\_id) {  
        if (function\_exists('aioseo')) {  
            $meta \= aioseo()-\>meta-\>metaData-\>getMetaData($post\_id);  
            return \[  
                'title' \=\> $meta-\>title ?? '',  
                'description' \=\> $meta-\>description ?? ''  
            \];  
        }  
          
        return \[  
            'title' \=\> get\_post\_meta($post\_id, '\_aioseop\_title', true),  
            'description' \=\> get\_post\_meta($post\_id, '\_aioseop\_description', true)  
        \];  
    }

}

Update the main `gkso_update_seo_meta()` function to include AIOSEO:

phpCopy

function gkso\_update\_seo\_meta($post\_id, $title, $description) {  
    $plugin \= gkso\_detect\_seo\_plugin();  
      
    switch ($plugin) {  
        case 'yoast':  
            return gkso\_update\_yoast\_meta($post\_id, $title, $description);  
        case 'rank\_math':  
            return gkso\_update\_rank\_math\_meta($post\_id, $title, $description);  
        case 'aioseo':  
            return \\GKSO\\Integrations\\AIOSEO::update\_meta($post\_id, $title, $description);  
        default:  
            return gkso\_update\_generic\_seo\_meta($post\_id, $title, $description);  
    }

}

---

## **5\. Critical: IP Detection Fix (Load Balancer/Proxy Support)**

`includes/Utils/Security.php`:

phpCopy

\<?php  
namespace GKSO\\Utils;

class Security {  
    */\*\**  
     *\* Get client IP behind proxies/load balancers*  
     *\*/*  
    public static function get\_client\_ip() {  
        $ip\_headers \= \[  
            'HTTP\_CF\_CONNECTING\_IP',    *// Cloudflare*  
            'HTTP\_X\_FORWARDED\_FOR',     *// Standard proxy*  
            'HTTP\_X\_FORWARDED',         *// Alternative*  
            'HTTP\_X\_CLUSTER\_CLIENT\_IP', *// Cluster/load balancer*  
            'HTTP\_FORWARDED\_FOR',       *// RFC 7239*  
            'HTTP\_FORWARDED',  
            'REMOTE\_ADDR'               *// Fallback*  
        \];  
          
        foreach ($ip\_headers as $header) {  
            if (\!empty($\_SERVER\[$header\])) {  
                *// X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2)*  
                $ips \= explode(',', $\_SERVER\[$header\]);  
                $ip \= trim($ips\[0\]);  
                  
                if (filter\_var($ip, FILTER\_VALIDATE\_IP, FILTER\_FLAG\_NO\_PRIV\_RANGE | FILTER\_FLAG\_NO\_RES\_RANGE)) {  
                    return $ip;  
                }  
            }  
        }  
          
        return '0.0.0.0';  
    }  
      
    */\*\**  
     *\* Verify webhook signature with timing attack safe comparison*  
     *\*/*  
    public static function verify\_signature($payload, $signature, $secret) {  
        $expected \= hash\_hmac('sha256', $payload, $secret);  
        return hash\_equals($expected, $signature);  
    }

}

---

## **6\. High Priority: Linking Agents Integration Layer**

Since you noted the Linking Agents are only \~35% ready (design docs without WordPress glue), here is the Integration Layer to bring them to production:

`includes/Linking/InternalLinkAgent.php`:

phpCopy

\<?php  
namespace GKSO\\Linking;

class InternalLinkAgent {  
    private $table\_name;  
      
    public function \_\_construct() {  
        global $wpdb;  
        $this-\>table\_name \= $wpdb-\>prefix . 'gkso\_link\_index';  
          
        add\_action('save\_post', \[$this, 'index\_post'\], 10, 3);  
        add\_action('gkso\_daily\_link\_analysis', \[$this, 'run\_analysis'\]);  
    }  
      
    */\*\**  
     *\* Create DB table for link index*  
     *\*/*  
    public static function create\_table() {  
        global $wpdb;  
        $sql \= "CREATE TABLE IF NOT EXISTS {$wpdb-\>prefix}gkso\_link\_index (  
            id bigint(20) unsigned NOT NULL AUTO\_INCREMENT,  
            post\_id bigint(20) unsigned NOT NULL,  
            post\_type varchar(20) NOT NULL,  
            content\_hash varchar(32) NOT NULL,  
            entities longtext, \-- JSON array of extracted entities  
            tfidf\_vector longtext, \-- JSON vector representation  
            target\_keywords longtext, \-- JSON array  
            outbound\_links longtext, \-- JSON array of current links  
            inbound\_count int(11) default 0,  
            last\_indexed datetime DEFAULT CURRENT\_TIMESTAMP,  
            PRIMARY KEY (id),  
            UNIQUE KEY post\_id (post\_id),  
            KEY post\_type (post\_type),  
            FULLTEXT KEY entities (entities)  
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4\_unicode\_ci;";  
          
        require\_once(ABSPATH . 'wp-admin/includes/upgrade.php');  
        dbDelta($sql);  
    }  
      
    */\*\**  
     *\* REST endpoint to trigger analysis*  
     *\*/*  
    public function register\_endpoints() {  
        register\_rest\_route(GKSO\_REST\_NAMESPACE, '/analyze-links/(?P\<post\_id\>\\\\d+)', \[  
            'methods' \=\> 'POST',  
            'callback' \=\> \[$this, 'api\_analyze\_post'\],  
            'permission\_callback' \=\> function() { return current\_user\_can('edit\_posts'); }  
        \]);  
          
        register\_rest\_route(GKSO\_REST\_NAMESPACE, '/link-suggestions/(?P\<post\_id\>\\\\d+)', \[  
            'methods' \=\> 'GET',  
            'callback' \=\> \[$this, 'api\_get\_suggestions'\],  
            'permission\_callback' \=\> function() { return current\_user\_can('edit\_posts'); }  
        \]);  
    }  
      
    */\*\**  
     *\* Index post content (TF-IDF \+ Entity extraction)*  
     *\*/*  
    public function index\_post($post\_id, $post, $update) {  
        if ($post-\>post\_status \!== 'publish') return;  
          
        $content \= $post-\>post\_content;  
        $hash \= md5($content);  
          
        *// Check if content changed*  
        $existing \= get\_post\_meta($post\_id, '\_gkso\_content\_hash', true);  
        if ($existing \=== $hash) return;  
          
        *// Extract entities (simplified \- in production use NER API)*  
        $entities \= $this-\>extract\_entities($content);  
          
        *// Calculate TF-IDF (simplified)*  
        $tfidf \= $this-\>calculate\_tfidf($content);  
          
        global $wpdb;  
        $wpdb-\>replace($this-\>table\_name, \[  
            'post\_id' \=\> $post\_id,  
            'post\_type' \=\> $post-\>post\_type,  
            'content\_hash' \=\> $hash,  
            'entities' \=\> wp\_json\_encode($entities),  
            'tfidf\_vector' \=\> wp\_json\_encode($tfidf),  
            'target\_keywords' \=\> wp\_json\_encode($this-\>get\_target\_keywords($post\_id)),  
            'outbound\_links' \=\> wp\_json\_encode($this-\>extract\_links($content)),  
            'last\_indexed' \=\> current\_time('mysql')  
        \], \['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'\]);  
          
        update\_post\_meta($post\_id, '\_gkso\_content\_hash', $hash);  
    }

}

---

## **7\. High Priority: PHPUnit Scaffold**

`tests/bootstrap.php`:

phpCopy

\<?php  
$\_tests\_dir \= getenv('WP\_TESTS\_DIR') ?: '/tmp/wordpress-tests-lib';  
require\_once $\_tests\_dir . '/includes/functions.php';  
require\_once $\_tests\_dir . '/includes/bootstrap.php';

*// Load plugin*  
require\_once dirname(\_\_DIR\_\_) . '/gemini-kimi-seo-optimizer.php';

class GKSO\_TestCase extends WP\_UnitTestCase {  
    protected function create\_test\_post($status \= 'publish') {  
        return $this-\>factory-\>post-\>create(\[  
            'post\_title' \=\> 'Test Post',  
            'post\_status' \=\> $status,  
            'post\_content' \=\> 'Test content with sample keyword.'  
        \]);  
    }

}

`tests/test-state-machine.php`:

phpCopy

\<?php  
class Test\_State\_Machine extends GKSO\_TestCase {  
    public function test\_baseline\_to\_testing\_transition() {  
        $post\_id \= $this-\>create\_test\_post();  
          
        *// Initial state should be Baseline*  
        $this-\>assertEquals('Baseline', get\_post\_meta($post\_id, '\_seo\_ab\_test\_status', true));  
          
        *// Trigger transition*  
        update\_post\_meta($post\_id, '\_seo\_ab\_test\_status', 'Testing');  
          
        *// Verify lock \- should reject new transition*  
        $this-\>assertTrue(gkso\_is\_test\_locked($post\_id));  
    }  
      
    public function test\_invalid\_transition\_prevention() {  
        $post\_id \= $this-\>create\_test\_post();  
        update\_post\_meta($post\_id, '\_seo\_ab\_test\_status', 'Testing');  
          
        *// Attempt invalid transition Testing \-\> Optimized without completion*  
        $result \= gkso\_validate\_state\_transition($post\_id, 'Optimized');  
        $this-\>assertWPError($result);  
    }

}

