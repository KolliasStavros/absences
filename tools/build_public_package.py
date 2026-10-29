#!/usr/bin/env python3
"""SPDX-License-Identifier: GPL-3.0-only. Build a clean, unprepared source distribution."""
from pathlib import Path
import argparse, hashlib, json, shutil, zipfile

def build(destination: Path):
    project=Path(__file__).resolve().parent.parent
    source=project/'public_html' if (project/'public_html').is_dir() else project
    destination=destination.resolve(); archive=destination.with_name(destination.name+'.zip')
    for folder in ['admin','teacher','api','assets','includes','bin','migrations','config','docs','tools']:
        if destination.is_relative_to(source/folder) or destination.is_relative_to(project/folder):
            raise RuntimeError('The destination must not be inside a source directory being packaged.')
    if destination.exists() or archive.exists():
        raise RuntimeError('Output already exists. Choose a new destination; no existing package is overwritten.')
    destination.parent.mkdir(parents=True,exist_ok=True)
    destination.mkdir(); public=destination/'public_html';public.mkdir()
    def copy(src,dst):
        if src.is_symlink():raise RuntimeError('Symlinks are not allowed in a release: '+str(src))
        dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
    def tree(src,dst):
        for item in sorted(src.rglob('*')):
            if item.is_symlink():raise RuntimeError('Symlink found: '+str(item))
            if item.is_file():copy(item,dst/item.relative_to(src))
    for name in ['.htaccess','index.php','install.php','license.php','forgot-password.php','reset-password.php','logout.php','schema.sql','manifest.json','sw.js']:
        copy(source/name,public/name)
    for name in ['admin','teacher','api','assets','includes','bin','migrations']:
        tree(source/name,public/name)
    for name in ['config.php','.htaccess']:copy(source/'config'/name,public/'config'/name)
    tree(project/'docs',destination/'docs')
    for name in ['LICENSE','NOTICE','THIRD-PARTY-NOTICES.md']:
        copy(project/name,destination/name);copy(project/name,public/name)
    for name in ['create-installation-key.html','prepare-installation.php','build_public_package.py']:
        copy(project/'tools'/name,destination/'tools'/name)
    copy(project/'docs/PUBLIC-DISTRIBUTION.md',destination/'START-HERE.md')
    (destination/'README.md').write_text('# Absences — Κοινό δημόσιο πακέτο\n\nΞεκινήστε από το [START-HERE.md](START-HERE.md). Το πακέτο δεν περιέχει ενεργό κλειδί ή στοιχεία σχολείου.\n\nΆδεια εφαρμογής: GPL-3.0-only, πλήρες κείμενο στο LICENSE. Οι βιβλιοθήκες διατηρούν τις δικές τους άδειες.\n',encoding='utf-8')
    lock=json.loads((source/'includes/spreadsheet/composer.lock').read_text(encoding='utf-8'))
    license_inventory=[]
    for package in lock['packages']:
        vendor=source/'includes/spreadsheet/vendor'/package['name']
        notices=[p for p in vendor.rglob('*') if p.is_file() and p.name.lower().startswith(('license','licence','copying','notice'))]
        if not notices:raise RuntimeError('No license file for '+package['name'])
        entry={'package':package['name'],'version':package['version'],'licenses':package.get('license',[]),'files':[]}
        for notice in notices:
            target=destination/'third-party-licenses'/package['name']/notice.relative_to(vendor)
            copy(notice,target);entry['files'].append({'path':target.relative_to(destination).as_posix(),'sha256':hashlib.sha256(target.read_bytes()).hexdigest()})
        license_inventory.append(entry)
    copy(source/'includes/spreadsheet/vendor/composer/LICENSE',destination/'third-party-licenses/composer-autoload/LICENSE')
    (destination/'THIRD-PARTY-LICENSES.json').write_text(json.dumps(license_inventory,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    expected_config={'config.php','.htaccess'}
    if {p.name for p in (public/'config').iterdir()}!=expected_config:raise RuntimeError('Unexpected configuration in package')
    if (destination/'INSTALLATION-KEY.txt').exists() or (public/'config/install-access.php').exists():raise RuntimeError('A public package must contain no prepared key')
    manifest={p.relative_to(destination).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(destination.rglob('*')) if p.is_file()}
    (destination/'FILE-MANIFEST.json').write_text(json.dumps({'format':1,'files':manifest,'note':'Original common distribution only. Local preparation intentionally adds a private key and config/install-access.php.'},ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    with zipfile.ZipFile(archive,'x',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for p in sorted(destination.rglob('*')):
            if p.is_file():z.write(p,p.relative_to(destination).as_posix())
    checksum=archive.with_suffix('.zip.sha256');checksum.write_text(hashlib.sha256(archive.read_bytes()).hexdigest()+'  '+archive.name+'\n',encoding='ascii')
    print(json.dumps({'folder':str(destination),'zip':str(archive),'sha256_file':str(checksum),'files':len(manifest)+1,'prepared_key':False},ensure_ascii=False))

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--destination',type=Path,required=True,help='New output directory; existing paths are never overwritten.')
    args=parser.parse_args();build(args.destination)
