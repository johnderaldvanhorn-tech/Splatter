#!/bin/bash
cd "$(dirname "$0")"
if [ -f .splatter.pid ]; then
  PID=$(cat .splatter.pid)
  if kill -0 "$PID" 2>/dev/null; then kill "$PID"; fi
  rm -f .splatter.pid
fi
echo "Splatter Innovations server stopped."
read -r -p "Press Return to close..."
