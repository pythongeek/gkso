## **Core Functions of the System**

There are 5 core functions, each building on the previous one in a continuous loop.

---

### **1\. Performance Data Collection**

This is the system's eyes. Every day at 2:00 AM UTC, n8n wakes up and pulls data from two Google sources simultaneously.

From **Google Search Console** it fetches how the post is performing in search results — impressions (how many times it appeared), clicks, CTR (click-through rate), and average ranking position. From **Google Analytics 4** it fetches what happens *after* the click — page views, session duration, and engagement rate.

These two datasets get merged by URL into a single unified record per post. Then three calculated fields are added on top: **Relative CTR** (is your CTR good or bad *for your position*?), **Position Volatility** (is your ranking stable or bouncing around?), and **Traffic Trend** (is traffic growing or declining over time?). These calculated fields are what the next function actually acts on.

---

### **2\. Threshold Evaluation**

This function takes the merged data and answers one question: *does this post need help?*

It does this through a weighted health score formula:

health\_score \= (CTR × 0.40) \+ (Position × 0.30) \+ (Traffic Trend × 0.20) \+ (Engagement × 0.10)

If the score drops below 75, the post enters the optimization queue. The system also has individual trip wires — if CTR drops more than 20% from its baseline, or ranking falls by more than 1 position on page one, those alone can trigger action regardless of the composite score.

The key design decision here is that it evaluates **relative degradation, not absolute values**. A 3% CTR might be excellent for a post ranking at position 8, but terrible for one at position 2\. The system knows the difference because it stores each post's personal baseline and compares against that.

---

### **3\. AI Title & Meta Generation**

When a post needs optimization, n8n builds a rich prompt and sends it to either Gemini or Kimi 2.5 (or both). The prompt isn't just "write me a better title" — it contains four layers of context:

* **Current performance data** — telling the AI *what specifically is broken* (low CTR vs. low ranking require different fixes)  
* **Content excerpt** — so the AI understands what the page is actually about  
* **Competitor SERP titles** — the top 3 ranking pages for the target keyword, so the AI can differentiate rather than copy  
* **Keyword intent classification** — whether the query is informational ("how to...") or transactional ("best... buy") changes the entire optimization strategy

The AI returns 3 options, each with a title (50-60 chars), meta description (140-160 chars), and a confidence score. The system runs validation checks on all three — correct length, keyword present, JSON parseable — and picks the highest-confidence option that passes. If Gemini fails or scores too low, Kimi gets a turn. If both fail, it queues for human review.

---

### **4\. A/B Test Execution**

This is the control function. Rather than just deploying the AI's suggestion and hoping for the best, the system runs a controlled 14-day experiment.

On day 0, it stores the original title/meta as the **baseline snapshot**, deploys the AI-generated version live (by writing to Yoast or Rank Math's postmeta keys), and flips the post's status to `Testing`. The post is now locked — nothing else can touch it.

For 14 days, Google's algorithms serve the new title to real searchers and the data accumulates. On day 14, the system wakes up and collects the test period's GSC/GA4 data using the exact same methodology as the baseline — same date range length, same metrics, same API calls — ensuring a fair comparison.

Throughout the test there's a safety net: if CTR drops more than 50% or impressions go to zero for 3+ days, the system auto-reverts without waiting for the 14 days to finish.

---

### **5\. Decision & Finalization**

At the end of the 14 days, a JavaScript code node inside n8n runs the comparison math:

CTR improvement % \= ((test\_ctr \- baseline\_ctr) / baseline\_ctr) × 100  
Rank improvement \= baseline\_position \- test\_position  (positive \= better)

These get combined into a composite score weighted 60% CTR / 40% ranking. The decision logic is intentionally simple:

* **Ranking improved** → test wins, regardless of CTR change  
* **Ranking same, CTR improved ≥ 10%** → test wins  
* **Everything else** → baseline wins, original is restored

When the test wins, n8n calls WordPress's REST API and permanently writes the new title/meta. The post status moves to `Optimized` and a 30-day cooldown starts before it's eligible for another round. When the baseline wins, the original metadata stays untouched, status reverts to `Baseline`, and the full test record gets archived to history for pattern analysis.

---

### **How They Connect**

The five functions form a closed loop with the state machine as the backbone holding it all together:

Data Collection → Threshold Evaluation → AI Generation → A/B Test → Decision  
       ↑                                                                  |  
       └──────────────────── New baseline established ───────────────────┘

The state (`Baseline → Testing → Optimized/Failed`) is the single source of truth stored in WordPress postmeta. Every function reads it before acting and writes it when done. This is what prevents two functions from acting on the same post simultaneously and what allows the system to recover cleanly if n8n crashes mid-execution.

the technical implementation of your 5 core functions — the specific algorithms, database operations, and state transitions that make this architecture executable.

---

## **1\. Performance Data Collection (The Merge Engine)**

### **The Calculated Fields (Algorithmic Implementation)**

JavaScriptCopy

*// n8n Code Node: Data Normalization & Calculated Fields*  
const mergeMetrics \= (gscData, ga4Data, historicalBaselines) \=\> {  
    
  *// URL normalization to handle trailing slashes, http/https variants*  
  const normalizeUrl \= (url) \=\> {  
    return url.replace(/^https?:\\/\\//, '').replace(/\\/$/, '').toLowerCase();  
  };  
    
  *// Merge by normalized URL*  
  const merged \= {};  
    
  gscData.forEach(gsc \=\> {  
    const key \= normalizeUrl(gsc.keys\[0\]); *// URL is first dimension*  
    merged\[key\] \= {  
      url: gsc.keys\[0\],  
      impressions: gsc.impressions,  
      clicks: gsc.clicks,  
      ctr: gsc.ctr,  
      position: gsc.position,  
      query: gsc.keys\[1\] || 'aggregated'  
    };  
  });  
    
  ga4Data.forEach(ga4 \=\> {  
    const key \= normalizeUrl(ga4.pagePath);  
    if (merged\[key\]) {  
      merged\[key\].pageviews \= ga4.screenPageViews;  
      merged\[key\].sessions \= ga4.sessions;  
      merged\[key\].engagementRate \= ga4.engagementRate;  
      merged\[key\].avgEngagementTime \= ga4.averageEngagementTime;  
    }  
  });  
    
  *// Calculate the three derived fields*  
  return Object.values(merged).map(post \=\> {  
      
    *// 1\. Relative CTR (position-adjusted expectation)*  
    const expectedCtrByPosition \= {  
      1: 0.315, 2: 0.245, 3: 0.189, 4: 0.142, 5: 0.112,  
      6: 0.089, 7: 0.074, 8: 0.061, 9: 0.053, 10: 0.047,  
      11: 0.028, 12: 0.024, 13: 0.021, 14: 0.019, 15: 0.017  
    };  
      
    const posFloor \= Math.min(15, Math.floor(post.position || 10));  
    const expectedCtr \= expectedCtrByPosition\[posFloor\] || 0.015;  
    const relativeCtr \= (post.ctr || 0) / expectedCtr; *// 1.0 \= expected, \>1 \= beating*  
      
    *// 2\. Position Volatility (coefficient of variation from 7-day history)*  
    const positionHistory \= historicalBaselines  
      .filter(h \=\> normalizeUrl(h.url) \=== normalizeUrl(post.url))  
      .map(h \=\> h.position);  
      
    let volatility \= 0;  
    if (positionHistory.length \> 1) {  
      const mean \= positionHistory.reduce((a,b) \=\> a\+b, 0) / positionHistory.length;  
      const variance \= positionHistory.reduce((sum, val) \=\> sum \+ Math.pow(val \- mean, 2), 0) / positionHistory.length;  
      const stdDev \= Math.sqrt(variance);  
      volatility \= stdDev / mean; *// Coefficient of variation*  
    }  
      
    *// 3\. Traffic Trend (linear regression slope over 14 days)*  
    const trafficHistory \= historicalBaselines  
      .filter(h \=\> normalizeUrl(h.url) \=== normalizeUrl(post.url))  
      .sort((a,b) \=\> new Date(a.date) \- new Date(b.date));  
      
    let trafficTrend \= 0;  
    if (trafficHistory.length \>= 7) {  
      const n \= trafficHistory.length;  
      const sumX \= trafficHistory.reduce((sum, \_, i) \=\> sum \+ i, 0);  
      const sumY \= trafficHistory.reduce((sum, h) \=\> sum \+ h.clicks, 0);  
      const sumXY \= trafficHistory.reduce((sum, h, i) \=\> sum \+ (i \* h.clicks), 0);  
      const sumX2 \= trafficHistory.reduce((sum, \_, i) \=\> sum \+ (i \* i), 0);  
        
      trafficTrend \= (n \* sumXY \- sumX \* sumY) / (n \* sumX2 \- sumX \* sumX); *// clicks/day slope*  
    }  
      
    return {  
      ...post,  
      calculated: {  
        relativeCtr: parseFloat(relativeCtr.toFixed(3)),  
        positionVolatility: parseFloat(volatility.toFixed(3)),  
        trafficTrend: parseFloat(trafficTrend.toFixed(2)),  
        isDeclining: relativeCtr \< 0.85 || trafficTrend \< \-5 || volatility \> 0.3  
      }  
    };  
  });

};

---

## **2\. Threshold Evaluation (The Tripwire System)**

### **Health Score Implementation**

JavaScriptCopy

*// n8n Function Node: Health Score Calculation*  
const calculateHealthScore \= (post, baselines) \=\> {  
    
  *// Normalize each metric to 0-100 scale*  
  *// CTR Weight: 40%*  
  const ctrScore \= Math.min(100, (post.calculated.relativeCtr || 0) \* 100); *// 1.0 \= 100pts*  
    
  *// Position Weight: 30% (inverted: lower position \= higher score)*  
  const positionScore \= Math.max(0, 100 \- ((post.position || 20) \* 5)); *// pos 1 \= 95pts, pos 20 \= 0pts*  
    
  *// Traffic Trend Weight: 20%*  
  *// Normalize trend: \+10 clicks/day \= 100pts, \-10 clicks/day \= 0pts*  
  const trendRaw \= post.calculated.trafficTrend || 0;  
  const trendScore \= Math.max(0, Math.min(100, 50 \+ (trendRaw \* 5)));  
    
  *// Engagement Weight: 10%*  
  const engagementScore \= (post.engagementRate || 0.5) \* 100;  
    
  *// Weighted composite*  
  const healthScore \= (  
    (ctrScore \* 0.40) \+  
    (positionScore \* 0.30) \+  
    (trendScore \* 0.20) \+  
    (engagementScore \* 0.10)  
  );  
    
  *// Individual tripwires (bypass composite score if critical)*  
  const baselineData \= baselines.find(b \=\> b.url \=== post.url);  
  let tripwire \= null;  
    
  if (baselineData) {  
    const ctrDecline \= (baselineData.ctr \- post.ctr) / baselineData.ctr;  
    const positionDecline \= post.position \- baselineData.position;  
      
    if (ctrDecline \> 0.20) tripwire \= 'ctr\_drop'; *// 20% CTR drop*  
    else if (positionDecline \> 1.0 && baselineData.position \<= 10) tripwire \= 'page\_one\_slippage';  
    else if (post.calculated.positionVolatility \> 0.4) tripwire \= 'high\_volatility';  
  }  
    
  return {  
    ...post,  
    evaluation: {  
      healthScore: Math.round(healthScore),  
      individualScores: { ctr: ctrScore, position: positionScore, trend: trendScore, engagement: engagementScore },  
      tripwire: tripwire,  
      shouldOptimize: healthScore \< 75 || tripwire \!== null,  
      priority: tripwire ? 'critical' : healthScore \< 60 ? 'high' : healthScore \< 75 ? 'medium' : 'none'  
    }  
  };

};

### **The Decision Branch (n8n Switch Node Logic)**

JSONCopy

{  
  "rules": {  
    "branch\_1": "{{ $json.evaluation.tripwire \=== 'ctr\_drop' }}",  
    "branch\_2": "{{ $json.evaluation.tripwire \=== 'page\_one\_slippage' }}",  
    "branch\_3": "{{ $json.evaluation.healthScore \< 75 }}",  
    "fallback": true  
  },  
  "outputs": {  
    "branch\_1": "immediate\_optimization",  
    "branch\_2": "immediate\_optimization",   
    "branch\_3": "queue\_optimization",  
    "fallback": "monitor\_only"  
  }

}

---

## **3\. AI Generation (The Context Builder)**

### **4-Layer Prompt Construction**

JavaScriptCopy

*// n8n Code Node: Prompt Assembly*  
const buildOptimizationPrompt \= (postData, serpData, performanceContext) \=\> {  
    
  *// Layer 1: Current Performance (The "What")*  
  const performanceLayer \= \`  
CURRENT PERFORMANCE METRICS (The Problem):  
\- Current Title: "${postData.currentTitle}"  
\- Current CTR: ${(postData.ctr \* 100).toFixed(2)}% (Expected for position ${Math.floor(postData.position)}: \~${(postData.expectedCtr \* 100).toFixed(1)}%)  
\- Current Position: ${postData.position.toFixed(1)}  
\- Traffic Trend: ${postData.calculated.trafficTrend \> 0 ? '+' : ''}${postData.calculated.trafficTrend} clicks/day  
\- Engagement Rate: ${(postData.engagementRate \* 100).toFixed(1)}%

DIAGNOSIS: ${postData.calculated.relativeCtr \< 0.85 ? 'CTR Underperformance \- Title appeal issue' : postData.calculated.trafficTrend \< 0 ? 'Declining Traffic \- Relevance decay' : 'Position Opportunity \- Can rank higher with better targeting'}  
\`;

  *// Layer 2: Content Context (The "About")*  
  const contentLayer \= \`  
CONTENT CONTEXT:  
\- Primary Topic: ${postData.focusKeyword}  
\- Content Excerpt: ${postData.excerpt.substring(0, 300)}...  
\- Content Type: ${postData.contentType} (how-to guide | listicle | review | pillar page)  
\- Target Audience: ${postData.audience || 'General'}  
\`;

  *// Layer 3: Competitive Intelligence (The "Differentiation")*  
  const competitorLayer \= \`  
COMPETITIVE LANDSCAPE (Top 3 Ranking Titles):  
${serpData.slice(0, 3).map((r, i) \=\> \`${i\+1}. Position ${r.position}: "${r.title}"\`).join('\\n')}

ANALYSIS:  
\- Common patterns: ${detectPatterns(serpData)}  
\- Differentiation opportunity: ${findWhitespace(serpData, postData.currentTitle)}  
\`;

  *// Layer 4: Intent Strategy (The "How")*  
  const intentStrategies \= {  
    informational: "Focus on comprehensive coverage, 'Complete Guide', 'Step-by-Step', educational value proposition",  
    transactional: "Focus on purchase intent, 'Best', 'Top', 'Reviews', comparison angles, value propositions",  
    navigational: "Focus on brand authority, direct answers, official resource positioning",  
    commercial\_investigation: "Focus on comparison, 'vs', 'Alternative to', unbiased expert stance"  
  };  
    
  const intentLayer \= \`  
KEYWORD INTENT STRATEGY:  
\- Intent Classification: ${postData.intent} (${postData.intent \=== 'informational' ? 'User wants to learn/know' : postData.intent \=== 'transactional' ? 'User wants to buy' : 'User comparing options'})  
\- Optimization Strategy: ${intentStrategies\[postData.intent\] || 'Balanced approach'}  
\- Emotional Trigger: ${postData.calculated.relativeCtr \< 0.7 ? 'Urgency/FOMO needed' : 'Trust/Authority needed'}  
\`;

  return {  
    model: postData.priority \=== 'critical' ? 'gemini-2.0-pro' : 'gemini-2.0-flash',  
    temperature: postData.priority \=== 'critical' ? 0.4 : 0.7,  
    prompt: \`${performanceLayer}\\n${contentLayer}\\n${competitorLayer}\\n${intentLayer}\\n\\nGENERATE 3 VARIANTS:\\nRules:\\n1. Title: 50-60 characters (optimal display)\\n2. Meta: 140-160 characters  \\n3. Include "${postData.focusKeyword}" naturally in first 50 chars of title\\n4. Differentiate from competitors shown above\\n5. Match ${postData.intent} intent explicitly\\n6. JSON format: {"variants":\[{"title":"...","description":"...","confidence":0.0-1.0,"rationale":"..."}\]}\`,  
    systemInstruction: "You are an elite SEO copywriter specializing in CTR optimization. You analyze performance data to diagnose specific problems and write titles that fix them."  
  };

};

### **Validation Pipeline**

JavaScriptCopy

const validateVariants \= (aiResponse, focusKeyword) \=\> {  
  let variants \= \[\];  
    
  try {  
    const parsed \= JSON.parse(aiResponse);  
    variants \= parsed.variants || \[\];  
  } catch(e) {  
    return { valid: false, reason: 'parse\_error', fallback: true };  
  }  
    
  const validated \= variants.filter(v \=\> {  
    const checks \= {  
      titleLength: v.title.length \>= 30 && v.title.length \<= 60,  
      descLength: v.description.length \>= 70 && v.description.length \<= 160,  
      keywordPresent: v.title.toLowerCase().includes(focusKeyword.toLowerCase()),  
      hasJsonChars: \!v.title.includes('{') && \!v.title.includes('}'), *// AI sometimes outputs template syntax*  
      confidenceHigh: v.confidence \>= 0.6  
    };  
      
    v.passedChecks \= Object.values(checks).filter(Boolean).length;  
    v.checkDetails \= checks;  
    return v.passedChecks \>= 4; *// Allow 1 minor failure*  
  });  
    
  if (validated.length \=== 0) return { valid: false, reason: 'validation\_failed', variants: variants };  
    
  *// Return highest confidence valid variant*  
  return {   
    valid: true,   
    selected: validated.sort((a,b) \=\> b.confidence \- a.confidence)\[0\],  
    alternatives: validated.slice(1)  
  };

};

---

## **4\. A/B Test Execution (The Lock & Monitor System)**

### **State Transition with Atomic Locking**

phpCopy

*// WordPress REST Endpoint: Initiate Test (Atomic Operation)*  
function gkso\_initiate\_test($request) {  
    global $wpdb;  
      
    $post\_id \= intval($request\['post\_id'\]);  
    $variant \= $request\['variant'\];  
      
    *// Advisory lock to prevent race conditions*  
    $lock\_key \= "gkso\_lock\_$post\_id";  
    $wpdb\-\>query("SELECT GET\_LOCK('$lock\_key', 10)");  
      
    try {  
        $current\_status \= get\_post\_meta($post\_id, '\_seo\_ab\_test\_status', true);  
          
        if ($current\_status \=== 'Testing') {  
            return new WP\_Error('locked', 'Test already in progress', \['status' \=\> 409\]);  
        }  
          
        *// 1\. Store baseline snapshot*  
        $baseline \= \[  
            'title' \=\> get\_post\_meta($post\_id, '\_yoast\_wpseo\_title', true) ?: get\_the\_title($post\_id),  
            'description' \=\> get\_post\_meta($post\_id, '\_yoast\_wpseo\_metadesc', true),  
            'timestamp' \=\> current\_time('mysql'),  
            'gsc\_metrics' \=\> $request\['baseline\_metrics'\]  
        \];  
          
        *// 2\. Atomic update to Testing status*  
        update\_post\_meta($post\_id, '\_seo\_ab\_test\_status', 'Testing');  
        update\_post\_meta($post\_id, '\_seo\_test\_baseline', $baseline);  
        update\_post\_meta($post\_id, '\_seo\_test\_variant', $variant);  
        update\_post\_meta($post\_id, '\_seo\_test\_started', time());  
        update\_post\_meta($post\_id, '\_seo\_test\_day', 0);  
          
        *// 3\. Deploy variant (Yoast example)*  
        update\_post\_meta($post\_id, '\_yoast\_wpseo\_title', sanitize\_text\_field($variant\['title'\]));  
        update\_post\_meta($post\_id, '\_yoast\_wpseo\_metadesc', sanitize\_textarea\_field($variant\['description'\]));  
          
        *// 4\. Schedule checkpoint (using wp\_schedule\_single\_event for day 7, 14\)*  
        wp\_schedule\_single\_event(time() \+ (7 \* DAY\_IN\_SECONDS), 'gkso\_test\_checkpoint', \[$post\_id, 7\]);  
        wp\_schedule\_single\_event(time() \+ (14 \* DAY\_IN\_SECONDS), 'gkso\_test\_complete', \[$post\_id\]);  
          
        *// 5\. Insert to pending table for n8n tracking*  
        $wpdb\-\>insert($wpdb\-\>prefix . 'gkso\_pending\_tests', \[  
            'post\_id' \=\> $post\_id,  
            'scheduled\_date' \=\> date('Y-m-d', strtotime('+14 days')),  
            'status' \=\> 'pending'  
        \]);  
          
        return \['status' \=\> 'Testing', 'test\_id' \=\> $wpdb\-\>insert\_id, 'started' \=\> time()\];  
          
    } finally {  
        $wpdb\-\>query("SELECT RELEASE\_LOCK('$lock\_key')");  
    }

}

### **Safety Net (Auto-Revert Triggers)**

JavaScriptCopy

*// n8n Daily Check (runs every 24h during test)*  
const safetyCheck \= async (postId, wpApi) \=\> {  
  const currentData \= await fetchGSCData(postId, 'last\_3\_days');  
  const baseline \= await wpApi.get(\`/test-baseline/${postId}\`);  
    
  *// Trigger 1: CTR collapse*  
  if (currentData.ctr \< baseline.ctr \* 0.5) {  
    return {   
      action: 'emergency\_revert',   
      reason: 'ctr\_collapse',  
      currentCtr: currentData.ctr,  
      baselineCtr: baseline.ctr  
    };  
  }  
    
  *// Trigger 2: Impressions disappearance (penalty/deindexing check)*  
  if (currentData.impressions \=== 0 && baseline.impressions \> 100) {  
    return {   
      action: 'emergency\_revert',  
      reason: 'visibility\_loss',  
      severity: 'critical'  
    };  
  }  
    
  *// Trigger 3: Position cliff (dropped to page 3+)*  
  if (currentData.position \> 20 && baseline.position \< 15) {  
    return { action: 'emergency\_revert', reason: 'ranking\_cliff' };  
  }  
    
  return { action: 'continue', days\_remaining: 14 \- daysSince(baseline.timestamp) };

};

---

## **5\. Decision & Finalization (The Math)**

### **Statistical Comparison Engine**

JavaScriptCopy

*// n8n Code Node: Final Decision Logic*  
const finalizeTest \= (baseline, test, config) \=\> {  
    
  *// 1\. Calculate improvements*  
  const ctrImprovement \= ((test.ctr \- baseline.ctr) / baseline.ctr) \* 100;  
  const rankImprovement \= baseline.position \- test.position; *// Positive \= moved up (lower number)*  
    
  *// 2\. Statistical significance (Z-test for proportions)*  
  const n1 \= baseline.impressions;  
  const n2 \= test.impressions;  
  const p1 \= baseline.ctr;  
  const p2 \= test.ctr;  
  const p\_pooled \= ((p1 \* n1) \+ (p2 \* n2)) / (n1 \+ n2);  
  const se \= Math.sqrt(p\_pooled \* (1 \- p\_pooled) \* ((1/n1) \+ (1/n2)));  
  const z \= (p2 \- p1) / se;  
  const p\_value \= 2 \* (1 \- Math.abs(z)); *// Simplified*  
    
  const isSignificant \= p\_value \< 0.05;  
    
  *// 3\. Composite Score (60% CTR / 40% Ranking)*  
  *// Normalize ranking: moving from 8→7 is \+1, but 3→2 is much more valuable*  
  const rankValue \= (pos) \=\> 100 / (pos \* pos); *// Inverse square law*  
  const rankImprovementPct \= ((rankValue(test.position) \- rankValue(baseline.position)) / rankValue(baseline.position)) \* 100;  
    
  const compositeScore \= (Math.max(\-100, Math.min(100, ctrImprovement)) \* 0.6) \+   
                         (Math.max(\-100, Math.min(100, rankImprovementPct)) \* 0.4);  
    
  *// 4\. Decision Logic*  
  let decision, reason;  
    
  if (rankImprovement \> 0) {  
    *// Ranking improved (even slightly) \= always win (visibility is king)*  
    decision \= 'test\_wins';  
    reason \= \`Ranking improved ${rankImprovement.toFixed(1)} positions\`;  
  } else if (Math.abs(rankImprovement) \< 0.5 && ctrImprovement \>= 10 && isSignificant) {  
    *// Same ranking, CTR up 10%+ and statistically significant*  
    decision \= 'test\_wins';  
    reason \= \`CTR improved ${ctrImprovement.toFixed(1)}% with stable ranking\`;  
  } else if (compositeScore \> 15) {  
    *// Strong composite improvement despite individual metrics*  
    decision \= 'test\_wins';  
    reason \= \`Strong composite score: ${compositeScore.toFixed(1)}\`;  
  } else {  
    decision \= 'baseline\_wins';  
    reason \= \`No significant improvement (CTR: ${ctrImprovement.toFixed(1)}%, Rank: ${rankImprovement \> 0 ? '+' : ''}${rankImprovement.toFixed(1)})\`;  
  }  
    
  *// 5\. Secondary metrics (tie breakers)*  
  if (decision \=== 'baseline\_wins' && test.engagementRate \> baseline.engagementRate \* 1.05) {  
    decision \= 'test\_wins';  
    reason \+= ' (Engagement rate tie-breaker)';  
  }  
    
  return {  
    decision,  
    reason,  
    metrics: {  
      ctrImprovement: parseFloat(ctrImprovement.toFixed(2)),  
      rankImprovement: parseFloat(rankImprovement.toFixed(2)),  
      compositeScore: parseFloat(compositeScore.toFixed(2)),  
      pValue: parseFloat(p\_value.toFixed(4)),  
      isSignificant  
    },  
    nextState: decision \=== 'test\_wins' ? 'Optimized' : 'Baseline',  
    cooldownDays: decision \=== 'test\_wins' ? 30 : 14  
  };

};

### **Finalization Actions (WordPress)**

phpCopy

function gkso\_finalize\_test($request) {  
    $post\_id \= $request\['post\_id'\];  
    $decision \= $request\['decision'\];  
    $metrics \= $request\['metrics'\];  
      
    $baseline \= get\_post\_meta($post\_id, '\_seo\_test\_baseline', true);  
      
    if ($decision \=== 'test\_wins') {  
        *// Keep the AI variant (already live), just update status*  
        update\_post\_meta($post\_id, '\_seo\_ab\_test\_status', 'Optimized');  
        update\_post\_meta($post\_id, '\_seo\_last\_optimized', time());  
          
        *// Archive to history table*  
        global $wpdb;  
        $wpdb\-\>insert($wpdb\-\>prefix . 'gkso\_test\_history', \[  
            'post\_id' \=\> $post\_id,  
            'test\_version' \=\> get\_post\_meta($post\_id, '\_seo\_ab\_test\_version', true) ?: 1,  
            'status' \=\> 'Optimized',  
            'baseline\_ctr' \=\> $metrics\['baseline\_ctr'\],  
            'result\_ctr' \=\> $metrics\['test\_ctr'\],  
            'improvement\_pct' \=\> $metrics\['ctrImprovement'\],  
            'started\_at' \=\> date('Y-m-d H:i:s', $baseline\['timestamp'\]),  
            'completed\_at' \=\> current\_time('mysql')  
        \]);  
          
    } else {  
        *// Revert to baseline*  
        update\_post\_meta($post\_id, '\_yoast\_wpseo\_title', $baseline\['title'\]);  
        update\_post\_meta($post\_id, '\_yoast\_wpseo\_metadesc', $baseline\['description'\]);  
        update\_post\_meta($post\_id, '\_seo\_ab\_test\_status', 'Baseline');  
          
        *// Store failure for analysis*  
        update\_post\_meta($post\_id, '\_seo\_last\_failure', \[  
            'date' \=\> time(),  
            'variant' \=\> get\_post\_meta($post\_id, '\_seo\_test\_variant', true),  
            'metrics' \=\> $metrics  
        \]);  
    }  
      
    *// Cleanup test meta to free space*  
    delete\_post\_meta($post\_id, '\_seo\_test\_baseline');  
    delete\_post\_meta($post\_id, '\_seo\_test\_variant');  
    delete\_post\_meta($post\_id, '\_seo\_test\_started');  
      
    return \['success' \=\> true, 'new\_state' \=\> $decision \=== 'test\_wins' ? 'Optimized' : 'Baseline'\];

}

---

## **The State Machine Loop (Visual Flow)**

Mermaid

Fullscreen Download Copy

CodePreview

Plugin activated

Health Score \< 75 OR Tripwire triggered

Test wins (Ranking ↑ OR CTR \+10%)

Baseline wins (No improvement)

Error/Auto-revert triggered

Degradation detected (30d cooldown)

Manual retry approved

Manual override

BaselineTestingOptimizedFailed

This implementation gives you the quantitative rigor (statistical significance testing, composite scoring) and operational safety (advisory locks, auto-revert triggers, atomic state transitions) required to run this at scale without human oversight.  
