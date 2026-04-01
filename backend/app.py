from datetime import datetime
from typing import List, Optional

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from engine import CopilotEngine


app = FastAPI(title="Business Copilot API", version="0.2.0")
engine = CopilotEngine()


class QueryRequest(BaseModel):
    question: str = Field(min_length=3)


class IngestRequest(BaseModel):
    content: str = Field(min_length=5)
    title: Optional[str] = None
    source: Optional[str] = None
    id: Optional[str] = None


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "service": "business-copilot-python"}


@app.get("/documents")
def documents() -> dict:
    return {"documents": engine.docs}


@app.post("/documents")
def ingest(payload: IngestRequest) -> dict:
    doc = engine.ingest_document(payload.model_dump())
    return {"document": doc}


@app.post("/query")
def query(payload: QueryRequest) -> dict:
    try:
        result = engine.query(payload.question)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    return {
        "question": payload.question,
        "trace_id": f"trace-{int(datetime.utcnow().timestamp() * 1000)}",
        "generated_at": datetime.utcnow().isoformat() + "Z",
        **result,
    }
