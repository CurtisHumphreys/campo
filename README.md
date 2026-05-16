# CampOffice Backup

Automated backup of the CampOffice app from ForgeBox.

| Item | Location |
|------|----------|
| Source code | `source/` |
| Web files | `web/` |
| API files | `api/` |
| Database dump | `database/campoffice-dump.sql` |

**Last backup:** 2026-05-16 21:06:08 ACST

## Restore

```bash
# Restore source
sudo rsync -a source/ /opt/forgebox/apps/campoffice/

# Restore web + api
sudo rsync -a web/ /var/www/html/apps/campoffice/
sudo rsync -a api/ /var/www/html/api/campoffice/

# Restore database (will replace existing data)
mysql --user=forgebox --password=<pass> forgebox < database/campoffice-dump.sql
```
