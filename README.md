# Business Copilot MVP (Python + OpenAI + FAISS + Netlify UI)

This MVP implements the requested architecture:

- **Python backend** (FastAPI) for orchestration and business logic
- **OpenAI integration** for SQL generation + grounded response synthesis
- **Vector database integration** using **FAISS** for semantic document retrieval
- **Structured + unstructured fusion** with citations, checks, and confidence scoring
- **Netlify function proxy** + React admin chat interface

## Architecture

```text
React Admin Chat UI
   -> Netlify Function: /.netlify/functions/ai-chat (proxy)
      -> FastAPI backend (backend/app.py)
         -> OpenAI Responses API (LLM + embeddings)
         -> SQLite analytics tables (SQL path)
         -> FAISS index over docs (RAG path)
```

## Features implemented

1. **Natural language → SQL**
   - OpenAI-generated read-only SQL (fallback deterministic SQL if no key)
   - SQL validation with `sqlglot` (blocks non-SELECT / unsafe statements)

2. **RAG over document corpus**
   - Document ingestion endpoint (`POST /documents`)
   - Embedding generation (OpenAI embedding model or local hash fallback)
   - FAISS similarity search (`retrieve`)

3. **Response guardrails**
   - Read-only SQL checks
   - Evidence-backed answer synthesis
   - Source attribution (SQL + docs)
   - Confidence score + validation checks

## API endpoints

### Python backend (`http://localhost:8000`)
- `GET /health`
- `GET /documents`
- `POST /documents`
- `POST /query`

### Netlify function proxy (`/.netlify/functions/ai-chat`)
- `GET /health`
- `GET /documents`
- `POST /documents`
- `POST /query`

## Local run

### 1) Python backend

```bash
cd backend
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
export OPENAI_API_KEY=your_key_here
uvicorn app:app --host 0.0.0.0 --port 8000
```

Optional env vars:
- `OPENAI_MODEL` (default: `gpt-4.1-mini`)
- `OPENAI_EMBEDDING_MODEL` (default: `text-embedding-3-small`)

### 2) Netlify + React app

In another terminal:

```bash
npm install
npm start
```

The UI sends requests to `/.netlify/functions/ai-chat/*`, and that function proxies to the Python backend (`BUSINESS_COPILOT_API` default `http://127.0.0.1:8000`).

## Why this fixes the previous version

- Uses **Python** as the core AI backend
- Uses **OpenAI APIs** for generation and embeddings
- Uses **vector index (FAISS)** for semantic retrieval
- Implements **multi-step workflow**: SQL generation/validation/execution + RAG retrieval + grounded synthesis + checks
