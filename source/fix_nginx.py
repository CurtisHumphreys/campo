ws = """    location /ws/claude-code {
        proxy_pass http://127.0.0.1:9102;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
    }

"""
for path in [
    '/etc/nginx/sites-available/campoffice',
    '/etc/nginx/sites-available/domain-connect-campoffice-urbantek-online',
]:
    c = open(path).read()
    if '/ws/claude-code' not in c:
        c = c.replace('    location / {', ws + '    location / {')
        open(path, 'w').write(c)
        print('fixed ' + path)
    else:
        print('already present: ' + path)
