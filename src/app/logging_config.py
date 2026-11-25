"""Structured JSON logging configuration for the CMT dashboard.

Usage (in main.py):

    from app.logging_config import configure
    configure()   # call once at startup, before serving requests

Loggers then emit one JSON object per line to stdout, parseable by any log
collector (Docker, journald, Loki, etc.).

Fields on every record: ts, level, logger, msg. Callers may add extra
context via `extra={"event": ..., ...}`, which lands as sibling keys.
"""
from __future__ import annotations

import json
import logging
import logging.config
import sys
from datetime import datetime, timezone

# Shared formatter string used by dictConfig and by tests.
JSON_FORMAT = "%(jsonserialized)s"


class JsonFormatter(logging.Formatter):
    """Format a LogRecord as a single-line JSON object."""

    _RESERVED = {
        "name", "msg", "args", "levelname", "levelno", "pathname", "filename",
        "module", "exc_info", "exc_text", "stack_info", "lineno", "funcName",
        "created", "msecs", "relativeCreated", "thread", "threadName",
        "processName", "process", "message", "taskName",
    }

    def format(self, record: logging.LogRecord) -> str:
        payload: dict = {
            "ts": datetime.fromtimestamp(record.created, tz=timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "msg": record.getMessage(),
        }
        # Merge non-reserved keys set via extra={...}.
        for key, value in record.__dict__.items():
            if key not in self._RESERVED and not key.startswith("_"):
                payload[key] = self._coerce(value)
        if record.exc_info:
            payload["exc"] = self.formatException(record.exc_info)
        return json.dumps(payload, ensure_ascii=False, default=str)

    @staticmethod
    def _coerce(value):
        # Keep JSON-safe scalars; stringify anything exotic (uuid, path, etc.).
        if isinstance(value, (str, int, float, bool)) or value is None:
            return value
        return str(value)


def configure() -> None:
    """Idempotent dictConfig installing the JSON formatter on root."""
    logging.config.dictConfig(
        {
            "version": 1,
            "disable_existing_loggers": False,
            "formatters": {"json": {"()": JsonFormatter}},
            "handlers": {
                "stdout": {
                    "class": "logging.StreamHandler",
                    "stream": sys.stdout,
                    "formatter": "json",
                }
            },
            "root": {"handlers": ["stdout"], "level": "INFO"},
        }
    )