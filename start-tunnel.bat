@echo off
setlocal

:: Set variables
set TUNNEL_TOKEN=your_cloudflare_tunnel_token_here

echo =======================================================
echo  OpenSquadron Cloudflare Tunnel Launcher
echo =======================================================
echo.
echo Make sure you have 'cloudflared' installed.
echo You can install it from: https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/
echo.
echo Launching tunnel for opensquadron.fracted.tech using token...
echo.

if "%TUNNEL_TOKEN%"=="your_cloudflare_tunnel_token_here" (
    echo [WARNING] Please edit start-tunnel.bat and replace 'your_cloudflare_tunnel_token_here' with your actual Cloudflare Tunnel token.
    echo.
)

cloudflared tunnel run --token %TUNNEL_TOKEN%

pause
