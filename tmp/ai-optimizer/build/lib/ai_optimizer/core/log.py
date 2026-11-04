"""Logging setup for the CLI."""

from __future__ import annotations

import logging
import sys

LOGGER_NAME = "ai_optimizer"


def configure_logging(verbosity: int = 0, quiet: bool = False) -> None:
    """Send library logs to stderr; -v enables INFO, -vv DEBUG, -q errors only."""
    logger = logging.getLogger(LOGGER_NAME)
    for handler in list(logger.handlers):
        logger.removeHandler(handler)
    handler = logging.StreamHandler(sys.stderr)
    handler.setFormatter(logging.Formatter("%(levelname)s: %(message)s"))
    logger.addHandler(handler)
    logger.propagate = False
    if quiet:
        logger.setLevel(logging.ERROR)
    elif verbosity >= 2:
        logger.setLevel(logging.DEBUG)
    elif verbosity == 1:
        logger.setLevel(logging.INFO)
    else:
        logger.setLevel(logging.WARNING)


def get_logger(name: str) -> logging.Logger:
    """Return a child logger of the application logger."""
    return logging.getLogger(f"{LOGGER_NAME}.{name}")
