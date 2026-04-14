<?php
// === API ENDPOINTS ===
if (isset($_GET['data'])) {
    header('Content-Type: application/json');
    $load = sys_getloadavg();
    $nproc = intval(trim(shell_exec("nproc 2>/dev/null"))) ?: 1;
    $cpu_usage = round(($load[0] / $nproc) * 100, 2);
    $free = shell_exec('free -t'); $free = trim($free); $free_arr = explode("\n", $free);
    $mem = explode(" ", preg_replace('/\s+/', ' ', $free_arr[1]));
    $mem_total = round($mem[1]/1024); $mem_used = round($mem[2]/1024);
    $mem_percent = round(($mem[2]/$mem[1])*100, 2);
    $disk_total = round(disk_total_space("/")/1073741824,1);
    $disk_free = round(disk_free_space("/")/1073741824,1);
    $disk_used = round(100-(disk_free_space("/")/disk_total_space("/")*100),2);
    $temp_raw = shell_exec("sensors 2>/dev/null|grep 'Package id 0'|awk '{print \$4}'|tr -d '+°C'");
    $temp = floatval(trim($temp_raw));
    $bat = intval(trim(shell_exec("cat /sys/class/power_supply/CMB*/capacity /sys/class/power_supply/BAT*/capacity 2>/dev/null | head -1")));
    $bat_status = trim(shell_exec("cat /sys/class/power_supply/CMB*/status /sys/class/power_supply/BAT*/status 2>/dev/null | head -1"));
    $swap_arr = explode(" ", preg_replace('/\s+/', ' ', $free_arr[2]));
    $swap_total = round($swap_arr[1]/1024); $swap_used = round($swap_arr[2]/1024);
    $cpu_cores = intval(trim(shell_exec("nproc 2>/dev/null")));
    $cpu_per_core = [];
    $mpstat = shell_exec("mpstat -P ALL 1 1 2>/dev/null|tail -n +4");
    if ($mpstat) { $lines = explode("\n",trim($mpstat)); foreach($lines as $l) { $p=preg_split('/\s+/',trim($l)); if(count($p)>=12&&is_numeric($p[2])) $cpu_per_core[]=round(100-floatval($p[11]),1); }}
    $ollama_raw = shell_exec('ollama list 2>&1'); $models = [];
    if ($ollama_raw&&strpos($ollama_raw,'NAME')!==false) { $lines=explode("\n",trim($ollama_raw)); array_shift($lines); foreach($lines as $l){$p=preg_split('/\s+/',trim($l));if(!empty($p[0]))$models[]=$p[0];}}
    $ollama_ps = shell_exec('ollama ps 2>&1'); $running_models = [];
    if ($ollama_ps&&strpos($ollama_ps,'NAME')!==false){$lines=explode("\n",trim($ollama_ps));array_shift($lines);foreach($lines as $l){$p=preg_split('/\s+/',trim($l));if(!empty($p[0]))$running_models[]=$p[0];}}
    $ps_raw = shell_exec('ps aux --sort=-%mem|head -n 11'); $processes = [];
    if($ps_raw){$lines=explode("\n",trim($ps_raw));array_shift($lines);foreach($lines as $l){$p=preg_split('/\s+/',trim($l));if(count($p)>=11)$processes[]=['user'=>$p[0],'pid'=>$p[1],'cpu'=>$p[2],'mem'=>$p[3],'rss'=>round($p[5]/1024),'command'=>implode(' ',array_slice($p,10))];}}
    $net_rx = trim(shell_exec("cat /sys/class/net/$(ip route show default|awk '/default/{print \$5}'|head -1)/statistics/rx_bytes 2>/dev/null"));
    $net_tx = trim(shell_exec("cat /sys/class/net/$(ip route show default|awk '/default/{print \$5}'|head -1)/statistics/tx_bytes 2>/dev/null"));
    echo json_encode(['cpu'=>$cpu_usage,'ram'=>$mem_percent,'ram_used'=>$mem_used,'ram_total'=>$mem_total,'disk'=>$disk_used,'disk_total'=>$disk_total,'disk_free'=>$disk_free,'temp'=>$temp,'battery'=>$bat,'bat_status'=>$bat_status,'swap_used'=>$swap_used,'swap_total'=>$swap_total,'cpu_cores'=>$cpu_cores,'cpu_per_core'=>$cpu_per_core,'uptime'=>trim(str_replace('up ','',shell_exec('uptime -p'))),'models'=>$models,'running_models'=>$running_models,'time'=>date('H:i:s'),'processes'=>$processes,'net_rx'=>round($net_rx/1048576,1),'net_tx'=>round($net_tx/1048576,1)]); exit;
}

if (isset($_GET['detail'])) {
    header('Content-Type: application/json');
    $type = $_GET['detail'];

    if ($type === 'cpu') {
        $model = trim(shell_exec("grep 'model name' /proc/cpuinfo|head -1|cut -d: -f2"));
        $freqs = []; $raw = shell_exec("cat /proc/cpuinfo|grep 'cpu MHz'");
        foreach(explode("\n",trim($raw)) as $l){$f=trim(explode(':',$l)[1]??'');if($f)$freqs[]=round(floatval($f));}
        $governor = trim(shell_exec("cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor 2>/dev/null"));
        $max_freq = trim(shell_exec("cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_max_freq 2>/dev/null"));
        $min_freq = trim(shell_exec("cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_min_freq 2>/dev/null"));
        echo json_encode(['model'=>$model,'freqs'=>$freqs,'governor'=>$governor,'max_freq'=>round($max_freq/1000),'min_freq'=>round($min_freq/1000)]);
    }
    elseif ($type === 'ram') {
        $raw = shell_exec('free -m'); $lines = explode("\n",trim($raw));
        $mem = preg_split('/\s+/',$lines[1]); $swap = preg_split('/\s+/',$lines[2]);
        echo json_encode(['total'=>$mem[1],'used'=>$mem[2],'free'=>$mem[3],'shared'=>$mem[4],'buff_cache'=>$mem[5],'available'=>$mem[6],'swap_total'=>$swap[1],'swap_used'=>$swap[2],'swap_free'=>$swap[3]]);
    }
    elseif ($type === 'temp') {
        $raw = shell_exec('sensors 2>/dev/null');
        $temps = [];
        preg_match('/Package id 0:\s+\+([\d.]+)/', $raw, $m); $temps['package'] = floatval($m[1]??0);
        preg_match('/Core 0:\s+\+([\d.]+)/', $raw, $m); $temps['core0'] = floatval($m[1]??0);
        preg_match('/Core 1:\s+\+([\d.]+)/', $raw, $m); $temps['core1'] = floatval($m[1]??0);
        preg_match('/temp1:\s+\+([\d.]+)/', $raw, $m); $temps['wifi'] = floatval($m[1]??0);
        preg_match('/in0:\s+([\d.]+)/', $raw, $m); $temps['voltage'] = floatval($m[1]??0);
        echo json_encode($temps);
    }
    elseif ($type === 'battery') {
        $raw = shell_exec("upower -i $(upower -e|grep battery) 2>/dev/null");
        $info = [];
        preg_match('/state:\s+(.+)/', $raw, $m); $info['state'] = trim($m[1]??'');
        preg_match('/energy:\s+([\d.,]+)/', $raw, $m); $info['energy'] = $m[1]??'';
        preg_match('/energy-full:\s+([\d.,]+)/', $raw, $m); $info['energy_full'] = $m[1]??'';
        preg_match('/energy-full-design:\s+([\d.,]+)/', $raw, $m); $info['energy_design'] = $m[1]??'';
        preg_match('/voltage:\s+([\d.,]+)/', $raw, $m); $info['voltage'] = $m[1]??'';
        preg_match('/energy-rate:\s+([\d.,]+)/', $raw, $m); $info['power'] = $m[1]??'';
        preg_match('/percentage:\s+(\d+)/', $raw, $m); $info['percentage'] = intval($m[1]??0);
        preg_match('/capacity:\s+([\d.,]+)/', $raw, $m); $info['health'] = $m[1]??'';
        preg_match('/time to/', $raw, $m); $info['time'] = isset($m[0]) ? trim(shell_exec("upower -i $(upower -e|grep battery)|grep 'time to'|cut -d: -f2")) : 'N/A';
        echo json_encode($info);
    }
    elseif ($type === 'network') {
        $interfaces = shell_exec("ip -br addr 2>/dev/null");
        $tailscale = shell_exec("tailscale status 2>/dev/null");
        $public_ip = trim(shell_exec("curl -s --max-time 3 ifconfig.me 2>/dev/null"));
        $dns = trim(shell_exec("cat /etc/resolv.conf|grep nameserver|head -3"));
        echo json_encode(['interfaces'=>$interfaces,'tailscale'=>$tailscale,'public_ip'=>$public_ip,'dns'=>$dns]);
    }
    elseif ($type === 'disk') {
        $df = shell_exec("df -h 2>/dev/null");
        $iostat = shell_exec("iostat -d 2>/dev/null|head -8");
        echo json_encode(['partitions'=>$df,'iostat'=>$iostat]);
    }
    elseif ($type === 'services') {
        $svcs = ['nginx','ollama','docker','ssh','tailscaled'];
        $result = [];
        foreach($svcs as $s) {
            $active = trim(shell_exec("systemctl is-active $s 2>/dev/null"));
            $result[] = ['name'=>$s,'status'=>$active];
        }
        echo json_encode($result);
    }
    elseif ($type === 'logs') {
        $lines = intval($_GET['lines'] ?? 30);
        $unit = $_GET['unit'] ?? '';
        $cmd = $unit ? "journalctl -u $unit -n $lines --no-pager 2>/dev/null" : "journalctl -n $lines --no-pager 2>/dev/null";
        echo json_encode(['logs'=>shell_exec($cmd)]);
    }
    elseif ($type === 'sysinfo') {
        echo json_encode([
            'hostname'=>gethostname(),
            'kernel'=>trim(shell_exec('uname -r')),
            'os'=>trim(shell_exec('lsb_release -d 2>/dev/null|cut -d: -f2')),
            'arch'=>trim(shell_exec('uname -m')),
            'users'=>trim(shell_exec('who 2>/dev/null')),
            'last_boot'=>trim(shell_exec("who -b|awk '{print \$3,\$4}'")),
        ]);
    }
    exit;
}

if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $a = $_POST['action'];
    if ($a==='free_memory'){shell_exec('sync && echo 3 > /proc/sys/vm/drop_caches 2>&1');echo json_encode(['ok'=>true,'msg'=>'Cache liberada']);}
    elseif($a==='unload_model'){$m=escapeshellarg($_POST['model']??'');shell_exec("ollama stop $m 2>&1");echo json_encode(['ok'=>true,'msg'=>'Modelo descargado']);}
    elseif($a==='kill_process'){$p=intval($_POST['pid']??0);if($p>0){shell_exec("kill $p 2>&1");echo json_encode(['ok'=>true,'msg'=>"PID $p terminado"]);}else echo json_encode(['ok'=>false,'msg'=>'PID inválido']);}
    elseif($a==='kill9_process'){$p=intval($_POST['pid']??0);if($p>0){shell_exec("kill -9 $p 2>&1");echo json_encode(['ok'=>true,'msg'=>"PID $p forzado"]);}else echo json_encode(['ok'=>false,'msg'=>'PID inválido']);}
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
        $ch=curl_init('http://localhost:11434/api/generate');
        curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>1,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>120]);
        $resp=curl_exec($ch);curl_close($ch);
        $j=json_decode($resp,true);
        echo json_encode(['ok'=>true,'response'=>$j['response']??'Error']);
    }
    elseif($a==='screenshot'){shell_exec('DISPLAY=:0 XAUTHORITY=/home/chemazener/.Xauthority import -window root /tmp/bicho-screenshot.png 2>&1');echo json_encode(['ok'=>true,'msg'=>'Screenshot guardado en /tmp/bicho-screenshot.png']);}
    else echo json_encode(['ok'=>false,'msg'=>'Acción desconocida']);
    exit;
}
?>
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
        :root{--bg:#050508;--accent:#ff4d4d;--accent-glow:rgba(255,77,77,0.3);--cyan:#4dffff;--green:#4dff88;--yellow:#ffd84d;--orange:#ff944d;--text-main:#f0f0f5;--text-muted:#888899;--card:rgba(20,20,25,0.8);--border:rgba(255,255,255,0.08)}
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{width:100%;height:100%;min-height:100vh}
        body{background:var(--bg);color:var(--text-main);font-family:'Outfit',sans-serif;overflow:hidden;display:flex;flex-direction:column;background-image:radial-gradient(circle at 50% 50%,rgba(255,77,77,0.03) 0%,transparent 70%),linear-gradient(rgba(255,255,255,0.01) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.01) 1px,transparent 1px);background-size:100% 100%,50px 50px,50px 50px}

        #hero{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:0.8s cubic-bezier(0.19,1,0.22,1);position:relative;z-index:200}
        .orb-wrapper{position:relative;cursor:pointer;transition:0.5s}.orb-wrapper:hover{transform:scale(1.05)}
        .orb{width:200px;height:200px;border-radius:50%;border:2px solid var(--accent);box-shadow:0 0 50px var(--accent-glow);display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);animation:breathe 5s infinite ease-in-out}
        .orb-inner{width:140px;height:140px;border-radius:50%;background:radial-gradient(circle at 35% 35%,var(--accent),#1a0000);box-shadow:inset 0 0 40px rgba(0,0,0,0.8);filter:blur(1px)}
        @keyframes breathe{0%,100%{box-shadow:0 0 40px var(--accent-glow);transform:scale(1)}50%{box-shadow:0 0 80px var(--accent-glow);transform:scale(1.08)}}
        .orb-label{margin-top:2.5rem;text-align:center}.orb-label h1{font-size:3rem;font-weight:900;letter-spacing:12px}.orb-label p{color:var(--accent);font-weight:600;letter-spacing:3px;font-size:0.75rem;opacity:0.8}
        .shifted{position:fixed;top:5px;left:10px;transform:scale(0.15);transform-origin:top left;opacity:1;z-index:300}

        #dashboard{position:fixed;bottom:-100vh;left:0;width:100%;height:100vh;background:rgba(8,8,12,0.98);backdrop-filter:blur(30px);transition:0.8s cubic-bezier(0.19,1,0.22,1);z-index:100;overflow:hidden}
        #dashboard.open{bottom:0}
        #dash-content{position:absolute;top:0;left:0;width:100%;height:100%;padding:12px 16px 8px 16px;display:grid;grid-template-columns:1fr 1fr 1fr 240px;grid-template-rows:auto 50px 1fr;gap:8px;transform-origin:top left;overflow:hidden}

        .close-btn{position:absolute;top:8px;right:16px;color:var(--text-muted);font-size:0.75rem;font-weight:800;cursor:pointer;letter-spacing:2px;text-transform:uppercase;z-index:300}
        .zoom-controls{position:absolute;top:8px;right:100px;display:flex;gap:4px;z-index:300}
        .zoom-btn{width:26px;height:26px;border-radius:6px;background:var(--card);border:1px solid var(--border);color:var(--text-main);font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:'JetBrains Mono',monospace;transition:all 0.2s}
        .zoom-btn:hover{border-color:var(--accent);color:var(--accent)}
        .zoom-label{color:var(--text-muted);font-size:0.65rem;font-weight:700;display:flex;align-items:center;font-family:'JetBrains Mono',monospace}

        .gauge-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px;display:flex;flex-direction:column;align-items:center;cursor:pointer;transition:border-color 0.3s}
        .gauge-card:hover{border-color:rgba(255,77,77,0.4)}
        .gauge-label{font-size:0.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:2px}
        .gauge-canvas{width:100%;max-width:120px;height:75px}
        .gauge-value{font-family:'JetBrains Mono',monospace;font-size:1.3rem;font-weight:700;margin-top:-8px}
        .gauge-sub{font-size:0.7rem;color:var(--text-muted);font-family:'JetBrains Mono',monospace}
        .core-bar{width:12px;height:16px;background:rgba(255,255,255,0.05);border-radius:2px;position:relative;overflow:hidden}
        .core-bar-fill{position:absolute;bottom:0;left:0;width:100%;border-radius:2px;transition:height 0.5s,background 0.5s}

        .vu-section{grid-column:1/4;display:grid;grid-template-columns:repeat(10,1fr);gap:4px;align-items:end;height:50px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:6px 10px}
        .vu-bar-col{display:flex;flex-direction:column;align-items:center;gap:1px;height:100%;justify-content:flex-end}
        .vu-bar{width:100%;border-radius:2px;transition:height 0.5s ease,background 0.5s ease;min-height:2px}
        .vu-bar-label{font-size:0.55rem;color:var(--text-muted);font-family:'JetBrains Mono',monospace;text-align:center;white-space:nowrap}

        .chart-panel{grid-column:1/4;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px;display:flex;flex-direction:column;overflow:hidden}
        .chart-tabs{display:flex;gap:4px;margin-bottom:4px}
        .chart-tab{background:rgba(255,255,255,0.03);border:1px solid var(--border);color:var(--text-muted);padding:3px 10px;border-radius:6px;cursor:pointer;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;transition:all 0.2s;font-family:'Outfit',sans-serif}
        .chart-tab.active{background:rgba(255,77,77,0.15);border-color:var(--accent);color:var(--accent)}
        .chart-tab:hover{border-color:rgba(255,77,77,0.3)}
        .chart-canvas-wrap{flex:1;position:relative;min-height:60px}
        .chart-canvas-wrap canvas{position:absolute;top:0;left:0;width:100%;height:100%}

        .right-panel{grid-row:1/4;grid-column:4;display:flex;flex-direction:column;gap:6px;overflow:hidden}
        .info-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px 10px;cursor:pointer;transition:border-color 0.3s}
        .info-card:hover{border-color:rgba(255,77,77,0.3)}
        .ai-panel{background:rgba(255,77,77,0.03);border:1px solid rgba(255,77,77,0.1);border-radius:12px;padding:8px 10px;flex:1;display:flex;flex-direction:column;overflow-y:auto}
        .ai-list{list-style:none;margin-top:4px;flex:1;overflow-y:auto}
        .ai-list li{padding:4px 3px;border-bottom:1px solid rgba(255,255,255,0.05);font-size:0.75rem;display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);cursor:pointer}
        .ai-list li:hover{background:rgba(255,255,255,0.03)}
        .proc-panel{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px 10px;flex:1;overflow-y:auto}
        .proc-list{list-style:none;font-family:'JetBrains Mono',monospace}
        .proc-item{border-bottom:1px solid rgba(255,255,255,0.04)}.proc-item:last-child{border-bottom:none}
        .proc-header{display:flex;justify-content:space-between;align-items:center;padding:3px;cursor:pointer;border-radius:6px;transition:background 0.2s}
        .proc-header:hover{background:rgba(255,255,255,0.03)}
        .proc-name{color:var(--text-main);font-size:0.75rem;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .proc-mem-badge{color:var(--accent);font-size:0.7rem;font-weight:800;margin-left:4px}
        .proc-arrow{color:var(--text-muted);font-size:0.45rem;margin-left:3px;transition:transform 0.3s}
        .proc-item.open .proc-arrow{transform:rotate(180deg)}
        .proc-details{max-height:0;overflow:hidden;transition:max-height 0.3s ease;padding:0 3px}
        .proc-item.open .proc-details{max-height:160px;padding:3px}
        .proc-detail-row{display:flex;justify-content:space-between;padding:1px 0;font-size:0.65rem}
        .proc-detail-label{color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
        .proc-detail-value{color:var(--text-main)}

        .action-btn{background:rgba(255,77,77,0.1);border:1px solid rgba(255,77,77,0.3);color:var(--accent);padding:4px 8px;border-radius:6px;cursor:pointer;font-family:'Outfit',sans-serif;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;transition:all 0.2s;white-space:nowrap}
        .action-btn:hover{background:rgba(255,77,77,0.25);transform:scale(1.05)}.action-btn:active{transform:scale(0.95)}
        .action-btn.green{background:rgba(77,255,136,0.1);border-color:rgba(77,255,136,0.3);color:var(--green)}
        .action-btn.cyan{background:rgba(77,255,255,0.1);border-color:rgba(77,255,255,0.3);color:var(--cyan)}
        .action-btn.yellow{background:rgba(255,216,77,0.1);border-color:rgba(255,216,77,0.3);color:var(--yellow)}

        .toast{position:fixed;top:12px;left:50%;transform:translateX(-50%) translateY(-80px);background:var(--card);border:1px solid var(--accent);color:var(--text-main);padding:6px 20px;border-radius:10px;font-size:0.8rem;font-weight:600;z-index:99999;transition:transform 0.4s cubic-bezier(0.19,1,0.22,1)}
        .toast.show{transform:translateX(-50%) translateY(0)}
        .label-sm{font-size:0.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:1px}

        /* MODAL */
        .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.75);z-index:10000;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity 0.3s}
        .modal-overlay.open{opacity:1;pointer-events:all}
        .modal{background:#0d0d12;border:1px solid var(--accent);border-radius:16px;padding:20px 24px;max-width:700px;width:90%;max-height:80vh;overflow-y:auto;position:relative}
        .modal-title{font-size:1.1rem;font-weight:800;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px;color:var(--accent)}
        .modal-close{position:absolute;top:12px;right:16px;color:var(--text-muted);cursor:pointer;font-size:1.2rem;font-weight:800}
        .modal-close:hover{color:var(--accent)}
        .modal-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:0.85rem}
        .modal-row:last-child{border:none}
        .modal-label{color:var(--text-muted);font-weight:600;text-transform:uppercase;font-size:0.75rem;letter-spacing:1px}
        .modal-val{color:var(--text-main);font-family:'JetBrains Mono',monospace}
        .modal-pre{background:rgba(0,0,0,0.4);border:1px solid var(--border);border-radius:8px;padding:10px;font-family:'JetBrains Mono',monospace;font-size:0.7rem;color:var(--text-muted);white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto;margin-top:8px}
        .modal-actions{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap}
        .chat-input{width:100%;background:rgba(0,0,0,0.3);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text-main);font-family:'JetBrains Mono',monospace;font-size:0.8rem;outline:none;margin-top:8px;resize:none}
        .chat-input:focus{border-color:var(--accent)}
        .chat-response{margin-top:8px;padding:10px;background:rgba(255,77,77,0.03);border:1px solid rgba(255,77,77,0.1);border-radius:8px;font-size:0.8rem;line-height:1.5;white-space:pre-wrap;max-height:300px;overflow-y:auto}

        .svc-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)}
        .svc-row:last-child{border:none}
        .svc-name{font-weight:700;font-size:0.9rem}
        .svc-status{font-family:'JetBrains Mono',monospace;font-size:0.8rem;font-weight:700}
        .svc-actions{display:flex;gap:4px}

        ::-webkit-scrollbar{width:3px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}
        footer{position:absolute;bottom:0;left:0;width:100%;padding:3px;text-align:center;font-size:0.6rem;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase}
        footer span{color:var(--accent);font-weight:800}

        @media(max-width:900px){#dash-content{grid-template-columns:1fr;grid-template-rows:auto;overflow-y:auto;padding:50px 10px 10px 10px;gap:6px}.gauge-card,.vu-section,.chart-panel,.right-panel{grid-column:1!important;grid-row:auto!important}.chart-panel{min-height:180px}.right-panel{min-height:auto}}
    </style>
</head>
<body>
<div class="toast" id="toast"></div>
<div class="modal-overlay" id="modal-overlay" onclick="if(event.target===this)closeModal()"><div class="modal" id="modal"></div></div>

<div id="hero"><div class="orb-wrapper" id="orb-btn"><div class="orb"><div class="orb-inner"></div></div><div class="orb-label"><h1>BICHO</h1><p>AI SERVER ANALYTICS</p></div></div></div>

<div id="dashboard">
<div id="dash-content">
    <div class="zoom-controls"><button class="zoom-btn" onclick="zoomOut()">-</button><span class="zoom-label" id="zoom-label">150%</span><button class="zoom-btn" onclick="zoomIn()">+</button></div>
    <div class="close-btn" id="close-btn">CLOSE</div>

    <div class="gauge-card" onclick="showCPU()">
        <div class="gauge-label">CPU</div>
        <canvas class="gauge-canvas" id="gauge-cpu"></canvas>
        <div class="gauge-value" id="val-cpu">0%</div>
        <div class="gauge-sub" id="sub-cpu">--</div>
        <div id="cpu-cores" style="display:flex;gap:2px;margin-top:4px;flex-wrap:wrap;justify-content:center"></div>
    </div>
    <div class="gauge-card" onclick="showRAM()">
        <div class="gauge-label">RAM</div>
        <canvas class="gauge-canvas" id="gauge-ram"></canvas>
        <div class="gauge-value" id="val-ram">0%</div>
        <div class="gauge-sub" id="sub-ram">--</div>
    </div>
    <div class="gauge-card" style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <div style="text-align:center;cursor:pointer" onclick="showTemp()">
            <div class="gauge-label">TEMP</div>
            <canvas class="gauge-canvas" id="gauge-temp" style="max-width:90px;height:60px"></canvas>
            <div class="gauge-value" style="font-size:1rem" id="val-temp">--</div>
        </div>
        <div style="text-align:center;cursor:pointer" onclick="showBattery()">
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
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div class="chart-tabs">
                <div class="chart-tab active" onclick="setChart('cpu')">CPU</div>
                <div class="chart-tab" onclick="setChart('ram')">RAM</div>
                <div class="chart-tab" onclick="setChart('temp')">TEMP</div>
                <div class="chart-tab" onclick="setChart('net')">NET</div>
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
                <div><span style="color:var(--green)">&#9650;</span> <span id="net-rx">0</span> MB</div>
                <div><span style="color:var(--accent)">&#9660;</span> <span id="net-tx">0</span> MB</div>
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
const orb=document.getElementById('orb-btn'),dash=document.getElementById('dashboard'),hero=document.getElementById('hero'),closebtn=document.getElementById('close-btn');
const H={cpu:[],ram:[],temp:[],net_rx:[]};const MAX_H=60;let activeChart='cpu',lastNetRx=0,lastNetTx=0,lastD=null;

function showToast(m){const t=document.getElementById('toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2500)}
function doAction(a,x){fetch('',{method:'POST',body:new URLSearchParams({action:a,...x})}).then(r=>r.json()).then(d=>{showToast(d.msg);fetchStats()})}
function confirmAction(a,m){openModal('<div class="modal-title">'+m+'</div><div class="modal-actions"><button class="action-btn" style="padding:8px 24px;font-size:0.85rem" onclick="closeModal();doAction(\''+a+'\')">SI</button><button class="action-btn green" style="padding:8px 24px;font-size:0.85rem" onclick="closeModal()">NO</button></div>')}
function setChart(t){activeChart=t;document.querySelectorAll('.chart-tab').forEach(e=>e.classList.toggle('active',e.textContent.toLowerCase()===t));drawChart()}

// Modal
function openModal(html){document.getElementById('modal').innerHTML='<div class="modal-close" onclick="closeModal()">&#10005;</div>'+html;document.getElementById('modal-overlay').classList.add('open')}
function closeModal(){document.getElementById('modal-overlay').classList.remove('open')}

// Detail modals
function showCPU(){
    fetch('?detail=cpu').then(r=>r.json()).then(d=>{
        let freqBars='';d.freqs.forEach((f,i)=>{const p=Math.min(f/3000*100,100);const c=p>80?'var(--accent)':p>50?'var(--yellow)':'var(--green)';freqBars+='<div style="display:flex;align-items:center;gap:8px;margin:3px 0"><span style="width:50px;font-size:0.75rem;color:var(--text-muted)">Core '+i+'</span><div style="flex:1;height:8px;background:rgba(255,255,255,0.05);border-radius:4px;overflow:hidden"><div style="width:'+p+'%;height:100%;background:'+c+';border-radius:4px;transition:width 0.5s"></div></div><span style="width:60px;text-align:right;font-size:0.75rem">'+f+' MHz</span></div>'});
        openModal('<div class="modal-title">CPU DETAIL</div><div class="modal-row"><span class="modal-label">Model</span><span class="modal-val">'+d.model+'</span></div><div class="modal-row"><span class="modal-label">Governor</span><span class="modal-val">'+d.governor+'</span></div><div class="modal-row"><span class="modal-label">Freq Range</span><span class="modal-val">'+d.min_freq+' - '+d.max_freq+' MHz</span></div><div style="margin-top:12px"><div class="label-sm" style="margin-bottom:6px">FREQUENCY PER CORE</div>'+freqBars+'</div>')
    })
}
function showRAM(){
    fetch('?detail=ram').then(r=>r.json()).then(d=>{
        const items=[['Total',d.total+' MB'],['Used',d.used+' MB'],['Free',d.free+' MB'],['Shared',d.shared+' MB'],['Buff/Cache',d.buff_cache+' MB'],['Available',d.available+' MB'],['Swap Total',d.swap_total+' MB'],['Swap Used',d.swap_used+' MB'],['Swap Free',d.swap_free+' MB']];
        let rows=items.map(i=>'<div class="modal-row"><span class="modal-label">'+i[0]+'</span><span class="modal-val">'+i[1]+'</span></div>').join('');
        openModal('<div class="modal-title">RAM DETAIL</div>'+rows+'<div class="modal-actions"><button class="action-btn green" onclick="doAction(\'free_memory\');closeModal()">LIBERAR CACHE</button></div>')
    })
}
function showTemp(){
    fetch('?detail=temp').then(r=>r.json()).then(d=>{
        const items=[['Package',d.package+'°C'],['Core 0',d.core0+'°C'],['Core 1',d.core1+'°C'],['WiFi',d.wifi+'°C'],['Voltage',d.voltage+' V']];
        let rows=items.map(i=>{const v=parseFloat(i[1]);const c=v>75?'var(--accent)':v>55?'var(--yellow)':'var(--green)';return '<div class="modal-row"><span class="modal-label">'+i[0]+'</span><span class="modal-val" style="color:'+c+'">'+i[1]+'</span></div>'}).join('');
        openModal('<div class="modal-title">TEMPERATURE</div>'+rows)
    })
}
function showBattery(){
    fetch('?detail=battery').then(r=>r.json()).then(d=>{
        const items=[['State',d.state],['Percentage',d.percentage+'%'],['Energy',d.energy+' Wh'],['Full',d.energy_full+' Wh'],['Design',d.energy_design+' Wh'],['Voltage',d.voltage+' V'],['Power',d.power+' W'],['Health',d.health+'%'],['Time',d.time]];
        let rows=items.map(i=>'<div class="modal-row"><span class="modal-label">'+i[0]+'</span><span class="modal-val">'+i[1]+'</span></div>').join('');
        openModal('<div class="modal-title">BATTERY</div>'+rows)
    })
}
function showNetwork(){
    fetch('?detail=network').then(r=>r.json()).then(d=>{
        let ts='';if(d.tailscale){d.tailscale.trim().split('\n').forEach(l=>{const p=l.trim().split(/\s+/);if(p.length>=4&&p[0].match(/^\d/)){ts+='<div class="svc-row"><span class="svc-name">'+p[1]+'</span><span class="svc-status" style="font-size:0.75rem">'+p[0]+'</span><span class="svc-status">'+p[3]+'</span><button class="action-btn cyan" style="font-size:0.55rem" onclick="doPing(\''+p[0]+'\')">PING</button></div>'}})}
        openModal('<div class="modal-title">NETWORK</div><div class="modal-row"><span class="modal-label">Public IP</span><span class="modal-val">'+d.public_ip+'</span></div><div style="margin-top:10px"><div class="label-sm">INTERFACES</div><div class="modal-pre">'+d.interfaces+'</div></div><div style="margin-top:10px"><div class="label-sm">TAILSCALE DEVICES</div>'+ts+'</div><div id="ping-result"></div>')
    })
}
function doPing(host){
    document.getElementById('ping-result').innerHTML='<div class="modal-pre" style="margin-top:8px">Pinging '+host+'...</div>';
    fetch('',{method:'POST',body:new URLSearchParams({action:'ping',host:host})}).then(r=>r.json()).then(d=>{document.getElementById('ping-result').innerHTML='<div class="modal-pre" style="margin-top:8px">'+d.msg+'</div>'})
}
function showSysInfo(){
    Promise.all([fetch('?detail=sysinfo').then(r=>r.json()),fetch('?detail=disk').then(r=>r.json())]).then(([s,dk])=>{
        const items=[['Hostname',s.hostname],['OS',s.os],['Kernel',s.kernel],['Arch',s.arch],['Last Boot',s.last_boot],['Users',s.users||'None']];
        let rows=items.map(i=>'<div class="modal-row"><span class="modal-label">'+i[0]+'</span><span class="modal-val">'+i[1]+'</span></div>').join('');
        openModal('<div class="modal-title">SYSTEM INFO</div>'+rows+'<div style="margin-top:10px"><div class="label-sm">DISK PARTITIONS</div><div class="modal-pre">'+dk.partitions+'</div></div>')
    })
}
function showServices(){
    fetch('?detail=services').then(r=>r.json()).then(svcs=>{
        let rows=svcs.map(s=>{const color=s.status==='active'?'var(--green)':'var(--accent)';return '<div class="svc-row"><span class="svc-name">'+s.name+'</span><span class="svc-status" style="color:'+color+'">'+s.status.toUpperCase()+'</span><div class="svc-actions"><button class="action-btn green" style="font-size:0.55rem" onclick="doAction(\'start_service\',{service:\''+s.name+'\'});setTimeout(showServices,1000)">START</button><button class="action-btn" style="font-size:0.55rem" onclick="doAction(\'restart_service\',{service:\''+s.name+'\'});setTimeout(showServices,1000)">RESTART</button><button class="action-btn yellow" style="font-size:0.55rem" onclick="doAction(\'stop_service\',{service:\''+s.name+'\'});setTimeout(showServices,1000)">STOP</button></div></div>'}).join('');
        openModal('<div class="modal-title">SERVICES</div>'+rows)
    })
}
function showLogs(unit){
    const u=unit||'';
    fetch('?detail=logs&lines=50&unit='+u).then(r=>r.json()).then(d=>{
        openModal('<div class="modal-title">SYSTEM LOGS</div><div style="display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap"><button class="action-btn '+(u===''?'green':'')+'" onclick="showLogs()">ALL</button><button class="action-btn '+(u==='nginx'?'green':'')+'" onclick="showLogs(\'nginx\')">NGINX</button><button class="action-btn '+(u==='ollama'?'green':'')+'" onclick="showLogs(\'ollama\')">OLLAMA</button><button class="action-btn '+(u==='docker'?'green':'')+'" onclick="showLogs(\'docker\')">DOCKER</button><button class="action-btn '+(u==='ssh'?'green':'')+'" onclick="showLogs(\'ssh\')">SSH</button></div><div class="modal-pre" style="max-height:400px">'+d.logs+'</div>')
    })
}
function showChat(model){
    openModal('<div class="modal-title">CHAT - '+model+'</div><div id="chat-history" class="chat-response" style="min-height:100px">Escribe algo para empezar...</div><textarea class="chat-input" id="chat-input" rows="2" placeholder="Escribe tu mensaje..." onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();sendChat(\''+model+'\')}"></textarea><div class="modal-actions"><button class="action-btn green" onclick="sendChat(\''+model+'\')">ENVIAR</button></div>')
}
function sendChat(model){
    const input=document.getElementById('chat-input');const prompt=input.value.trim();if(!prompt)return;
    const hist=document.getElementById('chat-history');
    hist.innerHTML+='<div style="color:var(--cyan);margin-top:8px"><b>You:</b> '+prompt+'</div><div style="color:var(--text-muted);margin-top:4px"><i>Thinking...</i></div>';
    input.value='';
    fetch('',{method:'POST',body:new URLSearchParams({action:'chat',model:model,prompt:prompt})}).then(r=>r.json()).then(d=>{
        hist.innerHTML=hist.innerHTML.replace('<div style="color:var(--text-muted);margin-top:4px"><i>Thinking...</i></div>','');
        hist.innerHTML+='<div style="color:var(--green);margin-top:4px"><b>'+model+':</b> '+d.response+'</div>';
        hist.scrollTop=hist.scrollHeight;
    })
}

// Gauges
function drawGauge(id,pct,color,max){const c=document.getElementById(id);if(!c)return;const dp=2,w=c.offsetWidth*dp,h=c.offsetHeight*dp;c.width=w;c.height=h;const ctx=c.getContext('2d');ctx.clearRect(0,0,w,h);const cx=w/2,cy=h*0.85,r=Math.min(cx,cy)*0.85,sA=Math.PI*1.15,eA=Math.PI*-0.15,rng=eA-sA+2*Math.PI,v=Math.min(pct/(max||100),1);ctx.beginPath();ctx.arc(cx,cy,r,sA,sA+rng);ctx.strokeStyle='rgba(255,255,255,0.06)';ctx.lineWidth=w*0.06;ctx.lineCap='round';ctx.stroke();ctx.beginPath();ctx.arc(cx,cy,r,sA,sA+rng*v);ctx.strokeStyle=color;ctx.lineWidth=w*0.06;ctx.lineCap='round';ctx.shadowColor=color;ctx.shadowBlur=15;ctx.stroke();ctx.shadowBlur=0;for(let i=0;i<=10;i++){const a=sA+(rng*i/10),inn=r-w*0.04,out=r+w*0.04;ctx.beginPath();ctx.moveTo(cx+Math.cos(a)*inn,cy+Math.sin(a)*inn);ctx.lineTo(cx+Math.cos(a)*out,cy+Math.sin(a)*out);ctx.strokeStyle=i<=v*10?color:'rgba(255,255,255,0.1)';ctx.lineWidth=i%5===0?2:1;ctx.stroke()}const nA=sA+rng*v,nL=r*0.7;ctx.beginPath();ctx.moveTo(cx,cy);ctx.lineTo(cx+Math.cos(nA)*nL,cy+Math.sin(nA)*nL);ctx.strokeStyle='#fff';ctx.lineWidth=2;ctx.stroke();ctx.beginPath();ctx.arc(cx,cy,4,0,Math.PI*2);ctx.fillStyle=color;ctx.fill()}
function setVU(id,p,mx){const c=document.getElementById(id);if(!c)return;const b=c.querySelector('.vu-bar');const v=Math.min(p/(mx||100),1);b.style.height=Math.max(v*100,5)+'%';b.style.background=v>0.8?'var(--accent)':v>0.6?'var(--orange)':v>0.4?'var(--yellow)':'var(--green)';b.style.boxShadow='0 0 6px '+b.style.background}
function getColor(p){return p>80?'var(--accent)':p>60?'var(--orange)':p>40?'var(--yellow)':'var(--green)'}

function drawChart(){const c=document.getElementById('main-chart');if(!c)return;const w_=c.parentElement;const dp=2,w=w_.offsetWidth*dp,h=w_.offsetHeight*dp;c.width=w;c.height=h;const ctx=c.getContext('2d');ctx.clearRect(0,0,w,h);let data,color;if(activeChart==='cpu'){data=H.cpu;color='rgb(255,77,77)'}else if(activeChart==='ram'){data=H.ram;color='rgb(77,200,255)'}else if(activeChart==='temp'){data=H.temp;color='rgb(255,180,77)'}else{data=H.net_rx;color='rgb(77,255,136)'}if(data.length<2)return;const pad={t:15,r:8,b:20,l:35},cw=w-pad.l-pad.r,ch=h-pad.t-pad.b,mx=Math.max(...data,1)*1.1,st=cw/(MAX_H-1);ctx.strokeStyle='rgba(255,255,255,0.04)';ctx.lineWidth=1;for(let i=0;i<=4;i++){const y=pad.t+ch-(ch*i/4);ctx.beginPath();ctx.moveTo(pad.l,y);ctx.lineTo(pad.l+cw,y);ctx.stroke();ctx.fillStyle='rgba(255,255,255,0.25)';ctx.font=(8*dp)+'px JetBrains Mono';ctx.textAlign='right';ctx.fillText(Math.round(mx*i/4),pad.l-4,y+3)}ctx.beginPath();ctx.strokeStyle=color;ctx.lineWidth=2.5;ctx.lineJoin='round';data.forEach((v,i)=>{const x=pad.l+i*st,y=pad.t+ch-(v/mx)*ch;if(i===0)ctx.moveTo(x,y);else ctx.lineTo(x,y)});ctx.shadowColor=color;ctx.shadowBlur=10;ctx.stroke();ctx.shadowBlur=0;ctx.lineTo(pad.l+(data.length-1)*st,pad.t+ch);ctx.lineTo(pad.l,pad.t+ch);ctx.closePath();const gr=ctx.createLinearGradient(0,pad.t,0,pad.t+ch);gr.addColorStop(0,color.replace('rgb','rgba').replace(')',',0.2)'));gr.addColorStop(1,'transparent');ctx.fillStyle=gr;ctx.fill();if(data.length>0){const lx=pad.l+(data.length-1)*st,ly=pad.t+ch-(data[data.length-1]/mx)*ch;ctx.beginPath();ctx.arc(lx,ly,4,0,Math.PI*2);ctx.fillStyle=color;ctx.shadowColor=color;ctx.shadowBlur=12;ctx.fill();ctx.shadowBlur=0}}

function fetchStats(){
    fetch('?data=1').then(r=>r.json()).then(d=>{
        lastD=d;const cpuPct=Math.min(d.cpu,100);
        drawGauge('gauge-cpu',cpuPct,cpuPct>70?'#ff4d4d':cpuPct>40?'#ffd84d':'#4dff88',100);
        document.getElementById('val-cpu').textContent=cpuPct.toFixed(1)+'%';document.getElementById('val-cpu').style.color=getColor(cpuPct);
        document.getElementById('sub-cpu').textContent=d.cpu_cores+' threads';
        const cd=document.getElementById('cpu-cores');cd.innerHTML='';
        if(d.cpu_per_core)d.cpu_per_core.forEach((u,i)=>{const c=u>80?'var(--accent)':u>50?'var(--yellow)':'var(--green)';cd.innerHTML+='<div style="text-align:center"><div class="core-bar"><div class="core-bar-fill" style="height:'+u+'%;background:'+c+'"></div></div></div>'});
        drawGauge('gauge-ram',d.ram,d.ram>70?'#ff4d4d':d.ram>40?'#ffd84d':'#4dff88',100);
        document.getElementById('val-ram').textContent=d.ram+'%';document.getElementById('val-ram').style.color=getColor(d.ram);
        document.getElementById('sub-ram').textContent=d.ram_used+'/'+d.ram_total+'MB';
        drawGauge('gauge-temp',d.temp,d.temp>75?'#ff4d4d':d.temp>55?'#ffd84d':'#4dff88',100);
        document.getElementById('val-temp').textContent=d.temp+'°C';document.getElementById('val-temp').style.color=getColor(d.temp);
        const bc=d.battery>50?'#4dff88':d.battery>20?'#ffd84d':'#ff4d4d';
        drawGauge('gauge-bat',d.battery,bc,100);document.getElementById('val-bat').textContent=d.battery+'%';document.getElementById('val-bat').style.color=bc;document.getElementById('sub-bat').textContent=d.bat_status;
        document.getElementById('uptime-val').textContent=d.uptime.toUpperCase();
        document.getElementById('disk-val').textContent=d.disk_free+'G/'+d.disk_total+'G';document.getElementById('disk-bar').style.width=d.disk+'%';
        document.getElementById('net-rx').textContent=d.net_rx;document.getElementById('net-tx').textContent=d.net_tx;
        document.getElementById('chart-time').textContent=d.time;
        setVU('vu-cpu',cpuPct,100);setVU('vu-ram',d.ram,100);setVU('vu-disk',d.disk,100);setVU('vu-temp',d.temp,100);
        setVU('vu-swap',d.swap_total>0?(d.swap_used/d.swap_total)*100:0,100);setVU('vu-bat',d.battery,100);
        if(d.processes.length>0)setVU('vu-p1',parseFloat(d.processes[0].mem),20);if(d.processes.length>1)setVU('vu-p2',parseFloat(d.processes[1].mem),20);if(d.processes.length>2)setVU('vu-p3',parseFloat(d.processes[2].mem),20);
        const nd=Math.abs(d.net_rx-lastNetRx)+Math.abs(d.net_tx-lastNetTx);setVU('vu-net',Math.min(nd,100),100);lastNetRx=d.net_rx;lastNetTx=d.net_tx;
        if(d.processes.length>0)document.querySelector('#vu-p1 .vu-bar-label').textContent=d.processes[0].command.split('/').pop().split(' ')[0].substring(0,6);
        if(d.processes.length>1)document.querySelector('#vu-p2 .vu-bar-label').textContent=d.processes[1].command.split('/').pop().split(' ')[0].substring(0,6);
        if(d.processes.length>2)document.querySelector('#vu-p3 .vu-bar-label').textContent=d.processes[2].command.split('/').pop().split(' ')[0].substring(0,6);
        H.cpu.push(cpuPct);H.ram.push(d.ram);H.temp.push(d.temp);H.net_rx.push(Math.abs(d.net_rx-(lastNetRx||d.net_rx)));
        if(H.cpu.length>MAX_H)H.cpu.shift();if(H.ram.length>MAX_H)H.ram.shift();if(H.temp.length>MAX_H)H.temp.shift();if(H.net_rx.length>MAX_H)H.net_rx.shift();
        drawChart();
        // AI Models
        const list=document.getElementById('ai-list');list.innerHTML='';
        if(d.models.length>0){d.models.forEach(m=>{const run=d.running_models.includes(m);const st=run?'<span style="color:var(--green);font-size:0.6rem">&#9679; VRAM</span>':'<span style="font-size:0.6rem">READY</span>';const btn=run?' <button class="action-btn" style="font-size:0.5rem;padding:2px 5px" onclick="event.stopPropagation();doAction(\'unload_model\',{model:\''+m+'\'})">UNLOAD</button>':'';list.innerHTML+='<li onclick="showChat(\''+m+'\')"><span style="font-size:0.7rem">'+m+'</span><span>'+st+btn+'</span></li>'})}else{list.innerHTML='<li style="font-size:0.7rem">OLLAMA OFFLINE</li>'}
        const as=document.getElementById('ai-status');as.innerHTML=d.running_models.length>0?'<span style="color:var(--green);font-size:0.55rem">&#9679; '+d.running_models.length+' LOADED</span>':'<span style="color:var(--text-muted);font-size:0.55rem">&#9679; IDLE</span>';
        // Processes
        const pl=document.getElementById('proc-list');const op=[...pl.querySelectorAll('.proc-item.open')].map(e=>e.dataset.pid);pl.innerHTML='';
        d.processes.forEach(p=>{const io=op.includes(p.pid)?' open':'';const cn=p.command.split('/').pop().split(' ')[0];
        pl.innerHTML+='<li class="proc-item'+io+'" data-pid="'+p.pid+'"><div class="proc-header" onclick="this.parentElement.classList.toggle(\'open\')"><span class="proc-name">'+cn+'</span><span class="proc-mem-badge">'+p.mem+'%</span><span class="proc-arrow">&#9660;</span></div><div class="proc-details"><div class="proc-detail-row"><span class="proc-detail-label">PID</span><span class="proc-detail-value">'+p.pid+'</span></div><div class="proc-detail-row"><span class="proc-detail-label">User</span><span class="proc-detail-value">'+p.user+'</span></div><div class="proc-detail-row"><span class="proc-detail-label">CPU</span><span class="proc-detail-value">'+p.cpu+'%</span></div><div class="proc-detail-row"><span class="proc-detail-label">RSS</span><span class="proc-detail-value">'+p.rss+'MB</span></div><div class="proc-detail-row"><span class="proc-detail-label">Cmd</span><span class="proc-detail-value" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+p.command+'</span></div><div style="display:flex;gap:4px;margin-top:4px"><button class="action-btn" style="font-size:0.5rem" onclick="event.stopPropagation();doAction(\'kill_process\',{pid:\''+p.pid+'\'})">KILL</button><button class="action-btn yellow" style="font-size:0.5rem" onclick="event.stopPropagation();doAction(\'kill9_process\',{pid:\''+p.pid+'\'})">KILL -9</button><button class="action-btn cyan" style="font-size:0.5rem" onclick="event.stopPropagation();doAction(\'renice\',{pid:\''+p.pid+'\',nice:\'10\'})">NICE+</button></div></div></li>'});
    })
}

// Zoom
let zoomLevel=parseFloat(localStorage.getItem('bicho-zoom')||'150');applyZoom();
function applyZoom(){const s=zoomLevel/100;const dc=document.getElementById('dash-content');if(dc){dc.style.transform='scale('+s+')';dc.style.width=(100/s)+'%';dc.style.height=(100/s)+'%'}document.getElementById('zoom-label').textContent=zoomLevel+'%';localStorage.setItem('bicho-zoom',zoomLevel);setTimeout(()=>drawChart(),150)}
function zoomIn(){zoomLevel=Math.min(zoomLevel+10,200);applyZoom()}
function zoomOut(){zoomLevel=Math.max(zoomLevel-10,50);applyZoom()}

let statsInterval;
orb.addEventListener('click',()=>{hero.classList.add('shifted');dash.classList.add('open');fetchStats();statsInterval=setInterval(fetchStats,3000)});
closebtn.addEventListener('click',()=>{hero.classList.remove('shifted');dash.classList.remove('open');clearInterval(statsInterval)});
setTimeout(()=>{if(!dash.classList.contains('open')){hero.classList.add('shifted');dash.classList.add('open');fetchStats();statsInterval=setInterval(fetchStats,3000)}},5000);
</script>
</body>
</html>
