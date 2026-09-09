<?php
if (!extension_loaded("imap") || !extension_loaded("curl")) { http_response_code(503); exit; }
echo "ok\n";
