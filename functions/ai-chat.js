import express from 'express'
import cors from 'cors'
import bodyParser from 'body-parser'
import serverless from 'serverless-http'
import fetch from 'isomorphic-fetch'

const app = express()
const router = express.Router()

router.use(cors())
router.use(bodyParser.json())

const upstreamBase = process.env.BUSINESS_COPILOT_API || 'http://127.0.0.1:8000'

async function proxy(req, res, path, method) {
  try {
    const response = await fetch(upstreamBase + path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: method === 'GET' ? undefined : JSON.stringify(req.body || {})
    })

    const payload = await response.json()
    return res.status(response.status).json(payload)
  } catch (error) {
    return res.status(502).json({
      error: 'Python backend unavailable',
      detail: error.message,
      hint: 'Start backend with: uvicorn app:app --host 0.0.0.0 --port 8000 (from backend/)'
    })
  }
}

router.get('/health', (req, res) => proxy(req, res, '/health', 'GET'))
router.get('/documents', (req, res) => proxy(req, res, '/documents', 'GET'))
router.post('/documents', (req, res) => proxy(req, res, '/documents', 'POST'))
router.post('/query', (req, res) => proxy(req, res, '/query', 'POST'))

const basePath = process.env.NODE_ENV === 'dev' ? '/ai-chat' : '/.netlify/functions/ai-chat'
app.use(basePath, router)

exports.handler = serverless(app)
