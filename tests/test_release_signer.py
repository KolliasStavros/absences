"""Signing-key continuity regression with disposable synthetic keys only."""
from pathlib import Path
import subprocess,sys,tempfile
sys.path.insert(0,str(Path(__file__).resolve().parent.parent))
from tools.build_release_140 import verify_signer
with tempfile.TemporaryDirectory(prefix='absences-signer-test-') as tmp:
    folder=Path(tmp)
    code="$d=$argv[1];foreach(['correct','other'] as $n){$k=sodium_crypto_sign_keypair();file_put_contents($d.'/'.$n.'.secret',base64_encode(sodium_crypto_sign_secretkey($k)));file_put_contents($d.'/'.$n.'.public',base64_encode(sodium_crypto_sign_publickey($k)));}"
    subprocess.run(['php','-d','xdebug.mode=off','-r',code,str(folder)],check=True)
    verify_signer(folder/'correct.secret',folder/'correct.public')
    try:verify_signer(folder/'other.secret',folder/'correct.public')
    except subprocess.CalledProcessError:pass
    else:raise AssertionError('Wrong valid signing key accepted')
    (folder/'invalid.secret').write_text('not-a-signing-key',encoding='ascii')
    try:verify_signer(folder/'invalid.secret',folder/'correct.public')
    except subprocess.CalledProcessError:pass
    else:raise AssertionError('Malformed signing key accepted')
print('PASS: correct signer accepted; different valid key and malformed key rejected before build output.')
