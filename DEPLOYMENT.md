# Deployment (GitHub → Hostinger)

## Hostinger target
Upload to: public_html/campo (or your app folder)

## Files to upload
- All repo files EXCEPT anything ignored by .gitignore
- DO NOT upload config.example.php as config.php
- config.php must exist on Hostinger with real secrets

## Deployment steps (manual)
1. GitHub repo → Code → Download ZIP
2. Unzip locally
3. Upload contents into Hostinger target folder
4. Confirm service worker version bump if applicable (sw.js) and hard refresh

## Source of truth (avoid drift)
- GitHub is the source of truth.
- Do not edit production files in Hostinger directly.
- If an emergency hotfix is made in Hostinger, immediately apply the same change in GitHub, otherwise the next deploy may overwrite it.
