# Authenticated integration test: only uniquely named temporary fixtures are changed.
from pathlib import Path
import uuid,datetime,urllib.error,xml.etree.ElementTree as ET
import sys
if '--write-fixtures' not in sys.argv: raise SystemExit('Pass a base URL and --write-fixtures to test temporary DAV items')
core=Path(__file__).with_name('smoke.py').read_text()
exec(core[:core.index("key='0'\nfor i in range")])
marker='CodexSyncTest'+uuid.uuid4().hex
base_dav='https://purelymail.com/'
headers={'Authorization':'Basic '+base64.b64encode((user+':'+password).encode()).decode()}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
q=urllib.request.Request('https://purelymail.com/.well-known/caldav',headers=headers,method='HEAD')
try:
 opener.open(q,timeout=20)
 raise RuntimeError('Expected Purelymail discovery redirect')
except urllib.error.HTTPError as e:
 home=e.headers.get('Location','')
 assert e.code in (301,302,307,308) and home.startswith('https://purelymail.com/webdav/') and home.endswith('/caldav/')
 base_dav=home[:-len('caldav/')]
def dav(method,path,data=None,ctype=None):
 h=dict(headers)
 if ctype:h['Content-Type']=ctype
 if method=='PUT' and data and marker.encode() not in data:raise RuntimeError('Invalid fixture')
 q=urllib.request.Request(base_dav+path,data=data,headers=h,method=method)
 try:
  with opener.open(q,timeout=20) as r:return r.status,r.read()
 except urllib.error.HTTPError as e:return e.code,e.read()
def sync(folder,key,commands=None):
 children=[el(0,11,key),el(0,18,folder),el(0,19,'0' if key=='0' else '1'),el(0,21,'100'),el(0,23,el(0,24,'1'),el(17,5,el(17,6,'1'),el(17,7,'0')))]
 if commands:children.append(el(0,22,*commands))
 b=req('Sync',el(0,5,el(0,28,el(0,15,*children))))
 assert val(b,0,14)=='1',('Sync status',val(b,0,14))
 return val(b,0,11),b
now=datetime.datetime.now(datetime.timezone.utc);fmt=lambda d:d.strftime('%Y%m%dT%H%M%SZ')
fixtures=[('9','carddav/default/'+marker+'.vcf',('BEGIN:VCARD\r\nVERSION:3.0\r\nUID:'+marker+'\r\nFN:'+marker+'\r\nN:Test;'+marker+';;;\r\nEND:VCARD\r\n').encode(),'text/vcard'),('8','caldav/2068226B-30F7-4618-A7FD-045AD11A26FD/'+marker+'.ics',('BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Codex//Sync test//EN\r\nBEGIN:VEVENT\r\nUID:'+marker+'\r\nDTSTAMP:'+fmt(now)+'\r\nDTSTART:'+fmt(now+datetime.timedelta(hours=1))+'\r\nDTEND:'+fmt(now+datetime.timedelta(hours=2))+'\r\nSUMMARY:'+marker+'\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n').encode(),'text/calendar')]
created=[]
try:
 for typ,path,body,ctype in fixtures:
  assert dav('PUT',path,body,ctype)[0] in (201,204)
  folder=next(val([f],7,8) for f in folders if val([f],7,10)==typ)
  key,b=sync(folder,'0');key,b=sync(folder,key)
  adds=find(b,0,7);matched=[a for a in adds if marker in str(a)]
  assert len(matched)==1,('Fixture not synchronized',typ)
  sid=val([matched[0]],0,13);assert sid
  print('PASS DAV to ActiveSync',typ,flush=True)
  field=el(1,31,marker+'Updated') if typ=='9' else el(4,38,marker+'Updated')
  appdata=find([matched[0]],0,29)[0][2]
  def tree(n):return n if isinstance(n,str) else el(n[0],n[1],*[tree(c) for c in n[2]])
  fields=[tree(n) for n in appdata if n[:2]!=[field[0],field[1]]]+[field]
  key,b=sync(folder,key,[el(0,8,el(0,13,sid),el(0,29,*fields))])
  status,data=dav('GET',path);data=data.replace(b'\r\n',b'\n').replace(b'\n\t',b'').replace(b'\n ',b'');assert status==200 and (marker+'Updated').encode() in data,('Update not stored',typ)
  print('PASS ActiveSync to DAV update',typ,flush=True)
  key,b=sync(folder,key,[el(0,9,el(0,13,sid))])
  assert dav('GET',path)[0]==404,('Delete not stored',typ)
  print('PASS ActiveSync to DAV delete',typ,flush=True)
  key,b=sync(folder,key,[el(0,7,el(0,12,marker+typ),el(0,29,*fields))])
  replies=find(b,0,6);newid=val(replies,0,13);assert newid,('Add missing ID',typ,find(b,0,14))
  import re
  assert re.fullmatch(r'[A-Za-z0-9_.-]+',newid), 'Unexpected new item ID'
  newpath='carddav/default/'+newid.split('-',1)[1]+'.vcf' if typ=='9' else 'caldav/2068226B-30F7-4618-A7FD-045AD11A26FD/'+newid
  created.append(newpath)
  status,data=dav('GET',newpath);data=data.replace(b'\r\n',b'\n').replace(b'\n\t',b'').replace(b'\n ',b'')
  assert status==200 and marker.encode() in data,('Created item absent',typ)
  print('PASS ActiveSync creates DAV item',typ,flush=True)
  key,b=sync(folder,key,[el(0,9,el(0,13,newid))])
  assert dav('GET',newpath)[0]==404

finally:
 for path in [x[1] for x in fixtures]+created:
  status,_=dav('DELETE',path)
  if status not in (204,404):print('Fixture cleanup requires attention:',path,status)
