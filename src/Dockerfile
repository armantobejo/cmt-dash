FROM python:3.11-slim
WORKDIR /app
COPY src/requirements.lock .
RUN pip install --no-cache-dir -r requirements.lock
COPY src/app/ ./app/
COPY tools/ ./tools/
EXPOSE 8000
# Set default DATABASE_URL, can be overridden at runtime
ENV DATABASE_URL=/data/CMT.db
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
