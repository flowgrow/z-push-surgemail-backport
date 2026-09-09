<?php
// Reproduce Purelymail's pre-TLS capability response without real credentials.
$dir=sys_get_temp_dir().'/zpush-sieve-tls-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$csr=openssl_csr_new(['commonName'=>'localhost'],$key,['digest_alg'=>'sha256']);
$cert=openssl_csr_sign($csr,null,$key,1,['digest_alg'=>'sha256']);
openssl_x509_export($cert,$certPem);openssl_pkey_export($key,$keyPem);
file_put_contents($dir.'/cert.pem',$certPem);file_put_contents($dir.'/server.pem',$certPem.$keyPem);chmod($dir.'/server.pem',0600);
try {
    foreach (['purelymail','standard','reject-auth','untrusted-cert'] as $mode) {
        $extra=$mode!=='standard';
        $context=stream_context_create(['ssl'=>['local_cert'=>$dir.'/server.pem','verify_peer'=>false]]);
        $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
        $port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1);
        $pid=pcntl_fork();
        if ($pid===0) {
            $socket=@stream_socket_accept($server,10);
            if (!$socket) exit(2);
            stream_set_timeout($socket,3);
            $caps="\"SIEVE\" \"vacation date relational\"\r\n\"SASL\" \"PLAIN\"\r\n\"STARTTLS\"\r\nOK\r\n";
            fwrite($socket,$caps);
            if(fgets($socket)!=="STARTTLS\r\n")exit(3);
            fwrite($socket,"OK\r\n".($extra?$caps:''));
            $tls=@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_SERVER);
            if($mode==='untrusted-cert')exit($tls?4:0);
            if(!$tls)exit(5);
            if(fgets($socket)!=="CAPABILITY\r\n")exit(6);
            fwrite($socket,$caps);
            $auth=fgets($socket);
            if($auth!=='AUTHENTICATE "PLAIN" "'.base64_encode("\0test@example.com\0fake-password")."\"\r\n")exit(7);
            if($mode==='reject-auth'){fwrite($socket,"NO \"denied\"\r\n");fclose($socket);exit(0);}
            fwrite($socket,"OK\r\n");
            if(fgets($socket)!=="LISTSCRIPTS\r\n")exit(8);
            fwrite($socket,"\"roundcube\" ACTIVE\r\nOK\r\n");fclose($socket);exit(0);
        }
        fclose($server);
        $code='require "/usr/share/z-push/backend/imap/managesieve.php"; try { $c=new ZPushManageSieve("localhost",'. $port .',"test@example.com","fake-password",'.($extra?'true':'false').'); if($c->scripts()!==["roundcube"=>true])exit(3); echo "success"; } catch(Throwable $e) { echo "rejected"; }';
        $command=[PHP_BINARY];
        if($mode!=='untrusted-cert')array_push($command,'-d','openssl.cafile='.$dir.'/cert.pem');
        array_push($command,'-r',$code);
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
        pcntl_waitpid($pid,$childStatus);
        $expected=in_array($mode,['reject-auth','untrusted-cert'],true)?'rejected':'success';
        if($status!==0 || !pcntl_wifexited($childStatus) || pcntl_wexitstatus($childStatus)!==0 || $output!==$expected)throw new Exception('TLS protocol test failed: '.$mode.' '.$errors);
        echo 'PASS ManageSieve TLS '.$mode."\n";
    }
} finally { unlink($dir.'/cert.pem');unlink($dir.'/server.pem');rmdir($dir); }
