#!/usr/bin/env python3
"""Build file-only 1.1.2 updates and a manual maintenance preparation from clean releases."""
from pathlib import Path
import argparse
import hashlib
import json
import shutil
import subprocess
import zipfile

PROTECTED={'.htaccess','install.php','includes/lifecycle.php','includes/lifecycle_guard.php',
           'includes/lifecycle_restore.php','admin/maintenance.php','bin/restore_backup.php'}
MANUAL=['includes/lifecycle.php','includes/lifecycle_restore.php','includes/maintenance_controller.php']

def checksum(file):
    file.with_suffix(file.suffix+'.sha256').write_text(hashlib.sha256(file.read_bytes()).hexdigest()+'  '+file.name+'\n',encoding='ascii')

def build(public_directory,baseline,staging,signing_output,key,php):
    project=Path(__file__).resolve().parent.parent
    source=project/'public_html' if (project/'public_html').is_dir() else project
    public=public_directory.resolve()/'public_html'; baseline=baseline.resolve()/'public_html'
    if not public.is_dir() or not baseline.is_dir() or staging.exists():
        raise RuntimeError('Missing clean distribution/baseline or staging already exists.')
    staging.mkdir(parents=True); signing_output.mkdir(parents=True,exist_ok=True)
    files=[]
    for file in sorted(public.rglob('*')):
        if not file.is_file(): continue
        rel=file.relative_to(public).as_posix()
        if rel in PROTECTED or rel in MANUAL or rel=='bin/sign_update.php' or rel.startswith('config/'): continue
        old=baseline/rel
        if old.is_file() and old.read_bytes()==file.read_bytes(): continue
        target=staging/rel;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(file,target);files.append(rel)
    if not files: raise RuntimeError('Empty release delta.')
    artifacts=[]
    for base in ['1.1.0','1.1.1']:
        manifest={'from_version':base,'version':'1.1.2','min_php':'8.2.0',
                  'description':'Βελτιώσεις βαθμολογίας/αναθέσεων, μαζικά συγκεντρωτικά email, ημερολόγια και εκτύπωση. Χωρίς αλλαγές βάσης. Οδηγίες docs/UPGRADE-1.1.2.md.',
                  'files':files,'migrations':[],'delete':[]}
        manifest_file=staging/f'manifest-{base}.json'
        manifest_file.write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
        output=signing_output/f'absences-update-{base}-to-1.1.2.zip'
        subprocess.run([php,'-d','xdebug.mode=off',str(source/'bin/sign_update.php'),str(manifest_file),str(staging.resolve()),str(key.resolve()),str(output.resolve())],check=True)
        target=public_directory.parent/output.name
        if target.exists(): raise RuntimeError('Refusing to overwrite '+str(target))
        shutil.copyfile(output,target);checksum(target);artifacts.append(target.name)
    preparation=public_directory.parent/'absences-maintenance-preparation-1.1.2.zip'
    paths=['public_html/'+f for f in MANUAL]+['docs/UPGRADE-1.1.2.md','docs/FUTURE-UPDATES.md','docs/PLESK-UPDATE-CHECK.md']
    with zipfile.ZipFile(preparation,'x',zipfile.ZIP_DEFLATED) as archive:
        for rel in paths:archive.write(public_directory/rel,rel)
        archive.writestr('FILE-MANIFEST.json',json.dumps({'format':1,'manual_preparation':True,'files':{rel:hashlib.sha256((public_directory/rel).read_bytes()).hexdigest() for rel in paths}},indent=2)+'\n')
    checksum(preparation)
    print(json.dumps({'updates':artifacts,'manual_preparation':preparation.name,'files_per_update':len(files),'migrations':0},ensure_ascii=False))

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__)
    for name in ['public-directory','baseline','staging','signing-output','key']:p.add_argument('--'+name,type=Path,required=True)
    p.add_argument('--php',default='php');args=p.parse_args()
    build(args.public_directory,args.baseline,args.staging,args.signing_output,args.key,args.php)
