#!/usr/bin/env python3
"""Exercise the generated vhost with real Apache and a recording FastCGI backend."""
import grp, json, os, pathlib, pwd, shutil, socket, struct, subprocess, threading, time, urllib.request
apache = os.environ.get('PJAS_TEST_APACHE', shutil.which('apache2'))
modules = os.environ.get('PJAS_TEST_APACHE_MODULES', '/usr/lib/apache2/modules')
if not apache:
    print('SKIP real Apache is unavailable (set PJAS_TEST_APACHE and PJAS_TEST_APACHE_MODULES)')
    raise SystemExit(0)
code = '''<?php
require 'tests/support/features.php';
[$dir,$cfg]=fixture('fcgi');$release=$cfg['www_root'].'/demo/releases/20261116-010101';
mkdir($release.'/public',0755,true);mkdir($release.'/server');symlink($release,$cfg['www_root'].'/demo/current');
file_put_contents($release.'/public/index.html','frontend');file_put_contents($release.'/public/other.php','<?php');file_put_contents($release.'/server/index.php','<?php');
$cfg+=['isolation_site'=>'demo','isolation_runtime'=>'php8.5-fpm','isolation_api_front_controller'=>'server/index.php'];
$spec=Pjas\\Agentd\\Spec::service(['name'=>'demo','domain'=>'demo.example.com','runtime'=>'php8.5-fpm','docroot'=>'public']);
echo json_encode(['dir'=>$dir,'release'=>$release,'conf'=>(new Pjas\\Agentd\\Ops\\Vhost($cfg,new Pjas\\Agentd\\Paths($cfg['www_root'])))->render($spec)]);
'''
result = subprocess.run(['php'], input=code, text=True, capture_output=True, check=True)
data = json.loads(result.stdout)
root = pathlib.Path(data['dir'])
backend = socket.socket(socket.AF_UNIX)
backend.bind(str(root / 'fcgi.sock'))
backend.listen(10)
backend.settimeout(.2)
stop = threading.Event()

def receive(conn, size):
    out = b''
    while len(out) < size:
        part = conn.recv(size - len(out))
        if not part:
            raise EOFError()
        out += part
    return out

def length(buf, offset):
    if buf[offset] & 128:
        return struct.unpack_from('!I', buf, offset)[0] & 0x7fffffff, offset + 4
    return buf[offset], offset + 1

def record(conn, kind, request, payload):
    conn.sendall(struct.pack('!BBHHBB', 1, kind, request, len(payload), 0, 0) + payload)

def serve():
    while not stop.is_set():
        try:
            conn, _ = backend.accept()
        except socket.timeout:
            continue
        with conn:
            params = b''
            try:
                while True:
                    _, kind, req, size, padding, _ = struct.unpack('!BBHHBB', receive(conn, 8))
                    payload = receive(conn, size)
                    if padding:
                        receive(conn, padding)
                    if kind == 4:
                        params += payload
                    if kind == 5 and not payload:
                        env, pos = {}, 0
                        while pos < len(params):
                            nl, pos = length(params, pos)
                            vl, pos = length(params, pos)
                            name = params[pos:pos + nl].decode()
                            pos += nl
                            env[name] = params[pos:pos + vl].decode()
                            pos += vl
                        status = '200 OK' if env.get('SCRIPT_FILENAME', '').endswith('.php') else '403 Forbidden'
                        body = ('Status: ' + status + '\r\nContent-Type: application/json\r\n\r\n' + json.dumps(env)).encode()
                        record(conn, 6, req, body)
                        record(conn, 6, req, b'')
                        record(conn, 3, req, struct.pack('!IB3x', 0, 0))
                        break
            except (EOFError, BrokenPipeError):
                pass
thread = threading.Thread(target=serve, daemon=True)
thread.start()
with socket.socket() as listener:
    listener.bind(('127.0.0.1', 0))
    port = listener.getsockname()[1]
user = pwd.getpwuid(os.geteuid()).pw_name
loads = '\n'.join(f'LoadModule {name}_module "{modules}/mod_{name}.so"' for name in ['mpm_event', 'authz_core', 'proxy', 'proxy_fcgi', 'dir', 'rewrite'])
conf = f'''ServerRoot "{root}"
PidFile "{root}/apache.pid"
ServerName localhost
Listen 127.0.0.1:{port}
User {user}
Group {grp.getgrgid(os.getegid()).gr_name}
ErrorLog "{root}/error.log"
{loads}
'''
conf += data['conf'].replace('*:80', '*:' + str(port)).replace('${APACHE_LOG_DIR}', str(root)).replace('/run/php/pjas-demo-8.5.sock', str(root / 'fcgi.sock'))
(root / 'apache.conf').write_text(conf)
process = None
try:
    checked = subprocess.run([apache, '-t', '-f', str(root / 'apache.conf')], capture_output=True, text=True)
    if checked.returncode:
        raise RuntimeError(checked.stderr)
    process = subprocess.Popen([apache, '-f', str(root / 'apache.conf'), '-DFOREGROUND'], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    for _ in range(100):
        try:
            with urllib.request.urlopen(f'http://127.0.0.1:{port}/', timeout=1) as response:
                assert response.read() == b'frontend'
                break
        except OSError:
            if process.poll() is not None:
                raise RuntimeError(process.stderr.read().decode())
            time.sleep(.02)
    else:
        raise RuntimeError('Apache did not become ready')
    for path in ['/api/health', '/api/health?probe=1', '/api', '/api/other/nested']:
        request = urllib.request.Request(f'http://127.0.0.1:{port}' + path, headers={'Authorization': 'Bearer test-only'})
        with urllib.request.urlopen(request, timeout=5) as response:
            env = json.load(response)
        assert env['SCRIPT_FILENAME'] == str(root / 'www/demo/current/server/index.php'), env
        assert env['REQUEST_URI'] == path, env
        assert env.get('QUERY_STRING', '') == (path.split('?', 1)[1] if '?' in path else ''), env
        assert not env.get('PATH_INFO'), env
        assert env.get('HTTP_AUTHORIZATION') == 'Bearer test-only', env
    with urllib.request.urlopen(f'http://127.0.0.1:{port}/api/health', timeout=5) as response:
        assert not json.load(response).get('HTTP_AUTHORIZATION')
    with urllib.request.urlopen(f'http://127.0.0.1:{port}/other.php', timeout=5) as response:
        env = json.load(response)
    assert env['SCRIPT_FILENAME'].endswith('/public/other.php'), env
    print('ok real Apache configtest, static frontend, fixed API SCRIPT_FILENAME, preserved URI/query/Authorization and independent ordinary PHP handler')
finally:
    if process:
        process.terminate()
        process.wait(timeout=10)
    stop.set()
    thread.join(timeout=2)
    backend.close()
    shutil.rmtree(root)
