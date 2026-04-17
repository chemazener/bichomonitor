<?php
session_start();

// === CONFIGURATION ===
define('BICHO_PASSWORD', 'bicho2024');          // CHANGE THIS
define('BICHO_RATE_LIMIT', 30);                 // max API requests per minute
define('BICHO_LOCALHOST_SKIP_AUTH', true);       // skip auth from localhost (kiosk)

// === SECURITY HEADERS ===
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; connect-src 'self'; img-src 'self' https://images.unsplash.com");

// === AUTH HELPERS ===
function isLocalhost(){return in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1']);}
function isAuthenticated(){
    if(BICHO_LOCALHOST_SKIP_AUTH && isLocalhost()) return true;
    return !empty($_SESSION['bicho_auth']);
}
function ensureCsrf(){
    if(empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
}
function validateCsrf(){
    $t=$_POST['csrf_token']??$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(empty($_SESSION['csrf_token'])||!hash_equals($_SESSION['csrf_token'],$t)){
        header('Content-Type: application/json');http_response_code(403);
        echo json_encode(['ok'=>false,'msg'=>'CSRF token invalido']);exit;
    }
}
function checkRateLimit(){
    if(!isset($_SESSION['rl_c'])){$_SESSION['rl_c']=0;$_SESSION['rl_r']=time()+60;}
    if(time()>$_SESSION['rl_r']){$_SESSION['rl_c']=0;$_SESSION['rl_r']=time()+60;}
    $_SESSION['rl_c']++;
    if($_SESSION['rl_c']>BICHO_RATE_LIMIT){
        header('Content-Type: application/json');http_response_code(429);
        echo json_encode(['ok'=>false,'msg'=>'Rate limit exceeded']);exit;
    }
}
function requireAuth(){
    if(!isAuthenticated()){
        header('Content-Type: application/json');http_response_code(401);
        echo json_encode(['ok'=>false,'msg'=>'No autenticado']);exit;
    }
    checkRateLimit();
}

// === LOGIN ===
if(isset($_POST['bicho_login'])){
    if(hash_equals(BICHO_PASSWORD,$_POST['bicho_password']??'')){
        session_regenerate_id(true);
        $_SESSION['bicho_auth']=true;
        ensureCsrf();
        header('Location: /');exit;
    }
    $login_error=true;
}
if(isset($_GET['logout'])){session_destroy();header('Location: /');exit;}
if(isAuthenticated()) ensureCsrf();

// === API: DATA ===
if(isset($_GET['data'])){
    requireAuth();
    header('Content-Type: application/json');
    $stat1=file_get_contents('/proc/stat');
    usleep(300000);
    $stat2=file_get_contents('/proc/stat');
    preg_match('/^cpu\s+(.+)$/m',$stat1,$m1);preg_match('/^cpu\s+(.+)$/m',$stat2,$m2);
    $c1=array_map('intval',preg_split('/\s+/',trim($m1[1])));
    $c2=array_map('intval',preg_split('/\s+/',trim($m2[1])));
    $idle1=$c1[3]+($c1[4]??0);$idle2=$c2[3]+($c2[4]??0);
    $total1=array_sum($c1);$total2=array_sum($c2);
    $td=$total2-$total1;$id=$idle2-$idle1;
    $cpu_usage=$td>0?round((1-$id/$td)*100,2):0;
    $free=shell_exec('free -t');$free=trim($free);$fa=explode("\n",$free);
    $mem=explode(" ",preg_replace('/\s+/',' ',$fa[1]));
    $mt=round($mem[1]/1024);$mu=round($mem[2]/1024);
    $mp=$mem[1]>0?round(($mem[2]/$mem[1])*100,2):0;
    $dt=round(disk_total_space("/")/1073741824,1);
    $df_=round(disk_free_space("/")/1073741824,1);
    $du=$dt>0?round(100-(disk_free_space("/")/disk_total_space("/")*100),2):0;
    $tr=shell_exec("sensors 2>/dev/null|grep 'Package id 0'|awk '{print \$4}'|tr -d '+°C'");
    $temp=floatval(trim($tr));
    $bat=intval(trim(shell_exec("cat /sys/class/power_supply/CMB*/capacity /sys/class/power_supply/BAT*/capacity 2>/dev/null|head -1")));
    $bs=trim(shell_exec("cat /sys/class/power_supply/CMB*/status /sys/class/power_supply/BAT*/status 2>/dev/null|head -1"));
    $sa=explode(" ",preg_replace('/\s+/',' ',$fa[2]));
    $st_=round($sa[1]/1024);$su=round($sa[2]/1024);
    $cc=intval(trim(shell_exec("nproc 2>/dev/null")));
    $cpc=[];
    $ms=shell_exec("mpstat -P ALL 2>/dev/null|tail -n +4");
    if($ms){$ls=explode("\n",trim($ms));foreach($ls as $l){$p=preg_split('/\s+/',trim($l));if(count($p)>=12&&is_numeric($p[2]))$cpc[]=round(100-floatval($p[11]),1);}}
    $or=shell_exec('ollama list 2>&1');$models=[];
    if($or&&strpos($or,'NAME')!==false){$ls=explode("\n",trim($or));array_shift($ls);foreach($ls as $l){$p=preg_split('/\s+/',trim($l));if(!empty($p[0]))$models[]=$p[0];}}
    $op=shell_exec('ollama ps 2>&1');$rm=[];
    if($op&&strpos($op,'NAME')!==false){$ls=explode("\n",trim($op));array_shift($ls);foreach($ls as $l){$p=preg_split('/\s+/',trim($l));if(!empty($p[0]))$rm[]=$p[0];}}
    $pr=shell_exec('ps aux --sort=-%mem|head -n 11');$procs=[];
    if($pr){$ls=explode("\n",trim($pr));array_shift($ls);foreach($ls as $l){$p=preg_split('/\s+/',trim($l));if(count($p)>=11)$procs[]=['user'=>$p[0],'pid'=>$p[1],'cpu'=>$p[2],'mem'=>$p[3],'rss'=>round($p[5]/1024),'command'=>implode(' ',array_slice($p,10))];}}
    $nr=trim(shell_exec("cat /sys/class/net/$(ip route show default|awk '/default/{print \$5}'|head -1)/statistics/rx_bytes 2>/dev/null"));
    $nt=trim(shell_exec("cat /sys/class/net/$(ip route show default|awk '/default/{print \$5}'|head -1)/statistics/tx_bytes 2>/dev/null"));
    $ur=shell_exec('uptime -p 2>/dev/null');
    $us=$ur?trim(str_replace('up ','',$ur)):'N/A';
    echo json_encode(['cpu'=>$cpu_usage,'ram'=>$mp,'ram_used'=>$mu,'ram_total'=>$mt,'disk'=>$du,'disk_total'=>$dt,'disk_free'=>$df_,'temp'=>$temp,'battery'=>$bat,'bat_status'=>$bs,'swap_used'=>$su,'swap_total'=>$st_,'cpu_cores'=>$cc,'cpu_per_core'=>$cpc,'uptime'=>$us,'models'=>$models,'running_models'=>$rm,'time'=>date('H:i:s'),'processes'=>$procs,'net_rx'=>floatval($nr),'net_tx'=>floatval($nt)]);exit;
}

// === API: DETAIL ===
if(isset($_GET['detail'])){
    requireAuth();
    header('Content-Type: application/json');
    $type=$_GET['detail'];
    if($type==='cpu'){
        $model=trim(shell_exec("grep 'model name' /proc/cpuinfo|head -1|cut -d: -f2"));
        $freqs=[];$raw=shell_exec("cat /proc/cpuinfo|grep 'cpu MHz'");
        foreach(explode("\n",trim($raw)) as $l){$f=trim(explode(':',$l)[1]??'');if($f)$freqs[]=round(floatval($f));}
        $gov=trim(shell_exec("cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor 2>/dev/null"));
        $mxf=trim(shell_exec("cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_max_freq 2>/dev/null"));
        $mnf=trim(shell_exec("cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_min_freq 2>/dev/null"));
        echo json_encode(['model'=>$model,'freqs'=>$freqs,'governor'=>$gov,'max_freq'=>round($mxf/1000),'min_freq'=>round($mnf/1000)]);
    }elseif($type==='ram'){
        $raw=shell_exec('free -m');$ls=explode("\n",trim($raw));
        $mem=preg_split('/\s+/',$ls[1]);$sw=preg_split('/\s+/',$ls[2]);
        echo json_encode(['total'=>$mem[1],'used'=>$mem[2],'free'=>$mem[3],'shared'=>$mem[4],'buff_cache'=>$mem[5],'available'=>$mem[6],'swap_total'=>$sw[1],'swap_used'=>$sw[2],'swap_free'=>$sw[3]]);
    }elseif($type==='temp'){
        $raw=shell_exec('sensors 2>/dev/null');$temps=[];
        preg_match('/Package id 0:\s+\+([\d.]+)/',$raw,$m);$temps['package']=floatval($m[1]??0);
        preg_match('/Core 0:\s+\+([\d.]+)/',$raw,$m);$temps['core0']=floatval($m[1]??0);
        preg_match('/Core 1:\s+\+([\d.]+)/',$raw,$m);$temps['core1']=floatval($m[1]??0);
        preg_match('/temp1:\s+\+([\d.]+)/',$raw,$m);$temps['wifi']=floatval($m[1]??0);
        preg_match('/in0:\s+([\d.]+)/',$raw,$m);$temps['voltage']=floatval($m[1]??0);
        echo json_encode($temps);
    }elseif($type==='battery'){
        $raw=shell_exec("upower -i $(upower -e|grep battery) 2>/dev/null");$info=[];
        preg_match('/state:\s+(.+)/',$raw,$m);$info['state']=trim($m[1]??'');
        preg_match('/energy:\s+([\d.,]+)/',$raw,$m);$info['energy']=$m[1]??'';
        preg_match('/energy-full:\s+([\d.,]+)/',$raw,$m);$info['energy_full']=$m[1]??'';
        preg_match('/energy-full-design:\s+([\d.,]+)/',$raw,$m);$info['energy_design']=$m[1]??'';
        preg_match('/voltage:\s+([\d.,]+)/',$raw,$m);$info['voltage']=$m[1]??'';
        preg_match('/energy-rate:\s+([\d.,]+)/',$raw,$m);$info['power']=$m[1]??'';
        preg_match('/percentage:\s+(\d+)/',$raw,$m);$info['percentage']=intval($m[1]??0);
        preg_match('/capacity:\s+([\d.,]+)/',$raw,$m);$info['health']=$m[1]??'';
        preg_match('/time to/',$raw,$m);$info['time']=isset($m[0])?trim(shell_exec("upower -i $(upower -e|grep battery)|grep 'time to'|cut -d: -f2")):'N/A';
        echo json_encode($info);
    }elseif($type==='network'){
        $ifaces=shell_exec("ip -br addr 2>/dev/null");
        $ts=shell_exec("tailscale status 2>/dev/null");
        // Cache public IP in session (1 hour)
        if(empty($_SESSION['public_ip_cache'])||time()-($_SESSION['public_ip_time']??0)>3600){
            $_SESSION['public_ip_cache']=trim(shell_exec("curl -s --max-time 3 ifconfig.me 2>/dev/null"));
            $_SESSION['public_ip_time']=time();
        }
        $dns=trim(shell_exec("cat /etc/resolv.conf|grep nameserver|head -3"));
        echo json_encode(['interfaces'=>$ifaces,'tailscale'=>$ts,'public_ip'=>$_SESSION['public_ip_cache'],'dns'=>$dns]);
    }elseif($type==='disk'){
        $df=shell_exec("df -h 2>/dev/null");$io=shell_exec("iostat -d 2>/dev/null|head -8");
        echo json_encode(['partitions'=>$df,'iostat'=>$io]);
    }elseif($type==='services'){
        $svcs=['nginx','ollama','docker','ssh','tailscaled'];$r=[];
        foreach($svcs as $s){$a=trim(shell_exec("systemctl is-active $s 2>/dev/null"));$r[]=['name'=>$s,'status'=>$a];}
        echo json_encode($r);
    }elseif($type==='logs'){
        $lines=intval($_GET['lines']??30);
        $unit=preg_replace('/[^a-z0-9_.\-]/','',($_GET['unit']??''));
        $cmd=$unit?"journalctl -u ".escapeshellarg($unit)." -n $lines --no-pager 2>/dev/null":"journalctl -n $lines --no-pager 2>/dev/null";
        echo json_encode(['logs'=>shell_exec($cmd)]);
    }elseif($type==='sysinfo'){
        echo json_encode(['hostname'=>gethostname(),'kernel'=>trim(shell_exec('uname -r')),'os'=>trim(shell_exec('lsb_release -d 2>/dev/null|cut -d: -f2')),'arch'=>trim(shell_exec('uname -m')),'users'=>trim(shell_exec('who 2>/dev/null')),'last_boot'=>trim(shell_exec("who -b|awk '{print \$3,\$4}'"))]);
    }
    exit;
}

// === API: ACTIONS ===
if(isset($_POST['action'])){
    requireAuth();validateCsrf();
    header('Content-Type: application/json');
    $a=$_POST['action'];
    if($a==='free_memory'){shell_exec('sync && echo 3 > /proc/sys/vm/drop_caches 2>&1');echo json_encode(['ok'=>true,'msg'=>'Cache liberada']);}
    elseif($a==='unload_model'){$m=escapeshellarg($_POST['model']??'');shell_exec("ollama stop $m 2>&1");echo json_encode(['ok'=>true,'msg'=>'Modelo descargado']);}
    elseif($a==='kill_process'){$p=intval($_POST['pid']??0);if($p>0){shell_exec("kill $p 2>&1");echo json_encode(['ok'=>true,'msg'=>"PID $p terminado"]);}else echo json_encode(['ok'=>false,'msg'=>'PID invalido']);}
    elseif($a==='kill9_process'){$p=intval($_POST['pid']??0);if($p>0){shell_exec("kill -9 $p 2>&1");echo json_encode(['ok'=>true,'msg'=>"PID $p forzado"]);}else echo json_encode(['ok'=>false,'msg'=>'PID invalido']);}
    elseif($a==='renice'){$p=intval($_POST['pid']??0);$n=intval($_POST['nice']??10);shell_exec("renice $n -p $p 2>&1");echo json_encode(['ok'=>true,'msg'=>"PID $p nice=$n"]);}
    elseif($a==='reboot'){echo json_encode(['ok'=>true,'msg'=>'Reiniciando...']);shell_exec('sudo reboot &');}
    elseif($a==='shutdown'){echo json_encode(['ok'=>true,'msg'=>'Apagando...']);shell_exec('sudo shutdown -h now &');}
    elseif($a==='restart_service'){$s=preg_replace('/[^a-z0-9_-]/','',($_POST['service']??''));shell_exec("sudo systemctl restart $s 2>&1");echo json_encode(['ok'=>true,'msg'=>"$s reiniciado"]);}
    elseif($a==='stop_service'){$s=preg_replace('/[^a-z0-9_-]/','',($_POST['service']??''));shell_exec("sudo systemctl stop $s 2>&1");echo json_encode(['ok'=>true,'msg'=>"$s detenido"]);}
    elseif($a==='start_service'){$s=preg_replace('/[^a-z0-9_-]/','',($_POST['service']??''));shell_exec("sudo systemctl start $s 2>&1");echo json_encode(['ok'=>true,'msg'=>"$s iniciado"]);}
    elseif($a==='restart_kiosk'){echo json_encode(['ok'=>true,'msg'=>'Refrescando pantalla...']);shell_exec('sudo systemctl restart kiosk &');}
    elseif($a==='ping'){$h=escapeshellarg($_POST['host']??'');$r=shell_exec("ping -c 3 -W 2 $h 2>&1");echo json_encode(['ok'=>true,'msg'=>$r]);}
    elseif($a==='chat'){
        $model=$_POST['model']??'';$prompt=$_POST['prompt']??'';
        $data=json_encode(['model'=>$model,'prompt'=>$prompt,'stream'=>false]);
        $ch=curl_init('http://100.83.125.65:11434/api/generate');
        curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>1,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>120]);
        $resp=curl_exec($ch);curl_close($ch);
        $j=json_decode($resp,true);
        echo json_encode(['ok'=>true,'response'=>$j['response']??'Error']);
    }
    elseif($a==='screenshot'){shell_exec('DISPLAY=:0 XAUTHORITY=/home/chemazener/.Xauthority import -window root /tmp/bicho-screenshot.png 2>&1');echo json_encode(['ok'=>true,'msg'=>'Screenshot guardado en /tmp/bicho-screenshot.png']);}
    else echo json_encode(['ok'=>false,'msg'=>'Accion desconocida']);
    exit;
}

// === LOGIN PAGE ===
if(!isAuthenticated()):
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>BICHO | Login</title>
<link rel="icon" href="/favicon.ico" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#050508;--accent:#ff4d4d;--accent-glow:rgba(255,77,77,0.3);--text-main:#f0f0f5;--text-muted:#888899;--card:rgba(20,20,25,0.8);--border:rgba(255,255,255,0.08)}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text-main);font-family:'Outfit',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;
background-image:radial-gradient(circle at 50% 50%,rgba(255,77,77,0.03) 0%,transparent 70%),linear-gradient(rgba(255,255,255,0.01) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.01) 1px,transparent 1px);background-size:100% 100%,50px 50px,50px 50px}
.login-box{text-align:center;max-width:340px;width:90%}
.orb{width:120px;height:120px;border-radius:50%;border:2px solid var(--accent);box-shadow:0 0 50px var(--accent-glow);display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);margin:0 auto 20px;animation:breathe 5s infinite ease-in-out}
.orb-inner{width:80px;height:80px;border-radius:50%;background:radial-gradient(circle at 35% 35%,var(--accent),#1a0000);box-shadow:inset 0 0 30px rgba(0,0,0,0.8);filter:blur(1px)}
@keyframes breathe{0%,100%{box-shadow:0 0 40px var(--accent-glow);transform:scale(1)}50%{box-shadow:0 0 80px var(--accent-glow);transform:scale(1.08)}}
h1{font-size:2rem;font-weight:900;letter-spacing:10px;margin-bottom:4px}
.sub{color:var(--accent);font-weight:600;letter-spacing:3px;font-size:0.7rem;margin-bottom:24px}
input[type=password]{width:100%;background:rgba(0,0,0,0.4);border:1px solid var(--border);border-radius:8px;padding:12px 16px;color:var(--text-main);font-family:'JetBrains Mono',monospace;font-size:0.9rem;outline:none;text-align:center;letter-spacing:4px;margin-bottom:12px}
input[type=password]:focus{border-color:var(--accent)}
button{width:100%;background:rgba(255,77,77,0.1);border:1px solid rgba(255,77,77,0.3);color:var(--accent);padding:12px;border-radius:8px;cursor:pointer;font-family:'Outfit',sans-serif;font-size:0.85rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;transition:all 0.2s}
button:hover{background:rgba(255,77,77,0.25)}
.error{color:var(--accent);font-size:0.8rem;margin-bottom:12px;font-weight:600}
</style></head><body>
<div class="login-box">
<div class="orb"><div class="orb-inner"></div></div>
<h1>BICHO</h1><p class="sub">AI SERVER ANALYTICS</p>
<?php if(!empty($login_error)):?><p class="error">Password incorrecto</p><?php endif;?>
<form method="POST"><input type="password" name="bicho_password" placeholder="password" autofocus required>
<input type="hidden" name="bicho_login" value="1">
<button type="submit">ACCEDER</button></form>
</div></body></html>
<?php exit;endif;?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BICHO | AI Server Intelligence</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root{--bg:#050508;--accent:#ff4d4d;--accent-glow:rgba(255,77,77,0.3);--cyan:#4dffff;--green:#4dff88;--yellow:#ffd84d;--orange:#ff944d;--text-main:#f0f0f5;--text-muted:#b0b0c0;--card:rgba(8,8,12,0.4);--border:rgba(255,255,255,0.13)}
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{width:100%;height:100%;min-height:100vh}
        body{background:var(--bg);color:var(--text-main);font-family:'Outfit',sans-serif;overflow:hidden;display:flex;flex-direction:column;background-image:linear-gradient(rgba(5, 5, 8, 0.8), rgba(5, 5, 8, 0.95)), url('https://images.unsplash.com/photo-1550751827-4bd374c3f58b?auto=format&fit=crop&q=80&w=2560');background-size:cover;background-position:center;background-repeat:no-repeat;}

        #hero{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:0.8s cubic-bezier(0.19,1,0.22,1);position:relative;z-index:200}
        .orb-wrapper{position:relative;cursor:pointer;transition:0.5s}.orb-wrapper:hover{transform:scale(1.05)}
        .orb{width:200px;height:200px;border-radius:50%;border:2px solid var(--accent);box-shadow:0 0 50px var(--accent-glow);display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);animation:breathe 5s infinite ease-in-out}
        .orb-inner{width:140px;height:140px;border-radius:50%;background:radial-gradient(circle at 35% 35%,var(--accent),#1a0000);box-shadow:inset 0 0 40px rgba(0,0,0,0.8);filter:blur(1px)}
        @keyframes breathe{0%,100%{box-shadow:0 0 40px var(--accent-glow);transform:scale(1)}50%{box-shadow:0 0 80px var(--accent-glow);transform:scale(1.08)}}
        .orb-label{margin-top:2.5rem;text-align:center}.orb-label h1{font-size:3rem;font-weight:900;letter-spacing:12px}.orb-label p{color:var(--accent);font-weight:600;letter-spacing:3px;font-size:0.75rem;opacity:0.8}
        .shifted{position:fixed;top:5px;left:10px;transform:scale(0.15);transform-origin:top left;opacity:1;z-index:300;min-width:44px;min-height:44px}

        #dashboard{position:fixed;bottom:-100vh;left:0;width:100%;height:100vh;background:rgba(5,5,8,0.15);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);transition:0.8s cubic-bezier(0.19,1,0.22,1);z-index:100;overflow:hidden}
        #dashboard.open{bottom:0}
        #dash-content{position:absolute;top:0;left:0;width:100%;height:100%;padding:12px 16px 8px 16px;display:grid;grid-template-columns:1fr 1fr 1fr 240px;grid-template-rows:auto 50px 1fr;gap:8px;transform-origin:top left;overflow:hidden}

        .logout-orb{position:absolute;top:10px;left:12px;z-index:300;width:42px;height:42px;border-radius:50%;border:2px solid var(--accent);box-shadow:0 0 15px var(--accent-glow);background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.3s;text-decoration:none}
        .logout-orb:hover{transform:scale(1.15);box-shadow:0 0 25px var(--accent-glow)}
        .logout-orb-inner{width:28px;height:28px;border-radius:50%;background:radial-gradient(circle at 35% 35%,var(--accent),#1a0000);box-shadow:inset 0 0 10px rgba(0,0,0,0.6)}
        .close-btn{position:absolute;top:8px;right:16px;color:var(--text-muted);font-size:0.75rem;font-weight:800;cursor:pointer;letter-spacing:2px;text-transform:uppercase;z-index:300;padding:8px;min-width:44px;min-height:44px;display:flex;align-items:center;justify-content:center}
        .zoom-controls{position:absolute;top:8px;right:100px;display:flex;gap:4px;z-index:300}
        .zoom-btn{width:32px;height:32px;border-radius:6px;background:var(--card);border:1px solid var(--border);color:var(--text-main);font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:'JetBrains Mono',monospace;transition:all 0.2s}
        .zoom-btn:hover{border-color:var(--accent);color:var(--accent)}
        .zoom-label{color:var(--text-muted);font-size:0.65rem;font-weight:700;display:flex;align-items:center;font-family:'JetBrains Mono',monospace}
        #screensaver{position:fixed;top:0;left:0;width:100%;height:100%;background:#000;z-index:99998;display:none;cursor:none}

        .gauge-card{background:var(--card);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:12px;padding:8px;display:flex;flex-direction:column;align-items:center;cursor:pointer;transition:border-color 0.3s,box-shadow 0.3s}
        .gauge-card:hover{border-color:rgba(255,77,77,0.4)}
        .gauge-label{font-size:0.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:2px}
        .gauge-canvas{width:100%;max-width:120px;height:75px}
        .gauge-value{font-family:'JetBrains Mono',monospace;font-size:1.3rem;font-weight:700;margin-top:-8px}
        .gauge-sub{font-size:0.7rem;color:var(--text-muted);font-family:'JetBrains Mono',monospace}
        .core-bar{width:12px;height:16px;background:rgba(255,255,255,0.05);border-radius:2px;position:relative;overflow:hidden}
        .core-bar-fill{position:absolute;bottom:0;left:0;width:100%;border-radius:2px;transition:height 0.5s,background 0.5s}

        .vu-section{grid-column:1/4;display:grid;grid-template-columns:repeat(10,1fr);gap:4px;align-items:end;height:50px;background:var(--card);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:12px;padding:6px 10px}
        .vu-bar-col{display:flex;flex-direction:column;align-items:center;gap:1px;height:100%;justify-content:flex-end}
        .vu-bar{width:100%;border-radius:2px;transition:height 0.5s ease,background 0.5s ease;min-height:2px}
        .vu-bar-label{font-size:0.55rem;color:var(--text-muted);font-family:'JetBrains Mono',monospace;text-align:center;white-space:nowrap}

        .chart-panel{grid-column:1/4;background:var(--card);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:12px;padding:8px;display:flex;flex-direction:column;overflow:hidden}
        .chart-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px}
        .chart-tabs{display:flex;gap:4px;flex-wrap:wrap}
        .chart-tab{background:rgba(255,255,255,0.03);border:1px solid var(--border);color:var(--text-muted);padding:3px 10px;border-radius:6px;cursor:pointer;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;transition:all 0.2s;font-family:'Outfit',sans-serif}
        .chart-tab.active{background:rgba(255,77,77,0.15);border-color:var(--accent);color:var(--accent)}
        .chart-tab:hover{border-color:rgba(255,77,77,0.3)}
        .chart-canvas-wrap{flex:1;position:relative;min-height:60px}
        .chart-canvas-wrap canvas{position:absolute;top:0;left:0;width:100%;height:100%}

        .right-panel{grid-row:1/4;grid-column:4;display:flex;flex-direction:column;gap:6px;overflow:hidden}
        .info-card{background:var(--card);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:12px;padding:8px 10px;cursor:pointer;transition:border-color 0.3s}
        .info-card:hover{border-color:rgba(255,77,77,0.3)}
        .ai-panel{background:rgba(255,77,77,0.05);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,77,77,0.15);border-radius:12px;padding:8px 10px;flex:1;display:flex;flex-direction:column;overflow-y:auto}
        .ai-list{list-style:none;margin-top:4px;flex:1;overflow-y:auto}
        .ai-list li{padding:4px 3px;border-bottom:1px solid rgba(255,255,255,0.05);font-size:0.75rem;display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);cursor:pointer;min-height:32px}
        .ai-list li:hover{background:rgba(255,255,255,0.03)}
        .proc-panel{background:var(--card);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:12px;padding:8px 10px;flex:1;overflow-y:auto}
        .proc-list{list-style:none;font-family:'JetBrains Mono',monospace}
        .proc-item{border-bottom:1px solid rgba(255,255,255,0.04)}.proc-item:last-child{border-bottom:none}
        .proc-header{display:flex;justify-content:space-between;align-items:center;padding:3px;cursor:pointer;border-radius:6px;transition:background 0.2s;min-height:36px}
        .proc-header:hover{background:rgba(255,255,255,0.03)}
        .proc-name{color:var(--text-main);font-size:0.75rem;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .proc-mem-badge{color:var(--accent);font-size:0.7rem;font-weight:800;margin-left:4px}

        .action-btn{background:rgba(255,77,77,0.1);border:1px solid rgba(255,77,77,0.3);color:var(--accent);padding:6px 10px;border-radius:6px;cursor:pointer;font-family:'Outfit',sans-serif;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;transition:all 0.2s;white-space:nowrap;min-height:36px}
        .action-btn:hover{background:rgba(255,77,77,0.25);transform:scale(1.05)}.action-btn:active{transform:scale(0.95)}
        .action-btn.green{background:rgba(77,255,136,0.1);border-color:rgba(77,255,136,0.3);color:var(--green)}
        .action-btn.cyan{background:rgba(77,255,255,0.1);border-color:rgba(77,255,255,0.3);color:var(--cyan)}
        .action-btn.yellow{background:rgba(255,216,77,0.1);border-color:rgba(255,216,77,0.3);color:var(--yellow)}

        .toast{position:fixed;top:12px;left:50%;transform:translateX(-50%) translateY(-80px);background:var(--card);border:1px solid var(--accent);color:var(--text-main);padding:6px 20px;border-radius:10px;font-size:0.8rem;font-weight:600;z-index:99999;transition:transform 0.4s cubic-bezier(0.19,1,0.22,1)}
        .toast.show{transform:translateX(-50%) translateY(0)}
        .label-sm{font-size:0.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:1px}

        .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:10000;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity 0.3s}
        .modal-overlay.open{opacity:1;pointer-events:all}
        .modal{background:rgba(10,10,15,0.7);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,77,77,0.25);border-radius:16px;padding:24px 28px;max-width:800px;width:92%;max-height:85vh;overflow-y:auto;position:relative;font-size:1rem;box-shadow:0 8px 40px rgba(0,0,0,0.5)}
        .modal-title{font-size:1.4rem;font-weight:800;letter-spacing:2px;text-transform:uppercase;margin-bottom:14px;color:var(--accent)}
        .modal-close{position:absolute;top:14px;right:18px;color:var(--text-muted);cursor:pointer;font-size:1.4rem;font-weight:800;min-width:44px;min-height:44px;display:flex;align-items:center;justify-content:center}
        .modal-close:hover{color:var(--accent)}
        .modal-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:1rem;align-items:center}
        .modal-row:last-child{border:none}
        .modal-label{color:var(--text-muted);font-weight:600;text-transform:uppercase;font-size:0.85rem;letter-spacing:1px}
        .modal-val{color:var(--text-main);font-family:'JetBrains Mono',monospace;font-size:0.95rem}
        .modal-pre{background:rgba(0,0,0,0.35);border:1px solid var(--border);border-radius:8px;padding:12px;font-family:'JetBrains Mono',monospace;font-size:0.85rem;color:var(--text-muted);white-space:pre-wrap;word-break:break-all;max-height:350px;overflow-y:auto;margin-top:10px;line-height:1.6}
        .modal-actions{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
        .modal .action-btn{font-size:0.85rem;padding:8px 14px;min-height:40px}
        .modal .label-sm{font-size:0.85rem}
        .chat-input{width:100%;background:rgba(0,0,0,0.3);border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text-main);font-family:'JetBrains Mono',monospace;font-size:0.95rem;outline:none;margin-top:10px;resize:none}
        .chat-input:focus{border-color:var(--accent)}
        .chat-response{margin-top:10px;padding:12px;background:rgba(255,77,77,0.03);border:1px solid rgba(255,77,77,0.1);border-radius:8px;font-size:0.95rem;line-height:1.6;white-space:pre-wrap;max-height:350px;overflow-y:auto}
        .svc-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);gap:8px;flex-wrap:wrap}
        .svc-row:last-child{border:none}
        .svc-name{font-weight:700;font-size:1.05rem}
        .svc-status{font-family:'JetBrains Mono',monospace;font-size:0.95rem;font-weight:700}
        .svc-actions{display:flex;gap:6px}

        @keyframes alertFlash{0%,100%{box-shadow:none;border-color:var(--border)}50%{box-shadow:0 0 30px rgba(255,77,77,0.5);border-color:var(--accent)}}
        @keyframes alertPulse{0%,100%{box-shadow:0 0 15px rgba(255,77,77,0.3);border-color:var(--accent)}50%{box-shadow:0 0 35px rgba(255,77,77,0.6);border-color:#ff6666}}
        .alert-warning{border-color:var(--accent)!important;box-shadow:0 0 20px rgba(255,77,77,0.4)!important;animation:alertPulse 2s ease-in-out infinite!important}
        .chart-legend{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-left:8px}
        .chart-legend-item{display:flex;align-items:center;gap:3px;font-size:0.6rem;font-family:'JetBrains Mono',monospace;color:var(--text-muted)}
        .chart-legend-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
        ::-webkit-scrollbar{width:3px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}
        footer{position:absolute;bottom:0;left:0;width:100%;padding:3px;text-align:center;font-size:0.6rem;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;z-index:150}
        footer span{color:var(--accent);font-weight:800}

        @media(max-width:1024px){
            #dash-content{grid-template-columns:1fr 1fr 200px;grid-template-rows:auto 50px 1fr;overflow-y:auto}
            .vu-section{grid-column:1/3}
            .chart-panel{grid-column:1/3}
            .right-panel{grid-column:3;grid-row:1/4}
        }
        @media(max-width:900px){
            #dash-content{grid-template-columns:1fr;grid-template-rows:auto;overflow-y:auto;padding:50px 10px 10px 10px;gap:6px}
            .gauge-card,.chart-panel,.right-panel{grid-column:1!important;grid-row:auto!important}
            .vu-section{grid-column:1!important;grid-row:auto!important;grid-template-columns:repeat(5,1fr);grid-template-rows:auto auto;height:auto;min-height:50px;padding:8px}
            .vu-bar-label{font-size:0.65rem}
            .chart-panel{min-height:200px}
            .chart-canvas-wrap{min-height:150px}
            .right-panel{min-height:auto}
            .action-btn{font-size:0.75rem;padding:8px 12px;min-height:40px}
            .svc-actions .action-btn{font-size:0.65rem;padding:6px 8px;min-height:36px}
            .proc-header{min-height:40px;padding:6px}
            .shifted{transform:scale(0.2);min-width:50px;min-height:50px}
            .close-btn{font-size:0.85rem;padding:10px;min-width:50px;min-height:50px}
            .zoom-controls{right:70px}
            .logout-orb{width:36px;height:36px;top:8px;left:8px}
            .logout-orb-inner{width:22px;height:22px}
            .chart-legend{display:none}
        }
        @media(max-width:480px){
            .modal{width:95%;padding:18px;max-height:90vh}
            .modal-title{font-size:1.15rem}
            .modal-row{flex-direction:column;gap:4px;font-size:0.95rem}
            .modal-label{font-size:0.8rem}
            .modal-val{font-size:0.9rem}
            .modal-pre{font-size:0.8rem}
            .svc-name{font-size:0.95rem}
            .gauge-canvas{max-width:100px;height:65px}
            .gauge-value{font-size:1.1rem}
            .vu-section{grid-template-columns:repeat(5,1fr)}
        }
    </style>
</head>
<body>
<div class="toast" id="toast"></div>
<div class="modal-overlay" id="modal-overlay" onclick="if(event.target===this)closeModal()"><div class="modal" id="modal"></div></div>

<div id="hero"><div class="orb-wrapper" id="orb-btn"><div class="orb"><div class="orb-inner"></div></div><div class="orb-label"><h1>BICHO</h1><p>AI SERVER ANALYTICS</p></div></div></div>

<div id="screensaver"></div>
<div id="dashboard">
<div id="dash-content">
    <a href="?logout" class="logout-orb" title="Logout"><div class="logout-orb-inner"></div></a>
    <div class="zoom-controls"><button class="zoom-btn" onclick="zoomOut()">-</button><span class="zoom-label" id="zoom-label">100%</span><button class="zoom-btn" onclick="zoomIn()">+</button></div>
    <div class="close-btn" id="close-btn">CLOSE</div>

    <div class="gauge-card" id="gc-cpu" onclick="showCPU()">
        <div class="gauge-label">CPU</div>
        <canvas class="gauge-canvas" id="gauge-cpu"></canvas>
        <div class="gauge-value" id="val-cpu">0%</div>
        <div class="gauge-sub" id="sub-cpu">--</div>
        <div id="cpu-cores" style="display:flex;gap:2px;margin-top:4px;flex-wrap:wrap;justify-content:center"></div>
    </div>
    <div class="gauge-card" id="gc-ram" onclick="showRAM()">
        <div class="gauge-label">RAM</div>
        <canvas class="gauge-canvas" id="gauge-ram"></canvas>
        <div class="gauge-value" id="val-ram">0%</div>
        <div class="gauge-sub" id="sub-ram">--</div>
    </div>
    <div class="gauge-card" style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <div style="text-align:center;cursor:pointer" id="gc-temp" onclick="showTemp()">
            <div class="gauge-label">TEMP</div>
            <canvas class="gauge-canvas" id="gauge-temp" style="max-width:90px;height:60px"></canvas>
            <div class="gauge-value" style="font-size:1rem" id="val-temp">--</div>
        </div>
        <div style="text-align:center;cursor:pointer" id="gc-bat" onclick="showBattery()">
            <div class="gauge-label">BATTERY</div>
            <canvas class="gauge-canvas" id="gauge-bat" style="max-width:90px;height:60px"></canvas>
            <div class="gauge-value" style="font-size:1rem" id="val-bat">--</div>
            <div class="gauge-sub" id="sub-bat">--</div>
        </div>
    </div>

    <div class="vu-section" id="vu-meter">
        <div class="vu-bar-col" id="vu-cpu"><div class="vu-bar"></div><div class="vu-bar-label">CPU</div></div>
        <div class="vu-bar-col" id="vu-ram"><div class="vu-bar"></div><div class="vu-bar-label">RAM</div></div>
        <div class="vu-bar-col" id="vu-disk"><div class="vu-bar"></div><div class="vu-bar-label">DISK</div></div>
        <div class="vu-bar-col" id="vu-temp"><div class="vu-bar"></div><div class="vu-bar-label">TEMP</div></div>
        <div class="vu-bar-col" id="vu-swap"><div class="vu-bar"></div><div class="vu-bar-label">SWAP</div></div>
        <div class="vu-bar-col" id="vu-bat"><div class="vu-bar"></div><div class="vu-bar-label">BAT</div></div>
        <div class="vu-bar-col" id="vu-p1"><div class="vu-bar"></div><div class="vu-bar-label">P1</div></div>
        <div class="vu-bar-col" id="vu-p2"><div class="vu-bar"></div><div class="vu-bar-label">P2</div></div>
        <div class="vu-bar-col" id="vu-p3"><div class="vu-bar"></div><div class="vu-bar-label">P3</div></div>
        <div class="vu-bar-col" id="vu-net"><div class="vu-bar"></div><div class="vu-bar-label">NET</div></div>
    </div>

    <div class="chart-panel">
        <div class="chart-header">
            <div class="chart-tabs">
                <div class="chart-tab active" onclick="setChart('all')">ALL</div>
                <div class="chart-tab" onclick="setChart('cpu')">CPU</div>
                <div class="chart-tab" onclick="setChart('ram')">RAM</div>
                <div class="chart-tab" onclick="setChart('temp')">TEMP</div>
                <div class="chart-tab" onclick="setChart('net')">NET</div>
            </div>
            <div class="chart-legend" id="chart-legend">
                <div class="chart-legend-item"><span class="chart-legend-dot" style="background:rgb(255,77,77)"></span>CPU</div>
                <div class="chart-legend-item"><span class="chart-legend-dot" style="background:rgb(77,200,255)"></span>RAM</div>
                <div class="chart-legend-item"><span class="chart-legend-dot" style="background:rgb(255,180,77)"></span>TEMP</div>
                <div class="chart-legend-item"><span class="chart-legend-dot" style="background:rgb(77,255,136)"></span>NET</div>
            </div>
            <span class="label-sm" id="chart-time">--</span>
        </div>
        <div class="chart-canvas-wrap"><canvas id="main-chart"></canvas></div>
    </div>

    <div class="right-panel">
        <div class="info-card" onclick="showSysInfo()">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div><div class="label-sm">UPTIME</div><div style="font-family:'JetBrains Mono',monospace;font-size:0.75rem;margin-top:2px" id="uptime-val">--</div></div>
                <div style="text-align:right"><div class="label-sm">DISK</div><div style="font-family:'JetBrains Mono',monospace;font-size:0.75rem;margin-top:2px" id="disk-val">--</div></div>
            </div>
            <div style="height:3px;background:rgba(255,255,255,0.03);border-radius:2px;margin-top:4px;overflow:hidden"><div id="disk-bar" style="height:100%;background:var(--accent);width:0%;transition:width 1s"></div></div>
        </div>

        <div class="info-card" onclick="showNetwork()">
            <div class="label-sm">NETWORK I/O</div>
            <div style="display:flex;justify-content:space-between;margin-top:4px;font-family:'JetBrains Mono',monospace;font-size:0.75rem">
                <div><span style="color:var(--green)">&#9660;</span> RX <span id="net-rx">0</span></div>
                <div><span style="color:var(--accent)">&#9650;</span> TX <span id="net-tx">0</span></div>
            </div>
        </div>

        <div class="info-card" style="display:flex;flex-direction:column;gap:3px">
            <div class="label-sm" style="margin-bottom:1px">ACTIONS</div>
            <button class="action-btn green" style="width:100%" onclick="event.stopPropagation();doAction('free_memory')">LIBERAR CACHE</button>
            <button class="action-btn" style="width:100%" onclick="event.stopPropagation();showServices()">SERVICES</button>
            <button class="action-btn cyan" style="width:100%" onclick="event.stopPropagation();showLogs()">LOGS</button>
            <button class="action-btn" style="width:100%" onclick="event.stopPropagation();location.reload()">REFRESH</button>
            <button class="action-btn yellow" style="width:100%" onclick="event.stopPropagation();confirmAction('reboot','Reiniciar equipo?')">REBOOT</button>
            <button class="action-btn" style="width:100%" onclick="event.stopPropagation();confirmAction('shutdown','Apagar equipo?')">SHUTDOWN</button>
        </div>

        <div class="ai-panel">
            <div class="label-sm">AI MODELS <span id="ai-status" style="float:right"></span></div>
            <ul class="ai-list" id="ai-list"><li>Loading...</li></ul>
        </div>

        <div class="proc-panel">
            <div class="label-sm" style="margin-bottom:3px">PROCESSES</div>
            <ul class="proc-list" id="proc-list"><li class="proc-item"><div class="proc-header"><span class="proc-name">Loading...</span></div></li></ul>
        </div>
    </div>
</div>
</div>
<footer>CREATED BY <span>CHEMADEV</span> | POWERED BY BICHO AI</footer>

<script>
const CSRF='<?=$_SESSION['csrf_token']??''?>';
const orb=document.getElementById('orb-btn'),dash=document.getElementById('dashboard'),hero=document.getElementById('hero'),closebtn=document.getElementById('close-btn');
const H={cpu:[],ram:[],temp:[],net_rx:[]};const MAX_H=60;
let activeChart='all',lastNetRx=null,lastNetTx=null,lastD=null,lastFetchTime=null;
let userClosed=false,fetchInProgress=false,maxNetSpeed=1024;

function esc(s){if(s===null||s===undefined)return '';const d=document.createElement('div');d.textContent=String(s);return d.innerHTML}

let toastTimer=null;
function showToast(m,dur){const t=document.getElementById('toast');t.textContent=m;t.classList.add('show');if(toastTimer)clearTimeout(toastTimer);toastTimer=setTimeout(()=>{t.classList.remove('show');toastTimer=null},dur||2500)}
function showAlertToast(m){showToast(m,5000)}

function handleResponse(r){if(r.status===401||r.status===403){location.reload();return null}return r.json()}
function doAction(a,x){fetch('',{method:'POST',body:new URLSearchParams({action:a,csrf_token:CSRF,...x})}).then(handleResponse).then(d=>{if(d){showToast(d.msg);fetchStats()}}).catch(()=>showToast('Error de conexion'))}
function confirmAction(a,m){openModal('<div class="modal-title">'+esc(m)+'</div><div class="modal-actions"><button class="action-btn" style="padding:8px 24px;font-size:0.85rem" onclick="closeModal();doAction(\''+a+'\')">SI</button><button class="action-btn green" style="padding:8px 24px;font-size:0.85rem" onclick="closeModal()">NO</button></div>')}
function setChart(t){activeChart=t;document.querySelectorAll('.chart-tab').forEach(e=>e.classList.toggle('active',e.textContent.toLowerCase()===t));document.getElementById('chart-legend').style.display=t==='all'?'flex':'none';drawChart()}

function getCpuColor(p){return p>80?'var(--accent)':p>60?'var(--orange)':p>40?'var(--yellow)':'var(--green)'}
function getCpuHex(p){return p>80?'#ff4d4d':p>60?'#ff944d':p>40?'#ffd84d':'#4dff88'}
function getRamColor(p){return p>80?'var(--accent)':p>60?'var(--orange)':p>40?'var(--yellow)':'var(--green)'}
function getRamHex(p){return p>80?'#ff4d4d':p>60?'#ff944d':p>40?'#ffd84d':'#4dff88'}
function getTempColor(p){return p>80?'var(--accent)':p>65?'var(--orange)':p>50?'var(--yellow)':'var(--green)'}
function getTempHex(p){return p>80?'#ff4d4d':p>65?'#ff944d':p>50?'#ffd84d':'#4dff88'}
function getBatColor(p){return p>50?'var(--green)':p>20?'var(--yellow)':'var(--accent)'}
function getBatHex(p){return p>50?'#4dff88':p>20?'#ffd84d':'#ff4d4d'}

function formatSpeed(bps){
    if(bps>=1073741824)return(bps/1073741824).toFixed(1)+' GB/s';
    if(bps>=1048576)return(bps/1048576).toFixed(1)+' MB/s';
    if(bps>=1024)return(bps/1024).toFixed(1)+' KB/s';
    return Math.round(bps)+' B/s';
}

function openModal(html){document.getElementById('modal').innerHTML='<div class="modal-close" onclick="closeModal()">&#10005;</div>'+html;document.getElementById('modal-overlay').classList.add('open')}
function closeModal(){document.getElementById('modal-overlay').classList.remove('open')}

function modalRow(label,val){return '<div class="modal-row"><span class="modal-label">'+esc(label)+'</span><span class="modal-val">'+esc(val)+'</span></div>'}

function showCPU(){
    fetch('?detail=cpu').then(handleResponse).then(d=>{
        if(!d)return;
        let fb='';d.freqs.forEach((f,i)=>{const p=Math.min(f/3000*100,100);const c=p>80?'var(--accent)':p>50?'var(--yellow)':'var(--green)';fb+='<div style="display:flex;align-items:center;gap:8px;margin:3px 0"><span style="width:50px;font-size:0.75rem;color:var(--text-muted)">Core '+i+'</span><div style="flex:1;height:8px;background:rgba(255,255,255,0.05);border-radius:4px;overflow:hidden"><div style="width:'+p+'%;height:100%;background:'+c+';border-radius:4px;transition:width 0.5s"></div></div><span style="width:60px;text-align:right;font-size:0.75rem">'+f+' MHz</span></div>'});
        openModal('<div class="modal-title">CPU DETAIL</div>'+modalRow('Model',d.model)+modalRow('Governor',d.governor)+modalRow('Freq Range',d.min_freq+' - '+d.max_freq+' MHz')+'<div style="margin-top:12px"><div class="label-sm" style="margin-bottom:6px">FREQUENCY PER CORE</div>'+fb+'</div>')
    }).catch(()=>showToast('Error cargando CPU'))
}
function showRAM(){
    fetch('?detail=ram').then(handleResponse).then(d=>{
        if(!d)return;
        const items=[['Total',d.total+' MB'],['Used',d.used+' MB'],['Free',d.free+' MB'],['Shared',d.shared+' MB'],['Buff/Cache',d.buff_cache+' MB'],['Available',d.available+' MB'],['Swap Total',d.swap_total+' MB'],['Swap Used',d.swap_used+' MB'],['Swap Free',d.swap_free+' MB']];
        openModal('<div class="modal-title">RAM DETAIL</div>'+items.map(i=>modalRow(i[0],i[1])).join('')+'<div class="modal-actions"><button class="action-btn green" onclick="doAction(\'free_memory\');closeModal()">LIBERAR CACHE</button></div>')
    }).catch(()=>showToast('Error cargando RAM'))
}
function showTemp(){
    fetch('?detail=temp').then(handleResponse).then(d=>{
        if(!d)return;
        const items=[['Package',d.package],['Core 0',d.core0],['Core 1',d.core1],['WiFi',d.wifi]];
        let rows=items.map(i=>{const c=getTempColor(i[1]);return '<div class="modal-row"><span class="modal-label">'+esc(i[0])+'</span><span class="modal-val" style="color:'+c+'">'+esc(i[1])+'°C</span></div>'}).join('');
        rows+=modalRow('Voltage',d.voltage+' V');
        openModal('<div class="modal-title">TEMPERATURE</div>'+rows)
    }).catch(()=>showToast('Error cargando temperatura'))
}
function showBattery(){
    fetch('?detail=battery').then(handleResponse).then(d=>{
        if(!d)return;
        const items=[['State',d.state],['Percentage',d.percentage+'%'],['Energy',d.energy+' Wh'],['Full',d.energy_full+' Wh'],['Design',d.energy_design+' Wh'],['Voltage',d.voltage+' V'],['Power',d.power+' W'],['Health',d.health+'%'],['Time',d.time]];
        openModal('<div class="modal-title">BATTERY</div>'+items.map(i=>modalRow(i[0],i[1])).join(''))
    }).catch(()=>showToast('Error cargando bateria'))
}
function showNetwork(){
    fetch('?detail=network').then(handleResponse).then(d=>{
        if(!d)return;
        let ts='';if(d.tailscale){d.tailscale.trim().split('\n').forEach(l=>{const p=l.trim().split(/\s+/);if(p.length>=4&&p[0].match(/^\d/)){ts+='<div class="svc-row"><span class="svc-name">'+esc(p[1])+'</span><span class="svc-status" style="font-size:0.75rem">'+esc(p[0])+'</span><span class="svc-status">'+esc(p[3])+'</span><button class="action-btn cyan" style="font-size:0.65rem" onclick="doPing(\''+esc(p[0])+'\')">PING</button></div>'}})}
        openModal('<div class="modal-title">NETWORK</div>'+modalRow('Public IP',d.public_ip)+'<div style="margin-top:10px"><div class="label-sm">INTERFACES</div><div class="modal-pre">'+esc(d.interfaces)+'</div></div><div style="margin-top:10px"><div class="label-sm">TAILSCALE DEVICES</div>'+ts+'</div><div id="ping-result"></div>')
    }).catch(()=>showToast('Error cargando red'))
}
function doPing(host){
    document.getElementById('ping-result').innerHTML='<div class="modal-pre" style="margin-top:8px">Pinging '+esc(host)+'...</div>';
    fetch('',{method:'POST',body:new URLSearchParams({action:'ping',host:host,csrf_token:CSRF})}).then(handleResponse).then(d=>{if(d)document.getElementById('ping-result').innerHTML='<div class="modal-pre" style="margin-top:8px">'+esc(d.msg)+'</div>'}).catch(()=>showToast('Error en ping'))
}
function showSysInfo(){
    Promise.all([fetch('?detail=sysinfo').then(handleResponse),fetch('?detail=disk').then(handleResponse)]).then(([s,dk])=>{
        if(!s||!dk)return;
        const items=[['Hostname',s.hostname],['OS',s.os],['Kernel',s.kernel],['Arch',s.arch],['Last Boot',s.last_boot],['Users',s.users||'None']];
        openModal('<div class="modal-title">SYSTEM INFO</div>'+items.map(i=>modalRow(i[0],i[1])).join('')+'<div style="margin-top:10px"><div class="label-sm">DISK PARTITIONS</div><div class="modal-pre">'+esc(dk.partitions)+'</div></div>')
    }).catch(()=>showToast('Error cargando info'))
}
function showServices(){
    fetch('?detail=services').then(handleResponse).then(svcs=>{
        if(!svcs)return;
        let rows=svcs.map(s=>{const color=s.status==='active'?'var(--green)':'var(--accent)';return '<div class="svc-row"><span class="svc-name">'+esc(s.name)+'</span><span class="svc-status" style="color:'+color+'">'+esc(s.status).toUpperCase()+'</span><div class="svc-actions"><button class="action-btn green" onclick="doAction(\'start_service\',{service:\''+esc(s.name)+'\'});setTimeout(showServices,1000)">START</button><button class="action-btn" onclick="doAction(\'restart_service\',{service:\''+esc(s.name)+'\'});setTimeout(showServices,1000)">RESTART</button><button class="action-btn yellow" onclick="doAction(\'stop_service\',{service:\''+esc(s.name)+'\'});setTimeout(showServices,1000)">STOP</button></div></div>'}).join('');
        openModal('<div class="modal-title">SERVICES</div>'+rows)
    }).catch(()=>showToast('Error cargando servicios'))
}
function showLogs(unit){
    const u=unit||'';
    fetch('?detail=logs&lines=50&unit='+encodeURIComponent(u)).then(handleResponse).then(d=>{
        if(!d)return;
        openModal('<div class="modal-title">SYSTEM LOGS</div><div style="display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap"><button class="action-btn '+(u===''?'green':'')+'" onclick="showLogs()">ALL</button><button class="action-btn '+(u==='nginx'?'green':'')+'" onclick="showLogs(\'nginx\')">NGINX</button><button class="action-btn '+(u==='ollama'?'green':'')+'" onclick="showLogs(\'ollama\')">OLLAMA</button><button class="action-btn '+(u==='docker'?'green':'')+'" onclick="showLogs(\'docker\')">DOCKER</button><button class="action-btn '+(u==='ssh'?'green':'')+'" onclick="showLogs(\'ssh\')">SSH</button></div><div class="modal-pre" style="max-height:400px">'+esc(d.logs||'Sin logs')+'</div>')
    }).catch(()=>showToast('Error cargando logs'))
}
function showChat(model){
    openModal('<div class="modal-title">CHAT - '+esc(model)+'</div><div id="chat-history" class="chat-response" style="min-height:100px">Escribe algo para empezar...</div><textarea class="chat-input" id="chat-input" rows="2" placeholder="Escribe tu mensaje..." onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();sendChat(\''+esc(model)+'\')}"></textarea><div class="modal-actions"><button class="action-btn green" onclick="sendChat(\''+esc(model)+'\')">ENVIAR</button></div>')
}
function sendChat(model){
    const input=document.getElementById('chat-input');const prompt=input.value.trim();if(!prompt)return;
    const hist=document.getElementById('chat-history');
    const userDiv=document.createElement('div');userDiv.style.cssText='color:var(--cyan);margin-top:8px';userDiv.innerHTML='<b>You:</b> '+esc(prompt);
    const thinkDiv=document.createElement('div');thinkDiv.style.cssText='color:var(--text-muted);margin-top:4px';thinkDiv.innerHTML='<i>Thinking...</i>';
    hist.appendChild(userDiv);hist.appendChild(thinkDiv);input.value='';
    fetch('',{method:'POST',body:new URLSearchParams({action:'chat',model:model,prompt:prompt,csrf_token:CSRF})}).then(handleResponse).then(d=>{
        thinkDiv.remove();
        if(!d)return;
        const respDiv=document.createElement('div');respDiv.style.cssText='color:var(--green);margin-top:4px';respDiv.innerHTML='<b>'+esc(model)+':</b> '+esc(d.response);
        hist.appendChild(respDiv);hist.scrollTop=hist.scrollHeight;
    }).catch(()=>{thinkDiv.textContent='Error de conexion'})
}

function showProcess(p){
    const cn=esc(p.command.split('/').pop().split(' ')[0]);
    openModal(
        '<div class="modal-title" style="display:flex;justify-content:space-between;align-items:center"><span>'+cn+'</span><span style="font-size:0.8rem;color:var(--text-muted)">PID '+esc(p.pid)+'</span></div>'+
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0">'+
            '<div style="background:rgba(255,77,77,0.05);border:1px solid rgba(255,77,77,0.15);border-radius:10px;padding:12px;text-align:center"><div class="label-sm">CPU</div><div style="font-family:JetBrains Mono,monospace;font-size:1.5rem;font-weight:700;color:'+getCpuColor(parseFloat(p.cpu))+'">'+esc(p.cpu)+'%</div></div>'+
            '<div style="background:rgba(77,200,255,0.05);border:1px solid rgba(77,200,255,0.15);border-radius:10px;padding:12px;text-align:center"><div class="label-sm">MEMORY</div><div style="font-family:JetBrains Mono,monospace;font-size:1.5rem;font-weight:700;color:'+getRamColor(parseFloat(p.mem))+'">'+esc(p.mem)+'%</div></div>'+
        '</div>'+modalRow('User',p.user)+modalRow('RSS Memory',p.rss+' MB')+
        '<div class="modal-row"><span class="modal-label">Full Command</span><span class="modal-val" style="font-size:0.75rem;word-break:break-all">'+esc(p.command)+'</span></div>'+
        '<div style="margin-top:16px"><div class="label-sm" style="margin-bottom:8px">ACTIONS</div>'+
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">'+
            '<button class="action-btn green" style="padding:10px;font-size:0.8rem" onclick="doAction(\'renice\',{pid:\''+esc(p.pid)+'\',nice:\'10\'});closeModal()">NICE + (Lower Priority)</button>'+
            '<button class="action-btn cyan" style="padding:10px;font-size:0.8rem" onclick="doAction(\'renice\',{pid:\''+esc(p.pid)+'\',nice:\'-5\'});closeModal()">NICE - (Higher Priority)</button>'+
            '<button class="action-btn yellow" style="padding:10px;font-size:0.8rem" onclick="confirmKill(\''+esc(p.pid)+'\',\''+cn+'\',\'kill_process\')">KILL (SIGTERM)</button>'+
            '<button class="action-btn" style="padding:10px;font-size:0.8rem" onclick="confirmKill(\''+esc(p.pid)+'\',\''+cn+'\',\'kill9_process\')">KILL -9 (SIGKILL)</button>'+
        '</div></div>'
    );
}
function confirmKill(pid,name,action){
    openModal('<div class="modal-title">KILL PROCESS</div><div style="text-align:center;padding:20px;font-size:1.1rem">Terminar <b style="color:var(--accent)">'+esc(name)+'</b> (PID '+esc(pid)+')?</div><div class="modal-actions" style="justify-content:center"><button class="action-btn" style="padding:10px 30px;font-size:0.9rem" onclick="closeModal();doAction(\''+action+'\',{pid:\''+pid+'\'})">SI, MATAR</button><button class="action-btn green" style="padding:10px 30px;font-size:0.9rem" onclick="closeModal()">CANCELAR</button></div>');
}

// Alerts
let alertCooldown={},flashTimers={};
function playAlertSound(){
    try{const ctx=new(window.AudioContext||window.webkitAudioContext)();const osc=ctx.createOscillator();const gain=ctx.createGain();osc.connect(gain);gain.connect(ctx.destination);osc.type='square';osc.frequency.value=800;gain.gain.value=0.15;osc.start();osc.frequency.setValueAtTime(800,ctx.currentTime);osc.frequency.setValueAtTime(600,ctx.currentTime+0.1);osc.frequency.setValueAtTime(800,ctx.currentTime+0.2);gain.gain.setValueAtTime(0.15,ctx.currentTime+0.3);gain.gain.linearRampToValueAtTime(0,ctx.currentTime+0.4);osc.stop(ctx.currentTime+0.4)}catch(e){}
}
function flashElement(selector){
    const el=document.querySelector(selector);if(!el)return;
    if(flashTimers[selector]){clearTimeout(flashTimers[selector].a);clearTimeout(flashTimers[selector].w)}
    el.style.animation='alertFlash 0.5s ease 5';
    flashTimers[selector]={
        a:setTimeout(()=>{el.style.animation='';el.classList.add('alert-warning')},2600),
        w:setTimeout(()=>{el.classList.remove('alert-warning');delete flashTimers[selector]},30000)
    };
}
function checkAlerts(d){
    const now=Date.now(),cpuPct=Math.min(d.cpu,100);
    if(cpuPct>90&&(!alertCooldown.cpu||now-alertCooldown.cpu>60000)){alertCooldown.cpu=now;playAlertSound();flashElement('#gc-cpu');showAlertToast('ALERTA: CPU al '+cpuPct.toFixed(0)+'%')}
    if(d.ram>90&&(!alertCooldown.ram||now-alertCooldown.ram>60000)){alertCooldown.ram=now;playAlertSound();flashElement('#gc-ram');showAlertToast('ALERTA: RAM al '+d.ram+'%')}
    if(d.temp>80&&(!alertCooldown.temp||now-alertCooldown.temp>60000)){alertCooldown.temp=now;playAlertSound();flashElement('#gc-temp');showAlertToast('ALERTA: Temperatura '+d.temp+'°C')}
    if(d.disk>90&&(!alertCooldown.disk||now-alertCooldown.disk>60000)){alertCooldown.disk=now;playAlertSound();showAlertToast('ALERTA: Disco al '+d.disk+'%')}
    if(d.battery<15&&d.bat_status!=='Charging'&&(!alertCooldown.bat||now-alertCooldown.bat>60000)){alertCooldown.bat=now;playAlertSound();flashElement('#gc-bat');showAlertToast('ALERTA: Bateria al '+d.battery+'%')}
}

// Gauges
function drawGauge(id,pct,color,max){const c=document.getElementById(id);if(!c)return;const dp=2,w=c.offsetWidth*dp,h=c.offsetHeight*dp;if(w<=0||h<=0)return;c.width=w;c.height=h;const ctx=c.getContext('2d');ctx.clearRect(0,0,w,h);const cx=w/2,cy=h*0.85,r=Math.min(cx,cy)*0.85,sA=Math.PI*1.15,eA=Math.PI*-0.15,rng=eA-sA+2*Math.PI,v=Math.min(pct/(max||100),1);ctx.beginPath();ctx.arc(cx,cy,r,sA,sA+rng);ctx.strokeStyle='rgba(255,255,255,0.06)';ctx.lineWidth=w*0.06;ctx.lineCap='round';ctx.stroke();ctx.beginPath();ctx.arc(cx,cy,r,sA,sA+rng*v);ctx.strokeStyle=color;ctx.lineWidth=w*0.06;ctx.lineCap='round';ctx.shadowColor=color;ctx.shadowBlur=15;ctx.stroke();ctx.shadowBlur=0;for(let i=0;i<=10;i++){const a=sA+(rng*i/10),inn=r-w*0.04,out=r+w*0.04;ctx.beginPath();ctx.moveTo(cx+Math.cos(a)*inn,cy+Math.sin(a)*inn);ctx.lineTo(cx+Math.cos(a)*out,cy+Math.sin(a)*out);ctx.strokeStyle=i<=v*10?color:'rgba(255,255,255,0.1)';ctx.lineWidth=i%5===0?2:1;ctx.stroke()}const nA=sA+rng*v,nL=r*0.7;ctx.beginPath();ctx.moveTo(cx,cy);ctx.lineTo(cx+Math.cos(nA)*nL,cy+Math.sin(nA)*nL);ctx.strokeStyle='#fff';ctx.lineWidth=2;ctx.stroke();ctx.beginPath();ctx.arc(cx,cy,4,0,Math.PI*2);ctx.fillStyle=color;ctx.fill()}
function setVU(id,p,mx){const c=document.getElementById(id);if(!c)return;const b=c.querySelector('.vu-bar');if(!b)return;const v=Math.min(p/(mx||100),1);b.style.height=Math.max(v*100,5)+'%';b.style.background=v>0.8?'var(--accent)':v>0.6?'var(--orange)':v>0.4?'var(--yellow)':'var(--green)';b.style.boxShadow='0 0 6px '+b.style.background}

function drawSingleLine(ctx,data,color,pad,cw,ch,mx,st,fill){
    if(data.length<2)return;
    ctx.beginPath();ctx.strokeStyle=color;ctx.lineWidth=2.5;ctx.lineJoin='round';
    data.forEach((v,i)=>{const x=pad.l+i*st,y=pad.t+ch-(v/mx)*ch;if(i===0)ctx.moveTo(x,y);else ctx.lineTo(x,y)});
    ctx.shadowColor=color;ctx.shadowBlur=8;ctx.stroke();ctx.shadowBlur=0;
    if(fill){ctx.lineTo(pad.l+(data.length-1)*st,pad.t+ch);ctx.lineTo(pad.l,pad.t+ch);ctx.closePath();const gr=ctx.createLinearGradient(0,pad.t,0,pad.t+ch);gr.addColorStop(0,color.replace('rgb','rgba').replace(')',',0.15)'));gr.addColorStop(1,'transparent');ctx.fillStyle=gr;ctx.fill()}
    if(data.length>0){const lx=pad.l+(data.length-1)*st,ly=pad.t+ch-(data[data.length-1]/mx)*ch;ctx.beginPath();ctx.arc(lx,ly,3,0,Math.PI*2);ctx.fillStyle=color;ctx.shadowColor=color;ctx.shadowBlur=8;ctx.fill();ctx.shadowBlur=0}
}
function drawChart(){const c=document.getElementById('main-chart');if(!c)return;const w_=c.parentElement;const dp=2,w=w_.offsetWidth*dp,h=w_.offsetHeight*dp;if(w<=0||h<=0)return;c.width=w;c.height=h;const ctx=c.getContext('2d');ctx.clearRect(0,0,w,h);
    const pad={t:15,r:8,b:20,l:35},cw=w-pad.l-pad.r,ch=h-pad.t-pad.b,st=cw/(MAX_H-1);
    function drawGrid(mx){ctx.strokeStyle='rgba(255,255,255,0.04)';ctx.lineWidth=1;for(let i=0;i<=4;i++){const y=pad.t+ch-(ch*i/4);ctx.beginPath();ctx.moveTo(pad.l,y);ctx.lineTo(pad.l+cw,y);ctx.stroke();ctx.fillStyle='rgba(255,255,255,0.25)';ctx.font=(8*dp)+'px JetBrains Mono';ctx.textAlign='right';ctx.fillText(Math.round(mx*i/4),pad.l-4,y+3)}}
    if(activeChart==='all'){
        const sets=[{data:H.cpu,color:'rgb(255,77,77)'},{data:H.ram,color:'rgb(77,200,255)'},{data:H.temp,color:'rgb(255,180,77)'},{data:H.net_rx,color:'rgb(77,255,136)'}];
        let mx=1;sets.forEach(s=>{if(s.data.length>0)mx=Math.max(mx,...s.data)});mx*=1.1;
        drawGrid(mx);sets.forEach(s=>drawSingleLine(ctx,s.data,s.color,pad,cw,ch,mx,st,false));
    }else{
        let data,color;if(activeChart==='cpu'){data=H.cpu;color='rgb(255,77,77)'}else if(activeChart==='ram'){data=H.ram;color='rgb(77,200,255)'}else if(activeChart==='temp'){data=H.temp;color='rgb(255,180,77)'}else{data=H.net_rx;color='rgb(77,255,136)'}
        if(data.length<2)return;const mx=Math.max(...data,1)*1.1;
        drawGrid(mx);drawSingleLine(ctx,data,color,pad,cw,ch,mx,st,true);
    }
}

function fetchStats(){
    if(fetchInProgress)return;fetchInProgress=true;
    const fetchStart=Date.now();
    fetch('?data=1').then(handleResponse).then(d=>{
        if(!d)return;
        const now=Date.now();
        const elapsed=lastFetchTime?(now-lastFetchTime)/1000:3;
        lastFetchTime=now;
        lastD=d;const cpuPct=Math.min(d.cpu,100);
        drawGauge('gauge-cpu',cpuPct,getCpuHex(cpuPct),100);
        document.getElementById('val-cpu').textContent=cpuPct.toFixed(1)+'%';document.getElementById('val-cpu').style.color=getCpuColor(cpuPct);
        document.getElementById('sub-cpu').textContent=d.cpu_cores+' cores';
        const cd=document.getElementById('cpu-cores');cd.innerHTML='';
        if(d.cpu_per_core)d.cpu_per_core.forEach((u)=>{const c=getCpuColor(u);cd.innerHTML+='<div style="text-align:center"><div class="core-bar"><div class="core-bar-fill" style="height:'+u+'%;background:'+c+'"></div></div></div>'});
        drawGauge('gauge-ram',d.ram,getRamHex(d.ram),100);
        document.getElementById('val-ram').textContent=d.ram+'%';document.getElementById('val-ram').style.color=getRamColor(d.ram);
        document.getElementById('sub-ram').textContent=d.ram_used+'/'+d.ram_total+'MB';
        drawGauge('gauge-temp',d.temp,getTempHex(d.temp),100);
        document.getElementById('val-temp').textContent=d.temp+'°C';document.getElementById('val-temp').style.color=getTempColor(d.temp);
        drawGauge('gauge-bat',d.battery,getBatHex(d.battery),100);document.getElementById('val-bat').textContent=d.battery+'%';document.getElementById('val-bat').style.color=getBatColor(d.battery);document.getElementById('sub-bat').textContent=d.bat_status||'N/A';
        document.getElementById('uptime-val').textContent=(d.uptime||'N/A').toUpperCase();
        document.getElementById('disk-val').textContent=d.disk_free+'G/'+d.disk_total+'G';document.getElementById('disk-bar').style.width=d.disk+'%';
        // Net speed from raw bytes with real elapsed time
        let rxSpeed=0,txSpeed=0;
        if(lastNetRx!==null&&elapsed>0){
            rxSpeed=Math.max((d.net_rx-lastNetRx)/elapsed,0);
            txSpeed=Math.max((d.net_tx-lastNetTx)/elapsed,0);
        }
        document.getElementById('net-rx').textContent=formatSpeed(rxSpeed);
        document.getElementById('net-tx').textContent=formatSpeed(txSpeed);
        document.getElementById('chart-time').textContent=d.time;
        // VU
        setVU('vu-cpu',cpuPct,100);setVU('vu-ram',d.ram,100);setVU('vu-disk',d.disk,100);setVU('vu-temp',d.temp,100);
        setVU('vu-swap',d.swap_total>0?(d.swap_used/d.swap_total)*100:0,100);setVU('vu-bat',d.battery,100);
        if(d.processes.length>0)setVU('vu-p1',parseFloat(d.processes[0].mem),100);
        if(d.processes.length>1)setVU('vu-p2',parseFloat(d.processes[1].mem),100);
        if(d.processes.length>2)setVU('vu-p3',parseFloat(d.processes[2].mem),100);
        // Adaptive net VU
        const totalSpeedKB=(rxSpeed+txSpeed)/1024;
        if(totalSpeedKB>maxNetSpeed)maxNetSpeed=totalSpeedKB;
        setVU('vu-net',totalSpeedKB,maxNetSpeed||1);
        // Process labels
        if(d.processes.length>0)document.querySelector('#vu-p1 .vu-bar-label').textContent=d.processes[0].command.split('/').pop().split(' ')[0].substring(0,6);
        if(d.processes.length>1)document.querySelector('#vu-p2 .vu-bar-label').textContent=d.processes[1].command.split('/').pop().split(' ')[0].substring(0,6);
        if(d.processes.length>2)document.querySelector('#vu-p3 .vu-bar-label').textContent=d.processes[2].command.split('/').pop().split(' ')[0].substring(0,6);
        // History - push speed in KB/s for chart
        const rxKB=rxSpeed/1024;
        H.cpu.push(cpuPct);H.ram.push(d.ram);H.temp.push(d.temp);H.net_rx.push(rxKB);
        lastNetRx=d.net_rx;lastNetTx=d.net_tx;
        if(H.cpu.length>MAX_H)H.cpu.shift();if(H.ram.length>MAX_H)H.ram.shift();if(H.temp.length>MAX_H)H.temp.shift();if(H.net_rx.length>MAX_H)H.net_rx.shift();
        drawChart();
        // AI
        const list=document.getElementById('ai-list');list.innerHTML='';
        if(d.models.length>0){d.models.forEach(m=>{const em=esc(m);const run=d.running_models.includes(m);const st=run?'<span style="color:var(--green);font-size:0.6rem">&#9679; VRAM</span>':'<span style="font-size:0.6rem">READY</span>';const btn=run?' <button class="action-btn" style="font-size:0.6rem;padding:3px 6px" onclick="event.stopPropagation();doAction(\'unload_model\',{model:\''+em+'\'})">UNLOAD</button>':'';list.innerHTML+='<li onclick="showChat(\''+em+'\')"><span style="font-size:0.7rem">'+em+'</span><span>'+st+btn+'</span></li>'})}else{list.innerHTML='<li style="font-size:0.7rem">OLLAMA OFFLINE</li>'}
        document.getElementById('ai-status').innerHTML=d.running_models.length>0?'<span style="color:var(--green);font-size:0.55rem">&#9679; '+d.running_models.length+' LOADED</span>':'<span style="color:var(--text-muted);font-size:0.55rem">&#9679; IDLE</span>';
        // Processes
        const pl=document.getElementById('proc-list');pl.innerHTML='';
        d.processes.forEach(p=>{const cn=esc(p.command.split('/').pop().split(' ')[0]);
        pl.innerHTML+='<li class="proc-item" data-pid="'+esc(p.pid)+'"><div class="proc-header" onclick="showProcess('+esc(JSON.stringify(p)).replace(/"/g,'&quot;')+')"><span class="proc-name">'+cn+'</span><span class="proc-mem-badge">'+esc(p.mem)+'%</span></div></li>'});
        checkAlerts(d);
    }).catch(e=>{console.error('fetchStats error:',e)}).finally(()=>{fetchInProgress=false})
}

// Zoom
let zoomLevel=parseFloat(localStorage.getItem('bicho-zoom')||'100');
if(window.innerWidth<=900)zoomLevel=100;
applyZoom();
function applyZoom(){const s=zoomLevel/100;const dc=document.getElementById('dash-content');if(dc){dc.style.transform='scale('+s+')';dc.style.width=(100/s)+'%';dc.style.height=(100/s)+'%'}document.getElementById('zoom-label').textContent=zoomLevel+'%';localStorage.setItem('bicho-zoom',zoomLevel);setTimeout(()=>drawChart(),150)}
function zoomIn(){zoomLevel=Math.min(zoomLevel+10,200);applyZoom()}
function zoomOut(){zoomLevel=Math.max(zoomLevel-10,50);applyZoom()}

let statsInterval;
function openDashboard(){clearInterval(statsInterval);hero.classList.add('shifted');dash.classList.add('open');fetchStats();statsInterval=setInterval(fetchStats,3000)}
function closeDashboard(){hero.classList.remove('shifted');dash.classList.remove('open');clearInterval(statsInterval);userClosed=true}
orb.addEventListener('click',openDashboard);
closebtn.addEventListener('click',closeDashboard);
setTimeout(()=>{if(!dash.classList.contains('open')&&!userClosed){openDashboard()}},5000);

// Screensaver - 5 min inactivity
const SCREENSAVER_TIMEOUT=5*60*1000;
let ssTimer=null,ssActive=false;
const ss=document.getElementById('screensaver');
function resetScreensaver(){
    if(ssActive){ssActive=false;ss.style.display='none'}
    clearTimeout(ssTimer);
    ssTimer=setTimeout(activateScreensaver,SCREENSAVER_TIMEOUT);
}
function activateScreensaver(){ssActive=true;ss.style.display='block'}
['mousemove','mousedown','keydown','touchstart','wheel','pointerdown'].forEach(e=>document.addEventListener(e,resetScreensaver,{passive:true}));
resetScreensaver();
</script>
</body>
</html>
