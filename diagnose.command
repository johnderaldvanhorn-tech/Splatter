#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"
echo "Splatter Innovations diagnostic"
echo "Directory: $DIR"
echo "Node version: $(node -v)"
echo "Server version in file: $(grep -o '"version":"[^"]*"' package.json || echo 'unknown')"
if [ -f .splatter.pid ]; then
  echo "Recorded PID: $(cat .splatter.pid)"
else
  echo "No recorded PID file"
fi
if command -v lsof >/dev/null 2>&1; then
  PIDS=$(lsof -t -i:4173 2>/dev/null || true)
  if [ -n "$PIDS" ]; then
    echo "Process(es) on port 4173: $PIDS"
    echo "Command lines:"
    ps -p $PIDS -o pid,command
  else
    echo "No process found on port 4173"
  fi
fi
echo ""
echo "Health check:"
curl -s http://localhost:4173/api/health || echo "(server not responding)"
echo ""
read -r -p "Press Return to close..."
