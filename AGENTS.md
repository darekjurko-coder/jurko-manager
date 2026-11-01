# JURKO MANAGER

Polish-language finance demo for JURKO, published at https://koszty.jurkoseo.pl.

## Structure
- `index.html`: standalone HTML, CSS and JavaScript app. No build dependencies.
- `CNAME`: custom domain; preserve it.

## Working rules
- Keep all user-facing text in Polish.
- This is a demo using localStorage, without authentication, a backend or accounting integrations. Preserve the visible demo notice until those features are actually implemented.
- Never commit credentials or real company financial records.
- Keep unrelated JURKO websites, repositories and DNS records unchanged.
- Check changed interactions in a browser; use synthetic records only and remove test records afterward.

## Preview
Run `python3 -m http.server 8000` from the repository root, then visit http://localhost:8000.
