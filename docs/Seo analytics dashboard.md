### **Design Direction**

**Mission Control aesthetic** — dark navy backgrounds, amber/green/blue accents, monospace numbers. This serves three audiences at once: the executive sees KPI cards at the top of every tab, the SEO manager reads the charts and tables, the developer gets raw metric values in monospace throughout.

---

### **The 5 Tabs & What They Show**

**Site Overview** — The broadest view. Four KPI cards (total tests, win rate, avg CTR lift, avg rank lift) sit above an area chart comparing baseline vs optimized CTR across 12 weeks. A second chart shows ranking position trajectory (inverted axis — lower is better, so improvement reads as a rising line). A bar chart shows the distribution of improvement magnitudes so you can see if most wins cluster around 10-20% or if you're getting big outliers.

**Active Tests** — Three summary cards at the top (active count, completing soon, early leaders), then a live progress table showing every running test with its CTR delta, rank delta, model used, and a progress bar. Below that a horizontal bar chart shows days elapsed vs remaining for each test at a glance.

**Win/Loss History** — Monthly grouped bars (wins in green, losses in muted red) give you trend at a glance. A donut chart shows the 71% overall win rate. A detailed results table shows the last 6 completed tests with all metrics and a win/loss badge — hoverable rows.

**AI Models** — A radar chart comparing Gemini, Kimi, and Ensemble across 5 dimensions (win rate, CTR lift, rank lift, speed, consistency). A donut shows test volume share. A grouped bar chart breaks win rate down by content category — this is where you'd discover "Kimi is better for long-form, Gemini is faster for news posts."

**Post Detail** — A post selector dropdown at the top switches context. Below it: four per-post KPIs, a dual-axis timeline chart (CTR on left, position on right, annotated dots for test start/end), and a side panel showing the exact baseline vs AI-generated metadata diff, plus the full version history for that post.

---

### **What to Wire Up**

All data is mocked right now. The real integration points are straightforward — each tab maps to existing REST endpoints already defined in the plugin spec: `/test-status/{post_id}` for the post detail tab, the history archive from `_seo_test_history` postmeta for the results table, and a new `/site-stats` summary endpoint you'd add to aggregate the KPI cards site-wide.

import { useState } from "react";  
import {  
  LineChart, Line, AreaChart, Area, BarChart, Bar,  
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,  
  RadarChart, Radar, PolarGrid, PolarAngleAxis, PolarRadiusAxis,  
  ScatterChart, Scatter, Cell, PieChart, Pie, Legend  
} from "recharts";

// ─── MOCK DATA ────────────────────────────────────────────────  
const SITE\_STATS \= {  
  totalTests: 148,  
  winRate: 71,  
  avgCTRLift: 23.4,  
  avgRankLift: 1.8,  
  activeTests: 12,  
  totalPosts: 384,  
  optimizedPosts: 97,  
};

const CTR\_TREND \= \[  
  { week: "W1 Jan", baseline: 2.1, optimized: 2.1 },  
  { week: "W2", baseline: 2.0, optimized: 2.3 },  
  { week: "W3", baseline: 2.2, optimized: 2.6 },  
  { week: "W4", baseline: 2.1, optimized: 2.8 },  
  { week: "W1 Feb", baseline: 1.9, optimized: 3.1 },  
  { week: "W2", baseline: 2.0, optimized: 3.3 },  
  { week: "W3", baseline: 2.2, optimized: 3.6 },  
  { week: "W4", baseline: 2.1, optimized: 3.9 },  
  { week: "W1 Mar", baseline: 2.3, optimized: 4.1 },  
  { week: "W2", baseline: 2.2, optimized: 4.4 },  
  { week: "W3", baseline: 2.1, optimized: 4.7 },  
  { week: "W4", baseline: 2.0, optimized: 5.1 },  
\];

const RANK\_TREND \= \[  
  { week: "W1 Jan", before: 14.2, after: 14.2 },  
  { week: "W2", before: 14.5, after: 13.8 },  
  { week: "W3", before: 14.1, after: 12.9 },  
  { week: "W4", before: 13.8, after: 11.5 },  
  { week: "W1 Feb", before: 14.0, after: 10.2 },  
  { week: "W2", before: 13.9, after: 9.4 },  
  { week: "W3", before: 14.2, after: 8.6 },  
  { week: "W4", before: 13.7, after: 7.9 },  
  { week: "W1 Mar", before: 14.1, after: 7.1 },  
  { week: "W2", before: 13.8, after: 6.8 },  
  { week: "W3", before: 14.0, after: 6.2 },  
  { week: "W4", before: 13.9, after: 5.9 },  
\];

const WIN\_LOSS\_MONTHLY \= \[  
  { month: "Sep", wins: 7, losses: 3 },  
  { month: "Oct", wins: 9, losses: 4 },  
  { month: "Nov", wins: 8, losses: 2 },  
  { month: "Dec", wins: 11, losses: 5 },  
  { month: "Jan", wins: 14, losses: 4 },  
  { month: "Feb", wins: 16, losses: 6 },  
  { month: "Mar", wins: 12, losses: 3 },  
\];

const ACTIVE\_TESTS \= \[  
  { id: 1, title: "10 Best Budget Laptops for Students in 2024", day: 11, ctrBaseline: 3.2, ctrCurrent: 4.1, rankBaseline: 8.5, rankCurrent: 6.2, model: "Gemini", health: "up" },  
  { id: 2, title: "How to Fix WordPress White Screen of Death", day: 6, ctrBaseline: 5.1, ctrCurrent: 4.9, rankBaseline: 3.1, rankCurrent: 2.8, model: "Kimi", health: "neutral" },  
  { id: 3, title: "Ultimate Guide to Email Marketing ROI", day: 13, ctrBaseline: 2.8, ctrCurrent: 3.9, rankBaseline: 11.2, rankCurrent: 7.4, model: "Ensemble", health: "up" },  
  { id: 4, title: "Shopify vs WooCommerce: Full Comparison", day: 3, ctrBaseline: 4.4, ctrCurrent: 4.2, rankBaseline: 5.6, rankCurrent: 5.9, model: "Gemini", health: "neutral" },  
  { id: 5, title: "Python for Beginners: Complete Course", day: 9, ctrBaseline: 6.2, ctrCurrent: 7.8, rankBaseline: 2.3, rankCurrent: 1.9, model: "Kimi", health: "up" },  
  { id: 6, title: "Best CRM Software for Small Business", day: 1, ctrBaseline: 3.7, ctrCurrent: 3.7, rankBaseline: 9.8, rankCurrent: 9.8, model: "Gemini", health: "neutral" },  
\];

const AI\_MODEL\_DATA \= \[  
  { metric: "Win Rate", gemini: 68, kimi: 74, ensemble: 82 },  
  { metric: "CTR Lift", gemini: 21, kimi: 26, ensemble: 31 },  
  { metric: "Rank Lift", gemini: 65, kimi: 71, ensemble: 78 },  
  { metric: "Speed", gemini: 92, kimi: 70, ensemble: 55 },  
  { metric: "Consistency", gemini: 75, kimi: 68, ensemble: 88 },  
\];

const MODEL\_PIE \= \[  
  { name: "Gemini", value: 62, color: "\#f59e0b" },  
  { name: "Kimi 2.5", value: 45, color: "\#38bdf8" },  
  { name: "Ensemble", value: 41, color: "\#a78bfa" },  
\];

const IMPROVEMENT\_DIST \= \[  
  { range: "0–10%", count: 12 },  
  { range: "10–20%", count: 28 },  
  { range: "20–30%", count: 23 },  
  { range: "30–50%", count: 19 },  
  { range: "50–75%", count: 11 },  
  { range: "75%+", count: 7 },  
\];

const POST\_HISTORY \= \[  
  { date: "Mar 14", ctr: 3.2, position: 8.5, event: null },  
  { date: "Mar 15", ctr: 3.1, position: 8.7, event: null },  
  { date: "Mar 16", ctr: 3.3, position: 8.4, event: "test\_start" },  
  { date: "Mar 17", ctr: 3.5, position: 8.1, event: null },  
  { date: "Mar 18", ctr: 3.8, position: 7.6, event: null },  
  { date: "Mar 19", ctr: 3.6, position: 7.8, event: null },  
  { date: "Mar 20", ctr: 4.0, position: 7.2, event: null },  
  { date: "Mar 21", ctr: 4.1, position: 7.0, event: null },  
  { date: "Mar 22", ctr: 3.9, position: 6.8, event: null },  
  { date: "Mar 23", ctr: 4.2, position: 6.5, event: null },  
  { date: "Mar 24", ctr: 4.4, position: 6.3, event: null },  
  { date: "Mar 25", ctr: 4.1, position: 6.4, event: null },  
  { date: "Mar 26", ctr: 4.5, position: 6.1, event: null },  
  { date: "Mar 27", ctr: 4.6, position: 5.9, event: null },  
  { date: "Mar 28", ctr: 4.8, position: 5.7, event: null },  
  { date: "Mar 29", ctr: 4.6, position: 5.8, event: null },  
  { date: "Mar 30", ctr: 5.1, position: 5.4, event: "test\_end" },  
\];

// ─── CUSTOM TOOLTIP ──────────────────────────────────────────  
const CustomTooltip \= ({ active, payload, label }) \=\> {  
  if (\!active || \!payload?.length) return null;  
  return (  
    \<div style={{ background: "\#0f172a", border: "1px solid \#1e293b", padding: "10px 14px", borderRadius: 6, fontFamily: "monospace", fontSize: 12 }}\>  
      \<p style={{ color: "\#94a3b8", marginBottom: 6 }}\>{label}\</p\>  
      {payload.map((p, i) \=\> (  
        \<p key={i} style={{ color: p.color, margin: "2px 0" }}\>  
          {p.name}: \<span style={{ color: "\#f8fafc", fontWeight: 700 }}\>{typeof p.value \=== "number" && p.value \< 100 ? p.value.toFixed(2) : p.value}{p.name?.includes("CTR") ? "%" : ""}\</span\>  
        \</p\>  
      ))}  
    \</div\>  
  );  
};

// ─── KPI CARD ─────────────────────────────────────────────────  
const KPICard \= ({ label, value, sub, accent, trend }) \=\> (  
  \<div style={{  
    background: "linear-gradient(135deg, \#0f172a 0%, \#1e293b 100%)",  
    border: \`1px solid ${accent}30\`,  
    borderTop: \`2px solid ${accent}\`,  
    borderRadius: 8,  
    padding: "18px 20px",  
    position: "relative",  
    overflow: "hidden",  
  }}\>  
    \<div style={{ position: "absolute", top: \-20, right: \-20, width: 80, height: 80, borderRadius: "50%", background: \`${accent}08\` }} /\>  
    \<p style={{ color: "\#64748b", fontSize: 11, fontFamily: "monospace", letterSpacing: "0.1em", textTransform: "uppercase", margin: "0 0 8px" }}\>{label}\</p\>  
    \<p style={{ color: accent, fontSize: 32, fontWeight: 800, fontFamily: "monospace", margin: "0 0 4px", lineHeight: 1 }}\>{value}\</p\>  
    {sub && \<p style={{ color: trend \=== "up" ? "\#4ade80" : trend \=== "down" ? "\#f87171" : "\#64748b", fontSize: 12, fontFamily: "monospace", margin: 0 }}\>{sub}\</p\>}  
  \</div\>  
);

// ─── SECTION HEADER ───────────────────────────────────────────  
const SectionHeader \= ({ title, sub }) \=\> (  
  \<div style={{ marginBottom: 16 }}\>  
    \<h3 style={{ color: "\#f8fafc", fontFamily: "'DM Sans', sans-serif", fontSize: 15, fontWeight: 700, margin: "0 0 2px", letterSpacing: "-0.02em" }}\>{title}\</h3\>  
    {sub && \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>{sub}\</p\>}  
  \</div\>  
);

// ─── CHART CARD ───────────────────────────────────────────────  
const ChartCard \= ({ children, style \= {} }) \=\> (  
  \<div style={{ background: "\#0f172a", border: "1px solid \#1e293b", borderRadius: 10, padding: "20px 20px 12px", ...style }}\>  
    {children}  
  \</div\>  
);

// ─── ACTIVE TEST ROW ──────────────────────────────────────────  
const TestRow \= ({ test }) \=\> {  
  const progress \= (test.day / 14\) \* 100;  
  const ctrDelta \= ((test.ctrCurrent \- test.ctrBaseline) / test.ctrBaseline \* 100).toFixed(1);  
  const rankDelta \= (test.rankBaseline \- test.rankCurrent).toFixed(1);  
  const isUp \= parseFloat(ctrDelta) \> 0;  
  const modelColor \= test.model \=== "Gemini" ? "\#f59e0b" : test.model \=== "Kimi" ? "\#38bdf8" : "\#a78bfa";

  return (  
    \<div style={{ padding: "14px 0", borderBottom: "1px solid \#1e293b" }}\>  
      \<div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 8 }}\>  
        \<div style={{ flex: 1, marginRight: 16 }}\>  
          \<p style={{ color: "\#e2e8f0", fontSize: 13, fontFamily: "'DM Sans', sans-serif", margin: "0 0 4px", fontWeight: 500 }}\>{test.title}\</p\>  
          \<div style={{ display: "flex", gap: 12, alignItems: "center" }}\>  
            \<span style={{ background: \`${modelColor}15\`, color: modelColor, fontSize: 10, fontFamily: "monospace", padding: "2px 7px", borderRadius: 4, border: \`1px solid ${modelColor}30\` }}\>{test.model}\</span\>  
            \<span style={{ color: "\#475569", fontFamily: "monospace", fontSize: 11 }}\>Day {test.day}/14\</span\>  
          \</div\>  
        \</div\>  
        \<div style={{ display: "flex", gap: 16, textAlign: "right" }}\>  
          \<div\>  
            \<p style={{ color: isUp ? "\#4ade80" : "\#f87171", fontFamily: "monospace", fontSize: 13, fontWeight: 700, margin: "0 0 2px" }}\>{isUp ? "+" : ""}{ctrDelta}%\</p\>  
            \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, margin: 0 }}\>CTR Δ\</p\>  
          \</div\>  
          \<div\>  
            \<p style={{ color: parseFloat(rankDelta) \> 0 ? "\#4ade80" : parseFloat(rankDelta) \< 0 ? "\#f87171" : "\#94a3b8", fontFamily: "monospace", fontSize: 13, fontWeight: 700, margin: "0 0 2px" }}\>{parseFloat(rankDelta) \> 0 ? "▲" : parseFloat(rankDelta) \< 0 ? "▼" : "–"}{Math.abs(rankDelta)}\</p\>  
            \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, margin: 0 }}\>Rank Δ\</p\>  
          \</div\>  
        \</div\>  
      \</div\>  
      \<div style={{ background: "\#1e293b", borderRadius: 100, height: 4, overflow: "hidden" }}\>  
        \<div style={{ width: \`${progress}%\`, height: "100%", background: progress \> 85 ? "\#f59e0b" : "\#3b82f6", borderRadius: 100, transition: "width 0.5s ease" }} /\>  
      \</div\>  
    \</div\>  
  );  
};

// ─── MAIN DASHBOARD ───────────────────────────────────────────  
export default function SEODashboard() {  
  const \[activeTab, setActiveTab\] \= useState("overview");  
  const \[selectedPost, setSelectedPost\] \= useState(null);

  const tabs \= \[  
    { id: "overview", label: "Site Overview" },  
    { id: "tests", label: "Active Tests" },  
    { id: "history", label: "Win/Loss History" },  
    { id: "models", label: "AI Models" },  
    { id: "post", label: "Post Detail" },  
  \];

  return (  
    \<div style={{  
      background: "\#020817",  
      minHeight: "100vh",  
      fontFamily: "'DM Sans', sans-serif",  
      color: "\#f8fafc",  
      padding: "0",  
    }}\>  
      \<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700\&display=swap" rel="stylesheet" /\>

      {/\* Header \*/}  
      \<div style={{  
        background: "linear-gradient(180deg, \#0f172a 0%, \#020817 100%)",  
        borderBottom: "1px solid \#1e293b",  
        padding: "20px 32px 0",  
      }}\>  
        \<div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 20 }}\>  
          \<div style={{ width: 32, height: 32, background: "linear-gradient(135deg, \#f59e0b, \#ef4444)", borderRadius: 8, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 16 }}\>⚡\</div\>  
          \<div\>  
            \<h1 style={{ fontFamily: "'DM Sans', sans-serif", fontSize: 18, fontWeight: 700, margin: 0, letterSpacing: "-0.03em" }}\>SEO Intelligence\</h1\>  
            \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, margin: 0, letterSpacing: "0.08em" }}\>GEMINI-KIMI OPTIMIZER v1.0\</p\>  
          \</div\>  
          \<div style={{ marginLeft: "auto", display: "flex", gap: 8 }}\>  
            \<div style={{ background: "\#4ade8015", border: "1px solid \#4ade8040", color: "\#4ade80", fontSize: 11, fontFamily: "monospace", padding: "4px 10px", borderRadius: 20, display: "flex", alignItems: "center", gap: 5 }}\>  
              \<span style={{ width: 6, height: 6, borderRadius: "50%", background: "\#4ade80", display: "inline-block", animation: "pulse 2s infinite" }} /\>  
              {SITE\_STATS.activeTests} ACTIVE  
            \</div\>  
          \</div\>  
        \</div\>

        {/\* Tabs \*/}  
        \<div style={{ display: "flex", gap: 0 }}\>  
          {tabs.map(tab \=\> (  
            \<button key={tab.id} onClick={() \=\> setActiveTab(tab.id)} style={{  
              background: "none", border: "none", cursor: "pointer",  
              padding: "10px 18px",  
              fontFamily: "'DM Sans', sans-serif", fontSize: 13, fontWeight: activeTab \=== tab.id ? 700 : 500,  
              color: activeTab \=== tab.id ? "\#f59e0b" : "\#64748b",  
              borderBottom: \`2px solid ${activeTab \=== tab.id ? "\#f59e0b" : "transparent"}\`,  
              transition: "all 0.15s ease",  
              letterSpacing: "-0.01em",  
            }}\>  
              {tab.label}  
            \</button\>  
          ))}  
        \</div\>  
      \</div\>

      {/\* Content \*/}  
      \<div style={{ padding: "28px 32px", maxWidth: 1280 }}\>

        {/\* ── OVERVIEW TAB ─────────────────────────────────── \*/}  
        {activeTab \=== "overview" && (  
          \<div\>  
            {/\* KPI Row \*/}  
            \<div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 14, marginBottom: 24 }}\>  
              \<KPICard label="Total Tests Run" value={SITE\_STATS.totalTests} sub="↑ 18 this month" accent="\#f59e0b" trend="up" /\>  
              \<KPICard label="Win Rate" value={\`${SITE\_STATS.winRate}%\`} sub="↑ 4% vs last quarter" accent="\#4ade80" trend="up" /\>  
              \<KPICard label="Avg CTR Lift" value={\`+${SITE\_STATS.avgCTRLift}%\`} sub="across winning tests" accent="\#38bdf8" trend="up" /\>  
              \<KPICard label="Avg Rank Lift" value={\`+${SITE\_STATS.avgRankLift}\`} sub="positions gained" accent="\#a78bfa" trend="up" /\>  
            \</div\>

            \<div style={{ display: "grid", gridTemplateColumns: "2fr 1fr", gap: 14, marginBottom: 14 }}\>  
              {/\* CTR Over Time \*/}  
              \<ChartCard\>  
                \<SectionHeader title="CTR Performance: Baseline vs Optimized" sub="Site-wide average across all posts — 12 week rolling" /\>  
                \<ResponsiveContainer width="100%" height={220}\>  
                  \<AreaChart data={CTR\_TREND} margin={{ top: 5, right: 10, left: \-20, bottom: 0 }}\>  
                    \<defs\>  
                      \<linearGradient id="gradOpt" x1="0" y1="0" x2="0" y2="1"\>  
                        \<stop offset="0%" stopColor="\#f59e0b" stopOpacity={0.3} /\>  
                        \<stop offset="100%" stopColor="\#f59e0b" stopOpacity={0} /\>  
                      \</linearGradient\>  
                      \<linearGradient id="gradBase" x1="0" y1="0" x2="0" y2="1"\>  
                        \<stop offset="0%" stopColor="\#475569" stopOpacity={0.3} /\>  
                        \<stop offset="100%" stopColor="\#475569" stopOpacity={0} /\>  
                      \</linearGradient\>  
                    \</defs\>  
                    \<CartesianGrid strokeDasharray="3 3" stroke="\#1e293b" /\>  
                    \<XAxis dataKey="week" tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                    \<YAxis tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} tickFormatter={v \=\> \`${v}%\`} /\>  
                    \<Tooltip content={\<CustomTooltip /\>} /\>  
                    \<Area type="monotone" dataKey="baseline" name="Baseline CTR" stroke="\#475569" fill="url(\#gradBase)" strokeWidth={1.5} strokeDasharray="4 2" dot={false} /\>  
                    \<Area type="monotone" dataKey="optimized" name="Optimized CTR" stroke="\#f59e0b" fill="url(\#gradOpt)" strokeWidth={2.5} dot={false} /\>  
                  \</AreaChart\>  
                \</ResponsiveContainer\>  
              \</ChartCard\>

              {/\* Improvement Distribution \*/}  
              \<ChartCard\>  
                \<SectionHeader title="CTR Improvement Distribution" sub="Frequency of improvement ranges" /\>  
                \<ResponsiveContainer width="100%" height={220}\>  
                  \<BarChart data={IMPROVEMENT\_DIST} margin={{ top: 5, right: 10, left: \-20, bottom: 0 }}\>  
                    \<CartesianGrid strokeDasharray="3 3" stroke="\#1e293b" vertical={false} /\>  
                    \<XAxis dataKey="range" tick={{ fill: "\#475569", fontSize: 9, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                    \<YAxis tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                    \<Tooltip content={\<CustomTooltip /\>} /\>  
                    \<Bar dataKey="count" name="Tests" radius={\[4, 4, 0, 0\]}\>  
                      {IMPROVEMENT\_DIST.map((\_, i) \=\> (  
                        \<Cell key={i} fill={i \< 2 ? "\#334155" : i \< 4 ? "\#f59e0b" : "\#ef4444"} /\>  
                      ))}  
                    \</Bar\>  
                  \</BarChart\>  
                \</ResponsiveContainer\>  
              \</ChartCard\>  
            \</div\>

            {/\* Ranking Trend \*/}  
            \<ChartCard\>  
              \<SectionHeader title="Average Ranking Position: Before vs After Optimization" sub="Lower \= better — inverted scale for clarity" /\>  
              \<ResponsiveContainer width="100%" height={200}\>  
                \<LineChart data={RANK\_TREND} margin={{ top: 5, right: 10, left: \-20, bottom: 0 }}\>  
                  \<CartesianGrid strokeDasharray="3 3" stroke="\#1e293b" /\>  
                  \<XAxis dataKey="week" tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                  \<YAxis reversed tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                  \<Tooltip content={\<CustomTooltip /\>} /\>  
                  \<Line type="monotone" dataKey="before" name="Before" stroke="\#334155" strokeWidth={1.5} strokeDasharray="4 2" dot={false} /\>  
                  \<Line type="monotone" dataKey="after" name="After Optimization" stroke="\#4ade80" strokeWidth={2.5} dot={false} /\>  
                \</LineChart\>  
              \</ResponsiveContainer\>  
            \</ChartCard\>  
          \</div\>  
        )}

        {/\* ── ACTIVE TESTS TAB ─────────────────────────────── \*/}  
        {activeTab \=== "tests" && (  
          \<div\>  
            \<div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14, marginBottom: 24 }}\>  
              \<KPICard label="Active Tests" value={SITE\_STATS.activeTests} sub="Running now" accent="\#f59e0b" /\>  
              \<KPICard label="Completing Soon" value="3" sub="Within 3 days" accent="\#38bdf8" /\>  
              \<KPICard label="Early Leaders" value="4" sub="Positive CTR trend" accent="\#4ade80" trend="up" /\>  
            \</div\>

            \<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}\>  
              \<ChartCard style={{ gridColumn: "1 / \-1" }}\>  
                \<SectionHeader title="Active A/B Tests" sub="Real-time progress — refreshes every 30s" /\>  
                {ACTIVE\_TESTS.map(test \=\> \<TestRow key={test.id} test={test} /\>)}  
              \</ChartCard\>  
            \</div\>

            {/\* Progress Timeline \*/}  
            \<ChartCard style={{ marginTop: 14 }}\>  
              \<SectionHeader title="Test Completion Timeline" sub="Days remaining per active test" /\>  
              \<ResponsiveContainer width="100%" height={180}\>  
                \<BarChart  
                  data={ACTIVE\_TESTS.map(t \=\> ({ name: t.title.substring(0, 28\) \+ "…", elapsed: t.day, remaining: 14 \- t.day }))}  
                  layout="vertical"  
                  margin={{ top: 0, right: 10, left: 220, bottom: 0 }}  
                \>  
                  \<CartesianGrid strokeDasharray="3 3" stroke="\#1e293b" horizontal={false} /\>  
                  \<XAxis type="number" tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} domain={\[0, 14\]} /\>  
                  \<YAxis type="category" dataKey="name" tick={{ fill: "\#94a3b8", fontSize: 11, fontFamily: "'DM Sans', sans-serif" }} axisLine={false} tickLine={false} width={220} /\>  
                  \<Tooltip content={\<CustomTooltip /\>} /\>  
                  \<Bar dataKey="elapsed" name="Days Elapsed" stackId="a" fill="\#f59e0b" radius={\[0, 0, 0, 0\]} /\>  
                  \<Bar dataKey="remaining" name="Days Left" stackId="a" fill="\#1e293b" radius={\[4, 4, 4, 4\]} /\>  
                \</BarChart\>  
              \</ResponsiveContainer\>  
            \</ChartCard\>  
          \</div\>  
        )}

        {/\* ── WIN/LOSS HISTORY TAB ──────────────────────────── \*/}  
        {activeTab \=== "history" && (  
          \<div\>  
            \<div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 14, marginBottom: 24 }}\>  
              \<KPICard label="Total Wins" value="105" sub="+16 this month" accent="\#4ade80" trend="up" /\>  
              \<KPICard label="Total Losses" value="43" sub="Baseline retained" accent="\#f87171" /\>  
              \<KPICard label="Win Streak" value="6" sub="Current streak" accent="\#f59e0b" /\>  
              \<KPICard label="Best Improvement" value="+187%" sub="Budget laptops post" accent="\#a78bfa" trend="up" /\>  
            \</div\>

            \<div style={{ display: "grid", gridTemplateColumns: "3fr 2fr", gap: 14, marginBottom: 14 }}\>  
              {/\* Monthly Win/Loss \*/}  
              \<ChartCard\>  
                \<SectionHeader title="Monthly Test Results" sub="Wins vs losses per calendar month" /\>  
                \<ResponsiveContainer width="100%" height={240}\>  
                  \<BarChart data={WIN\_LOSS\_MONTHLY} margin={{ top: 5, right: 10, left: \-20, bottom: 0 }}\>  
                    \<CartesianGrid strokeDasharray="3 3" stroke="\#1e293b" vertical={false} /\>  
                    \<XAxis dataKey="month" tick={{ fill: "\#475569", fontSize: 11, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                    \<YAxis tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                    \<Tooltip content={\<CustomTooltip /\>} /\>  
                    \<Bar dataKey="wins" name="Wins" fill="\#4ade80" radius={\[4, 4, 0, 0\]} /\>  
                    \<Bar dataKey="losses" name="Losses" fill="\#ef444460" radius={\[4, 4, 0, 0\]} /\>  
                  \</BarChart\>  
                \</ResponsiveContainer\>  
              \</ChartCard\>

              {/\* Win Rate Donut \*/}  
              \<ChartCard\>  
                \<SectionHeader title="Overall Win Rate" sub="148 tests completed" /\>  
                \<ResponsiveContainer width="100%" height={240}\>  
                  \<PieChart\>  
                    \<Pie data={\[{ value: 71, name: "Wins" }, { value: 29, name: "Losses" }\]} cx="50%" cy="50%" innerRadius={65} outerRadius={95} startAngle={90} endAngle={-270} paddingAngle={3} dataKey="value"\>  
                      \<Cell fill="\#4ade80" /\>  
                      \<Cell fill="\#1e293b" /\>  
                    \</Pie\>  
                    \<text x="50%" y="48%" textAnchor="middle" dominantBaseline="middle" style={{ fill: "\#f8fafc", fontSize: 28, fontFamily: "monospace", fontWeight: 800 }}\>71%\</text\>  
                    \<text x="50%" y="62%" textAnchor="middle" dominantBaseline="middle" style={{ fill: "\#64748b", fontSize: 11, fontFamily: "monospace" }}\>WIN RATE\</text\>  
                  \</PieChart\>  
                \</ResponsiveContainer\>  
              \</ChartCard\>  
            \</div\>

            {/\* Recent tests table \*/}  
            \<ChartCard\>  
              \<SectionHeader title="Recent Test Results" sub="Last 6 completed tests" /\>  
              \<table style={{ width: "100%", borderCollapse: "collapse", fontFamily: "monospace", fontSize: 12 }}\>  
                \<thead\>  
                  \<tr style={{ borderBottom: "1px solid \#1e293b" }}\>  
                    {\["Post", "Model", "CTR Before", "CTR After", "Δ CTR", "Rank Δ", "Result"\].map(h \=\> (  
                      \<th key={h} style={{ color: "\#475569", textAlign: "left", padding: "8px 10px", fontWeight: 500, fontSize: 10, letterSpacing: "0.08em", textTransform: "uppercase" }}\>{h}\</th\>  
                    ))}  
                  \</tr\>  
                \</thead\>  
                \<tbody\>  
                  {\[  
                    { post: "Best Budget Laptops 2024", model: "Ensemble", before: 3.2, after: 5.4, rankD: "+2.3", result: "win" },  
                    { post: "WordPress Security Guide", model: "Gemini", before: 4.1, after: 3.9, rankD: "0.0", result: "loss" },  
                    { post: "Python Beginners Course", model: "Kimi", before: 6.2, after: 7.8, rankD: "+0.4", result: "win" },  
                    { post: "Email Marketing ROI", model: "Ensemble", before: 2.8, after: 3.9, rankD: "+3.8", result: "win" },  
                    { post: "Shopify vs WooCommerce", model: "Gemini", before: 4.4, after: 4.2, rankD: "-0.3", result: "loss" },  
                    { post: "React Hooks Explained", model: "Kimi", before: 5.1, after: 6.9, rankD: "+1.9", result: "win" },  
                  \].map((r, i) \=\> (  
                    \<tr key={i} style={{ borderBottom: "1px solid \#0f172a", transition: "background 0.15s" }}  
                      onMouseEnter={e \=\> e.currentTarget.style.background \= "\#1e293b"}  
                      onMouseLeave={e \=\> e.currentTarget.style.background \= "transparent"}\>  
                      \<td style={{ color: "\#e2e8f0", padding: "10px 10px", fontSize: 12, fontFamily: "'DM Sans', sans-serif" }}\>{r.post}\</td\>  
                      \<td style={{ padding: "10px 10px" }}\>  
                        \<span style={{ color: r.model \=== "Gemini" ? "\#f59e0b" : r.model \=== "Kimi" ? "\#38bdf8" : "\#a78bfa", fontSize: 10 }}\>{r.model}\</span\>  
                      \</td\>  
                      \<td style={{ color: "\#94a3b8", padding: "10px 10px" }}\>{r.before}%\</td\>  
                      \<td style={{ color: "\#94a3b8", padding: "10px 10px" }}\>{r.after}%\</td\>  
                      \<td style={{ color: r.after \> r.before ? "\#4ade80" : "\#f87171", padding: "10px 10px", fontWeight: 700 }}\>  
                        {r.after \> r.before ? "+" : ""}{((r.after \- r.before) / r.before \* 100).toFixed(1)}%  
                      \</td\>  
                      \<td style={{ color: r.rankD.startsWith("+") ? "\#4ade80" : r.rankD.startsWith("-") ? "\#f87171" : "\#64748b", padding: "10px 10px" }}\>{r.rankD}\</td\>  
                      \<td style={{ padding: "10px 10px" }}\>  
                        \<span style={{  
                          background: r.result \=== "win" ? "\#4ade8015" : "\#f8717115",  
                          color: r.result \=== "win" ? "\#4ade80" : "\#f87171",  
                          border: \`1px solid ${r.result \=== "win" ? "\#4ade8030" : "\#f8717130"}\`,  
                          padding: "2px 8px", borderRadius: 4, fontSize: 10, fontWeight: 700, textTransform: "uppercase"  
                        }}\>{r.result \=== "win" ? "✓ Won" : "✗ Lost"}\</span\>  
                      \</td\>  
                    \</tr\>  
                  ))}  
                \</tbody\>  
              \</table\>  
            \</ChartCard\>  
          \</div\>  
        )}

        {/\* ── AI MODELS TAB ────────────────────────────────── \*/}  
        {activeTab \=== "models" && (  
          \<div\>  
            \<div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14, marginBottom: 24 }}\>  
              \<KPICard label="Gemini Usage" value="62 tests" sub="68% win rate" accent="\#f59e0b" /\>  
              \<KPICard label="Kimi 2.5 Usage" value="45 tests" sub="74% win rate" accent="\#38bdf8" /\>  
              \<KPICard label="Ensemble Usage" value="41 tests" sub="82% win rate — best" accent="\#a78bfa" trend="up" /\>  
            \</div\>

            \<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14, marginBottom: 14 }}\>  
              {/\* Radar comparison \*/}  
              \<ChartCard\>  
                \<SectionHeader title="Model Capability Radar" sub="Normalized scores across 5 dimensions" /\>  
                \<ResponsiveContainer width="100%" height={280}\>  
                  \<RadarChart data={AI\_MODEL\_DATA} margin={{ top: 10, right: 20, left: 20, bottom: 10 }}\>  
                    \<PolarGrid stroke="\#1e293b" /\>  
                    \<PolarAngleAxis dataKey="metric" tick={{ fill: "\#64748b", fontSize: 11, fontFamily: "monospace" }} /\>  
                    \<PolarRadiusAxis domain={\[0, 100\]} tick={false} axisLine={false} /\>  
                    \<Radar name="Gemini" dataKey="gemini" stroke="\#f59e0b" fill="\#f59e0b" fillOpacity={0.15} strokeWidth={2} /\>  
                    \<Radar name="Kimi 2.5" dataKey="kimi" stroke="\#38bdf8" fill="\#38bdf8" fillOpacity={0.15} strokeWidth={2} /\>  
                    \<Radar name="Ensemble" dataKey="ensemble" stroke="\#a78bfa" fill="\#a78bfa" fillOpacity={0.15} strokeWidth={2} /\>  
                    \<Legend wrapperStyle={{ fontFamily: "monospace", fontSize: 11, color: "\#94a3b8" }} /\>  
                    \<Tooltip content={\<CustomTooltip /\>} /\>  
                  \</RadarChart\>  
                \</ResponsiveContainer\>  
              \</ChartCard\>

              {/\* Usage Donut \*/}  
              \<ChartCard\>  
                \<SectionHeader title="Test Volume by Model" sub="Share of 148 completed tests" /\>  
                \<ResponsiveContainer width="100%" height={280}\>  
                  \<PieChart\>  
                    \<Pie data={MODEL\_PIE} cx="50%" cy="50%" outerRadius={100} innerRadius={55} paddingAngle={4} dataKey="value"\>  
                      {MODEL\_PIE.map((entry, i) \=\> \<Cell key={i} fill={entry.color} /\>)}  
                    \</Pie\>  
                    \<Legend  
                      formatter={(value, entry) \=\> (  
                        \<span style={{ color: entry.color, fontFamily: "monospace", fontSize: 11 }}\>{value}\</span\>  
                      )}  
                    /\>  
                    \<Tooltip content={\<CustomTooltip /\>} /\>  
                  \</PieChart\>  
                \</ResponsiveContainer\>  
              \</ChartCard\>  
            \</div\>

            {/\* Win rate bar comparison \*/}  
            \<ChartCard\>  
              \<SectionHeader title="Model Win Rate by Content Category" sub="Wins per model across different post types" /\>  
              \<ResponsiveContainer width="100%" height={200}\>  
                \<BarChart  
                  data={\[  
                    { category: "Technical", gemini: 72, kimi: 68, ensemble: 85 },  
                    { category: "Commercial", gemini: 65, kimi: 78, ensemble: 82 },  
                    { category: "Informational", gemini: 70, kimi: 75, ensemble: 79 },  
                    { category: "Long-form", gemini: 58, kimi: 81, ensemble: 83 },  
                    { category: "News", gemini: 74, kimi: 64, ensemble: 80 },  
                  \]}  
                  margin={{ top: 5, right: 10, left: \-20, bottom: 0 }}  
                \>  
                  \<CartesianGrid strokeDasharray="3 3" stroke="\#1e293b" vertical={false} /\>  
                  \<XAxis dataKey="category" tick={{ fill: "\#475569", fontSize: 11, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                  \<YAxis tick={{ fill: "\#475569", fontSize: 10, fontFamily: "monospace" }} axisLine={false} tickLine={false} tickFormatter={v \=\> \`${v}%\`} domain={\[40, 100\]} /\>  
                  \<Tooltip content={\<CustomTooltip /\>} /\>  
                  \<Bar dataKey="gemini" name="Gemini" fill="\#f59e0b" radius={\[4, 4, 0, 0\]} /\>  
                  \<Bar dataKey="kimi" name="Kimi 2.5" fill="\#38bdf8" radius={\[4, 4, 0, 0\]} /\>  
                  \<Bar dataKey="ensemble" name="Ensemble" fill="\#a78bfa" radius={\[4, 4, 0, 0\]} /\>  
                \</BarChart\>  
              \</ResponsiveContainer\>  
            \</ChartCard\>  
          \</div\>  
        )}

        {/\* ── POST DETAIL TAB ───────────────────────────────── \*/}  
        {activeTab \=== "post" && (  
          \<div\>  
            {/\* Post selector \*/}  
            \<ChartCard style={{ marginBottom: 14 }}\>  
              \<div style={{ display: "flex", alignItems: "center", gap: 12 }}\>  
                \<span style={{ color: "\#64748b", fontFamily: "monospace", fontSize: 11 }}\>VIEWING:\</span\>  
                \<select  
                  style={{ background: "\#1e293b", border: "1px solid \#334155", color: "\#f8fafc", fontFamily: "'DM Sans', sans-serif", fontSize: 13, padding: "6px 12px", borderRadius: 6, flex: 1, cursor: "pointer" }}  
                  defaultValue="post1"  
                \>  
                  \<option value="post1"\>10 Best Budget Laptops for Students in 2024\</option\>  
                  \<option value="post2"\>How to Fix WordPress White Screen of Death\</option\>  
                  \<option value="post3"\>Python for Beginners: Complete Course\</option\>  
                \</select\>  
                \<div style={{ display: "flex", gap: 8 }}\>  
                  \<span style={{ background: "\#f59e0b15", color: "\#f59e0b", border: "1px solid \#f59e0b30", fontFamily: "monospace", fontSize: 10, padding: "4px 10px", borderRadius: 4 }}\>TESTING\</span\>  
                  \<span style={{ color: "\#64748b", fontFamily: "monospace", fontSize: 10, padding: "4px 10px" }}\>Day 11/14\</span\>  
                \</div\>  
              \</div\>  
            \</ChartCard\>

            {/\* Post KPIs \*/}  
            \<div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 14, marginBottom: 14 }}\>  
              \<KPICard label="Current CTR" value="4.1%" sub="↑ from 3.2% baseline" accent="\#4ade80" trend="up" /\>  
              \<KPICard label="Current Rank" value="6.2" sub="↑ from 8.5 baseline" accent="\#38bdf8" trend="up" /\>  
              \<KPICard label="Impressions" value="5.2K" sub="14-day test period" accent="\#f59e0b" /\>  
              \<KPICard label="Projected Winner" value="Test" sub="82% confidence" accent="\#a78bfa" trend="up" /\>  
            \</div\>

            \<div style={{ display: "grid", gridTemplateColumns: "2fr 1fr", gap: 14 }}\>  
              {/\* Per-post CTR \+ Rank chart \*/}  
              \<ChartCard\>  
                \<SectionHeader title="Post Performance Timeline" sub="Daily CTR and ranking — red line \= test start, green \= test end" /\>  
                \<ResponsiveContainer width="100%" height={240}\>  
                  \<LineChart data={POST\_HISTORY} margin={{ top: 5, right: 10, left: \-20, bottom: 0 }}\>  
                    \<defs\>  
                      \<linearGradient id="ctrGrad" x1="0" y1="0" x2="1" y2="0"\>  
                        \<stop offset="40%" stopColor="\#475569" /\>  
                        \<stop offset="41%" stopColor="\#f59e0b" /\>  
                      \</linearGradient\>  
                    \</defs\>  
                    \<CartesianGrid strokeDasharray="3 3" stroke="\#1e293b" /\>  
                    \<XAxis dataKey="date" tick={{ fill: "\#475569", fontSize: 9, fontFamily: "monospace" }} axisLine={false} tickLine={false} interval={2} /\>  
                    \<YAxis yAxisId="ctr" tick={{ fill: "\#475569", fontSize: 9, fontFamily: "monospace" }} axisLine={false} tickLine={false} tickFormatter={v \=\> \`${v}%\`} /\>  
                    \<YAxis yAxisId="rank" orientation="right" reversed tick={{ fill: "\#475569", fontSize: 9, fontFamily: "monospace" }} axisLine={false} tickLine={false} /\>  
                    \<Tooltip content={\<CustomTooltip /\>} /\>  
                    {/\* Test start line \*/}  
                    \<Line yAxisId="ctr" type="monotone" dataKey="ctr" name="CTR" stroke="\#f59e0b" strokeWidth={2.5} dot={(props) \=\> {  
                      if (props.payload.event \=== "test\_start" || props.payload.event \=== "test\_end") {  
                        return \<circle key={props.key} cx={props.cx} cy={props.cy} r={5} fill={props.payload.event \=== "test\_start" ? "\#ef4444" : "\#4ade80"} stroke="\#020817" strokeWidth={2} /\>;  
                      }  
                      return \<circle key={props.key} cx={props.cx} cy={props.cy} r={2} fill="\#f59e0b" /\>;  
                    }} /\>  
                    \<Line yAxisId="rank" type="monotone" dataKey="position" name="Position" stroke="\#38bdf8" strokeWidth={1.5} strokeDasharray="4 2" dot={false} /\>  
                  \</LineChart\>  
                \</ResponsiveContainer\>  
                \<div style={{ display: "flex", gap: 16, marginTop: 8 }}\>  
                  {\[\["● CTR", "\#f59e0b"\], \["● Position (right axis)", "\#38bdf8"\], \["● Test Start", "\#ef4444"\], \["● Test End", "\#4ade80"\]\].map((\[label, color\]) \=\> (  
                    \<span key={label} style={{ color, fontFamily: "monospace", fontSize: 10 }}\>{label}\</span\>  
                  ))}  
                \</div\>  
              \</ChartCard\>

              {/\* Metadata diff \*/}  
              \<ChartCard\>  
                \<SectionHeader title="Metadata A/B" sub="Baseline vs test variant" /\>  
                \<div style={{ marginBottom: 16 }}\>  
                  \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.08em", marginBottom: 6 }}\>Baseline Title\</p\>  
                  \<p style={{ color: "\#94a3b8", fontFamily: "'DM Sans', sans-serif", fontSize: 13, background: "\#1e293b", padding: "10px 12px", borderRadius: 6, margin: 0, lineHeight: 1.5 }}\>  
                    Best Budget Laptops 2024: Our Top Picks  
                  \</p\>  
                \</div\>  
                \<div style={{ marginBottom: 16 }}\>  
                  \<p style={{ color: "\#f59e0b", fontFamily: "monospace", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.08em", marginBottom: 6 }}\>Test Title \<span style={{ color: "\#4ade80" }}\>← AI Generated\</span\>\</p\>  
                  \<p style={{ color: "\#e2e8f0", fontFamily: "'DM Sans', sans-serif", fontSize: 13, background: "\#1e293b", borderLeft: "3px solid \#f59e0b", padding: "10px 12px", borderRadius: 6, margin: 0, lineHeight: 1.5 }}\>  
                    10 Best Budget Laptops for Students in 2024  
                  \</p\>  
                \</div\>  
                \<div\>  
                  \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.08em", marginBottom: 6 }}\>AI Model Used\</p\>  
                  \<span style={{ background: "\#f59e0b15", color: "\#f59e0b", border: "1px solid \#f59e0b30", fontFamily: "monospace", fontSize: 11, padding: "4px 10px", borderRadius: 4 }}\>Gemini 2.0 Flash\</span\>  
                \</div\>  
                \<div style={{ marginTop: 16, paddingTop: 16, borderTop: "1px solid \#1e293b" }}\>  
                  \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.08em", marginBottom: 10 }}\>Test Version History\</p\>  
                  {\[{ v: 3, date: "Mar 16", winner: "Test", improvement: "+28%" }, { v: 2, date: "Feb 01", winner: "Baseline", improvement: "+5%" }, { v: 1, date: "Dec 12", winner: "Test", improvement: "+41%" }\].map(r \=\> (  
                    \<div key={r.v} style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", borderBottom: "1px solid \#1e293b" }}\>  
                      \<span style={{ color: "\#64748b", fontFamily: "monospace", fontSize: 11 }}\>v{r.v} · {r.date}\</span\>  
                      \<span style={{ color: r.winner \=== "Test" ? "\#4ade80" : "\#94a3b8", fontFamily: "monospace", fontSize: 11 }}\>{r.winner \=== "Test" ? \`✓ ${r.improvement}\` : "– Baseline"}\</span\>  
                    \</div\>  
                  ))}  
                \</div\>  
              \</ChartCard\>  
            \</div\>  
          \</div\>  
        )}  
      \</div\>

      \<style\>{\`  
        @keyframes pulse {  
          0%, 100% { opacity: 1; }  
          50% { opacity: 0.4; }  
        }  
        \* { box-sizing: border-box; }  
        ::-webkit-scrollbar { width: 6px; }  
        ::-webkit-scrollbar-track { background: \#020817; }  
        ::-webkit-scrollbar-thumb { background: \#1e293b; border-radius: 3px; }  
      \`}\</style\>  
    \</div\>  
  );  
}  
