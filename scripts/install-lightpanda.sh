#!/bin/bash
#
# Lightpanda Installation Script
#
# Downloads and installs Lightpanda headless browser.
# Note: Lightpanda is still in beta and may not work for all websites.
#
# Usage:
#   ./scripts/install-lightpanda.sh         # Install
#   ./scripts/install-lightpanda.sh start   # Start CDP server
#   ./scripts/install-lightpanda.sh stop    # Stop CDP server
#   ./scripts/install-lightpanda.sh status  # Check status
#

set -e

INSTALL_DIR="$(dirname "$0")/../storage/lightpanda"
BINARY_NAME="lightpanda"
PID_FILE="$INSTALL_DIR/lightpanda.pid"
LOG_FILE="$INSTALL_DIR/lightpanda.log"
PORT="${LIGHTPANDA_PORT:-9222}"

# Detect OS and architecture
detect_platform() {
    local os=$(uname -s | tr '[:upper:]' '[:lower:]')
    local arch=$(uname -m)

    case "$os" in
        linux)
            case "$arch" in
                x86_64)
                    echo "x86_64-linux"
                    ;;
                aarch64)
                    echo "aarch64-linux"
                    ;;
                *)
                    echo "Unsupported architecture: $arch" >&2
                    exit 1
                    ;;
            esac
            ;;
        darwin)
            case "$arch" in
                arm64|aarch64)
                    echo "aarch64-macos"
                    ;;
                x86_64)
                    echo "x86_64-macos"
                    ;;
                *)
                    echo "Unsupported architecture: $arch" >&2
                    exit 1
                    ;;
            esac
            ;;
        *)
            echo "Unsupported OS: $os" >&2
            exit 1
            ;;
    esac
}

install() {
    echo "Installing Lightpanda..."

    mkdir -p "$INSTALL_DIR"

    local platform=$(detect_platform)
    local download_url="https://github.com/lightpanda-io/browser/releases/download/nightly/lightpanda-$platform"

    echo "Downloading from: $download_url"

    curl -L -o "$INSTALL_DIR/$BINARY_NAME" "$download_url"
    chmod +x "$INSTALL_DIR/$BINARY_NAME"

    echo "Lightpanda installed to: $INSTALL_DIR/$BINARY_NAME"
    echo ""
    echo "To start the CDP server, run:"
    echo "  $0 start"
}

start() {
    if [ ! -f "$INSTALL_DIR/$BINARY_NAME" ]; then
        echo "Lightpanda not installed. Run: $0 install"
        exit 1
    fi

    if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
        echo "Lightpanda is already running (PID: $(cat "$PID_FILE"))"
        exit 0
    fi

    echo "Starting Lightpanda CDP server on port $PORT..."

    nohup "$INSTALL_DIR/$BINARY_NAME" serve --host 127.0.0.1 --port "$PORT" > "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"

    # Wait a moment and check if it started
    sleep 2

    if kill -0 $(cat "$PID_FILE") 2>/dev/null; then
        echo "Lightpanda started (PID: $(cat "$PID_FILE"))"
        echo "CDP endpoint: http://127.0.0.1:$PORT"
    else
        echo "Failed to start Lightpanda. Check log: $LOG_FILE"
        cat "$LOG_FILE"
        exit 1
    fi
}

stop() {
    if [ -f "$PID_FILE" ]; then
        local pid=$(cat "$PID_FILE")
        if kill -0 "$pid" 2>/dev/null; then
            echo "Stopping Lightpanda (PID: $pid)..."
            kill "$pid"
            rm -f "$PID_FILE"
            echo "Lightpanda stopped."
        else
            echo "Lightpanda is not running (stale PID file)"
            rm -f "$PID_FILE"
        fi
    else
        echo "Lightpanda is not running."
    fi
}

status() {
    if [ -f "$PID_FILE" ] && kill -0 $(cat "$PID_FILE") 2>/dev/null; then
        echo "Lightpanda is running (PID: $(cat "$PID_FILE"))"
        echo "CDP endpoint: http://127.0.0.1:$PORT"

        # Try to check if it's responding
        if curl -s "http://127.0.0.1:$PORT/json/version" > /dev/null 2>&1; then
            echo "Status: Responding to requests"
        else
            echo "Status: Not responding (may be starting up)"
        fi
    else
        echo "Lightpanda is not running."
    fi
}

# Docker alternative
docker_start() {
    echo "Starting Lightpanda via Docker..."
    docker run -d --name lightpanda -p "$PORT:9222" lightpanda/browser:nightly
    echo "Lightpanda started via Docker on port $PORT"
}

docker_stop() {
    echo "Stopping Lightpanda Docker container..."
    docker stop lightpanda 2>/dev/null || true
    docker rm lightpanda 2>/dev/null || true
    echo "Done."
}

# Main command handler
case "${1:-install}" in
    install)
        install
        ;;
    start)
        start
        ;;
    stop)
        stop
        ;;
    restart)
        stop
        sleep 1
        start
        ;;
    status)
        status
        ;;
    docker-start)
        docker_start
        ;;
    docker-stop)
        docker_stop
        ;;
    *)
        echo "Usage: $0 {install|start|stop|restart|status|docker-start|docker-stop}"
        exit 1
        ;;
esac
