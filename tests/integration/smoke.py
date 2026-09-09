import base64, json, subprocess, urllib.request, urllib.parse, time, sys
from pathlib import Path
v={}
for line in (Path(__file__).resolve().parents[2] / '.env.test').read_text().splitlines():
 if '=' in line and not line.lstrip().startswith('#'):
  k,s=line.split('=',1);v[k.strip()]=s.strip().strip('"').strip("'")
user,password=v['IMAP_USER'],v['IMAP_PASSWORD']
base=sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:18081'
policy='0';device='CODEXDAVSMOKE20260909'
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
# Provision a synthetic test client; no real device policy or mailbox writes.
b=req('Provision',el(14,5,el(14,6,el(14,7,el(14,8,'MS-EAS-Provisioning-WBXML')))))
k=val(b,14,9);assert k,'No policy key';policy=k
b=req('Provision',el(14,5,el(14,6,el(14,7,el(14,8,'MS-EAS-Provisioning-WBXML'),el(14,9,k),el(14,11,'1')))))
policy=val(b,14,9);assert policy,'No final policy key'
b=req('FolderSync',el(7,22,el(7,18,'0')));assert val(b,7,12)=='1','FolderSync failed'
folders=find(b,7,15);print('Folder types:',[val([f],7,10) for f in folders],flush=True);inbox=next(val([f],7,8) for f in folders if val([f],7,10)=='2');print('FolderSync successful; inbox found',flush=True)
key='0'
for i in range(20):
 children=[el(0,11,key),el(0,18,inbox),el(0,19,'0' if key=='0' else '1'),el(0,21,'100'),el(0,23,el(0,24,'1'),el(17,5,el(17,6,'1'),el(17,7,'0')))]
 b=req('Sync',el(0,5,el(0,28,el(0,15,*children))))
 if not b:break
 assert val(b,0,14)=='1','Sync failed'
 key=val(b,0,11);assert key
 if i and not find(b,0,20):break
print('Read-only recent-mail sync successful',flush=True)
t=time.monotonic();b=req('Ping',el(13,5,el(13,8,'15'),el(13,9,el(13,10,el(13,11,inbox),el(13,12,'Email')))))
print('Ping status',val(b,13,7),'duration',round(time.monotonic()-t,2),flush=True)
