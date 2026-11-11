#!/usr/bin/env python3
"""Build a file-only 1.2.0 -> 1.2.1 signed patch from clean distributions."""
from pathlib import Path
import argparse
import hashlib
import json
import shutil
import subprocess
import tempfile


def build(key: Path):
    root = Path(__file__).resolve().parent.parent
    public = root / 'release/absences-public-1.2.1/public_html'
    baseline = root / 'release/absences-public-1.2.0/public_html'
    target = root / 'release/absences-update-1.2.0-to-1.2.1.zip'
    expected = {'admin/index.php', 'api/get_live_attendance.php',
                'assets/css/school-calendar.css', 'includes/school_calendar_page.php',
                'includes/school_calendar_holidays.php'}
    changed = set()
    for path in public.rglob('*'):
        if not path.is_file():
            continue
        rel = path.relative_to(public).as_posix()
        old = baseline / rel
        if not old.is_file() or old.read_bytes() != path.read_bytes():
            changed.add(rel)
    if changed != expected | {'config/config.php'}:
        raise RuntimeError('Unexpected release delta: ' + str(sorted(changed)))
    if target.exists():
        raise RuntimeError('Refusing to overwrite existing release')
    # Private temporary key and signer output remain outside all web/source roots.
    with tempfile.TemporaryDirectory(prefix='absences-sign-121-') as temp:
        work = Path(temp)
        private_key = work / 'signing.key'
        shutil.copyfile(key, private_key)
        staging = work / 'source'
        staging.mkdir()
        for rel in sorted(expected):
            dest = staging / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(public / rel, dest)
        manifest = {'from_version': '1.2.0', 'version': '1.2.1', 'min_php': '8.2.0',
                    'description': 'Αποδέσμευση συνεδρίας live API, backoff αποτυχιών και χρώματα ημερολογίου 2026–2027. Χωρίς SQL. Πρώτα backup και οδηγίες UPGRADE-1.2.1.',
                    'files': sorted(expected), 'migrations': [], 'delete': []}
        manifest_file = work / 'manifest.json'
        manifest_file.write_text(json.dumps(manifest, ensure_ascii=False), encoding='utf-8')
        output = work / target.name
        app = root / 'public_html' if (root / 'public_html').is_dir() else root
        subprocess.run(['php', '-d', 'xdebug.mode=off', str(app / 'bin/sign_update.php'),
                        str(manifest_file), str(staging), str(private_key), str(output)], check=True)
        shutil.copyfile(output, target)
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix('.zip.sha256').write_text(digest + '  ' + target.name + '\n', encoding='ascii')
    print(json.dumps({'package': target.name, 'files': sorted(expected), 'sha256': digest}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--key', type=Path, required=True)
    build(parser.parse_args().key)
