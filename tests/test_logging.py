"""T003: structured JSON logging via dictConfig."""
from __future__ import annotations

import io
import json
import logging
import logging.config

from app.logging_config import JsonFormatter, configure


def _capture(handler_factory):
    """Attach a stream handler that returns its captured output."""
    buf = io.StringIO()
    handler = logging.StreamHandler(buf)
    handler.setFormatter(JsonFormatter())
    return handler, buf


def test_configure_is_idempotent():
    """configure() must be callable multiple times without error."""
    configure()
    configure()
    assert True  # reached without raising


def test_log_emits_parseable_json():
    """Records emitted by a configured logger must parse as JSON."""
    configure()

    # Attach our own stream handler to a dedicated logger so we can capture output.
    logger = logging.getLogger("cmt_test.logging")
    logger.setLevel(logging.DEBUG)
    logger.propagate = False  # don't double-up with root
    buf = io.StringIO()
    handler = logging.StreamHandler(buf)
    handler.setFormatter(JsonFormatter())
    logger.addHandler(handler)
    try:
        logger.info("hello world", extra={"event": "test_event", "x": 1})
    finally:
        logger.removeHandler(handler)

    line = buf.getvalue().strip()
    assert line, "expected a log line"
    record = json.loads(line)
    assert record["msg"] == "hello world"
    assert record["level"] == "INFO"
    assert record["event"] == "test_event"
    assert record["x"] == 1
    assert "ts" in record and "logger" in record


def test_extra_fields_round_trip():
    """Extra keyword fields must appear in the JSON output."""
    configure()
    logger = logging.getLogger("cmt_test.extra")
    logger.setLevel(logging.DEBUG)
    logger.propagate = False
    buf = io.StringIO()
    handler = logging.StreamHandler(buf)
    handler.setFormatter(JsonFormatter())
    logger.addHandler(handler)
    try:
        logger.warning("warn-event", extra={"event": "warn_event", "code": 42})
    finally:
        logger.removeHandler(handler)

    record = json.loads(buf.getvalue().strip())
    assert record["event"] == "warn_event"
    assert record["code"] == 42
    assert record["level"] == "WARNING"