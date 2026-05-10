<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

$CACHE_TTL = ['netwatch'=>30,'odp'=>600,'hotspot'=>15];
$CACHE_DIR = __DIR__ . '/cache';
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0755, true);

session_start();
require 'routeros_api.class.php';

$deleteMessage = '';
$deleteStatus = 'success'; // success atau error
if(isset($_SESSION['delete_message'])){
    $deleteMessage = $_SESSION['delete_message'];
    $deleteStatus = $_SESSION['delete_status'] ?? 'success';
    unset($_SESSION['delete_message']);
    unset($_SESSION['delete_status']);
}

if (!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])) {
    header('Location: index.php');
    exit;
}

$mt_ip = $_SESSION['ip'];
$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $mt_ip);
$dataFile = __DIR__ . "/ap_data_{$safe_ip}.json";
if (!file_exists($dataFile)) file_put_contents($dataFile, json_encode([]));

function getCacheFile($type,$ip){global $CACHE_DIR;return $CACHE_DIR.'/ap_'.$type.'_'.preg_replace('/[^a-zA-Z0-9]/','_',$ip).'.cache';}function getFromCache($type,$ip){$file=getCacheFile($type,$ip);if(!file_exists($file))return null;$data=@json_decode(file_get_contents($file),true);if(!$data||!isset($data['expires']))return null;if(time()>$data['expires']){@unlink($file);return null;}return $data['content']?? null;}function setCache($type,$ip,$data,$ttl){$file=getCacheFile($type,$ip);$cache=['expires'=>time()+$ttl,'content'=>$data,'cached_at'=>date('Y-m-d H:i:s')];@file_put_contents($file,json_encode($cache));}function formatSince($s){if(!$s)return '';$ts=@strtotime($s);if($ts===false||$ts===-1)return $s;return date('M/d/Y H:i:s',$ts);}$netwatch_cached=getFromCache('netwatch',$mt_ip);$odp_cached=getFromCache('odp',$mt_ip);$hotspot_cached=getFromCache('hotspot',$mt_ip);$apList=[];$netwatch=$netwatch_cached ??[];$hotspotActive=$hotspot_cached ??[];function getWifiFromNetwatch($mt_ip,$mt_user,$mt_pass,$savedApList,&$netwatch){global $netwatch_cached;if($netwatch_cached!==null){$netwatch=$netwatch_cached;$wifiList=[];$mapIpToId=[];foreach($savedApList as $ap){if(!empty($ap['ip'])&&$ap['type']==='wifi'){$mapIpToId[$ap['ip']]=$ap['id'];}}foreach($netwatch as $hostIp=>$info){foreach($savedApList as $ap){if(!empty($ap['ip'])&&$ap['ip']===$hostIp&&$ap['type']==='wifi'){$wifiList[]=['id'=>$ap['id'],'name'=>$ap['name'],'ip'=>$hostIp,'lat'=>$ap['lat'],'lng'=>$ap['lng'],'line'=>$ap['line']?? null,'lineColor'=>$ap['lineColor']?? 'lime','icon'=>'wifi','status'=>$info['status']?? 'unknown','lasttime'=>$info['since']?? '','type'=>'wifi'];}}}return $wifiList;}$wifiList=[];$mapIpToId=[];foreach($savedApList as $ap){if(!empty($ap['ip'])&&$ap['type']==='wifi'){$mapIpToId[$ap['ip']]=$ap['id'];}}$API=new RouterosAPI();$API->debug=false;if($API->connect($mt_ip,$mt_user,$mt_pass)){$rows=$API->comm('/tool/netwatch/print');foreach($rows as $r){if(!isset($r['host']))continue;$hostIp=$r['host'];$status=strtolower($r['status']?? 'unknown');$since=$r['since']?? '';$comment=trim($r['comment']?? '');$netwatch[$hostIp]=['status'=>$status,'since'=>$since];if($comment==='')continue;$normalized=str_replace('//','/',$comment);$parts=explode('/',$normalized);$coordsPart=null;foreach($parts as $p){$pTrim=trim($p);if($pTrim==='')continue;if(preg_match('/^\s*[-+]?\d+(\.\d+)?\s*,\s*[-+]?\d+(\.\d+)?\s*$/',$pTrim)){$coordsPart=$pTrim;break;}}if($coordsPart===null)continue;list($latStr,$lngStr)=array_map('trim',explode(',',$coordsPart));$lat=(float)$latStr;$lng=(float)$lngStr;$name=null;foreach($parts as $p){if(trim($p)!==''){$name=trim($p);break;}}if(!$name)$name=$hostIp;$lineColor='lime';$acceptedColors=['lime','pink','blue','gray','green','gold','aqua','gainsboro','chartreuse','magenta','orange','fuchsia','black','yellow','brown'];foreach($parts as $p){$pTrim=trim($p);if($pTrim==='')continue;$lc=strtolower($pTrim);if(in_array($lc,$acceptedColors)){$lineColor=$lc;break;}}$parentId=null;foreach($parts as $p){$t=trim($p);if($t===''||$t===$name)continue;$lowerT=strtolower($t);if(in_array($lowerT,['none','no','null','-'])){$parentId=null;break;}if(strpos($t,'ap_')===0){$parentId=$t;break;}if(filter_var($t,FILTER_VALIDATE_IP)){foreach($savedApList as $existing){if(!empty($existing['ip'])&&$existing['ip']===$t){$parentId=$existing['id'];break 2;}}continue;}$found=false;foreach($savedApList as $existing){$existingName=trim($existing['name']?? '');if($existingName===$t||strtolower($existingName)===$lowerT){$parentId=$existing['id'];$found=true;break;}}if($found)break;}$apId=$mapIpToId[$hostIp]??('ap_'.uniqid());$wifiList[]=['id'=>$apId,'name'=>$name,'ip'=>$hostIp,'lat'=>$lat,'lng'=>$lng,'line'=>$parentId,'lineColor'=>$lineColor,'icon'=>'wifi','status'=>$status,'lasttime'=>$since,'type'=>'wifi'];}$API->disconnect();}else{}setCache('netwatch',$mt_ip,$netwatch,$CACHE_TTL['netwatch']);return $wifiList;}function getApFromJson($dataFile){$apList=[];if(file_exists($dataFile)){$apList=json_decode(file_get_contents($dataFile),true)?:[];}return $apList;}function getOdpFromCertificate($mt_ip,$mt_user,$mt_pass,$savedApList=null){global $odp_cached;if($odp_cached!==null){return $odp_cached;}$odpList=[];$API=new RouterosAPI();$API->debug=false;if($API->connect($mt_ip,$mt_user,$mt_pass)){try{$certs=$API->comm('/certificate/print');foreach($certs as $cert){$certName=$cert['name']?? '';if(strpos($certName,'#')===false)continue;$parts=explode('#',$certName);if(count($parts)<5)continue;$apId=trim($parts[0]);$latStr=trim($parts[1]);$lngStr=trim($parts[2]);$lineColor=trim($parts[3]);$parentId=null;$name='';if(count($parts)>=6){$parentToken=trim($parts[4]);$name=trim($parts[5]);// ✅ PERBAIKAN: Cari parent ID dari parent name
if(!empty($parentToken)&&$parentToken!==''&&strlen($parentToken)>0){// Cek apakah parentToken adalah ID (format: ap_xxx)
if(strpos($parentToken,'ap_')===0){$parentId=$parentToken;}else{// Jika parentToken adalah nama, cari ID-nya dari AP list
if(!is_null($savedApList)&&is_array($savedApList)){foreach($savedApList as $ap){if(trim($ap['name'])===$parentToken){$parentId=$ap['id'];break;}}}}}}else{$name=trim($parts[4]);$parentId=null;}$lat=(float)$latStr;$lng=(float)$lngStr;if(empty($apId)||!is_numeric($latStr)||!is_numeric($lngStr)){continue;}$odpList[]=['id'=>$apId,'name'=>$name?:$apId,'ip'=>'','lat'=>$lat,'lng'=>$lng,'line'=>$parentId,'lineColor'=>!empty($lineColor)?strtolower($lineColor):'lime','icon'=>'odp','status'=>'unknown','lasttime'=>'','type'=>'odp'];}}catch(Exception $e){}$API->disconnect();}setCache('odp',$mt_ip,$odpList,$CACHE_TTL['odp']);return $odpList;}$savedApList=getApFromJson($dataFile);$wifiList=getWifiFromNetwatch($_SESSION['ip'],$_SESSION['user'],$_SESSION['pass'],$savedApList,$netwatch);$odpList=getOdpFromCertificate($_SESSION['ip'],$_SESSION['user'],$_SESSION['pass'],$savedApList);$apList=$savedApList;foreach($wifiList as $wifi){$alreadyExists=false;foreach($apList as&$existing){if(!empty($existing['ip'])&&$existing['ip']===$wifi['ip']){$existing['status']=$wifi['status'];$existing['lasttime']=$wifi['lasttime'];if(!empty($wifi['line'])){$existing['line']=$wifi['line'];}$alreadyExists=true;break;}}if(!$alreadyExists){$apList[]=$wifi;}}foreach($odpList as $odp){$alreadyExists=false;foreach($apList as&$existing){if($existing['id']===$odp['id']&&$existing['type']==='odp'){$existing['lat']=$odp['lat'];$existing['lng']=$odp['lng'];$existing['line']=$odp['line'];$existing['lineColor']=$odp['lineColor'];$alreadyExists=true;break;}}if(!$alreadyExists){$apList[]=$odp;}}file_put_contents($dataFile,json_encode($apList,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));$apById=[];foreach($apList as $ap){$apById[$ap['id']]=$ap;}$acceptedColors=['lime','pink','blue','gray','green','gold','aqua','gainsboro','chartreuse','magenta','orange','fuchsia','black','yellow','brown'];if(!empty($wifiList)){if($hotspot_cached===null){$API=new RouterosAPI();$API->debug=false;if($API->connect($_SESSION['ip'],$_SESSION['user'],$_SESSION['pass'])){$hs=$API->comm('/ip/hotspot/active/print');if(is_array($hs)){foreach($hs as $u){$username=$u['user']?? $u['name']?? '';$address=$u['address']?? '';$uptime=$u['uptime']?? '';if($username||$address){$hotspotActive[]=['user'=>$username,'address'=>$address,'uptime'=>$uptime];}}}$API->disconnect();setCache('hotspot',$_SESSION['ip'],$hotspotActive,$CACHE_TTL['hotspot']);}}else{$hotspotActive=$hotspot_cached;}}$linesFile=__DIR__."/ap_lines_{$safe_ip}.json";$linesData=[];function haversine($lat1,$lon1,$lat2,$lon2){$R=6378137;$dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lon2-$lon1);$a=sin($dLat/2)*sin($dLat/2)+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);$c=2*atan2(sqrt($a),sqrt(1-$a));return $R*$c;}$apByIdTmp=[];foreach($apList as $a)$apByIdTmp[$a['id']]=$a;foreach($apList as $ap){if(empty($ap['line']))continue;$fromRef=$ap['line'];$from=null;if(isset($apByIdTmp[$fromRef])){$from=$apByIdTmp[$fromRef];}else if(!empty($fromRef)){foreach($apList as $cand){if(!empty($cand['ip'])&&$cand['ip']===$fromRef){$from=$cand;break;}}}if(!$from&&!empty($fromRef)){foreach($apList as $cand){if(strtolower(trim($cand['name']))===strtolower(trim($fromRef))){$from=$cand;break;}}}if(!$from){continue;}$lat1=(float)$from['lat'];$lng1=(float)$from['lng'];$lat2=(float)$ap['lat'];$lng2=(float)$ap['lng'];$straight=haversine($lat1,$lng1,$lat2,$lng2);$midLat=($lat1+$lat2)/2.0;$midLng=($lng1+$lng2)/2.0;$linesData[$ap['id']]=['id'=>$ap['id'],'fromRef'=>$fromRef,'toId'=>$ap['id'],'latlngs'=>[[$lat1,$lng1],[$lat2,$lng2]],'straight'=>$straight,'mid'=>[$midLat,$midLng],'color'=>$ap['lineColor']?? 'lime'];}file_put_contents($linesFile,json_encode($linesData,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));$settingsFile=__DIR__.'/settings.json';$reloadMinutes=0;if(file_exists($settingsFile)){$s=json_decode(file_get_contents($settingsFile),true);if(isset($s['reload_minutes']))$reloadMinutes=intval($s['reload_minutes']);}$maxCacheAge=3600;if(is_dir($CACHE_DIR)){$files=@glob($CACHE_DIR.'/*.cache');if($files){foreach($files as $file){if(@filemtime($file)<(time()-$maxCacheAge)){@unlink($file);}}}} ?><!doctypehtml><html lang="id"><head><meta charset="utf-8"><meta content="width=device-width,initial-scale=1"name="viewport"><title>Netwatch AP Monitoring</title><link href="favicon.png"rel="icon"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css"rel="stylesheet"><link href="https://unpkg.com/leaflet/dist/leaflet.css"rel="stylesheet"><style>:root{--up:#16a34a;--down:#ef4444;--unknown:#6b7280;--glass-bg:rgba(255,255,255,0.85)}body,html{height:100%;margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial}.wrap{display:flex;height:100vh;overflow:hidden;position:relative}#sidebar{position:absolute;top:0;right:0;width:266px;max-height:80vh;background:#3d4242;color:#fff;border-radius:2px;padding:10px;overflow-y:auto;z-index:1200;transition:transform .3s ease}#sidebar.hidden{transform:translateX(110%)}#sidebar-toggle{position:absolute;top:0;right:286px;width:26px;background:rgba(255,255,255,.85);color:#000;text-align:center;padding:4px 6px;border-radius:6px 0 0 6px;cursor:pointer;z-index:1300;font-size:28px;font-weight:700;user-select:none;transition:right .3s ease}body.sidebar-hidden #sidebar-toggle{right:0}#map{flex:1;height:100%;will-change:transform}h3{margin:6px 0 12px 0;font-size:16px;display:flex;justify-content:space-between;align-items:center}.ap-item{background:var(--glass-bg);border:1px solid rgba(0,0,0,.05);padding:6px;border-radius:8px;margin-bottom:8px;cursor:pointer}.ap-item .name{font-weight:700;display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:12px}.ap-item .sub{font-size:11px;color:#475569;margin-top:2px}.ap-actions{margin-top:6px;display:flex;gap:6px;flex-wrap:wrap}.btn{display:inline-block;padding:6px 8px;border-radius:6px;background:#0ea5e9;color:#fff;text-decoration:none;font-size:15px}.btn.danger{background:#ef4444}.hint{font-size:11px;color:#64748b;margin-top:8px}#toggleSidebar{position:absolute;top:12px;left:12px;z-index:1300;background:#fff;color:#000;border-radius:8px;padding:6px 8px;cursor:pointer;box-shadow:0 6px 18px rgba(2,6,23,.2);font-size:12px}#hotspot-control-wrapper{position:absolute;top:2px;right:286px;z-index:1250;display:flex;align-items:center;gap:6px;background:#3d4242b8;padding:8px 10px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.1);transition:right .3s cubic-bezier(.4,0,.2,1);flex-direction:row}#hotspot-control-wrapper.hidden{right:-150px;opacity:0}#hotspot-control-wrapper:hover::before{background:rgba(255,255,255,1);box-shadow:-2px 0 6px rgba(0,0,0,.15)}#hotspot-control-wrapper.hidden::before{content:'❮';left:0}body.sidebar-hidden #hotspot-control-wrapper{right:-212px;top:2px}body.sidebar-hidden #hotspot-control-wrapper.hidden{right:12px;opacity:1}.hotspot-control{width:36px;height:36px;background:rgba(255,255,255,.95)!important;color:#000!important;border:0!important;border-radius:6px!important;cursor:pointer!important;display:flex!important;align-items:center!important;justify-content:center!important;font-weight:700!important;font-size:16px!important;padding:0!important;margin:0!important;box-shadow:0 2px 6px rgba(0,0,0,.2)!important;transition:all .2s ease!important}.hotspot-control:hover{background:rgba(255,255,255,1)!important;box-shadow:0 4px 8px rgba(0,0,0,.3)!important;transform:translateY(-2px)!important}.hotspot-control:active{transform:translateY(0)!important}.wifi-icon{width:15px;height:15px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #111827;box-shadow:none}.wifi-icon img.wifi-img{width:13px;height:13px}.wifi-icon.up{background:#0f0}.wifi-icon.down{background:red}.wifi-icon.unknown{background:gray}.hotspot-control{margin-top:-6px;background:#f3eee5;color:#000;padding:2px 10px;border-radius:6px;font-weight:700;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.2)}.leaflet-tooltip.my-label{background:rgba(255,255,255,.95);border:1px solid rgba(0,0,0,.06);color:#0f172a;font-weight:600;border-radius:6px;padding:2px 4px;font-size:11px}.leaflet-control-attribution{display:none!important}.leaflet-top.leaflet-left{top:-12px;left:-12px;font-size:12px}.line-distance-tooltip{background:rgb(255 255 255 / 10%);border:1px solid rgba(0,0,0,.2);color:#fff;font-size:9px;font-weight:600;padding:2px 4px;border-radius:4px;pointer-events:none}.separator{margin:10px auto 10px;border:0}.separator--line2{border:0;border-bottom:3px solid #fff;-webkit-animation:separator-width 1s ease-out forwards;animation:separator-width 1s ease-out forwards}@media (max-width:768px){#sidebar{width:204px;top:0;max-height:70vh;font-size:13px}#sidebar-toggle{top:0;right:224px;font-size:28px}.leaflet-bottom.leaflet-right{margin-bottom:60px}body.sidebar-hidden #sidebar-toggle{right:0}#hotspot-control-wrapper{top:2px;right:224px;gap:4px;padding:6px 8px}.hotspot-control{width:32px!important;height:32px!important;font-size:14px!important}body.sidebar-hidden #hotspot-control-wrapper{right:-182px;top:2px}}.filter-badge{display:inline-block;padding:4px 8px;border-radius:6px;cursor:pointer;user-select:none;font-weight:700}.filter-badge.active{box-shadow:0 2px 8px rgba(0,0,0,.4);transform:translateY(-1px)}.filter-badge.offline{background:#ef4444;color:#fff}.filter-badge.online{background:#16a34a;color:#fff;margin-left:6px}.filter-badge.all{background:#64748b;color:#fff;margin-left:6px}.parent-row{font-weight:700}.child-row{font-weight:400}#meter-toggle-btn{padding:4px 6px;font-size:12px;border-radius:6px;background:#fff;color:#000;border:0;cursor:pointer}#meter-toggle-btn.on{background:#e6f4ea}#meter-toggle-btn.off{background:#fff;opacity:.85}#ap-names-toggle-btn{padding:4px 6px;font-size:12px;border-radius:6px;background:#fff;color:#000;border:0;cursor:pointer}#ap-names-toggle-btn.on{background:#e6f4ea}#ap-names-toggle-btn.off{background:#fff;opacity:.85}#line-toggle-btn.on{background:rgba(255,255,255,.95)!important;opacity:1!important}#line-toggle-btn.off{background:rgba(255,255,255,.95)!important;opacity:.4!important}.color-picker{display:flex;align-items:center;gap:8px;margin-top:4px}.color-swatch{width:20px;height:20px;border:2px solid #111827;border-radius:3px;box-sizing:border-box;flex:0 0 20px}.line-color-select{min-width:120px;padding:6px 10px;border-radius:6px;border:2px solid rgba(0,0,0,.15);font-weight:700;-webkit-appearance:none;-moz-appearance:none;appearance:none;background-repeat:no-repeat;background-position:right 8px center;background-size:16px 16px;cursor:pointer}.line-color-select::-ms-expand{display:block}@media (max-width:420px){.line-color-select{min-width:100px;padding:5px 8px}}.icon-type-selector{display:flex;gap:10px;margin:8px 0;align-items:center}.icon-type-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 12px;border:2px solid #ddd;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;font-weight:600;transition:all .2s ease}.icon-type-btn.active{background:#0ea5e9;color:#fff;border-color:#0ea5e9}.icon-type-btn img{width:16px;height:16px}.ip-input-wrapper{transition:opacity .3s ease}.ip-input-wrapper.disabled{opacity:.5;pointer-events:none}#search-ap-input{width:90%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:12px;margin-bottom:8px;box-sizing:border-box;margin-left:auto;margin-right:auto;display:block}#search-ap-input::placeholder{color:#999}.ap-item.hidden{display:none}.leaflet-tile-dark{filter:brightness(.6) contrast(1.2) saturate(1.1)}</style></head><body><div id="sidebar-toggle">❯</div><div class="wrap"><?php $countUp=0;$countDown=0;$countUnknown=0;foreach($apList as $ap){$nw=$netwatch[$ap['ip']]?? null;$st=$nw['status']??($ap['status']?? 'unknown');if($st==='up')$countUp++;elseif($st==='down')$countDown++;else $countUnknown++;} ?><aside id="sidebar"><div style="margin-top:-6px"><a class="btn"href="peta.php">Refresh</a> <span style="display:inline-block;width:6px"></span> <a class="btn"href="index.php"style="float:right;background:#94a3b8"><i class="fa-sign-out-alt fas"></i>Logout</a></div><hr class="separator separator--line2"><div style="margin:6px 0 12px;font-size:12px"><span class="filter-badge offline"data-status="down"id="filter-offline"><?=$countDown?> Off </span><span class="filter-badge online"data-status="up"id="filter-online"><?=$countUp?> On </span><span class="filter-badge all"data-status="all"id="filter-all">All (<?=$countUp+$countDown+$countUnknown?>)</span></div><input id="search-ap-input"placeholder="🔍 "><div style="max-height:70vh;overflow:auto;border:1px solid #ddd;border-radius:8px"><table id="ap-table"style="width:90%;border-collapse:collapse;font-size:smaller"><tbody><?php usort($apList,function($a,$b)use($netwatch){$order=['down'=>0,'unknown'=>1,'up'=>2];$statusA='unknown';if(($a['type']?? 'wifi')==='odp'&&empty($a['ip'])){$statusA='unknown';}else{$statusA=$netwatch[$a['ip']]['status']??($a['status']?? 'unknown');}$statusB='unknown';if(($b['type']?? 'wifi')==='odp'&&empty($b['ip'])){$statusB='unknown';}else{$statusB=$netwatch[$b['ip']]['status']??($b['status']?? 'unknown');}return $order[$statusA]<=> $order[$statusB];});$apById=[];foreach($apList as $ap){$apById[$ap['id']]=$ap;}function getDownChildren($id,$apList,$netwatch){$result=[];foreach($apList as $ap){if(!empty($ap['line'])&&$ap['line']===$id){$status=$netwatch[$ap['ip']]['status']??($ap['status']?? 'unknown');if($status==='down'){$result[]=$ap;$result=array_merge($result,getDownChildren($ap['id'],$apList,$netwatch));}}}return $result;}$offlineGroups=[];foreach($apList as $ap){$status=$netwatch[$ap['ip']]['status']??($ap['status']?? 'unknown');if($status!=='down')continue;$parentLine=$ap['line']?? null;if($parentLine&&isset($apById[$parentLine])){$parentAp=$apById[$parentLine];$parentStatus=$netwatch[$parentAp['ip']]['status']??($parentAp['status']?? 'unknown');if($parentStatus==='down'){continue;}}$childrenDown=getDownChildren($ap['id'],$apList,$netwatch);if(!empty($childrenDown)){$offlineGroups[$ap['id']]=$childrenDown;}}$shownIds=[];foreach($offlineGroups as $parentId=>$group){$parent=$apById[$parentId];$groupId='group_'.md5($parentId);echo '<tr class="parent-row" data-group="'.$groupId.'" data-root-status="down" style="background:#2d2d2d;color:#ef4444;font-weight:bold;cursor:pointer;">
            <td colspan="3" style="padding:4px 6px;">
                🔴 '.htmlspecialchars($parent['name']).' (+'.count($group).')
                <span class="toggle-icon" style="float:right;">⯈</span>
            </td>
          </tr>';foreach($group as $child){$shownIds[]=$child['id'];$sinceRaw=$netwatch[$child['ip']]['since']??($child['lasttime']?? '');$since=formatSince($sinceRaw);$childStatus=$netwatch[$child['ip']]['status']??($child['status']?? 'unknown');echo '<tr class="child-row '.$groupId.'" style="display:none;border-bottom:1px solid #444;cursor:pointer;" data-status="'.htmlspecialchars($childStatus).'" onclick="focusToAp(\''.$child['id'].'\')">
                <td style="width:18px;padding:2px 4px;color:#ef4444;">↳</td>
                <td style="padding:2px 4px;">'.htmlspecialchars($child['name']).'</td>
                <td style="padding:2px 4px;text-align:right;color:#75ddff;">'.htmlspecialchars($since).'</td>
              </tr>';}$shownIds[]=$parentId;}$shownIds=array_values(array_unique($shownIds));foreach($apList as $ap):if(in_array($ap['id'],$shownIds))continue;$status=$netwatch[$ap['ip']]['status']??($ap['status']?? 'unknown');$cls=$status==='up'?'color:#16a34a;':($status==='down'?'color:#ef4444;':'color:#6b7280;');$icon=$status==='up'?'🟢':($status==='down'?'🔴':'⚪');$sinceRaw=$netwatch[$ap['ip']]['since']??($ap['lasttime']?? '');$since=formatSince($sinceRaw); ?><tr data-status="<?=htmlspecialchars($status)?>"onclick='focusToAp("<?=$ap['id']?>")'style="border-bottom:1px solid #eee;cursor:pointer"><td style="padding:2px 4px;<?=$cls?>width:18px"><?=$icon?></td><td style="padding:2px 4px;white-space:nowrap;width:18px"><?=htmlspecialchars($ap['name'])?></td><td style="padding:2px 4px;text-align:right;white-space:nowrap;color:#75ddff;width:10px"><?php if(($ap['type']?? 'wifi')==='odp'&&empty($ap['ip'])){echo 'ODP';}else{$sinceRaw=$netwatch[$ap['ip']]['since']??($ap['lasttime']?? '');$since=formatSince($sinceRaw);echo htmlspecialchars($since);} ?></td></tr><?php endforeach; ?></tbody></table><script>// 🧠 Toggle tampil/sembunyi child group
document.querySelectorAll('.parent-row').forEach(row => {
    row.addEventListener('click', () => {
        const groupId = row.dataset.group;
        const children = document.querySelectorAll('.'+groupId);
        const icon = row.querySelector('.toggle-icon');
        const isVisible = children[0]?.style.display !== 'none';

        children.forEach(tr => {
            tr.style.display = isVisible ? 'none' : 'table-row';
        });

        icon.textContent = isVisible ? '⯈' : '⯆';
    });
});</script></div></aside><div id="map"></div></div><script src="https://unpkg.com/leaflet/dist/leaflet.js"></script><script src="https://unpkg.com/leaflet-polylinedecorator@1.6.0/dist/leaflet.polylineDecorator.js"></script><script>const MT_IP =<?=json_encode($mt_ip,JSON_UNESCAPED_SLASHES)?>;
const AP_LIST =<?=json_encode($apList,JSON_UNESCAPED_UNICODE)?>;
const NW_STATUS =<?=json_encode($netwatch,JSON_UNESCAPED_UNICODE)?>;
const HOTSPOT_ACTIVE =<?=json_encode($hotspotActive,JSON_UNESCAPED_UNICODE)?>;
const LINES_JSON =<?=json_encode($linesData ??[],JSON_UNESCAPED_UNICODE)?>;

const ACCEPTED_COLORS = ['lime','pink','blue','gray','green','gold','aqua','gainsboro','chartreuse','magenta','orange','fuchsia','black','yellow','brown'];

// ✅ PERBAIKAN: Handle ODP yang tidak punya IP
function getStatus(ip){ 
  if (!ip || ip === '') return 'unknown'; // ODP return 'unknown'
  return (NW_STATUS[ip] && NW_STATUS[ip].status) ? NW_STATUS[ip].status : 'unknown'; 
}

function getSince(ip){ 
  if (!ip || ip === '') return ''; // ODP tidak punya since
  return (NW_STATUS[ip] && NW_STATUS[ip].since) ? NW_STATUS[ip].since : ''; 
}

 // ---- NEW: color utilities for swatch & select text color ----
function colorNameToHex(name){
  if(!name) return name;
  const m = {
    'lime':'#00ff00',
    'pink':'#ff69b4',
    'blue':'#007bff',
    'gray':'#6b7280',
    'green':'#008000',
    'gold':'#ffd700',
    'aqua':'#00ffff',
    'gainsboro':'#dcdcdc',
    'chartreuse':'#7fff00',
    'magenta':'#ff00ff',
    'orange':'#f59e0b',
    'fuchsia':'#ff00ff',
    'black':'#000000',
    'yellow':'#ffff00',
    'brown':'#a0522d'
  };
  const s = String(name).trim().toLowerCase();
  return m[s] || name;
}
function expandHex3(h){
  // '#abc' -> '#aabbcc'
  return h.replace(/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i, (m,r,g,b) => '#' + r+r + g+g + b+b);
}
function getTextColorFor(bg){
  if(!bg) return '#000';
  let hex = colorNameToHex(bg);
  if(/^#[0-9a-f]{3}$/i.test(hex)) hex = expandHex3(hex);
  if(/^#[0-9a-f]{6}$/i.test(hex)){
    const r = parseInt(hex.substr(1,2),16);
    const g = parseInt(hex.substr(3,2),16);
    const b = parseInt(hex.substr(5,2),16);
    const lum = 0.299*r + 0.587*g + 0.114*b;
    return lum > 186 ? '#000' : '#fff';
  }
  const darkNames = ['black','navy','purple','maroon','brown','darkblue','darkgreen'];
  if(darkNames.indexOf(String(bg).toLowerCase()) !== -1) return '#fff';
  return '#000';
}
// ---- end new utils ----

// create map dengan dark satellite sebagai default
const sat = L.tileLayer(
  'https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}',
  { maxZoom: 21, subdomains:['mt0','mt1','mt2','mt3'] }
);

const satDark = L.tileLayer(
  'https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}',
  { 
    maxZoom: 21, 
    subdomains:['mt0','mt1','mt2','mt3'],
    className: 'leaflet-tile-dark'
  }
);

const hybrid = L.tileLayer(
  'https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
  { maxZoom: 21, subdomains:['mt0','mt1','mt2','mt3'] }
);

const map = L.map('map', { center:[0,118], zoom:4, layers:[sat], zoomControl:false });
L.control.zoom({ position: 'bottomright' }).addTo(map);
L.control.layers({ 
  "Google Satellite": sat, 
  "Google Satellite Dark": satDark, 
  "Google Hybrid": hybrid 
}, null, { position:'topleft' }).addTo(map);

// HOTSPOT control dengan tambahan AP Names
function buildHotspotHtml(){
  const cnt = (HOTSPOT_ACTIVE && HOTSPOT_ACTIVE.length) ? HOTSPOT_ACTIVE.length : 0;
  return `<div style="display:flex;align-items:center;gap:8px;flex-direction:row;">
    <div class="hotspot-control">${cnt}</div>
    <button id="meter-toggle-btn" class="hotspot-control" style="margin-top:0;">📏</button>
    <button id="ap-names-toggle-btn" class="hotspot-control" style="margin-top:0;">📝</button>
    <button id="line-toggle-btn" class="hotspot-control" style="margin-top:0;">📍</button>
  </div>`;
}
const hotspotControlDiv = buildHotspotHtml();
const HotspotControl = L.Control.extend({
  options: { position: 'topleft' },
  onAdd: function() {
    const container = L.DomUtil.create('div');
    container.id = 'hotspot-control-wrapper';
    container.innerHTML = buildHotspotHtml();
    L.DomEvent.disableClickPropagation(container);
    return container;
  }
});
map.addControl(new HotspotControl());

// state containers
const markers={}; const markersByIp={}; const lines={}; const allLatLng=[];
const lineTooltips = {};
const decorators = {};
const lineColors = {};
const apNameLabels = {};

const linesMeta = {};

 // quick AP_BY_ID map
const AP_BY_ID = {};
AP_LIST.forEach(a => { AP_BY_ID[a.id] = a; });

function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c])); }

// NEW: Make WiFi/ODP icon based on type
function makeIconByType(status, type) {
  const cls = status === 'up' ? 'up' : (status === 'down' ? 'down' : 'unknown');
  
  if (type === 'odp') {
    // ODP icon - yellow/orange color
    return L.divIcon({
      className: '',
      html: `<div class="wifi-icon ${cls}" style="background: white;"><img src="icons/ODP.webp" class="wifi-img" style="width:14px;height:14px;"></div>`,
      iconSize: [15, 15],
      iconAnchor: [10, 10],
      popupAnchor: [0, -15]
    });
  } else {
    // WiFi icon
    return L.divIcon({
      className: '',
      html: `<div class="wifi-icon ${cls}"><img src="icons/Wifi.webp" class="wifi-img"></div>`,
      iconSize: [15, 15],
      iconAnchor: [10, 10],
      popupAnchor: [0, -15]
    });
  }
}

// Keep old function for backward compatibility
function makeWifiIcon(status){
  return makeIconByType(status, 'wifi');
}

function normalizeColor(c){
  if(!c) return 'lime';
  const s = String(c).trim();
  const lower = s.toLowerCase();
  if (ACCEPTED_COLORS.indexOf(lower) !== -1) return lower;
  if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s)) return s;
  return 'lime';
}

function getMarkerForReference(ref){
  if(!ref) return null;
  if(markers[ref]) return markers[ref];
  if(markersByIp[ref]) return markersByIp[ref];
  if(AP_BY_ID[ref] && markers[AP_BY_ID[ref].id]) return markers[AP_BY_ID[ref].id];
  const lower = String(ref).toLowerCase();
  for(const k in AP_BY_ID){
    if(String(AP_BY_ID[k].name).toLowerCase() === lower && markers[AP_BY_ID[k].id]) return markers[AP_BY_ID[k].id];
  }
  return null;
}

// ---------- Performance-minded tweaks (keep arrows and all features) ----------
const canvasRenderer = L.canvas({ padding: 0.5 });

function isLatLngInView(latlng){
  try{
    return map.getBounds().pad(0.25).contains(latlng);
  }catch(e){ return true; }
}
function isLineInView(first, last){
  try{
    const bounds = map.getBounds().pad(0.25);
    const box = L.latLngBounds([first, last]);
    return bounds.intersects(box) || bounds.contains(first) || bounds.contains(last);
  }catch(e){ return true; }
}

function debounce(fn, wait){
  let t;
  return function(...args){
    clearTimeout(t);
    t = setTimeout(()=> fn.apply(this, args), wait);
  };
}

function hashSignForId(id){
  let sum = 0;
  for(let i=0;i<id.length;i++) sum += id.charCodeAt(i);
  return (sum % 2 === 0) ? 1 : -1;
}

const EARTH_RADIUS = 6378137;

function toRad(deg){ return deg * Math.PI / 180; }

function haversineDistance(lat1, lon1, lat2, lon2){
  const φ1 = toRad(lat1), φ2 = toRad(lat2);
  const dφ = toRad(lat2 - lat1);
  const dλ = toRad(lon2 - lon1);
  const a = Math.sin(dφ/2)*Math.sin(dφ/2) + Math.cos(φ1)*Math.cos(φ2)*Math.sin(dλ/2)*Math.sin(dλ/2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  return EARTH_RADIUS * c;
}

function flattenLatLngs(raw){
  if (Array.isArray(raw) && Array.isArray(raw[0]) && raw[0][0] && raw[0][0].lat !== undefined) return raw[0];
  return raw;
}

function polylineTotalLength(latlngs){
  if(!latlngs || latlngs.length < 2) return 0;
  let sum = 0;
  for(let i=1;i<latlngs.length;i++){
    sum += haversineDistance(latlngs[i-1].lat, latlngs[i-1].lng, latlngs[i].lat, latlngs[i].lng);
  }
  return sum;
}

function interpolateLatLng(a, b, t){
  return L.latLng(
    a.lat + (b.lat - a.lat) * t,
    a.lng + (b.lng - a.lng) * t
  );
}

function pointAtDistanceAlong(latlngs, dist){
  if(!latlngs || latlngs.length === 0) return null;
  if(dist <= 0) return latlngs[0];
  let acc = 0;
  for(let i=1;i<latlngs.length;i++){
    const seg = haversineDistance(latlngs[i-1].lat, latlngs[i-1].lng, latlngs[i].lat, latlngs[i].lng);
    if(acc + seg >= dist){
      const remain = dist - acc;
      const t = seg <= 0 ? 0 : (remain / seg);
      return interpolateLatLng(latlngs[i-1], latlngs[i], t);
    }
    acc += seg;
  }
  return latlngs[latlngs.length-1];
}

function latLngToMercator(lat, lng){
  const x = EARTH_RADIUS * toRad(lng);
  const y = EARTH_RADIUS * Math.log(Math.tan(Math.PI/4 + toRad(lat)/2));
  return L.point(x, y);
}
function mercatorToLatLng(pt){
  const lng = (pt.x / EARTH_RADIUS) * 180 / Math.PI;
  const lat = (2 * Math.atan(Math.exp(pt.y / EARTH_RADIUS)) - Math.PI/2) * 180 / Math.PI;
  return L.latLng(lat, lng);
}

const SIMPLE_LINES_THRESHOLD = 200;
const ZOOM_THRESHOLD_FOR_DETAILS = 18;
const maxDecorators = 30;
const maxTooltips = 200;

const simplifiedMode = (AP_LIST && AP_LIST.length >= SIMPLE_LINES_THRESHOLD);

function buildBentLatLngs(fromLatLng, toLatLng, id){
  try {
    const straightFallback = [fromLatLng, toLatLng];
    if (simplifiedMode) return straightFallback;

    const p1 = latLngToMercator(fromLatLng.lat, fromLatLng.lng);
    const p2 = latLngToMercator(toLatLng.lat, toLatLng.lng);

    const dx = p2.x - p1.x, dy = p2.y - p1.y;
    const segM = Math.sqrt(dx*dx + dy*dy);

    if (segM < 80) return straightFallback;

    const mid = L.point((p1.x + p2.x) / 2, (p1.y + p2.y) / 2);

    let nx = -dy, ny = dx;
    const norm = Math.sqrt(nx*nx + ny*ny) || 1; nx /= norm; ny /= norm;

    const offsetM = Math.min(Math.max(8, segM * 0.055), 140);
    const sign = hashSignForId(id);
    const control = L.point(mid.x + nx * offsetM * sign, mid.y + ny * offsetM * sign);

    const N = Math.min(12, Math.max(4, Math.round(segM / 300)));
    const latlngs = [];
    for (let i = 0; i <= N; i++) {
      const t = i / N;
      const x = (1 - t)*(1 - t)*p1.x + 2*(1 - t)*t*control.x + t*t*p2.x;
      const y = (1 - t)*(1 - t)*p1.y + 2*(1 - t)*t*control.y + t*t*p2.y;
      latlngs.push(mercatorToLatLng(L.point(x, y)));
    }
    return latlngs;
  } catch (err) {
    return [fromLatLng, toLatLng];
  }
}

function createPolylineFor(id, fromLatLng, toLatLng, options){
  const zoom = map.getZoom ? map.getZoom() : ZOOM_THRESHOLD_FOR_DETAILS;
  
  if (simplifiedMode && zoom < ZOOM_THRESHOLD_FOR_DETAILS) {
    const opts = Object.assign({ 
      renderer: canvasRenderer,  // ← TETAP canvasRenderer (jangan L.SVG())
      color: options.color || '#00ff00', 
      weight: 1,           // ← Ringan untuk zoom jauh
      opacity: 0.6,        // ← Transparency untuk zoom jauh
      interactive: false 
    }, options||{});
    return L.polyline([fromLatLng, toLatLng], opts);
  }
  
  const latlngs = buildBentLatLngs(fromLatLng, toLatLng, id);
  
  // ← UBAH: Adaptive weight & smoothFactor berdasar zoom
  const weight = zoom >= 16 ? 2 : (zoom >= 12 ? 1.5 : 1);
  const smoothFactor = zoom < 12 ? 3 : (zoom < 16 ? 2 : 1);
  
  const opts = Object.assign({ 
    renderer: canvasRenderer,  // ← TETAP canvasRenderer (jangan dirubah)
    color: options.color || '#00ff00', 
    weight: weight,
    opacity: 0.8, 
    interactive: false, 
    smoothFactor: smoothFactor
  }, options||{});
  
  return L.polyline(latlngs, opts);
}

function createDecoratorForLine(id, poly, color){
  if(!poly) return;

  const existingDecoratorsCount = Object.keys(decorators).length;
  if (existingDecoratorsCount >= maxDecorators && !decorators[id]) {
    return;
  }

  const zoom = map.getZoom ? map.getZoom() : 0;
  if (simplifiedMode && zoom < ZOOM_THRESHOLD_FOR_DETAILS) return;

  let latlngs = [];
  try {
    const raw = poly.getLatLngs();
    latlngs = flattenLatLngs(raw);
  } catch (e) { return; }
  if(!latlngs || latlngs.length < 2) return;
  const first = latlngs[0], last = latlngs[latlngs.length-1];
  if (!isLineInView(first, last)) {
    if(decorators[id]) removeDecorator(id);
    return;
  }

  if(decorators[id]) { removeDecorator(id); }

  function bearingDeg(lat1, lon1, lat2, lon2){
    const φ1 = lat1 * Math.PI/180;
    const φ2 = lat2 * Math.PI/180;
    const Δλ = (lon2 - lon1) * Math.PI/180;
    const y = Math.sin(Δλ) * Math.cos(φ2);
    const x = Math.cos(φ1)*Math.sin(φ2) - Math.sin(φ1)*Math.cos(φ2)*Math.cos(Δλ);
    const θ = Math.atan2(y, x);
    return (θ*180/Math.PI + 360) % 360;
  }

  const pts = latlngs.map(ll => map.latLngToLayerPoint(ll));
  if(pts.length < 2) return;

  let markerRadiusPx = 6;
  const mk = markers[id];
  if(mk && mk._icon){
    try{
      const w = mk._icon.offsetWidth || mk._icon.clientWidth || 0;
      const h = mk._icon.offsetHeight || mk._icon.clientHeight || 0;
      markerRadiusPx = Math.max(w, h) / 2;
    }catch(e){}
  }
  const GAP_PX = 4;
  const desiredOffsetFromMarker = Math.max(markerRadiusPx + GAP_PX, 6);
  let remaining = desiredOffsetFromMarker;
  let foundPoint = null;
  for (let i = pts.length - 1; i > 0; i--) {
    const A = pts[i-1], B = pts[i];
    const segLen = A.distanceTo(B);
    if (segLen >= remaining) {
      const t = (segLen - remaining) / segLen;
      const x = A.x + (B.x - A.x) * t;
      const y = A.y + (B.y - A.y) * t;
      foundPoint = L.point(x, y);
      break;
    } else remaining -= segLen;
  }
  if(!foundPoint){
    const lastIdx = pts.length - 1;
    const A = pts[lastIdx - 1], B = pts[lastIdx];
    const segLen = A.distanceTo(B) || 1;
    const t = Math.max(0, (segLen - desiredOffsetFromMarker) / segLen);
    const x = A.x + (B.x - A.x) * t;
    const y = A.y + (B.y - A.y) * t;
    foundPoint = L.point(x, y);
  }

  const arrowLatLng = map.layerPointToLatLng(foundPoint);
  const deg = bearingDeg(arrowLatLng.lat, arrowLatLng.lng, last.lat, last.lng);

  const SVG_SIZE = simplifiedMode ? 10 : 14;
  const polyWeight = (poly.options && poly.options.weight) || 2;
  const arrowStroke = Math.max(1, Math.round(polyWeight * 1.2));
  const half = simplifiedMode ? 3 : 4;
  const pathColor = color || (poly.options && poly.options.color) || '#00ff00';
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${SVG_SIZE}" height="${SVG_SIZE}" viewBox="${-half-2} ${-half-2} ${ (half+2)*2 } ${ (half+2)*2 }">
      <path d="M ${-half} ${half} L 0 0 L ${half} ${half}" stroke="${pathColor}" stroke-width="${arrowStroke}" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`;
  const html = `<div style="width:${SVG_SIZE}px;height:${SVG_SIZE}px;transform:rotate(${deg}deg);display:flex;align-items:center;justify-content:center;pointer-events:none;">${svg}</div>`;
  const icon = L.divIcon({ className:'', html:html, iconSize:[SVG_SIZE, SVG_SIZE], iconAnchor:[Math.floor(SVG_SIZE/2), Math.floor(SVG_SIZE/2)] });
  const arrowMarker = L.marker(arrowLatLng, { icon: icon, interactive: false, pane: 'overlayPane' }).addTo(map);
  if (arrowMarker && arrowMarker._icon) arrowMarker._icon.style.zIndex = 650;
  decorators[id] = arrowMarker;
}

function roundDistance(d){
  if(typeof d !== 'number' || isNaN(d)) return 0;
  return Math.round(d);
}

function removeDecorator(id){
  if(!decorators[id]) return;
  try { if(map.hasLayer(decorators[id])) map.removeLayer(decorators[id]); } catch(e){}
  try { if(decorators[id]._icon && decorators[id]._icon.remove) decorators[id]._icon.remove(); } catch(e){}
  delete decorators[id];
}

function removeTooltip(id){
  if(!lineTooltips[id]) return;
  try { if(map.hasLayer(lineTooltips[id])) map.removeLayer(lineTooltips[id]); } catch(e){}
  delete lineTooltips[id];
}

function ensureTooltipsCreated(){
  try{
    const wantMeters = (localStorage.getItem('showMeters') === null) ? true : (localStorage.getItem('showMeters') === 'true');
    if(!wantMeters) return;
    
    let existing = Object.keys(lineTooltips).length;
    const bounds = map.getBounds().pad(0.3);  // ← TAMBAHAN: Viewport culling
    let createdThisRound = 0;                  // ← TAMBAHAN: Per-event counter
    
    for(const id in lines){
      if(existing >= 50) break;                // ← UBAH dari maxTooltips (200) jadi 50
      if(lineTooltips[id]) continue;
      if(createdThisRound >= 3) break;         // ← TAMBAHAN: Max 3 per event
      
      try {
        const poly = lines[id];
        if(!poly) continue;
        const raw = poly.getLatLngs();
        const latlngs = flattenLatLngs(raw);
        if(!latlngs || latlngs.length < 2) continue;
        
        const first = latlngs[0], last = latlngs[latlngs.length-1];
        
        // ← TAMBAHAN: Skip kalau di luar viewport
        if(!bounds.contains(first) && !bounds.contains(last)) continue;
        
        const straightDist = haversineDistance(first.lat, first.lng, last.lat, last.lng);
        const totalLen = polylineTotalLength(latlngs);
        const midPoint = (totalLen > 0) ? pointAtDistanceAlong(latlngs, totalLen / 2) : latlngs[Math.floor(latlngs.length/2)];
        if(midPoint){
          const distanceTooltip = L.tooltip({
            permanent: true,
            direction: 'center',
            className: 'line-distance-tooltip',
            interactive: false
          }).setContent(roundDistance(straightDist) + ' m').setLatLng(midPoint).addTo(map);
          lineTooltips[id] = distanceTooltip;
          existing++;
          createdThisRound++;  // ← TAMBAHAN: Hitung yang dibuat
        }
      } catch(e){}
    }
  }catch(e){
    console.warn('ensureTooltipsCreated error', e);
  }
}

function setupColorSwatch(swatchId, selectId){
  const sw = document.getElementById(swatchId);
  const sel = document.getElementById(selectId);
  if(!sw || !sel) return;

  if(!sel.value) sel.value = 'lime';
  const setColorOnSwatch = (val) => {
    const cssColor = colorNameToHex(val);
    sw.style.background = cssColor;
    sel.style.background = cssColor;
    sel.style.color = getTextColorFor(cssColor);
  };
  setColorOnSwatch(sel.value);
  sel.addEventListener('change', ()=> setColorOnSwatch(sel.value));

  sw.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const existing = document.getElementById(swatchId + '-palette');
    if(existing){ existing.remove(); return; }

    const palette = document.createElement('div');
    palette.id = swatchId + '-palette';
    palette.style.position = 'absolute';
    palette.style.zIndex = 10050;
    palette.style.display = 'grid';
    palette.style.gridAutoFlow = 'row';
    palette.style.gridTemplateColumns = 'repeat(5, 26px)';
    palette.style.gap = '6px';
    palette.style.padding = '8px';
    palette.style.background = '#fff';
    palette.style.border = '1px solid rgba(0,0,0,0.12)';
    palette.style.borderRadius = '8px';
    palette.style.boxShadow = '0 6px 18px rgba(2,6,23,0.12)';

    const opts = Array.from(sel.options);
    opts.forEach(o => {
      const c = o.value;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.title = o.textContent || c;
      btn.style.width = '26px';
      btn.style.height = '26px';
      btn.style.border = '2px solid #111827';
      btn.style.borderRadius = '6px';
      btn.style.cursor = 'pointer';
      btn.style.padding = '0';
      const cssColor = colorNameToHex(c);
      btn.style.background = cssColor;
      btn.addEventListener('click', (ev2) => {
        ev2.stopPropagation();
        sel.value = c;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        setColorOnSwatch(c);
        palette.remove();
      });
      palette.appendChild(btn);
    });

    document.body.appendChild(palette);

    const rect = sw.getBoundingClientRect();
    const left = Math.max(8, rect.left);
    const top = rect.bottom + 8;
    palette.style.left = left + 'px';
    palette.style.top = top + 'px';

    const onDocClick = (e) => {
      if(!palette.contains(e.target) && e.target !== sw){
        palette.remove();
        document.removeEventListener('click', onDocClick);
      }
    };
    setTimeout(()=> document.addEventListener('click', onDocClick), 0);
  });
}

// ✅ NEW: Toggle AP Names on map
let showAPNames = (localStorage.getItem('showAPNames') === 'true');

function toggleAPNames(){
  showAPNames = !showAPNames;
  const btn = document.getElementById('ap-names-toggle-btn');
  if(!btn) return;
  
  if(showAPNames){
    btn.textContent = '📝 On';
    btn.classList.add('on');
    btn.classList.remove('off');
    // Tampilkan semua labels
    Object.values(apNameLabels).forEach(label => {
      if(label && label.getElement()) label.getElement().style.display = 'block';
    });
  } else {
    btn.textContent = '📝 Off';
    btn.classList.remove('on');
    btn.classList.add('off');
    // Sembunyikan semua labels
    Object.values(apNameLabels).forEach(label => {
      if(label && label.getElement()) label.getElement().style.display = 'none';
    });
  }
  localStorage.setItem('showAPNames', showAPNames);
}

// ---------- Marker creation (unchanged logic, keep draggable) ----------
function addMarker(ap){
  // ✅ Validasi lat/lng
  const lat = parseFloat(ap.lat);
  const lng = parseFloat(ap.lng);
  
  if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
    console.warn('Invalid coordinates for AP:', ap.id, ap.name, lat, lng);
    return; // Skip marker jika koordinat invalid
  }

  const status = getStatus(ap.ip);
  // NEW: Use makeIconByType to support ODP
  const iconType = (ap.type === 'odp') ? 'odp' : 'wifi';
  const icon = makeIconByType(status, iconType);
  const m = L.marker([lat, lng], {icon, draggable:true}).addTo(map);
  allLatLng.push([lat, lng]);

  const sinceText = getSince(ap.ip) || ap.lasttime || '';
  const popupHtml = `<div style="min-width:160px; font-size:12px">
    <b>${escapeHtml(ap.name)}</b><br>
    ${ap.type === 'odp' ? 'Type: ODP<br>' : 'IP: ' + escapeHtml(ap.ip) + '<br>'}
    Status: ${status.toUpperCase()}<br>
    Since: ${escapeHtml(sinceText)}<br>
    <div style="margin-top:6px; display:flex; gap:6px; flex-direction:column;">
      <div style="display:flex; gap:6px;">
        <a class="btn" style="color:#fff" onclick='openEditPopup(${JSON.stringify(ap)})'>Edit</a>
        <a class="btn danger" style="color:#fff" href="delete_ap.php?id=${encodeURIComponent(ap.id)}" onclick="return confirm('Hapus AP ini?')">Hapus</a>
      </div>
      <div style="display:flex; gap:6px; justify-content:flex-start;">
        ${ap.type !== 'odp' ? '<a class="btn" style="background:#16a34a;color:#fff" href="http://' + encodeURIComponent(ap.ip) + '/" target="_blank" rel="noopener" title="Buka IP ' + escapeHtml(ap.ip) + '">Open</a>' : ''}
        <a class="btn" style="background:#f59e0b;color:#fff" href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(lat + ',' + lng)}" target="_blank" rel="noopener" title="Trace ke ${escapeHtml(ap.name)}">Trace</a>
      </div>
    </div>
  </div>`;
  m.bindPopup(popupHtml);

  markers[ap.id]=m;
  if (ap.ip) markersByIp[ap.ip]=m;

  // ✅ NEW: Add AP Name Label
  const label = L.tooltip({
    permanent: true,
    direction: 'center',
    className: 'my-label',
    interactive: false,
    offset: [0, 10]
  }).setContent(escapeHtml(ap.name)).setLatLng([lat, lng]).addTo(map);
  
  apNameLabels[ap.id] = label;
  
  // Set initial visibility
  if(label.getElement()) label.getElement().style.display = showAPNames ? 'block' : 'none';

  m.on('drag', e=>{
    if(ap.line && lines[ap.id]){
      const from = getMarkerForReference(ap.line);
      if(from) {
        const latlngs = buildBentLatLngs(from.getLatLng(), m.getLatLng(), ap.id);
        lines[ap.id].setLatLngs(latlngs);
        createDecoratorForLine(ap.id, lines[ap.id], lineColors[ap.id]);
      }
    }
    AP_LIST.forEach(child=>{
      if(child.line===ap.id && lines[child.id]) {
        const latlngs = buildBentLatLngs(m.getLatLng(), markers[child.id].getLatLng(), child.id);
        lines[child.id].setLatLngs(latlngs);
        createDecoratorForLine(child.id, lines[child.id], lineColors[child.id]);
      }
    });
    
    // Update label position
    if(apNameLabels[ap.id]){
      apNameLabels[ap.id].setLatLng(m.getLatLng());
    }
  });

  m.on('dragstart', e=>{
      m._originalLatLng = m.getLatLng();
  });

  m.on('dragend', async e=>{
      const pos = m.getLatLng();

      if(!confirm('Anda yakin ingin memindahkan AP "'+ap.name+'" ke koordinat baru?')){
          m.setLatLng(m._originalLatLng);

          if(ap.line && lines[ap.id]){
              const from = getMarkerForReference(ap.line);
              if(from) lines[ap.id].setLatLngs(buildBentLatLngs(from.getLatLng(), m._originalLatLng, ap.id));
              createDecoratorForLine(ap.id, lines[ap.id], lineColors[ap.id]);
          }

          AP_LIST.forEach(child=>{
              if(child.line === ap.id && lines[child.id]){
                  lines[child.id].setLatLngs(buildBentLatLngs(m._originalLatLng, markers[child.id].getLatLng(), child.id));
                  createDecoratorForLine(child.id, lines[child.id], lineColors[child.id]);
              }
          });
          
          if(apNameLabels[ap.id]){
            apNameLabels[ap.id].setLatLng(m._originalLatLng);
          }

          showToast('Perubahan dibatalkan');
          return;
      }

      try{
          const resp = await fetch('update_coord.php',{
              method:'POST',
              headers:{'Content-Type':'application/json'},
              body: JSON.stringify({id:ap.id, lat:pos.lat, lng:pos.lng, router_ip:MT_IP})
          });
          if(!resp.ok) throw new Error('HTTP '+resp.status);
          const j = await resp.json();

          if(j.ok){
              const el=document.querySelector(`.ap-item[data-id="${ap.id}"]`);
              if(el) el.querySelector('.sub').textContent='Koord: '+pos.lat.toFixed(6)+', '+pos.lng.toFixed(6);

              showToast('Koordinat AP "'+ap.name+'" berhasil diperbarui!', 3000);
              setTimeout(()=>location.reload(), 800);
          } else {
              showToast('Gagal menyimpan koordinat', 3000);
              m.setLatLng(m._originalLatLng);
          }
      } catch(err){
          showToast('Gagal menyimpan koordinat: '+err.message, 4000);
          m.setLatLng(m._originalLatLng);
      }
  });

}

AP_LIST.forEach(a=>addMarker(a));

Object.values(LINES_JSON).forEach(ld => {
  const ap = AP_BY_ID[ld.toId];
  if(!ap) return;
  const fromMarker = getMarkerForReference(ld.fromRef);
  let fromLatLng = null;
  if (fromMarker) fromLatLng = fromMarker.getLatLng();
  else {
    const fromAp = AP_BY_ID[ld.fromRef];
    if(fromAp) fromLatLng = L.latLng(fromAp.lat, fromAp.lng);
    else fromLatLng = L.latLng(ld.latlngs[0][0], ld.latlngs[0][1]);
  }
  const toMarker = markers[ld.toId];
  if(!toMarker) {
    console.warn('Skip membuat garis: child marker missing untuk', ld.toId);
    return;
  }
  const toLatLng = toMarker.getLatLng();
  const visible = isLineInView(fromLatLng, toLatLng);

  const targetStatus = getStatus(ap.ip);
  let colorWord;
  if (targetStatus === 'down') { colorWord = 'red'; }
  else if (targetStatus === 'up') { colorWord = normalizeColor(ld.color || ap.lineColor || 'lime'); }
  else { colorWord = normalizeColor(ld.color || ap.lineColor || 'gray'); }

  lineColors[ap.id] = colorWord;

  const poly = createPolylineFor(ap.id, fromLatLng, toLatLng, { color: colorWord, weight: 2, opacity: 1 });
  poly.addTo(map);
  lines[ap.id] = poly;

  linesMeta[ap.id] = { fromRef: ld.fromRef, toId: ld.toId };

  const zoom = map.getZoom ? map.getZoom() : 0;
  if (visible && ( (!simplifiedMode) || (zoom >= ZOOM_THRESHOLD_FOR_DETAILS) )) {
    createDecoratorForLine(ap.id, poly, colorWord);
  }

  const showMetersNow = (localStorage.getItem('showMeters') === null) ? true : (localStorage.getItem('showMeters') === 'true');
  const existingTooltipsCount = Object.keys(lineTooltips).length;
  if (visible && showMetersNow && (existingTooltipsCount < maxTooltips)) {
    const midPoint = L.latLng(ld.mid[0], ld.mid[1]);
    const distanceTooltip = L.tooltip({
      permanent: true,
      direction: 'center',
      className: 'line-distance-tooltip',
      interactive: false
    }).setContent(roundDistance(ld.straight) + ' m').setLatLng(midPoint).addTo(map);
    lineTooltips[ap.id] = distanceTooltip;
  }
});

if(allLatLng.length>0) map.fitBounds(L.latLngBounds(allLatLng),{padding:[40,40]});

const updatePolylinesDebounced = debounce(function(){
  for(const id in linesMeta){
    try{
      const meta = linesMeta[id];
      const poly = lines[id];
      if(!meta || !poly) continue;
      const from = getMarkerForReference(meta.fromRef);
      const to = markers[meta.toId];
      if(!from || !to) continue;
      const newLatlngs = buildBentLatLngs(from.getLatLng(), to.getLatLng(), id);
      poly.setLatLngs(newLatlngs);
      createDecoratorForLine(id, poly, lineColors[id]);

      try {
        const tt = lineTooltips[id];
        if (tt) {
          const raw = poly.getLatLngs();
          const flat = flattenLatLngs(raw);
          const totalLen = polylineTotalLength(flat);
          const midPoint = (totalLen > 0) ? pointAtDistanceAlong(flat, totalLen / 2) : flat[Math.floor(flat.length/2)];
          if (midPoint) tt.setLatLng(midPoint);
          const dist = (flat && flat.length >= 2) ? haversineDistance(flat[0].lat, flat[0].lng, flat[flat.length-1].lat, flat[flat.length-1].lng) : 0;
          tt.setContent(roundDistance(dist) + ' m');
        }
      } catch(e) {
      }

    }catch(e){
      console.warn('update poly error', id, e);
    }
  }
}, 400);

const rebuildDecoratorsDebounced = debounce(function(){
  const zoom = map.getZoom ? map.getZoom() : 0;
  const bounds = map.getBounds();
  const padded = bounds.pad(0.35);
  
  let createdCount = 0;
  const maxPerFrame = 8;
  
  for(const id in lines){
    // ← TAMBAHAN: Jika sudah 8, hentikan
    if(createdCount >= maxPerFrame) break;
    
    try{
      const poly = lines[id];
      const latlngs = poly.getLatLngs();
      const first = latlngs[0], last = latlngs[latlngs.length-1];
      
      // ← MODIFIKASI: Cek viewport dulu sebelum culling
      if (!padded.contains(first) && !padded.contains(last)) {
        removeDecorator(id);
        removeTooltip(id);
        continue;
      }
      
      // ← MODIFIKASI: Hanya buat kalau belum ada DAN di viewport
      if(!decorators[id] && isLineInView(first, last)){
        createDecoratorForLine(id, poly, lineColors[id]);
        createdCount++;
      }
    } catch(e){
      console.warn('decorator rebuild error', id, e);
    }
  }
}, 600); // ← UBAH DARI 220

let showMeters = (localStorage.getItem('showMeters') === null) ? true : (localStorage.getItem('showMeters') === 'true');

// ← TAMBAHAN BARU
let isCurrentlyZooming = false;
map.on('zoomstart', () => { isCurrentlyZooming = true; });
map.on('zoomend', () => { isCurrentlyZooming = false; });

function updateAllLineTooltips(){
  if(isCurrentlyZooming) return; // ← TAMBAH 1 BARIS INI
  
  const zoomOk = map.getZoom() >= ZOOM_THRESHOLD_FOR_DETAILS;
  const shouldShow = zoomOk && showMeters;
  Object.entries(lineTooltips).forEach(([id, t]) => {
    const el = t.getElement();
    if(!el) return;
    el.style.display = shouldShow ? 'block' : 'none';
  });
  const btn = document.getElementById('meter-toggle-btn');
  if(btn){
    btn.textContent = '📏' + (showMeters ? 'On' : 'Off');
    btn.classList.toggle('on', showMeters);
    btn.classList.toggle('off', !showMeters);
  }
}

// Set initial state for AP Names button
const apNamesBtn = document.getElementById('ap-names-toggle-btn');
if(apNamesBtn){
  if(showAPNames){
    apNamesBtn.textContent = '📝 On';
    apNamesBtn.classList.add('on');
  } else {
    apNamesBtn.textContent = '📝 Off';
    apNamesBtn.classList.add('off');
  }
}

updateAllLineTooltips();

const ensureTooltipsCreatedDebounced = debounce(ensureTooltipsCreated, 600);

map.on('zoomend moveend', function(){
  updatePolylinesDebounced();
  rebuildDecoratorsDebounced();
  ensureTooltipsCreatedDebounced();
  updateAllLineTooltips();
});

setTimeout(() => {
  try {
    if (allLatLng.length > 0) {
      try { map.invalidateSize(); } catch(e) {}
      updatePolylinesDebounced();
      rebuildDecoratorsDebounced();
      ensureTooltipsCreatedDebounced();
      updateAllLineTooltips();
    }
  } catch (e) {
    console.warn('initial decorator rebuild failed', e);
  }
}, 300);

map.whenReady(() => {
  setTimeout(() => {
    try {
      rebuildDecoratorsDebounced();
      ensureTooltipsCreatedDebounced();
      updateAllLineTooltips();
    } catch (e) {}
  }, 400);
});

// ===== LINE TOGGLE ON/OFF =====
let showLines = (localStorage.getItem('showLines') === null) ? true : (localStorage.getItem('showLines') === 'true');

function updateLineVisibility(){
  const btn = document.getElementById('line-toggle-btn');
  if(!btn) return;
  
  if(showLines){
    btn.classList.add('on');
    btn.classList.remove('off');
    Object.values(lines).forEach(line => {
      if(line && !map.hasLayer(line)) map.addLayer(line);
    });
    Object.values(decorators).forEach(decorator => {
      if(decorator && !map.hasLayer(decorator)) map.addLayer(decorator);
    });
  } else {
    btn.classList.remove('on');
    btn.classList.add('off');
    
    // ✅ BERSIHKAN SEMUA di-layer untuk benar-benar OFF
    map.eachLayer(function(layer) {
      if(layer instanceof L.Polyline && !(layer instanceof L.Polygon)) {
        map.removeLayer(layer);
      }
    });
    Object.values(decorators).forEach(decorator => {
      if(decorator && map.hasLayer(decorator)) map.removeLayer(decorator);
    });
  }
  localStorage.setItem('showLines', showLines);
}

// Set initial state
updateLineVisibility();

document.addEventListener('click', function(e){
  const el = e.target;
  if(!el) return;
  
  if(el.id === 'line-toggle-btn'){
    showLines = !showLines;
    updateLineVisibility();
  }
  
  if(el.id === 'meter-toggle-btn'){
    showMeters = !showMeters;
    localStorage.setItem('showMeters', showMeters);
    if(showMeters){
      ensureTooltipsCreated();
    }
    updateAllLineTooltips();
  }
  
  if(el.id === 'ap-names-toggle-btn'){
    toggleAPNames();
  }
});


function showToast(message, duration=3000){
    let toast = document.createElement('div');
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.bottom = '20px';
    toast.style.right = '20px';
    toast.style.background = 'rgba(0,0,0,0.75)';
    toast.style.color = '#fff';
    toast.style.padding = '8px 12px';
    toast.style.borderRadius = '6px';
    toast.style.zIndex = 5000;
    toast.style.fontSize = '12px';
    toast.style.boxShadow = '0 2px 6px rgba(0,0,0,0.3)';
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s';
    document.body.appendChild(toast);
    setTimeout(()=>toast.style.opacity='1', 10);
    setTimeout(()=>{ toast.style.opacity='0'; setTimeout(()=>toast.remove(), 300); }, duration);
}

document.querySelectorAll('.ap-item').forEach(item=>{
  item.addEventListener('click', ()=>{ const marker=markers[item.dataset.id]; if(marker){ map.setView(marker.getLatLng(),18,{animate:true}); marker.openPopup(); } });
});

// NEW: Icon type selector handler
function setupIconTypeSelector(formId, ipInputSelector, defaultType='wifi') {
  const form = document.getElementById(formId);
  if (!form) return;

  const wifiBtn = form.querySelector('[data-icon-type="wifi"]');
  const odpBtn = form.querySelector('[data-icon-type="odp"]');
  const ipWrapper = form.querySelector(ipInputSelector);

  if (!wifiBtn || !odpBtn) return;

  // Set initial active state
  const setActive = (type) => {
    if (type === 'odp') {
      wifiBtn.classList.remove('active');
      odpBtn.classList.add('active');
      if (ipWrapper) ipWrapper.classList.add('disabled');
      const ipInput = form.querySelector('input[name="ip"]');
      if (ipInput) ipInput.required = false;
    } else {
      wifiBtn.classList.add('active');
      odpBtn.classList.remove('active');
      if (ipWrapper) ipWrapper.classList.remove('disabled');
      const ipInput = form.querySelector('input[name="ip"]');
      if (ipInput) ipInput.required = true;
    }
    const typeInput = form.querySelector('input[name="type"]');
    if (typeInput) typeInput.value = type;
  };

  // Check if type field already has value
  const existingType = form.querySelector('input[name="type"]')?.value || defaultType;
  setActive(existingType);

  wifiBtn.addEventListener('click', () => setActive('wifi'));
  odpBtn.addEventListener('click', () => setActive('odp'));
}

function openAddPopup(latlng){
  const options=[{id:'', name:'— Tidak ada —'}].concat(AP_LIST.map(x=>({id:x.id,name:x.name})));
  const optsHtml = options.map(o=>`<option value="${o.id}">${o.name}</option>`).join('');

  const lineColors = [
    {value:'lime', label:'Hijau'},
    {value:'pink', label:'Pink'},
    {value:'blue', label:'Biru'},
    {value:'orange', label:'Orens'},
    {value:'fuchsia', label:'Ungu'},
    {value:'black', label:'Hitam'},
    {value:'yellow', label:'Kuning'},
    {value:'gray', label:'Abu'},
    {value:'green', label:'Hijau Tua'},
    {value:'gold', label:'Emas'},
    {value:'aqua', label:'Biru Air'},
    {value:'gainsboro', label:'Gainsboro'},
    {value:'chartreuse', label:'Chartreuse'},
    {value:'magenta', label:'Magenta'},
    {value:'brown', label:'Coklat'}
  ];
  const colorOptions = lineColors.map(c=>`<option value="${c.value}">${c.label}</option>`).join('');

  const formHtml = `<div style="min-width:220px; font-size:12px">
    <h4>Tambah AP</h4>
    <form id="addApForm">
      <label>Nama AP:<br><input type="text" name="name" required style="width:100%;font-size:12px"/></label><br>
      
      <label style="display:block;margin-bottom:8px;">Tipe:<br>
        <div class="icon-type-selector">
          <button type="button" class="icon-type-btn" data-icon-type="wifi">
            <img src="icons/Wifi.webp" alt="WiFi"> WiFi
          </button>
          <button type="button" class="icon-type-btn" data-icon-type="odp">
            <img src="icons/ODP.webp" alt="ODP"> ODP
          </button>
        </div>
        <input type="hidden" name="type" value="wifi"/>
      </label>
      
      <div class="ip-input-wrapper" style="margin-bottom:8px;">
        <label>IP AP:<br><input type="text" name="ip" required style="width:100%;font-size:12px"/></label><br>
      </div>
      
      <label>Source:<br>
        <input type="text" id="addSourceSearch" placeholder="Cari AP..." style="width:100%;font-size:12px;margin-bottom:4px"/><br>
        <select name="line" style="font-size:12px">${optsHtml}</select>
      </label><br><br>
      <label>Warna Garis:<br>
        <div class="color-picker">
          <div id="swatch-add" class="color-swatch"></div>
          <select id="addLineColor" name="lineColor" class="line-color-select">${colorOptions}</select>
        </div>
      </label><br>
      <input type="hidden" name="lat" value="${latlng.lat}"/>
      <input type="hidden" name="lng" value="${latlng.lng}"/>
      <div style="margin-top:6px; text-align:right">
        <button type="button" id="cancelAdd" class="btn" style="background:#94a3b8">Batal</button>
        <button type="submit" class="btn">Tambah</button>
      </div>
    </form>
  </div>`;
  const popup=L.popup().setLatLng(latlng).setContent(formHtml).openOn(map);
  document.getElementById('cancelAdd').addEventListener('click',()=>map.closePopup());

  // Setup icon type selector
  setupIconTypeSelector('addApForm', '.ip-input-wrapper', 'wifi');

  try{
    const searchInput = document.getElementById('addSourceSearch');
    const selectEl = document.querySelector('#addApForm select[name="line"]');
    const originalOptions = options.slice();
    searchInput.addEventListener('input', function(){
      const q = String(this.value || '').trim().toLowerCase();
      const filtered = originalOptions.filter(o => {
        if(q === '') return true;
        return (String(o.name || '').toLowerCase().indexOf(q) !== -1) || (String(o.id || '').toLowerCase().indexOf(q) !== -1);
      });
      selectEl.innerHTML = filtered.map(o => `<option value="${o.id}">${escapeHtml(o.name)}</option>`).join('');
    });
  }catch(e){
    console.warn('Search init (add) error', e);
  }

  try{
    setupColorSwatch('swatch-add', 'addLineColor');
  }catch(e){ console.warn('init color (add) error', e); }

document.getElementById('addApForm').addEventListener('submit', async ev=>{
    ev.preventDefault();
    const form=ev.target;
    const lineColor = form.lineColor.value || 'lime';
    const apType = form.type.value || 'wifi';

    const formData={
        name: form.name.value.trim(),
        ip: apType === 'odp' ? '' : form.ip.value.trim(),
        lat: parseFloat(form.lat.value),
        lng: parseFloat(form.lng.value),
        line: form.line.value,
        lineColor: lineColor,
        type: apType
    };
    if(!formData.name){ alert("Nama wajib diisi"); return; }
    if(apType === 'wifi' && !formData.ip){ alert("IP wajib diisi untuk tipe WiFi"); return; }

    try{
        const resp = await fetch('add_ap.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(formData)
        });
        const j = await resp.json();
        if(j.success){
            addMarker(j.ap);
            AP_LIST.push(j.ap);
            map.closePopup();
            alert('AP berhasil ditambahkan' + (j.mikrotik==='ok'?' ':' '));
            location.reload();
        } else alert('Gagal menambahkan AP: '+(j.error||'unknown'));
    }catch(err){ alert('Gagal menambahkan AP: '+err.message); }
  });
}

function openEditPopup(ap){
    const options = [{id:'', name:'— Tidak ada —'}].concat(AP_LIST.filter(x=>x.id!==ap.id).map(x=>({id:x.id,name:x.name})));
    const optsHtml = options.map(o=>`<option value="${o.id}" ${ap.line===o.id?'selected':''}>${o.name}</option>`).join('');

    const currentColor = (ap.lineColor && ACCEPTED_COLORS.indexOf(String(ap.lineColor).toLowerCase()) !== -1) ? String(ap.lineColor).toLowerCase() : 'lime';

    const lineColors = [
      {value:'lime', label:'Hijau'},
      {value:'pink', label:'Pink'},
      {value:'blue', label:'Biru'},
      {value:'orange', label:'Orens'},
      {value:'fuchsia', label:'Ungu'},
      {value:'black', label:'Hitam'},
      {value:'yellow', label:'Kuning'},
      {value:'gray', label:'Abu'},
      {value:'green', label:'Hijau Tua'},
      {value:'gold', label:'Emas'},
      {value:'aqua', label:'Biru Air'},
      {value:'gainsboro', label:'Gainsboro'},
      {value:'chartreuse', label:'Chartreuse'},
      {value:'magenta', label:'Magenta'},
      {value:'brown', label:'Coklat'}
    ];
    const colorOptions = lineColors.map(c=>`<option value="${c.value}" ${currentColor===c.value?'selected':''}>${c.label}</option>`).join('');

    const formHtml = `<div style="min-width:220px; font-size:12px">
        <h4>Edit AP</h4>
        <form id="editApForm">
            <label>Nama AP:<br><input type="text" name="name" value="${escapeHtml(ap.name)}" required style="width:100%;font-size:12px"/></label><br>
            
            <label style="display:block;margin-bottom:8px;">Tipe:<br>
              <div class="icon-type-selector">
                <button type="button" class="icon-type-btn" data-icon-type="wifi">
                  <img src="icons/Wifi.webp" alt="WiFi"> WiFi
                </button>
                <button type="button" class="icon-type-btn" data-icon-type="odp">
                  <img src="icons/ODP.webp" alt="ODP"> ODP
                </button>
              </div>
              <input type="hidden" name="type" value="${ap.type || 'wifi'}"/>
            </label>
            
            <div class="ip-input-wrapper" style="margin-bottom:8px;">
              <label>IP AP:<br><input type="text" name="ip" value="${escapeHtml(ap.ip || '')}" style="width:100%;font-size:12px"/></label><br>
            </div>
            
            <label>Latitude:<br><input type="number" step="any" name="lat" value="${ap.lat}" required style="width:100%;font-size:12px"/></label><br>
            <label>Longitude:<br><input type="number" step="any" name="lng" value="${ap.lng}" required style="width:100%;font-size:12px"/></label><br>
            <label>Source:<br>
              <input type="text" id="editSourceSearch" placeholder="Cari AP..." style="width:100%;font-size:12px;margin-bottom:4px"/><br>
              <select name="line" style="width:100%;font-size:12px">${optsHtml}</select>
            </label><br><br>
<label>Warna Garis:<br>
  <div class="color-picker">
    <div id="swatch-edit" class="color-swatch"></div>
    <select id="editLineColor" name="lineColor" class="line-color-select">${colorOptions}</select>
  </div>
</label><br>
<br>

            <div style="margin-top:6px; text-align:right">
                <button type="button" id="cancelEdit" class="btn" style="background:#94a3b8">Batal</button>
                <button type="submit" class="btn">Simpan</button>
            </div>
        </form>
    </div>`;

    const popup = L.popup().setLatLng([ap.lat, ap.lng]).setContent(formHtml).openOn(map);

    document.getElementById('cancelEdit').addEventListener('click', ()=>map.closePopup());

    // Setup icon type selector
    setupIconTypeSelector('editApForm', '.ip-input-wrapper', ap.type || 'wifi');

    try{
      const searchInput = document.getElementById('editSourceSearch');
      const selectEl = document.querySelector('#editApForm select[name="line"]');
      const originalOptions = options.slice();
      searchInput.addEventListener('input', function(){
        const q = String(this.value || '').trim().toLowerCase();
        const filtered = originalOptions.filter(o => {
          if(q === '') return true;
          return (String(o.name || '').toLowerCase().indexOf(q) !== -1) || (String(o.id || '').toLowerCase().indexOf(q) !== -1);
        });
        selectEl.innerHTML = filtered.map(o => `<option value="${o.id}" ${String(ap.line) === String(o.id) ? 'selected' : ''}>${escapeHtml(o.name)}</option>`).join('');
      });
    }catch(e){
      console.warn('Search init (edit) error', e);
    }

    try{
      setupColorSwatch('swatch-edit', 'editLineColor');
    }catch(e){ console.warn('init color (edit) error', e); }

    document.getElementById('editApForm').addEventListener('submit', async ev=>{
        ev.preventDefault();
        const form = ev.target;
        const lineColor = form.lineColor.value || 'lime';
        const apType = form.type.value || 'wifi';

        const formData = {
            id: ap.id,
            name: form.name.value.trim(),
            ip: apType === 'odp' ? '' : form.ip.value.trim(),
            lat: parseFloat(form.lat.value),
            lng: parseFloat(form.lng.value),
            line: form.line.value,
            lineColor: lineColor,
            type: apType
        };
        try{
            const resp = await fetch('edit_ap_ajax.php', {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify(formData)
            });
            const j = await resp.json();
            if(j.success){
                updateMarkerOnMap(j.ap);
                map.closePopup();
                alert('AP berhasil diperbarui');
                location.reload();
            } else alert('Gagal update AP: '+(j.error||'unknown'));
        }catch(err){ alert('Gagal update AP: '+err.message); }
    });
}

function updateMarkerOnMap(ap){
    const m = markers[ap.id];
    if(!m) return;

    m.setLatLng([ap.lat, ap.lng]);
    const status = getStatus(ap.ip);
    // NEW: Use type to select icon
    const iconType = (ap.type === 'odp') ? 'odp' : 'wifi';
    m.setIcon(makeIconByType(status, iconType));
    m.getPopup().setContent(`
        <div style="min-width:120px; font-size:12px">
            <b>${escapeHtml(ap.name)}</b><br>
            ${ap.type === 'odp' ? 'Type: ODP<br>' : 'IP: ' + escapeHtml(ap.ip || '') + '<br>'}
            Status: ${status.toUpperCase()}<br>
            Since: ${escapeHtml(getSince(ap.ip)||ap.lasttime||'')}<br>
            <div style="margin-top:6px; display:flex; gap:6px; flex-direction:column;">
                <div style="display:flex; gap:6px;">
                    <a class="btn" style="color:#fff" onclick="openEditPopup(${JSON.stringify(ap)})">Edit</a>
                    <a class="btn danger" style="color:#fff" onclick="if(confirm('Hapus AP ini?')) deleteAp('${ap.id}')">Hapus</a>
                </div>
                <div style="display:flex; gap:6px; justify-content:flex-end;">
                    ${ap.type !== 'odp' ? '<a class="btn" style="background:#16a34a;color:#fff" href="http://' + encodeURIComponent(ap.ip || '') + '/" target="_blank" rel="noopener" title="Buka IP ' + escapeHtml(ap.ip || '') + '">Akses IP</a>' : ''}
                    <a class="btn" style="background:#f59e0b;color:#fff" href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(String(ap.lat) + ',' + String(ap.lng))}" target="_blank" rel="noopener" title="Trace ke ${escapeHtml(ap.name)}">Trace</a>
                </div>
            </div>
        </div>
    `);

    if(ap.line && lines[ap.id]){
        const from = getMarkerForReference(ap.line);
        if(from) {
            const latlngs = buildBentLatLngs(from.getLatLng(), m.getLatLng(), ap.id);
            lines[ap.id].setLatLngs(latlngs);
        }
        const newColor = normalizeColor(ap.lineColor || 'lime');
        lines[ap.id].setStyle({ color: newColor, opacity: 1 });
        lineColors[ap.id] = newColor;
        createDecoratorForLine(ap.id, lines[ap.id], newColor);

        try {
          const tt = lineTooltips[ap.id];
          if (tt) {
            const raw = lines[ap.id].getLatLngs();
            const flat = flattenLatLngs(raw);
            const totalLen = polylineTotalLength(flat);
            const midPoint = (totalLen > 0) ? pointAtDistanceAlong(flat, totalLen / 2) : flat[Math.floor(flat.length/2)];
            if(midPoint) tt.setLatLng(midPoint);
            const dist = (flat && flat.length >= 2) ? haversineDistance(flat[0].lat, flat[0].lng, flat[flat.length-1].lat, flat[flat.length-1].lng) : 0;
            tt.setContent(roundDistance(dist) + ' m');
          }
        } catch(e){}
    } else if(!ap.line && lines[ap.id]){
        try { if(map.hasLayer(lines[ap.id])) map.removeLayer(lines[ap.id]); } catch(e){}
        delete lines[ap.id];
        removeDecorator(ap.id);
        try { if(lineTooltips[ap.id] && map.hasLayer(lineTooltips[ap.id])) map.removeLayer(lineTooltips[ap.id]); } catch(e){}
        delete lineTooltips[ap.id];
    }

    const idx = AP_LIST.findIndex(a=>a.id===ap.id);
    if(idx>=0) AP_LIST[idx] = ap;
}

function focusToAp(id){ const marker=markers[id]; if(marker){ map.setView(marker.getLatLng(),18,{animate:true}); marker.openPopup(); } }

map.on('contextmenu', e=>openAddPopup(e.latlng));
let touchTimer=null;
map.on('touchstart', e=>{ if(e.touches && e.touches.length>1) return; touchTimer=setTimeout(()=>openAddPopup(e.latlng),700); });
map.on('touchend touchmove', ()=>{ if(touchTimer) clearTimeout(touchTimer); touchTimer=null; });
map.on('mousedown', e=>{ if(e.originalEvent && e.originalEvent.ctrlKey) openAddPopup(e.latlng); });</script><script>const sidebar = document.getElementById("sidebar");
const toggleBtn = document.getElementById("sidebar-toggle");
const body = document.body;

toggleBtn.addEventListener("click", () => {
    sidebar.classList.toggle("hidden");
    body.classList.toggle("sidebar-hidden");

    if (sidebar.classList.contains("hidden")) {
        toggleBtn.textContent = "❮";
    } else {
        toggleBtn.textContent = "❯";
    }
});

(function(){
    const btnOffline = document.getElementById('filter-offline');
    const btnOnline = document.getElementById('filter-online');
    const btnAll = document.getElementById('filter-all');
    const table = document.getElementById('ap-table');

    let activeFilter = 'all';

    function clearActiveClasses(){
        btnOffline.classList.remove('active');
        btnOnline.classList.remove('active');
        btnAll.classList.remove('active');
    }

    function applyFilter(status){
        if(activeFilter === status || status === 'all'){
            activeFilter = 'all';
            clearActiveClasses();
            btnAll.classList.add('active');
        } else {
            activeFilter = status;
            clearActiveClasses();
            if(status === 'down') btnOffline.classList.add('active');
            else if(status === 'up') btnOnline.classList.add('active');
        }

        const rows = Array.from(table.querySelectorAll('tbody tr'));
        rows.forEach(row => {
            const isParent = row.classList.contains('parent-row');
            const groupId = row.dataset.group;

            if(isParent){
                const childRows = Array.from(document.querySelectorAll('.'+groupId));
                if(activeFilter === 'all'){
                    row.style.display = '';
                    childRows.forEach((cr)=>{ cr.style.display = 'none'; });
                    const icon = row.querySelector('.toggle-icon');
                    if(icon) icon.textContent = '⯈';
                } else {
                    const anyChildMatch = childRows.some(cr => cr.dataset.status === activeFilter);
                    if(anyChildMatch){
                        row.style.display = '';
                        childRows.forEach(cr=>{
                            if(cr.dataset.status === activeFilter) cr.style.display = 'table-row';
                            else cr.style.display = 'none';
                        });
                        const icon = row.querySelector('.toggle-icon');
                        if(icon) icon.textContent = '⯆';
                    } else {
                        row.style.display = 'none';
                        childRows.forEach(cr=>{ cr.style.display = 'none'; });
                    }
                }
            } else {
                const s = row.dataset.status || 'unknown';
                if(activeFilter === 'all') row.style.display = '';
                else row.style.display = (s === activeFilter) ? '' : 'none';
            }
        });
    }

    btnAll.classList.add('active');

    btnOffline.addEventListener('click', ()=> applyFilter('down'));
    btnOnline.addEventListener('click', ()=> applyFilter('up'));
    btnAll.addEventListener('click', ()=> applyFilter('all'));
})();

// ✅ NEW: Quick Search AP in Sidebar
(function(){
  const searchInput = document.getElementById('search-ap-input');
  const table = document.getElementById('ap-table');
  
  if(!searchInput || !table) return;
  
  searchInput.addEventListener('input', function(){
    const query = this.value.toLowerCase().trim();
    const rows = table.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
      if(query === ''){
        row.style.display = '';
      } else {
        const text = row.textContent.toLowerCase();
        if(text.includes(query)){
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      }
    });
  });
})();</script><script>(function(){
    const AUTO_RELOAD_MINUTES =<?=json_encode(intval($reloadMinutes))?>;

    if (AUTO_RELOAD_MINUTES > 0) {
      const ms = AUTO_RELOAD_MINUTES * 60 * 1000;
      console.info('Auto-reload aktif: setiap ' + AUTO_RELOAD_MINUTES + ' menit (' + ms + ' ms)');
      setInterval(() => {
        try {
          const popups = document.querySelectorAll('.leaflet-popup-open, .leaflet-popup');
          if (popups && popups.length > 0) {
            console.info('Auto-reload ditunda karena popup terbuka.');
            return;
          }
        } catch(e){}
        location.reload();
      }, ms);
    } else {
      console.info('Auto-reload dinonaktifkan (Disabled)');
    }
  })();
  
  // ✅ TAMBAHAN: Show delete notification
<?php if($deleteMessage): ?>
  (function() {
    const msg = <?php echo json_encode($deleteMessage); ?>;
    const status = <?php echo json_encode($deleteStatus); ?>;
    
    // Tunggu DOM siap
    setTimeout(() => {
      if(status === 'success') {
        showToast(msg, 3000);
      } else {
        showToast(msg, 5000);
      }
    }, 800);
  })();
<?php endif; ?>
</script><script src="js/ajax-update-ap.js"></script></body></html>