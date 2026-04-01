import express from 'express'
import cors from 'cors'
import bodyParser from 'body-parser'
import serverless from 'serverless-http'

const aiEngine = require('./ai-engine')

const app = express()
const router = express.Router()

router.use(cors())
router.use(bodyParser.json())

router.get('/health', (req, res) => {
  res.json({ status: 'ok', service: 'ai-chat-mvp' })
})

router.get('/documents', (req, res) => {
  res.json({ documents: aiEngine.listDocuments() })
})

router.post('/documents', (req, res) => {
  if (!req.body || !req.body.content) {
    return res.status(400).json({ error: 'content is required' })
  }

  const doc = aiEngine.ingestDocument(req.body)
  return res.status(201).json({ document: doc })
})

router.post('/query', (req, res) => {
  const question = req.body && req.body.question

  if (!question) {
    return res.status(400).json({ error: 'question is required' })
  }

  const response = aiEngine.buildResponse(question)
  return res.json({
    question,
    trace_id: 'trace-' + Date.now(),
    generated_at: new Date().toISOString(),
    ...response
  })
})

const basePath = process.env.NODE_ENV === 'dev' ? '/ai-chat' : '/.netlify/functions/ai-chat'
app.use(basePath, router)

exports.handler = serverless(app)
