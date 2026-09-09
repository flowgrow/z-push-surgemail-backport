import base64, json, subprocess, urllib.request, urllib.parse, time, sys
from pathlib import Path
v={}
for line in (Path(__file__).resolve().parents[2] / '.env.test').read_text().splitlines():
 if '=' in line and not line.lstrip().startswith('#'):
  k,s=line.split('=',1);v[k.strip()]=s.strip().strip('"').strip("'")
user,password=v['IMAP_USER'],v['IMAP_PASSWORD']
base=sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:18081'
policy='0';device='CODEXOOFSMOKE20260909'
def el(p,t,*children): return (p,t,children)
def xml(tree):
 out=bytearray(b'\x03\x01\x6a\x00');page=0
 def emit(n):
  nonlocal page
  if isinstance(n,str):out.extend(b'\x03'+n.encode()+b'\x00');return
  p,t,c=n
  if p!=page:out.extend(bytes([0,p]));page=p
  out.append(t|(64 if c else 0))
  for child in c:emit(child)
  if c:out.append(1)
 emit(tree);return bytes(out)
def parse(b):
 i=0;page=0;stack=[];root=[]
 def integer():
  nonlocal i
  v=0
  while True:
   c=b[i];i+=1;v=(v<<7)|(c&127)
   if not c&128:return v
 integer();integer();integer();n=integer();i+=n
 while i<len(b):
  t=b[i];i+=1
  if t==0:page=b[i];i+=1
  elif t==1:stack.pop()
  elif t==3:
   j=b.index(0,i);s=b[i:j].decode(errors='replace');i=j+1
   if stack:stack[-1][2].append(s)
  elif t==195:
   n=integer();i+=n
  else:
   n=[page,t&63,[]];(stack[-1][2] if stack else root).append(n)
   if t&64:stack.append(n)
 return root
def find(nodes,p,t):
 result=[]
 for n in nodes:
  if isinstance(n,str):continue
  if n[:2]==[p,t]:result.append(n)
  result+=find(n[2],p,t)
 return result
def val(nodes,p,t):
 n=find(nodes,p,t);return n[0][2][0] if n and n[0][2] else None
def req(cmd,tree):
 url=base+'/Microsoft-Server-ActiveSync?'+urllib.parse.urlencode({'Cmd':cmd,'User':user,'DeviceId':device,'DeviceType':'CodexTest'})
 headers={'Authorization':'Basic '+base64.b64encode((user+':'+password).encode()).decode(),'MS-ASProtocolVersion':'14.0','X-MS-PolicyKey':policy,'Content-Type':'application/vnd.ms-sync.wbxml','User-Agent':'Codex-IDLE-Smoke/1.0'}
 q=urllib.request.Request(url,data=xml(tree),headers=headers)
 try:
  with urllib.request.urlopen(q,timeout=55) as r:b=r.read();print(cmd,'HTTP',r.status,flush=True)
 except urllib.error.HTTPError as e:print(cmd,'HTTP',e.code,flush=True);raise SystemExit(1)
 return parse(b) if b else []

# Never enable immediate replies in a live account. Future-dated fixtures only.
import os, tempfile, uuid
container=sys.argv[2] if len(sys.argv)>2 else 'zpush-oof-check'
def store(action, **kwargs):
 data={'user':user,'password':password,'action':action,**kwargs}
 r=subprocess.run(['docker','exec','-i',container,'php','/opt/z-push-tests/integration/oof_store.php'],input=json.dumps(data),text=True,capture_output=True)
 if r.returncode: raise RuntimeError('Sieve test helper failed; inspect server test setup')
 return json.loads(r.stdout)
def oof(*parts, get=False):
 r=req('Settings',el(18,5,el(18,9,el(18,7 if get else 8,*parts))))
 statuses=[n[2][0] for n in find(r,18,6)]
 assert statuses==['1','1'], 'OOF rejected: '+repr(statuses)
 return r
def message(audience,text):return el(18,13,el(18,audience),el(18,17,'1'),el(18,18,text),el(18,19,'Text'))
b=req('Provision',el(14,5,el(14,6,el(14,7,el(14,8,'MS-EAS-Provisioning-WBXML')))))
k=val(b,14,9);assert k,'No policy key';policy=k
b=req('Provision',el(14,5,el(14,6,el(14,7,el(14,8,'MS-EAS-Provisioning-WBXML'),el(14,9,k),el(14,11,'1')))))
policy=val(b,14,9);assert policy,'No final policy key'
before=store('snapshot'); marker='OOF test '+uuid.uuid4().hex
fd,backup=tempfile.mkstemp(prefix='zpush-oof-backup-',suffix='.json')
with os.fdopen(fd,'w') as f:json.dump(before,f)
changed=False
try:
 r=oof(el(18,19,'Text'),get=True)
 assert val(r,18,10)=='0','An existing active OOF must be tested separately'
 changed=True
 text=marker+' — Grüße "quoted"\nSecond line'
 oof(el(18,10,'2'),el(18,11,'2030-01-01T00:00:00.000Z'),el(18,12,'2030-01-02T00:00:00.000Z'),*(message(a,text) for a in [14,15,16]))
 r=oof(el(18,19,'Text'),get=True)
 assert val(r,18,10)=='2' and val(r,18,18)==text,'Scheduled OOF readback mismatch'
 assert val(r,18,11).startswith('2030-01-01'),'Start time mismatch'
 snapshot=store('snapshot');active=next(n for n,a in snapshot['scripts'].items() if a)
 svc_script=snapshot['files'][active]
 assert 'currentdate' in svc_script and '2030-01-02T00:00:00+00:00' in svc_script,'Schedule not installed in Sieve'
 print('PASS ActiveSync schedule, UTF-8 text and Sieve readback')
 oof(el(18,10,'0'))
 r=oof(el(18,19,'Text'),get=True)
 assert val(r,18,10)=='0' and val(r,18,18)==text,'Disable must preserve reply text'
 print('PASS ActiveSync disable and retained reply text')
finally:
 if changed:store('restore',before=before,marker=marker)
 after=store('snapshot')
 assert after==before,'Original filters were not restored exactly'
 os.unlink(backup)
 print('PASS original Sieve scripts and activation restored exactly')
