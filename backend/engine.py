import os
import sqlite3
from dataclasses import dataclass
from datetime import datetime
from typing import Dict, List, Optional, Tuple

import faiss
import numpy as np
import sqlglot
from openai import OpenAI


@dataclass
class RetrievedChunk:
    id: str
    source: str
    title: str
    content: str
    score: float


class CopilotEngine:
    def __init__(self) -> None:
        self.openai_model = os.getenv("OPENAI_MODEL", "gpt-4.1-mini")
        self.embedding_model = os.getenv("OPENAI_EMBEDDING_MODEL", "text-embedding-3-small")
        self.openai_client = OpenAI(api_key=os.getenv("OPENAI_API_KEY")) if os.getenv("OPENAI_API_KEY") else None
        self.embedding_dim = 1536 if self.openai_client else 128

        self.db = sqlite3.connect(":memory:", check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        self._setup_sql()

        self.docs: List[Dict[str, str]] = []
        self.doc_vectors: List[np.ndarray] = []
        self.faiss_index = faiss.IndexFlatIP(self.embedding_dim)
        self._seed_docs()

    def _setup_sql(self) -> None:
        cur = self.db.cursor()
        cur.executescript(
            """
            CREATE TABLE sales_monthly (
                month TEXT NOT NULL,
                distributor TEXT NOT NULL,
                revenue REAL NOT NULL,
                returns REAL NOT NULL
            );

            CREATE TABLE agent_performance (
                month TEXT NOT NULL,
                agent TEXT NOT NULL,
                close_rate REAL NOT NULL,
                avg_resolution_hours REAL NOT NULL,
                nps INTEGER NOT NULL
            );
            """
        )

        sales_rows = [
            ("2026-01", "Northwind Foods", 210000, 9000),
            ("2026-01", "Blue Ocean Retail", 184000, 7000),
            ("2026-01", "Urban Harvest", 198500, 8300),
            ("2026-02", "Northwind Foods", 228000, 10400),
            ("2026-02", "Blue Ocean Retail", 189500, 7600),
            ("2026-02", "Urban Harvest", 207200, 8700),
            ("2026-03", "Northwind Foods", 241200, 12100),
            ("2026-03", "Blue Ocean Retail", 196900, 9500),
            ("2026-03", "Urban Harvest", 215300, 10200),
        ]
        cur.executemany("INSERT INTO sales_monthly VALUES (?, ?, ?, ?)", sales_rows)

        agent_rows = [
            ("2026-01", "Ava", 0.51, 9.3, 62),
            ("2026-01", "Noah", 0.47, 11.1, 58),
            ("2026-01", "Mia", 0.56, 8.8, 66),
            ("2026-02", "Ava", 0.54, 8.9, 65),
            ("2026-02", "Noah", 0.50, 10.4, 61),
            ("2026-02", "Mia", 0.58, 8.2, 69),
            ("2026-03", "Ava", 0.57, 8.4, 68),
            ("2026-03", "Noah", 0.52, 9.8, 63),
            ("2026-03", "Mia", 0.60, 7.7, 72),
        ]
        cur.executemany("INSERT INTO agent_performance VALUES (?, ?, ?, ?, ?)", agent_rows)
        self.db.commit()

    def _seed_docs(self) -> None:
        seeded = [
            {
                "id": "doc-policy-returns-v3",
                "title": "Returns Policy Update v3",
                "source": "Operations Handbook",
                "content": "Returns review changed in February 2026. High-value orders now require dual approval. This raised return processing time but reduced fraud risk.",
            },
            {
                "id": "doc-sop-distributors",
                "title": "Distributor Incentive SOP",
                "source": "Sales SOP",
                "content": "Top-tier distributors receive volume incentives based on monthly net revenue. Northwind Foods reached tier 1 in March 2026.",
            },
            {
                "id": "doc-support-playbook",
                "title": "Support Agent Coaching Playbook",
                "source": "Support Wiki",
                "content": "Weekly coaching and macro templates improved first-contact resolution and customer NPS across Q1 2026.",
            },
        ]
        for doc in seeded:
            self.ingest_document(doc)

    def _hash_embedding(self, text: str) -> np.ndarray:
        vec = np.zeros(self.embedding_dim, dtype="float32")
        tokens = [tok for tok in text.lower().split() if tok]
        for tok in tokens:
            vec[hash(tok) % self.embedding_dim] += 1
        norm = np.linalg.norm(vec) or 1.0
        return (vec / norm).astype("float32")

    def _embed(self, text: str) -> np.ndarray:
        if not self.openai_client:
            return self._hash_embedding(text)
        emb = self.openai_client.embeddings.create(model=self.embedding_model, input=text)
        arr = np.array(emb.data[0].embedding, dtype="float32")
        norm = np.linalg.norm(arr) or 1.0
        return (arr / norm).astype("float32")

    def ingest_document(self, payload: Dict[str, str]) -> Dict[str, str]:
        doc = {
            "id": payload.get("id") or f"doc-{int(datetime.utcnow().timestamp())}",
            "title": payload.get("title", "Untitled"),
            "source": payload.get("source", "Uploaded source"),
            "content": payload.get("content", ""),
        }
        vector = self._embed(f"{doc['title']}\n{doc['content']}")
        self.docs.append(doc)
        self.doc_vectors.append(vector)
        self.faiss_index.add(np.expand_dims(vector, axis=0))
        return doc

    def retrieve(self, question: str, top_k: int = 4) -> List[RetrievedChunk]:
        if not self.docs:
            return []
        q_vec = self._embed(question)
        scores, indexes = self.faiss_index.search(np.expand_dims(q_vec, axis=0), min(top_k, len(self.docs)))
        out: List[RetrievedChunk] = []
        for score, idx in zip(scores[0], indexes[0]):
            if idx < 0:
                continue
            doc = self.docs[idx]
            out.append(
                RetrievedChunk(
                    id=doc["id"],
                    source=doc["source"],
                    title=doc["title"],
                    content=doc["content"],
                    score=float(score),
                )
            )
        return out

    def _schema(self) -> str:
        return (
            "sales_monthly(month TEXT, distributor TEXT, revenue REAL, returns REAL)\n"
            "agent_performance(month TEXT, agent TEXT, close_rate REAL, avg_resolution_hours REAL, nps INTEGER)"
        )

    def _generate_sql(self, question: str) -> str:
        if not self.openai_client:
            q = question.lower()
            if "highest revenue" in q or "top distributor" in q:
                return "SELECT distributor, revenue FROM sales_monthly WHERE month = '2026-03' ORDER BY revenue DESC LIMIT 1;"
            if "agent" in q and ("trend" in q or "performance" in q):
                return "SELECT agent, month, close_rate, avg_resolution_hours, nps FROM agent_performance WHERE month BETWEEN '2026-01' AND '2026-03' ORDER BY agent, month;"
            return "SELECT month, SUM(revenue) AS total_revenue, SUM(returns) AS total_returns FROM sales_monthly GROUP BY month ORDER BY month;"

        prompt = (
            "You generate only read-only SQLite SQL. Return SQL only.\n"
            "Rules: SELECT statements only, no joins outside provided tables, add LIMIT <= 200 when possible.\n"
            f"Schema:\n{self._schema()}\n"
            f"Question: {question}"
        )
        response = self.openai_client.responses.create(
            model=self.openai_model,
            input=prompt,
            temperature=0,
        )
        return response.output_text.strip()

    def _validate_sql(self, sql: str) -> None:
        parsed = sqlglot.parse_one(sql, read="sqlite")
        if parsed.key != "select":
            raise ValueError("Only SELECT queries are allowed")
        blocked = ["insert", "update", "delete", "drop", "alter", "create", "attach", "pragma"]
        lowered = sql.lower()
        if any(word in lowered for word in blocked):
            raise ValueError("Unsafe SQL blocked")

    def run_sql(self, question: str) -> Tuple[str, List[Dict[str, object]]]:
        sql = self._generate_sql(question)
        self._validate_sql(sql)
        rows = [dict(row) for row in self.db.execute(sql).fetchall()]
        return sql, rows

    def _compose_answer(self, question: str, sql: str, rows: List[Dict[str, object]], docs: List[RetrievedChunk]) -> str:
        doc_context = "\n".join([f"- {d.title}: {d.content}" for d in docs[:3]])
        row_context = str(rows[:20])

        if not self.openai_client:
            base = f"SQL result rows: {len(rows)}. "
            if rows:
                base += f"Top row: {rows[0]}. "
            if docs:
                base += "Document support: " + ", ".join([d.title for d in docs[:2]]) + "."
            return base

        prompt = (
            "Answer the business question using ONLY provided SQL rows and retrieved docs."
            "If evidence is insufficient, say so. Include concise business explanation.\n"
            f"Question: {question}\nSQL: {sql}\nRows: {row_context}\nDocs:\n{doc_context}"
        )
        response = self.openai_client.responses.create(
            model=self.openai_model,
            input=prompt,
            temperature=0.1,
        )
        return response.output_text.strip()

    def query(self, question: str) -> Dict[str, object]:
        sql, rows = self.run_sql(question)
        docs = self.retrieve(question)
        answer = self._compose_answer(question, sql, rows, docs)

        checks = [
            "SQL parsed and validated as read-only",
            "Answer generated from SQL rows and retrieved documents",
            "Citations attached for all sources",
        ]

        citations = [{"type": "sql", "sql": sql, "row_count": len(rows)}]
        citations.extend(
            [
                {
                    "type": "document",
                    "id": d.id,
                    "title": d.title,
                    "source": d.source,
                    "score": round(d.score, 4),
                }
                for d in docs[:3]
            ]
        )

        confidence = 0.65
        if rows and docs:
            confidence = 0.9
        elif rows or docs:
            confidence = 0.78

        return {
            "answer": answer,
            "confidence": confidence,
            "checks": checks,
            "citations": citations,
            "sql_rows": rows[:20],
        }
