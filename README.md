# CampOffice Backup

Automated backup of the CampOffice app from ForgeBox.

| Item | Location |
|------|----------|
| Source code | `source/` |
| Web files | `web/` |
| API files | `api/` |
| Database dump | `database/campoffice-dump.sql` |

**Last backup:** 2026-05-22 03:00:02 ACST

## Restore

```bash
sudo rsync -a source/ /opt/forgebox/apps/campoffice/
sudo rsync -a web/ /var/www/html/apps/campoffice/
sudo rsync -a api/ /var/www/html/api/campoffice/
mysql --user=forgebox --password=<pass> forgebox < database/campoffice-dump.sql
```
