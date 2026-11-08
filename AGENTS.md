# JURKO MANAGER

Polish-language owner portal for JURKO, published at https://koszty.jurkoseo.pl/index.html.

## Structure
- `index.html`: standalone HTML, CSS and JavaScript app. No build dependencies.
- `CNAME`: custom domain; preserve it.

## Working rules
- Keep all user-facing text in Polish.
- Finance, tasks and staff planning use MySQL and owner Google sign-in. Keep unfinished integrations explicitly marked as planned. Never put production records in localStorage.
- Never commit credentials or real company financial records.
- Keep unrelated JURKO websites, repositories and DNS records unchanged.
- Check changed interactions in a browser; use synthetic records only and remove test records afterward.

## Preview
Run `python3 -m http.server 8000` from the repository root, then visit http://localhost:8000.
