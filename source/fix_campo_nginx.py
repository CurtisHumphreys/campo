#!/usr/bin/env python3
"""
Fix campo.urbantek.online to proxy through the campoffice VirtualHost
so the public intranet is served at campo.urbantek.online/intranet,
and the root / redirects there automatically.
"""
import subprocess

CONFIG_PATH = '/etc/nginx/sites-available/campo'

NEW_CONFIG = r"""server {
    listen 80;
    listen [::]:80;
    server_name campo.urbantek.online;

    # Root redirects to the public intranet
    location = / {
        return 302 /intranet;
    }

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host campoffice.nix.local;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 120s;
        client_max_body_size 64M;
    }
}

server {
    listen 80;
    server_name campo.nix.local;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name campo.nix.local;

    ssl_certificate     /etc/ssl/nix/nix.crt;
    ssl_certificate_key /etc/ssl/nix/nix.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    # Root redirects to the public intranet
    location = / {
        return 302 /intranet;
    }

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host campoffice.nix.local;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 120s;
        client_max_body_size 64M;
    }
}
"""

with open(CONFIG_PATH, 'w') as f:
    f.write(NEW_CONFIG)
print(f"Written: {CONFIG_PATH}")

result = subprocess.run(['nginx', '-t'], capture_output=True, text=True)
print(result.stdout)
print(result.stderr)
if result.returncode != 0:
    print("nginx -t FAILED — config not reloaded")
    exit(1)

result = subprocess.run(['systemctl', 'reload', 'nginx'], capture_output=True, text=True)
if result.returncode == 0:
    print("nginx reloaded successfully")
else:
    print("reload failed:", result.stderr)
