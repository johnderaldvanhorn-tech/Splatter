#!/bin/bash
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"
printf '\033]0;Splatter Innovations Installer\007'
echo ""
echo "========================================"
echo "  SPLATTER INNOVATIONS - MAC INSTALLER"
echo "========================================"
echo ""

if ! command -v node >/dev/null 2>&1; then
  echo "Node.js 18 or newer is required."
  if command -v brew >/dev/null 2>&1; then
    read -r -p "Install Node.js with Homebrew now? [Y/n] " answer
    answer=${answer:-Y}
    if [[ "$answer" =~ ^[Yy]$ ]]; then brew install node; else exit 1; fi
  else
    echo "Install Node.js from https://nodejs.org, then run this file again."
    read -r -p "Press Return to close..."
    exit 1
  fi
fi

MAJOR=$(node -p "process.versions.node.split('.')[0]")
if [ "$MAJOR" -lt 18 ]; then echo "Node.js 18+ required. Current: $(node -v)"; exit 1; fi

mkdir -p logs

# Kill any existing server on port 4173, regardless of PID file
if command -v lsof >/dev/null 2>&1; then
  OLD_PIDS=$(lsof -t -i:4173 2>/dev/null || true)
  if [ -n "$OLD_PIDS" ]; then
    echo "Stopping old server(s) on port 4173: $OLD_PIDS"
    kill -9 $OLD_PIDS 2>/dev/null || true
    sleep 1
  fi
fi

# Also kill our own recorded PID if still alive
if [ -f .splatter.pid ] && kill -0 "$(cat .splatter.pid)" 2>/dev/null; then
  kill -9 "$(cat .splatter.pid)" 2>/dev/null || true
  rm -f .splatter.pid
fi

echo "Starting Splatter Innovations server..."
echo "   Directory: $DIR"
nohup node server.js > logs/server.log 2>&1 &
echo $! > .splatter.pid
sleep 2

# Verify the server is responding
if curl -s http://localhost:4173/api/health | grep -q '"version":"1.7.1"'; then
  echo "Server is running correctly."
else
  echo "Warning: server did not respond as expected. Check logs/server.log"
fi

open "http://localhost:4173"
echo ""
echo "Site opened at http://localhost:4173"
echo "Default login: admin / splatter"
echo "Use stop-site.command to stop the local server."
echo ""
read -r -p "Press Return to close this installer..."
