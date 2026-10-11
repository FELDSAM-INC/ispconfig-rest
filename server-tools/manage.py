#!/usr/bin/env python3
"""Compatibility stub: managers before 1.1.0 copy this file during 'ispconfig-rest update' (spec 058)."""
import sys

print("The server tools moved to the hosting extension: sudo ispconfig-rest extension:install hosting", file=sys.stderr)
sys.exit(1)
