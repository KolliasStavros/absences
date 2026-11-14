#!/usr/bin/env python3
"""Build 1.3.0 from the official 1.2.1 distribution plus the reviewed feature allowlist."""
from pathlib import Path
import argparse
import hashlib
import json
import shutil
import subprocess
import tempfile
import zipfile

FILES = '''admin/header.php admin/import.php admin/marks.php admin/myschool_import.php
admin/settings.php admin/students.php admin/teachers.php teacher/marks.php
api/save_marks.php api/student_email.php api/toggle_student_absence.php
includes/absence_history_email.php includes/bulk_notification_preview.php
includes/email_templates.php includes/email_template_settings.php includes/marks_enabled.php
includes/marks_import.php includes/myschool_import.php includes/teacher_lifecycle.php
includes/teacher_assignment_import_page.php assets/css/email-template-settings.css
assets/css/myschool-import.css bin/migrate_teacher_registry.php
migrations/20261114_teacher_registry.sql schema.sql'''.split()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def checksum(path):
    path.with_suffix(path.suffix + '.sha256').write_text(digest(path) + '  ' + path.name + '\n', encoding='ascii')


def build(key):
    root = Path(__file__).resolve().parent.parent
    app = root / 'public_html' if (root / 'public_html').is_dir() else root
    baseline = root / 'release/absences-public-1.2.1'
    target = root / 'release/absences-public-1.3.0'
    archive = target.parent / (target.name + '.zip')
    update = target.parent / 'absences-update-1.2.1-to-1.3.0.zip'
    if any(p.exists() for p in [target, archive, update]):
        raise RuntimeError('Refusing to overwrite an existing release.')
    # Verify the baseline before relying on it.
    baseline_manifest = json.loads((baseline / 'FILE-MANIFEST.json').read_text(encoding='utf-8'))
    for name, expected in baseline_manifest['files'].items():
        if digest(baseline / name) != expected:
            raise RuntimeError('Baseline mismatch: ' + name)
    shutil.copytree(baseline, target)
    public = target / 'public_html'
    for name in FILES:
        source = app / name
        if source.is_symlink():
            raise RuntimeError('Symlink refused: ' + name)
        dest = public / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, dest)
    config = public / 'config/config.php'
    text = config.read_text(encoding='utf-8')
    if "$installedVersion='1.2.1';" not in text:
        raise RuntimeError('Unexpected baseline version.')
    config.write_text(text.replace("$installedVersion='1.2.1';", "$installedVersion='1.3.0';"), encoding='utf-8')
    for name in ['MYSCHOOL-IMPORT.md', 'UPGRADE-1.3.0.md']:
        shutil.copyfile(root / 'docs' / name, target / 'docs' / name)
    (target / 'README.md').write_text('# Absences 1.3.0\n\nΝέα εγκατάσταση: [START-HERE.md](START-HERE.md).\n\nΥπάρχουσα 1.2.1: χρησιμοποιήστε το υπογεγραμμένο πακέτο αναβάθμισης και τον [οδηγό](docs/UPGRADE-1.3.0.md). Μην αντιγράψετε ολόκληρο το public ZIP πάνω σε υπάρχουσα εγκατάσταση.\n\nΕνιαία εισαγωγή MySchool, ΑΜ καθηγητών, ασφαλής αποχώρηση/αντικατάσταση και επεξεργάσιμα πρότυπα email.\n', encoding='utf-8')
    changed = []
    for file in sorted(public.rglob('*')):
        if file.is_file():
            rel = file.relative_to(public).as_posix()
            old = baseline / 'public_html' / rel
            if not old.is_file() or file.read_bytes() != old.read_bytes():
                changed.append(rel)
    if set(changed) != set(FILES) | {'config/config.php'}:
        raise RuntimeError('Unexpected public release delta: ' + repr(changed))
    # The official maintenance engine and backup coverage stay byte-identical.
    for name in ['includes/lifecycle.php', 'includes/lifecycle_restore.php', 'includes/maintenance_controller.php', 'admin/maintenance.php']:
        if digest(public / name) != digest(baseline / 'public_html' / name):
            raise RuntimeError('Maintenance baseline changed: ' + name)
    for file in target.rglob('*'):
        vendor = file.is_relative_to(public / 'includes/spreadsheet/vendor')
        if file.is_file() and (file.name in {'release-secret.key', 'database.php', 'local.php', 'production.php', 'private-config.php', 'installed.lock', 'install-access.php', 'INSTALLATION-KEY.txt'} or (not vendor and file.suffix.lower() in {'.xls', '.xlsx', '.log', '.jsonl'})):
            raise RuntimeError('Private or unexpected artifact: ' + str(file))
    manifest = {p.relative_to(target).as_posix(): digest(p) for p in sorted(target.rglob('*')) if p.is_file() and p.name != 'FILE-MANIFEST.json'}
    (target / 'FILE-MANIFEST.json').write_text(json.dumps({'format': 1, 'files': manifest, 'note': 'Clean common distribution; no installation credentials or school data.'}, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    with zipfile.ZipFile(archive, 'x', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for p in sorted(target.rglob('*')):
            if p.is_file():
                z.write(p, p.relative_to(target).as_posix())
    checksum(archive)
    patch_files = sorted(FILES)
    # The signing tool requires secret and output outside application/source roots.
    with tempfile.TemporaryDirectory(prefix='absences-sign-130-') as temporary:
        work = Path(temporary)
        secret = work / 'signing.key'
        shutil.copyfile(key, secret)
        staging = work / 'source'
        staging.mkdir()
        for name in patch_files:
            dest = staging / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(public / name, dest)
        migration = '20261114_teacher_registry.sql'
        sql = 'ALTER TABLE `{{prefix}}users` ADD COLUMN teacher_registry_number VARCHAR(30) DEFAULT NULL, ADD UNIQUE KEY unique_teacher_registry_number (teacher_registry_number);\n'
        (staging / migration).write_text(sql, encoding='utf-8')
        spec = {'from_version': '1.2.1', 'version': '1.3.0', 'min_php': '8.2.0',
                'description': 'Ενιαία εισαγωγή MySchool, ΑΜ καθηγητών, αποχώρηση/αντικατάσταση και πρότυπα email. Προσθέτει μία κενή μοναδική στήλη στους χρήστες. Απαιτεί ALTER. Διαβάστε UPGRADE-1.3.0 πριν την εγκατάσταση.',
                'files': patch_files, 'migrations': [migration], 'new_tables': [], 'delete': []}
        spec_file = work / 'manifest.json'
        spec_file.write_text(json.dumps(spec, ensure_ascii=False, indent=2), encoding='utf-8')
        signed = work / update.name
        subprocess.run(['php', '-d', 'xdebug.mode=off', str(app / 'bin/sign_update.php'), str(spec_file), str(staging), str(secret), str(signed)], check=True)
        shutil.copyfile(signed, update)
    checksum(update)
    (root / 'release/release-1.3.0-manifest.json').write_text(json.dumps({'version': '1.3.0', 'base': '1.2.1', 'files': patch_files, 'migrations': ['20261114_teacher_registry.sql'], 'public_sha256': digest(archive), 'update_sha256': digest(update)}, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'public': archive.name, 'update': update.name, 'runtime_files': len(patch_files), 'migrations': 1, 'public_sha256': digest(archive), 'update_sha256': digest(update)}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--key', type=Path, required=True)
    build(parser.parse_args().key)
