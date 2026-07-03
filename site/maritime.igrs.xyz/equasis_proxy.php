<?php
/**
 * TradeMatrix — Equasis Vessel Registry Proxy
 * Server-side: login → search → parse → JSON
 * Browser never sees equasis.org
 */
define('EQ_EMAIL',    'sampande29@gmail.com');
define('EQ_PASS',     'P@s5w0rd');
define('EQ_BASE',     'https://www.equasis.org/EquasisWeb');
define('SESSION_FILE', sys_get_temp_dir() . '/eq_sess.txt');
define('CACHE_DIR',    sys_get_temp_dir() . '/eq_cache/');
define('CACHE_TTL',    3600);

$allowed = ['https://maritime.igrs.xyz','https://www.maritime.igrs.xyz'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Access-Control-Allow-Origin: '.(in_array($origin,$allowed)?$origin:'https://maritime.igrs.xyz'));
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

$query = strtoupper(trim($_GET['q']??''));
$query = preg_replace('/[^A-Z0-9 \-\.]/','',$query);
if(strlen($query)<2){http_response_code(400);echo json_encode(['error'=>'too_short']);exit;}

// Cache
if(!is_dir(CACHE_DIR))@mkdir(CACHE_DIR,0700,true);
$cfile = CACHE_DIR.md5($query).'.json';
if(file_exists($cfile)&&(time()-filemtime($cfile))<CACHE_TTL){
    $c=json_decode(file_get_contents($cfile),true);
    if($c){$c['cached']=true;echo json_encode($c);exit;}
}

// cURL with cookie jar
$ch=curl_init();
curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
    CURLOPT_TIMEOUT=>25,CURLOPT_COOKIEFILE=>SESSION_FILE,CURLOPT_COOKIEJAR=>SESSION_FILE,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
    CURLOPT_HTTPHEADER=>['Accept: text/html,*/*;q=0.8','Accept-Language: en-US,en;q=0.5'],
]);

// Step 1: Homepage (get cookies)
curl_setopt($ch,CURLOPT_URL,EQ_BASE.'/public/HomePage');
curl_setopt($ch,CURLOPT_POST,false);
$hp=curl_exec($ch);
if(!$hp){curl_close($ch);http_response_code(502);echo json_encode(['error'=>'unreachable']);exit;}

// Extract any hidden token
preg_match('/name="__token"\s+value="([^"]+)"/i',$hp,$tm);
$token=$tm[1]??'';

// Step 2: Login POST
$ld=http_build_query(['j_email'=>EQ_EMAIL,'j_password'=>EQ_PASS,'submit'=>'Login']);
if($token)$ld.='&__token='.urlencode($token);
curl_setopt($ch,CURLOPT_URL,EQ_BASE.'/public/Auth');
curl_setopt($ch,CURLOPT_POST,true);
curl_setopt($ch,CURLOPT_POSTFIELDS,$ld);
curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/x-www-form-urlencoded','Referer: '.EQ_BASE.'/public/HomePage','Origin: https://www.equasis.org']);
$lr=curl_exec($ch);
$ok=strpos($lr,'Logout')!==false||strpos($lr,'My profile')!==false||strpos($lr,'j_email')===false;
if(!$ok){
    // Retry with GET-based auth
    curl_setopt($ch,CURLOPT_URL,EQ_BASE.'/public/Auth?j_email='.urlencode(EQ_EMAIL).'&j_password='.urlencode(EQ_PASS));
    curl_setopt($ch,CURLOPT_POST,false);
    $lr2=curl_exec($ch);
    $ok=strpos($lr2,'Logout')!==false||strpos($lr2,'My profile')!==false;
}
if(!$ok){curl_close($ch);http_response_code(503);echo json_encode(['error'=>'login_failed','message'=>'Equasis login failed']);exit;}

// Step 3: Search
$isIMO=preg_match('/^\d{7}$/',$query);
$sd=$isIMO?http_build_query(['P_SEARCH_MODE'=>'P_IMO','P_IMO'=>$query,'col'=>'S']):http_build_query(['P_SEARCH_MODE'=>'P_NAME','P_NAME'=>$query,'P_FUZZY'=>'0','col'=>'S']);
curl_setopt($ch,CURLOPT_URL,EQ_BASE.'/restricted/Search?col=S');
curl_setopt($ch,CURLOPT_POST,true);
curl_setopt($ch,CURLOPT_POSTFIELDS,$sd);
curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/x-www-form-urlencoded','Referer: '.EQ_BASE.'/restricted/SearchShip','Origin: https://www.equasis.org']);
$sr=curl_exec($ch);

// Step 4: Parse
$vessels=parse_results($sr);
if(empty($vessels)){
    $direct=parse_ship($sr);
    if($direct){
        $out=['query'=>$query,'source'=>'equasis','total'=>1,'vessels'=>[$direct],'cached'=>false,'fetched_at'=>date('c')];
        file_put_contents($cfile,json_encode($out));curl_close($ch);echo json_encode($out);exit;
    }
    // Try alternate search URL
    curl_setopt($ch,CURLOPT_URL,EQ_BASE.'/restricted/Search?col=S&P_SEARCH_MODE=P_NAME&P_NAME='.urlencode($query));
    curl_setopt($ch,CURLOPT_POST,false);
    $sr2=curl_exec($ch);
    $vessels=parse_results($sr2);
    if(empty($vessels)){$direct2=parse_ship($sr2);if($direct2)$vessels=[$direct2];}
}

// If single match, get full detail
if(count($vessels)===1&&isset($vessels[0]['_url'])){
    $du=EQ_BASE.$vessels[0]['_url'];
    curl_setopt($ch,CURLOPT_URL,$du);curl_setopt($ch,CURLOPT_POST,false);
    $dr=curl_exec($ch);
    $det=parse_ship($dr);
    if($det){unset($vessels[0]['_url']);$vessels[0]=array_merge($vessels[0],$det);}
    else unset($vessels[0]['_url']);
}

curl_close($ch);
$out=['query'=>$query,'source'=>'equasis','total'=>count($vessels),'vessels'=>$vessels,'cached'=>false,'fetched_at'=>date('c')];
if(count($vessels)>0)file_put_contents($cfile,json_encode($out));
echo json_encode($out);

// ── PARSE SEARCH RESULTS ──
function parse_results(string $html):array{
    $v=[];
    if(!preg_match_all('/<tr[^>]*class="[^"]*(?:Odd|Even|Row)[^"]*"[^>]*>([\s\S]*?)<\/tr>/i',$html,$rows))
        preg_match_all('/<tr[^>]*>\s*(?:<td[^>]*>[\s\S]*?<\/td>\s*){3,}<\/tr>/i',$html,$rows);
    if(empty($rows[1]))return[];
    foreach($rows[1] as $row){
        preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/i',$row,$cells);
        $c=array_map('eq_clean',$cells[1]??[]);
        if(count($c)<2)continue;
        preg_match('/href="([^"]*(?:Ship|vessel|detail)[^"]*\d+[^"]*)"/i',$row,$lm);
        $item=['name'=>$c[0]??'','imo'=>$c[1]??'','flag'=>$c[2]??'','type'=>$c[3]??''];
        if($lm[1]??'')$item['_url']=html_entity_decode($lm[1]);
        if($item['name']||$item['imo'])$v[]=$item;
    }
    return$v;
}

// ── PARSE SHIP DETAIL PAGE ──
function parse_ship(string $html):?array{
    if(!$html||strlen($html)<300)return null;
    if(strpos($html,'IMO')=== false&&strpos($html,'imo')===false)return null;
    $d=[];
    $pairs=[
        'name'             => '/(?:Name|SHIPNAME)\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([^<]{2,60})/i',
        'imo'              => '/IMO\s*(?:Number)?\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*(\d{7})/i',
        'mmsi'             => '/MMSI\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*(\d{9})/i',
        'call_sign'        => '/Call\s*[Ss]ign\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([A-Z0-9]{3,10})/i',
        'flag'             => '/[Ff]lag\s*(?:[Ss]tate)?\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([A-Za-z ]{3,40})/i',
        'ship_type'        => '/[Ss]hip\s*[Tt]ype\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([^<]{3,50})/i',
        'gross_tonnage'    => '/[Gg]ross\s*[Tt]onnage\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([\d,]{2,12})/i',
        'dwt'              => '/(?:DWT|[Dd]ead\s*[Ww]eight)\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([\d,]{2,12})/i',
        'year_built'       => '/[Yy]ear\s*(?:of\s*)?[Bb]uild\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*(\d{4})/i',
        'loa'              => '/[Ll]ength\s*(?:[Oo]verall)?\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([\d.]{3,8})/i',
        'beam'             => '/[Bb]readth\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*([\d.]{2,8})/i',
        'registered_owner' => '/[Rr]egistered\s*[Oo]wner\s*<\/[^>]+>[\s\S]{0,150}?<[^>]+>\s*([^<]{3,80})/i',
        'operator'         => '/[Oo]perator\s*<\/[^>]+>[\s\S]{0,150}?<[^>]+>\s*([^<]{3,80})/i',
        'ism_manager'      => '/ISM\s*[Mm]anager\s*<\/[^>]+>[\s\S]{0,150}?<[^>]+>\s*([^<]{3,80})/i',
        'classification'   => '/[Cc]lassification\s*(?:[Ss]ociety)?\s*<\/[^>]+>[\s\S]{0,150}?<[^>]+>\s*([^<]{2,50})/i',
        'recognised_org'   => '/[Rr]ecognised\s*[Oo]rganis\w+\s*<\/[^>]+>[\s\S]{0,150}?<[^>]+>\s*([^<]{2,50})/i',
        'p_and_i'          => '/P\s*[&amp;]+\s*I\s*<\/[^>]+>[\s\S]{0,150}?<[^>]+>\s*([^<]{3,80})/i',
    ];
    foreach($pairs as $k=>$rx){
        if(preg_match($rx,$html,$m)){
            $v=eq_clean($m[1]);
            if($v&&strlen($v)>1)$d[$k]=$v;
        }
    }
    // PSC inspections table
    $psc=[];
    if(preg_match_all('/<tr[^>]*>[\s\S]{0,30}<td[^>]*>\s*(\d{2}[\/\-]\d{2}[\/\-]\d{2,4})\s*<\/td>[\s\S]{0,50}<td[^>]*>\s*([^<]{5,50})\s*<\/td>[\s\S]{0,50}<td[^>]*>\s*(\d+)\s*<\/td>[\s\S]{0,50}<td[^>]*>\s*(\d+)\s*<\/td>/i',$html,$pr)){
        for($i=0;$i<min(count($pr[1]),5);$i++){
            $psc[]=['date'=>trim($pr[1][$i]),'authority'=>trim($pr[2][$i]),'inspections'=>(int)$pr[3][$i],'deficiencies'=>(int)$pr[4][$i]];
        }
    }
    if($psc)$d['psc_inspections']=$psc;
    preg_match('/[Dd]etention\w*\s*<\/[^>]+>[\s\S]{0,80}?<[^>]+>\s*(\d+)/i',$html,$det);
    if($det[1]??'')$d['detentions']=(int)$det[1];
    if(count($d)<3)return null;
    $d['data_source']='Equasis — EMSA Official Maritime Registry';
    return $d;
}
function eq_clean(string $s):string{return trim(preg_replace('/\s+/',' ',strip_tags(html_entity_decode($s,ENT_QUOTES,'UTF-8'))));}
