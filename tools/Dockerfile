FROM python:3.11-slim
WORKDIR /app
COPY src/requirements.lock .
RUN pip install --no-cache-dir -r requirements.lock
COPY tools/sync_db.py tools/schema.py .
ENV DBF_DIR=/data/dbf
ENV DATABASE_URL=/data/CMT.db
ENV DATA_DIR=/data
CMD ["python", "sync_db.py"]
