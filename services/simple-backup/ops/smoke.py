#!/usr/bin/env python3
"""Public HTTPS protocol smoke test. Uses only disposable test data and keys."""
import base64, hashlib, json, os, struct, uuid, urllib.request, urllib.error
from datetime import datetime, timezone
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
BASE='https://atlas.predhit.com/simple-backup'
PREFIX='/simple-backup'
def canonical(x): return json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
def get(path):
    with urllib.request.urlopen(BASE+path,timeout=30) as r:return r.read()
manifest_raw=get('/manifest.json');manifest=json.loads(manifest_raw)
operator=Ed25519PublicKey.from_public_bytes(bytes.fromhex(manifest['operator'].split(':')[-1]))
def verify(document,omit=('signature',)):
    operator.verify(base64.b64decode(document['signature']),canonical({k:v for k,v in document.items() if k not in omit}))
verify(manifest);operator.verify(base64.b64decode(get('/manifest.json.sig')),manifest_raw)
assert manifest['componentId']=='onym:component:simple-backup'
assert manifest['name']=='Simple backup'
assert manifest['endpoints'][0]['uri']==BASE
terms=json.loads(get('/terms/'+manifest['declaredTerms'].split(':')[1]+'.json'))
verify(terms,('signature','termsId'))
assert terms['termsId']=='sha256:'+hashlib.sha256(canonical({k:v for k,v in terms.items() if k not in ['signature','termsId']})).hexdigest()
assert terms['jurisdictions']==['FI']
print('PASS signed manifest, detached signature, signed terms and endpoint')
key=Ed25519PrivateKey.generate()
def req(method,path,body=b'',who=key,nonce=None):
    holder='onym:seat-key:'+who.public_key().public_bytes(serialization.Encoding.Raw,serialization.PublicFormat.Raw).hex()
    timestamp=datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ');nonce=nonce or str(uuid.uuid4())
    fields=[b'onym-backup-v1',method.encode(),(PREFIX+path).encode(),holder.encode(),timestamp.encode(),nonce.encode(),hashlib.sha256(body).digest()]
    signature=base64.b64encode(who.sign(b''.join(struct.pack('>I',len(f))+f for f in fields))).decode()
    h={'X-Onym-Holder':holder,'X-Onym-Timestamp':timestamp,'X-Onym-Nonce':nonce,'X-Onym-Signature':signature,'Content-Type':'application/octet-stream' if method=='PUT' else 'application/json'}
    r=urllib.request.Request(BASE+path,data=body if method!='GET' else None,headers=h,method=method)
    try:
        with urllib.request.urlopen(r,timeout=120) as response:return response.status,response.read()
    except urllib.error.HTTPError as e:return e.code,e.read()
def success(method,path,body=b''):
    status,data=req(method,path,body);assert status==200,(method,path,status,data[:300]);return data
# More than one upload chunk, encrypted before transmission; no real user data.
plain=os.urandom(9*1024*1024);encryption_key=AESGCM.generate_key(bit_length=256);iv=os.urandom(12)
sealed=iv+AESGCM(encryption_key).encrypt(iv,plain,None);digest=hashlib.sha256(sealed).hexdigest()
reference={'referenceVersion':1,'algorithm':'sha-256/lowercase-hex','digest':'sha256:'+digest,'sealedByteSize':len(sealed)}
committed=False
try:
    grant=json.loads(success('POST','/v1/preflight',canonical({'version':1,'operationId':str(uuid.uuid4()),'snapshotReference':reference,'acceptedTermsId':terms['termsId']})))
    for i in range(grant['chunkCount']):
        success('PUT',f"/v1/uploads/{grant['uploadId']}/chunks/{i}",sealed[i*grant['chunkBytes']:(i+1)*grant['chunkBytes']])
    success('POST',f"/v1/uploads/{grant['uploadId']}/commit");committed=True
    assert digest.encode() in success('GET','/v1/snapshots')
    downloaded=success('GET','/v1/snapshots/'+digest);assert downloaded==sealed
    assert AESGCM(encryption_key).decrypt(downloaded[:12],downloaded[12:],None)==plain
    print('PASS multi-chunk upload, commit, listing, download and local decryption')
    assert req('GET','/v1/snapshots/'+digest,who=Ed25519PrivateKey.generate())[0] in (401,403,404)
    replay=str(uuid.uuid4());assert req('GET','/v1/snapshots',nonce=replay)[0]==200;assert req('GET','/v1/snapshots',nonce=replay)[0]==401
    print('PASS holder isolation and replay rejection')
    export=json.loads(success('GET','/v1/exports'));assert any(s['snapshotReference']['digest']=='sha256:'+digest for s in export['snapshots'])
    assert success('GET','/v1/exports/'+digest)==sealed
    print('PASS export descriptor and exported bytes')
finally:
    if committed:
        receipts=json.loads(success('POST','/v1/erasures',canonical({'version':1,'operationId':str(uuid.uuid4()),'scope':'sha256:'+digest})))
        for receipt in receipts:verify(receipt)
        assert req('GET','/v1/snapshots/'+digest)[0] in (404,410)
        print('PASS own test snapshot erased with verified signed receipt')
