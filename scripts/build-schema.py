"""Buduje plik importu phpMyAdmin z modułów; --check wykrywa rozbieżności."""
from pathlib import Path
import json, sys
root = Path(__file__).resolve().parents[1] / 'server'
files = json.loads((root / 'migrations/manifest.json').read_text())
sql = '-- Wygenerowano z server/migrations; nie edytuj ręcznie.\n-- Pusty schemat, bez kont i danych firmowych.\n'
for file in files:
    sql += '\n-- Moduł: ' + file + '\n' + (root / 'migrations' / file).read_text()
path = root / 'schema.sql'
if '--check' in sys.argv:
    if path.read_text() != sql:
        raise SystemExit('schema.sql jest nieaktualny; uruchom python3 scripts/build-schema.py')
else:
    path.write_text(sql)
