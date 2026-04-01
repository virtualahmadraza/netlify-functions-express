# Business Copilot MVP (Netlify Functions + React)

This repository now includes a working MVP for an admin AI chat interface that combines:

- structured analytics answers (simulated SQL workflow)
- unstructured retrieval over ingested docs (lightweight RAG)
- grounded responses with citations, confidence, and checks

## What is implemented

### Frontend
- Chat UI for admin-style business questions
- Evidence panel showing citations and quality checks
- Confidence + trace ID visibility for debugging

### Backend (`/.netlify/functions/ai-chat`)
- `POST /query` — ask a question and receive grounded response
- `GET /documents` — list indexed documents
- `POST /documents` — ingest a new document into retrieval index
- `GET /health` — service status

## Example questions
- Which distributor earned the highest revenue last month?
- Show agent performance trends.
- Why did returns increase?

## Run locally

```bash
npm install
npm start
```

- React app: `http://localhost:3000`
- Netlify Functions: `http://localhost:9000`

## Deploy

```bash
npm run build
```

Deploy to Netlify using this repo's `netlify.toml` settings.

## Notes

- This is an MVP with in-memory datasets and lightweight retrieval logic for fast iteration.
- For production hardening, replace the in-memory data with your real SQL warehouse and vector DB.
