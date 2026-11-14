#!/usr/bin/env python3
"""Reviewed overlay on official 1.3.0; protected maintenance preparation is separate."""
from pathlib import Path
import argparse,hashlib,json,re,shutil,subprocess,tempfile,zipfile

FEATURES='''admin/academic_years.php admin/header.php admin/index.php admin/settings.php admin/portable_recovery.php
teacher/index.php includes/marks_page.php includes/marks.php includes/db.php includes/auth.php
includes/attendance_security.php includes/school_calendar.php includes/academic_year_state.php
includes/academic_years.php includes/academic_year_archives.php assets/css/academic-years.css
assets/css/portable-recovery.css assets/css/style.css assets/css/email-template-settings.css
assets/css/shared-surfaces.css assets/css/theme-tokens.css assets/js/attendance-sync.js'''.split()
PROTECTED='''includes/lifecycle.php includes/lifecycle_restore.php includes/lifecycle_portable_recovery.php
includes/portable_recovery.php includes/teacher_registry_migration.php'''.split()
DOCS=['ACADEMIC-YEARS.md','PORTABLE-RECOVERY.md','UI-STYLING.md','UPGRADE-1.4.0.md']
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def checksum(path):path.with_suffix(path.suffix+'.sha256').write_text(digest(path)+'  '+path.name+'\n',encoding='ascii')
def archive(folder,path):
    with zipfile.ZipFile(path,'x',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for p in sorted(folder.rglob('*')):
            if p.is_file():z.write(p,p.relative_to(folder).as_posix())
    checksum(path)
def replace_once(text,old,new):
    if text.count(old)!=1:raise RuntimeError('Unexpected reviewed baseline anchor: '+old[:70])
    return text.replace(old,new,1)
def verify_signer(key,public_file):
    # Match the previous official release's public key BEFORE creating any output.
    # Never accept an arbitrary, otherwise-valid signing key silently.
    code="""$s=base64_decode(trim(file_get_contents($argv[1])),true);$p=base64_decode(trim(file_get_contents($argv[2])),true);if($s===false||strlen($s)!==64||$p===false||strlen($p)!==32||!hash_equals($p,sodium_crypto_sign_publickey_from_secretkey($s))){fwrite(STDERR,'Signing key does not match the previous official release public key.');exit(1);}"""
    subprocess.run(['php','-d','xdebug.mode=off','-r',code,str(key),str(public_file)],check=True)
def build(key):
    root=Path(__file__).resolve().parent.parent
    app=root/'public_html' if (root/'public_html').is_dir() else root
    baseline=root/'release/absences-public-1.3.0';target=root/'release/absences-public-1.4.0'
    verify_signer(key,baseline/'docs/RELEASE-PUBLIC-KEY.txt')
    public_zip=root/'release/absences-public-1.4.0.zip'
    update=root/'release/absences-update-1.3.0-to-1.4.0.zip';prep=root/'release/absences-maintenance-preparation-1.4.0.zip'
    if any(p.exists() for p in [target,public_zip,update,prep]):raise RuntimeError('Refusing to overwrite existing release.')
    manifest=json.loads((baseline/'FILE-MANIFEST.json').read_text(encoding='utf-8'))
    for rel,sha in manifest['files'].items():
        if digest(baseline/rel)!=sha:raise RuntimeError('Baseline mismatch: '+rel)
    shutil.copytree(baseline,target);public=target/'public_html'
    for rel in FEATURES+PROTECTED[2:]:
        src=app/rel
        if src.is_symlink():raise RuntimeError('Symlink source refused.')
        dst=public/rel;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
    # Extend the OFFICIAL maintenance engine, not the local legacy experimental engine.
    lifecycle=public/'includes/lifecycle.php';text=lifecycle.read_text(encoding='utf-8')
    text=replace_once(text,"require_once __DIR__.'/lifecycle_restore.php';","require_once __DIR__.'/lifecycle_restore.php';\nrequire_once __DIR__.'/teacher_registry_migration.php';\nrequire_once __DIR__.'/lifecycle_portable_recovery.php';")
    text=replace_once(text,'use AbsencesRestore;','use AbsencesRestore, AbsencesPortableRecovery;')
    text=replace_once(text,"'includes/lifecycle_restore.php','admin/maintenance.php'","'includes/lifecycle_restore.php','includes/lifecycle_portable_recovery.php','includes/portable_recovery.php','includes/teacher_registry_migration.php','admin/maintenance.php'")
    local=(app/'includes/lifecycle.php').read_text(encoding='utf-8')
    a=text.index('    private function migrationPreflight(');b=text.index('    public function verifyPackage(',a)
    x=local.index('    private function migrationPreflight(');y=local.index('    public function verifyPackage(',x)
    text=text[:a]+local[x:y]+text[b:]
    old="                foreach(($m['migrations']??[]) as $path=>$hash){$databaseStarted=true;$sql=$zip->getFromName('sql/'.$path);$this->db->exec(str_replace('{{prefix}}',$this->prefix,$sql));}"
    x=local.index("                foreach(($m['migrations']??[]) as $path=>$hash){");y=local.index("                foreach(($m['files']??[])",x)
    text=replace_once(text,old,local[x:y].rstrip('\n'))
    lifecycle.write_text(text,encoding='utf-8')
    restore=public/'includes/lifecycle_restore.php';text=restore.read_text(encoding='utf-8')
    text=replace_once(text,"'includes/lifecycle_restore.php',","'includes/lifecycle_restore.php','includes/lifecycle_portable_recovery.php','includes/portable_recovery.php','includes/teacher_registry_migration.php',")
    restore.write_text(text,encoding='utf-8')
    config=public/'config/config.php';text=config.read_text(encoding='utf-8');config.write_text(replace_once(text,"$installedVersion='1.3.0';","$installedVersion='1.4.0';"),encoding='utf-8')
    for name in DOCS:shutil.copyfile(root/'docs'/name,target/'docs'/name)
    (target/'README.md').write_text('# Absences 1.4.0\n\nΝέα εγκατάσταση: START-HERE.md.\n\nΥπάρχουσα 1.3.0: διαβάστε docs/UPGRADE-1.4.0.md. Πρώτα εφαρμόζεται χωριστά η προετοιμασία προστατευμένων αρχείων συντήρησης και μετά το υπογεγραμμένο update. Δεν αντικαθίσταται η ιδιωτική ρύθμιση ή το κλειδί της εγκατάστασης.\n\nΣχολικά έτη, ετήσιο backup/restore, ανάκτηση νέας εγκατάστασης χωρίς κωδικούς και κοινή παραμετροποιήσιμη εμφάνιση.\n',encoding='utf-8')
    changed=[p.relative_to(public).as_posix() for p in sorted(public.rglob('*')) if p.is_file() and (not (baseline/'public_html'/p.relative_to(public)).exists() or p.read_bytes()!=(baseline/'public_html'/p.relative_to(public)).read_bytes())]
    if set(changed)!=set(FEATURES+PROTECTED+['config/config.php']):raise RuntimeError('Unexpected release delta: '+repr(changed))
    # Never ship private config, runtime state, developer source, datasets or logs.
    for p in target.rglob('*'):
        if not p.is_file():continue
        vendor=p.is_relative_to(public/'includes/spreadsheet/vendor')
        if p.is_symlink() or p.name in {'database.php','local.php','production.php','private-config.php','installed.lock','install-access.php','INSTALLATION-KEY.txt','release-secret.key'} or (not vendor and p.suffix.lower() in {'.xls','.xlsx','.log','.jsonl','.key','.pem'}):raise RuntimeError('Private artifact: '+str(p))
    files={p.relative_to(target).as_posix():digest(p) for p in sorted(target.rglob('*')) if p.is_file() and p.name!='FILE-MANIFEST.json'}
    (target/'FILE-MANIFEST.json').write_text(json.dumps({'format':1,'files':files,'note':'Clean common distribution; no school data, credentials or private keys.'},ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    archive(target,public_zip)
    with tempfile.TemporaryDirectory(prefix='absences-sign-140-') as tmp:
        work=Path(tmp);secret=work/'signing.key';shutil.copyfile(key,secret);staging=work/'source';staging.mkdir()
        for rel in FEATURES:
            dst=staging/rel;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(public/rel,dst)
        # Old engines reject this comment-only requirement before entering maintenance.
        # Prepared engines recognize the exact marker and execute no database statement.
        marker='require-maintenance-140.sql';(staging/marker).write_text('-- ABSENCES MAINTENANCE 1.4.0 REQUIRED\n',encoding='ascii')
        spec={'from_version':'1.3.0','version':'1.4.0','min_php':'8.2.0','description':'Σχολικά έτη, ετήσια αντίγραφα, ανάκτηση νέας εγκατάστασης και κοινό στιλ. ΠΡΩΤΑ προετοιμασία συντήρησης1.4.0 σύμφωνα με UPGRADE-1.4.0. Ένας έλεγχος προετοιμασίας, χωρίς SQL μεταβολές.','files':sorted(FEATURES),'migrations':[marker],'new_tables':[],'delete':[]}
        specfile=work/'manifest.json';specfile.write_text(json.dumps(spec,ensure_ascii=False,indent=2),encoding='utf-8');signed=work/update.name
        subprocess.run(['php','-d','xdebug.mode=off',str(app/'bin/sign_update.php'),str(specfile),str(staging),str(secret),str(signed)],check=True);shutil.copyfile(signed,update)
        package=work/'preparation';package.mkdir()
        for rel in PROTECTED:
            dst=package/rel;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(public/rel,dst)
        shutil.copyfile(root/'docs/UPGRADE-1.4.0.md',package/'README.md')
        (package/'FILES.sha256').write_text(''.join(digest(package/rel)+'  '+rel+'\n' for rel in sorted(PROTECTED)),encoding='ascii');archive(package,prep)
    checksum(update)
    result={'version':'1.4.0','base':'1.3.0','files':sorted(FEATURES),'protected_preparation':PROTECTED,'migrations':1,'sql_statements':0,'new_tables':0,'delete':0,'assets':{p.name:{'sha256':digest(p),'size':p.stat().st_size} for p in [public_zip,update,prep]}}
    (root/'release/release-1.4.0-manifest.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf-8');print(json.dumps(result))
if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--key',required=True,type=Path);build(parser.parse_args().key)
