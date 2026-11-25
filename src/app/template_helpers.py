"""Jinja template helpers — made available in every template context."""


def badge_class(type_name: str) -> str:
    """Map an anomaly type to a Bootstrap badge color class."""
    return {
        "unbalanced": "warning",
        "orphan": "danger",
        "bad_date": "info",
        "bad_dk": "secondary",
    }.get(type_name, "secondary")


def anomaly_label(type_name: str) -> str:
    """Map an anomaly type to its translatable label key."""
    return {
        "unbalanced": "Unbalanced",
        "orphan": "Orphan",
        "bad_date": "Bad date",
        "bad_dk": "Bad DK flag",
    }.get(type_name, type_name)
