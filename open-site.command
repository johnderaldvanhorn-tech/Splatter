#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

if [ -f .splatter.pid ] && kill -0 "$(cat .splatter.pid)" 2>/dev/null; then
  open "http://localhost:4173"
else
  ./install-and-run.command
fi
