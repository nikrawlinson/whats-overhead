#!/bin/bash
# Wait for the Wayland/X desktop session to settle
sleep 5

# Ensure PHP server is running
pgrep -f "php -S" || php -S 0.0.0.0:8001 -t /var/www/html &

# Wait 2 seconds for the server to bind
sleep 2

# Set the display variable (required for background autostart scripts)
export DISPLAY=:0

# Launch Chromium in Kiosk mode
chromium --kiosk --noerrdialogs --disable-infobars --no-first-run http://localhost:8001#!/bin/bash
