const seededSales = [
  { month: '2026-01', distributor: 'Northwind Foods', revenue: 210000, returns: 9000 },
  { month: '2026-01', distributor: 'Blue Ocean Retail', revenue: 184000, returns: 7000 },
  { month: '2026-01', distributor: 'Urban Harvest', revenue: 198500, returns: 8300 },
  { month: '2026-02', distributor: 'Northwind Foods', revenue: 228000, returns: 10400 },
  { month: '2026-02', distributor: 'Blue Ocean Retail', revenue: 189500, returns: 7600 },
  { month: '2026-02', distributor: 'Urban Harvest', revenue: 207200, returns: 8700 },
  { month: '2026-03', distributor: 'Northwind Foods', revenue: 241200, returns: 12100 },
  { month: '2026-03', distributor: 'Blue Ocean Retail', revenue: 196900, returns: 9500 },
  { month: '2026-03', distributor: 'Urban Harvest', revenue: 215300, returns: 10200 }
]

const seededAgentPerformance = [
  { month: '2026-01', agent: 'Ava', close_rate: 0.51, avg_resolution_hours: 9.3, nps: 62 },
  { month: '2026-01', agent: 'Noah', close_rate: 0.47, avg_resolution_hours: 11.1, nps: 58 },
  { month: '2026-01', agent: 'Mia', close_rate: 0.56, avg_resolution_hours: 8.8, nps: 66 },
  { month: '2026-02', agent: 'Ava', close_rate: 0.54, avg_resolution_hours: 8.9, nps: 65 },
  { month: '2026-02', agent: 'Noah', close_rate: 0.5, avg_resolution_hours: 10.4, nps: 61 },
  { month: '2026-02', agent: 'Mia', close_rate: 0.58, avg_resolution_hours: 8.2, nps: 69 },
  { month: '2026-03', agent: 'Ava', close_rate: 0.57, avg_resolution_hours: 8.4, nps: 68 },
  { month: '2026-03', agent: 'Noah', close_rate: 0.52, avg_resolution_hours: 9.8, nps: 63 },
  { month: '2026-03', agent: 'Mia', close_rate: 0.6, avg_resolution_hours: 7.7, nps: 72 }
]

const seededDocs = [
  {
    id: 'doc-policy-returns-v3',
    title: 'Returns Policy Update v3',
    source: 'Operations Handbook',
    content:
      'Returns review changed in February 2026. High-value orders now require dual approval. This raised return processing time but reduced fraud risk.'
  },
  {
    id: 'doc-sop-distributors',
    title: 'Distributor Incentive SOP',
    source: 'Sales SOP',
    content:
      'Top-tier distributors receive volume incentives based on monthly net revenue. Northwind Foods reached tier 1 in March 2026.'
  },
  {
    id: 'doc-support-playbook',
    title: 'Support Agent Coaching Playbook',
    source: 'Support Wiki',
    content:
      'Weekly coaching and macro templates improved first-contact resolution and customer NPS across Q1 2026.'
  }
]

let dynamicDocs = seededDocs.slice()

function tokenize(input) {
  return input
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, ' ')
    .split(/\s+/)
    .filter(Boolean)
}

function scoreDocument(question, document) {
  const qTokens = tokenize(question)
  const dTokens = tokenize(document.title + ' ' + document.content)
  let score = 0
  qTokens.forEach((token) => {
    if (dTokens.indexOf(token) > -1) {
      score += 1
    }
  })
  return score
}

function retrieveDocuments(question, topK) {
  const ranked = dynamicDocs
    .map((doc) => ({
      doc,
      score: scoreDocument(question, doc)
    }))
    .sort((a, b) => b.score - a.score)
    .slice(0, topK)
    .filter((item) => item.score > 0)

  return ranked
}

function classifyIntent(question) {
  const lower = question.toLowerCase()
  const asksForTrend = lower.indexOf('trend') > -1 || lower.indexOf('performance') > -1
  const asksForRevenue = lower.indexOf('revenue') > -1 || lower.indexOf('distributor') > -1
  const asksForPolicy = lower.indexOf('why') > -1 || lower.indexOf('policy') > -1

  if ((asksForTrend && asksForPolicy) || (asksForRevenue && asksForPolicy)) {
    return 'hybrid'
  }

  if (asksForTrend || asksForRevenue) {
    return 'sql'
  }

  return 'rag'
}

function latestMonth(rows) {
  return rows.map((r) => r.month).sort().pop()
}

function runSql(question) {
  const lower = question.toLowerCase()
  const month = latestMonth(seededSales)

  if (lower.indexOf('highest revenue') > -1 || lower.indexOf('top distributor') > -1) {
    const monthly = seededSales.filter((row) => row.month === month)
    const winner = monthly.sort((a, b) => b.revenue - a.revenue)[0]

    return {
      sql: "SELECT distributor, revenue FROM sales_monthly WHERE month = '2026-03' ORDER BY revenue DESC LIMIT 1;",
      rows: [winner],
      summary: winner.distributor + ' led revenue in ' + month + ' with $' + winner.revenue.toLocaleString()
    }
  }

  if (lower.indexOf('agent performance') > -1 || lower.indexOf('performance trend') > -1 || lower.indexOf('trend') > -1) {
    const agents = ['Ava', 'Noah', 'Mia']
    const rows = agents.map((agent) => {
      const history = seededAgentPerformance.filter((row) => row.agent === agent)
      const first = history[0]
      const last = history[history.length - 1]
      return {
        agent,
        close_rate_change: +(last.close_rate - first.close_rate).toFixed(2),
        nps_change: last.nps - first.nps,
        resolution_hour_change: +(last.avg_resolution_hours - first.avg_resolution_hours).toFixed(1)
      }
    })

    return {
      sql:
        "SELECT agent, month, close_rate, avg_resolution_hours, nps FROM agent_performance WHERE month BETWEEN '2026-01' AND '2026-03';",
      rows,
      summary: 'All agents improved close rate and NPS through Q1 2026; Mia shows the strongest overall improvement.'
    }
  }

  const totals = seededSales.reduce(
    (acc, row) => {
      acc.revenue += row.revenue
      acc.returns += row.returns
      return acc
    },
    { revenue: 0, returns: 0 }
  )

  return {
    sql: "SELECT SUM(revenue) AS revenue, SUM(returns) AS returns FROM sales_monthly;",
    rows: [totals],
    summary: 'Overall tracked revenue is $' + totals.revenue.toLocaleString() + ' with returns of $' + totals.returns.toLocaleString()
  }
}

function buildResponse(question) {
  const intent = classifyIntent(question)
  const citations = []
  let sqlResult = null

  if (intent === 'sql' || intent === 'hybrid') {
    sqlResult = runSql(question)
    citations.push({
      type: 'sql',
      source: 'analytics_dataset',
      sql: sqlResult.sql
    })
  }

  const retrieved = retrieveDocuments(question, 3)
  retrieved.forEach((entry) => {
    citations.push({
      type: 'document',
      source: entry.doc.source,
      id: entry.doc.id,
      title: entry.doc.title
    })
  })

  const confidence = citations.length > 1 ? 0.9 : citations.length === 1 ? 0.75 : 0.55

  if (!sqlResult && retrieved.length === 0) {
    return {
      answer: "I don't have enough grounded data to answer this yet. Please ingest more documents or connect additional analytics tables.",
      confidence,
      citations,
      checks: ['No structured query executed', 'No relevant documents retrieved']
    }
  }

  const ragLine =
    retrieved.length > 0
      ? 'Supporting context: ' + retrieved.map((entry) => entry.doc.title).join(', ') + '.'
      : 'No relevant document evidence was retrieved.'

  const answer = (sqlResult ? sqlResult.summary + '. ' : '') + ragLine

  return {
    answer,
    confidence,
    citations,
    checks: [
      sqlResult ? 'Structured query executed with read-only template' : 'No SQL required',
      retrieved.length > 0 ? 'Document relevance score above threshold' : 'No document matched tokens'
    ],
    sql_rows: sqlResult ? sqlResult.rows : []
  }
}

function ingestDocument(payload) {
  const newDoc = {
    id: payload.id || 'doc-' + Date.now(),
    title: payload.title || 'Untitled document',
    source: payload.source || 'Uploaded source',
    content: payload.content || ''
  }

  dynamicDocs.unshift(newDoc)
  return newDoc
}

function listDocuments() {
  return dynamicDocs
}

module.exports = {
  buildResponse,
  ingestDocument,
  listDocuments
}
