<?php
// ============================================================
// Paramotor WX - Thermal Area (Tempest + NBM forecast)
// - NOW wind (Tempest) + FORECAST wind (NBM next 2h)
// - 15s AJAX refresh, Listen/Stop pill
// - Adds: 24h forecast endpoint (?forecast24=1) cached 10 minutes
// - Times shown in California local time (America/Los_Angeles)
// PHP 5.3 compatible
// ============================================================

// ✅ California local time
date_default_timezone_set('America/Los_Angeles');

$accessToken = '70bd2c96-f363-4d3d-8ae1-ef5724a63e94';
$stationId   = '161239';
$tempestUrl  = "https://swd.weatherflow.com/swd/rest/observations/station/$stationId?token=$accessToken";
$nbmGridUrl  = "https://api.weather.gov/gridpoints/SGX/100,48";

function fetchJson($url, $timeout = 8, $accept = "application/json") {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "User-Agent: Paramotor-WX/1.0 (contact: coach@europilotcenter.be)",
    "Accept: $accept"
  ));
  $resp = curl_exec($ch);
  curl_close($ch);
  if (!$resp) return null;
  $data = json_decode($resp, true);
  return is_array($data) ? $data : null;
}

function padInt($n,$w){ $n=intval($n); $s=(string)$n; while(strlen($s)<$w)$s='0'.$s; return $s; }

function speakDigits($str) {
  $map = array('0'=>'zero','1'=>'one','2'=>'two','3'=>'three','4'=>'four','5'=>'five','6'=>'six','7'=>'seven','8'=>'eight','9'=>'nine');
  $out = array();
  $str = (string)$str;
  for ($i=0;$i<strlen($str);$i++){
    $ch = $str[$i];
    if ($ch==='-') $out[]='minus';
    else if (ctype_digit($ch)) $out[]=$map[$ch];
  }
  return implode(' ', $out);
}

function zuluWithDashes($timestampUtc) {
  $hhmm = gmdate('Hi', $timestampUtc);
  return implode(' - ', str_split($hhmm));
}

function windDirToTens($dirDeg) {
  $dir = intval(round(floatval($dirDeg) / 10.0) * 10);
  if ($dir <= 0) $dir = 360;
  if ($dir > 360) $dir = 360;
  return $dir;
}

// --- NBM helpers (minimal) ---
function parseIsoTimeToUnix($iso) { $t = strtotime($iso); return $t ? $t : 0; }

function getNearestFutureValue($series, $nowUnix, $maxAheadSeconds) {
  if (!$series || !is_array($series)) return null;
  $best = null; $bestDt = null;
  foreach ($series as $item) {
    if (!is_array($item) || !isset($item['validTime'])) continue;
    $parts = explode('/', $item['validTime']);
    $start = parseIsoTimeToUnix($parts[0]);
    if ($start <= 0) continue;
    $dt = $start - $nowUnix;
    if ($dt < 0 || $dt > $maxAheadSeconds) continue;
    if ($bestDt === null || $dt < $bestDt) {
      $bestDt = $dt;
      $best = array('time'=>$start, 'value'=>isset($item['value'])?$item['value']:null);
    }
  }
  return $best;
}

function unitToKnots($value, $unitCode) {
  if ($value === null) return null;
  $v = floatval($value);
  if (strpos($unitCode,'km_h-1')!==false) return $v * 0.5399568;
  if (strpos($unitCode,'m_s-1')!==false)  return $v * 1.943844;
  return $v;
}

// Returns forecast wind-from direction (wd2) and speed (ws2) for ~next 2 hours
function nbmForecast2h($nbmGridUrl) {
  $grid = fetchJson($nbmGridUrl, 10, "application/geo+json, application/json");
  if (!$grid || !isset($grid['properties'])) return array('ok'=>false);

  $p = $grid['properties'];
  $now = time();
  $max2h = 7200;
  $max1h = 3600;

  $wd = isset($p['windDirection']['values']) ? $p['windDirection']['values'] : null;
  $ws = isset($p['windSpeed']['values']) ? $p['windSpeed']['values'] : null;

  $wd1 = getNearestFutureValue($wd, $now, $max1h);
  $wd2 = getNearestFutureValue($wd, $now+3600, $max2h); if ($wd2 === null) $wd2 = $wd1;

  $ws1 = getNearestFutureValue($ws, $now, $max1h);
  $ws2 = getNearestFutureValue($ws, $now+3600, $max2h); if ($ws2 === null) $ws2 = $ws1;

  $wdv2 = ($wd2 && $wd2['value']!==null) ? intval(round($wd2['value'],0)) : null;

  $wsUnit = isset($p['windSpeed']['uom']) ? $p['windSpeed']['uom'] : '';
  $wsk2 = ($ws2 && $ws2['value']!==null) ? intval(round(unitToKnots($ws2['value'],$wsUnit),0)) : null;

  return array('ok'=>true,'wd2'=>$wdv2,'ws2'=>$wsk2);
}

// ---------- 24h forecast row endpoint (12 points, every 2 hours) ----------
function gridNearestAbs($values, $targetUnix, $maxDeltaSeconds) {
  if (!$values || !is_array($values)) return null;

  $bestVal = null;
  $bestDt = null;

  foreach ($values as $item) {
    if (!is_array($item) || !isset($item['validTime'])) continue;
    $parts = explode('/', $item['validTime']);
    $start = strtotime($parts[0]);
    if (!$start) continue;

    $dt = abs($start - $targetUnix);
    if ($dt > $maxDeltaSeconds) continue;

    if ($bestDt === null || $dt < $bestDt) {
      $bestDt = $dt;
      $bestVal = isset($item['value']) ? $item['value'] : null;
    }
  }
  return $bestVal;
}

function getForecast24Points($nbmGridUrl) {
  // cache 10 minutes to avoid hammering NWS
  $cacheFile = '/tmp/pm_fcst24_cache.json';
  $cacheTtl = 600;

  if (file_exists($cacheFile)) {
    $age = time() - filemtime($cacheFile);
    if ($age >= 0 && $age < $cacheTtl) {
      $raw = @file_get_contents($cacheFile);
      $j = $raw ? json_decode($raw, true) : null;
      if (is_array($j) && isset($j['ok'])) return $j;
    }
  }

  $grid = fetchJson($nbmGridUrl, 10, "application/geo+json, application/json");
  if (!$grid || !isset($grid['properties'])) return array('ok'=>false);

  $p = $grid['properties'];
  $wdVals = isset($p['windDirection']['values']) ? $p['windDirection']['values'] : null;
  $wsVals = isset($p['windSpeed']['values']) ? $p['windSpeed']['values'] : null;
  $tVals  = isset($p['temperature']['values']) ? $p['temperature']['values'] : null;
  $wsUom  = isset($p['windSpeed']['uom']) ? $p['windSpeed']['uom'] : '';

  $now = time();
  $points = array();

  // 12 points: +0h, +2h, ... +22h
  for ($i=0; $i<12; $i++) {
    $target = $now + ($i * 2 * 3600);
    $maxDelta = 75 * 60; // 75 minutes matching tolerance

    $wd = gridNearestAbs($wdVals, $target, $maxDelta);
    $ws = gridNearestAbs($wsVals, $target, $maxDelta);
    $tc = gridNearestAbs($tVals,  $target, $maxDelta);

    $wd = ($wd !== null) ? intval(round($wd,0)) : null;
    $ws = ($ws !== null) ? intval(round(unitToKnots($ws, $wsUom),0)) : null;
    $tc = ($tc !== null) ? intval(round($tc,0)) : null;

    $points[] = array(
      // ✅ California local time
      't' => date('H:i', $target),
      'wd' => $wd,
      'ws' => $ws,
      'tempC' => $tc
    );
  }

  $out = array('ok'=>true, 'points'=>$points);
  @file_put_contents($cacheFile, json_encode($out));
  return $out;
}

if (isset($_GET['forecast24']) && $_GET['forecast24'] == '1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(getForecast24Points($nbmGridUrl));
  exit;
}
// ---------------------------------------------------------------------------

// Build API bundle
function buildBundle($tempestUrl, $nbmGridUrl) {
  $data = fetchJson($tempestUrl, 8, "application/json");
  if (!$data || !isset($data['obs'][0])) return array('ok'=>false);

  $obs = $data['obs'][0];

  $timestamp = isset($obs['timestamp']) ? intval($obs['timestamp']) : time();
  $windDir = windDirToTens(isset($obs['wind_direction']) ? $obs['wind_direction'] : 0);
  $windKt  = intval(round((isset($obs['wind_avg']) ? floatval($obs['wind_avg']) : 0) * 1.94384, 0));
  $gustKt  = intval(round((isset($obs['wind_gust']) ? floatval($obs['wind_gust']) : 0) * 1.94384, 0));

  $fcst = nbmForecast2h($nbmGridUrl);
  $fcstDir = ($fcst['ok'] && $fcst['wd2']!==null) ? intval($fcst['wd2']) : null;
  $fcstKt  = ($fcst['ok'] && $fcst['ws2']!==null) ? intval($fcst['ws2']) : null;

  $gustFactor = max(0, $gustKt - $windKt);
  $risk = "GREEN";
  if ($windKt >= 15 || $gustKt >= 20 || $gustFactor >= 8) $risk = "RED";
  else if ($windKt >= 12 || $gustKt >= 16 || $gustFactor >= 5) $risk = "YELLOW";

  // keep speech in UTC as-is
  $timeZulu = zuluWithDashes($timestamp);

  $speech1 = "IPCA Paramotor Weather, $timeZulu U T C.";
  $speech2 = "Wind " . speakDigits(padInt($windDir,3)) . " degrees, " . speakDigits(padInt($windKt,2)) . " knots, gusting " . speakDigits(padInt($gustKt,2)) . " knots. ";
  if ($fcstDir !== null && $fcstKt !== null) {
    $speech2 .= "Forecast, wind " . speakDigits(padInt($fcstDir,3)) . " degrees, " . speakDigits(padInt($fcstKt,2)) . " knots. ";
  }
  $speech2 .= "Risk " . strtolower($risk) . ".";

  return array(
    'ok'=>true,
    // ✅ California local time
    'recorded_at'=>date('H:i',$timestamp).' PT',
    'wind_dir_deg'=>$windDir,
    'wind_speed_kt'=>$windKt,
    'wind_gust_kt'=>$gustKt,
    'gust_factor'=>$gustFactor,
    'risk'=>$risk,
    'forecast_wind_dir_deg'=>$fcstDir,
    'forecast_wind_speed_kt'=>$fcstKt,
    'speech_part1'=>$speech1,
    'speech_part2'=>$speech2
  );
}

// API endpoint (existing)
if (isset($_GET['api']) && $_GET['api']=='1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(buildBundle($GLOBALS['tempestUrl'], $GLOBALS['nbmGridUrl']));
  exit;
}

$bundle = buildBundle($tempestUrl, $nbmGridUrl);

$initWindDir = $bundle['ok'] ? intval($bundle['wind_dir_deg']) : 0;
$initWindKt  = $bundle['ok'] ? intval($bundle['wind_speed_kt']) : 0;
$initFcstDir = ($bundle['ok'] && $bundle['forecast_wind_dir_deg']!==null) ? intval($bundle['forecast_wind_dir_deg']) : 'null';

$speech_part1 = $bundle['ok'] ? $bundle['speech_part1'] : "IPCA Paramotor Weather. Data unavailable.";
$speech_part2 = $bundle['ok'] ? $bundle['speech_part2'] : "Please try again.";
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>IPCA Paramotor Weather</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="css/normalize.css" rel="stylesheet" type="text/css">
  <link href="css/webflow.css" rel="stylesheet" type="text/css">
  <link href="css/ipca-53e7b50094f8054912d52ba59d3c2c23.webflow.css" rel="stylesheet" type="text/css">

  <style>
    /* 3) Actual Weather green section */
    .pm_actual_box{
      max-width:520px;
      margin:14px auto 0 auto;
      padding:12px 14px;
      border-radius:14px;
      color:#fff;
      background: rgba(24,160,88,0.92);
      box-shadow:0 10px 24px rgba(0,0,0,0.25);
    }

    /* 4) Forecast gradient section (will copy header bg if it is a gradient) */
    .pm_forecast_box{
      max-width:520px;
      margin:14px auto 0 auto;
      padding:12px 10px 10px 10px;
      border-radius:14px;
      color:#fff;
      box-shadow:0 10px 24px rgba(0,0,0,0.25);
      background: linear-gradient(90deg,#1e3c72,#2a5298);
    }
  </style>

  <script>
    var speechPart1 = <?php echo json_encode($speech_part1); ?>;
    var speechPart2 = <?php echo json_encode($speech_part2); ?>;

    var started=false, isPlaying=false, loopTimer=null;

    function setPillLabel(){
      var btn=document.getElementById('spc_audio_pill');
      if(!btn) return;
      btn.innerHTML = isPlaying ? "■ Stop" : "▶ Listen";
      btn.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
    }

    function speakOnceWithPause(){
      if(!started) return;
      window.speechSynthesis.cancel();
      var u1=new SpeechSynthesisUtterance(speechPart1);
      var u2=new SpeechSynthesisUtterance(speechPart2);

      u1.onend=function(){
        if(!started) return;
        setTimeout(function(){ if(started) window.speechSynthesis.speak(u2); }, 500);
      };
      u2.onend=function(){
        if(!started) return;
        loopTimer=setTimeout(speakOnceWithPause, 6000);
      };

      window.speechSynthesis.speak(u1);
    }

    function toggleSpeech(){
      if(isPlaying){
        isPlaying=false; started=false;
        if(loopTimer){ clearTimeout(loopTimer); loopTimer=null; }
        window.speechSynthesis.cancel();
        setPillLabel();
      } else {
        isPlaying=true; started=true;
        setPillLabel();
        speakOnceWithPause();
      }
    }

    function httpGetJson(url, cb){
      var xhr=new XMLHttpRequest();
      xhr.open("GET", url, true);
      xhr.onreadystatechange=function(){
        if(xhr.readyState===4){
          if(xhr.status>=200 && xhr.status<300){
            try{ cb(null, JSON.parse(xhr.responseText)); } catch(e){ cb(e,null); }
          } else cb(new Error("HTTP "+xhr.status), null);
        }
      };
      xhr.send();
    }

    function updateFromApi(){
      var url = window.location.pathname + "?api=1&_=" + new Date().getTime();
      httpGetJson(url, function(err, data){
        if(err || !data || !data.ok) return;

        document.getElementById("recorded_at_value").innerHTML = " " + data.recorded_at;
        document.getElementById("pm_now").innerHTML = "NOW: " + data.wind_dir_deg + "° / " + data.wind_speed_kt + " kt (G " + data.wind_gust_kt + ")";
        document.getElementById("pm_fcst").innerHTML = (data.forecast_wind_dir_deg!==null ? ("FORECAST: " + data.forecast_wind_dir_deg + "° / " + data.forecast_wind_speed_kt + " kt") : "FORECAST: n/a");
        document.getElementById("pm_risk").innerHTML = "RISK: " + data.risk + " (gust factor " + data.gust_factor + " kt)";

        speechPart1 = data.speech_part1;
        speechPart2 = data.speech_part2;

        if(window.updateRunwayUI){
          window.updateRunwayUI(
            parseInt(data.wind_dir_deg,10),
            parseInt(data.wind_speed_kt,10),
            (data.forecast_wind_dir_deg!==null ? data.forecast_wind_dir_deg : null)
          );
        }
      });
    }

    function applyHeaderGradientToForecastBox(){
      var header = document.querySelector(".section_01");
      var box = document.getElementById("pm_forecast_box");
      if(!header || !box) return;

      var cs = window.getComputedStyle(header);
      var bgImg = cs.backgroundImage;
      if (bgImg && bgImg !== "none") {
        box.style.backgroundImage = bgImg;
        box.style.backgroundColor = cs.backgroundColor;
      }
    }

    document.addEventListener("DOMContentLoaded", function(){
      setPillLabel();
      document.getElementById("spc_audio_pill").addEventListener("click", function(e){ e.preventDefault(); toggleSpeech(); });

      if(window.updateRunwayUI){
        window.updateRunwayUI(<?php echo intval($initWindDir); ?>, <?php echo intval($initWindKt); ?>, <?php echo $initFcstDir; ?>);
      }

      applyHeaderGradientToForecastBox();

      setInterval(updateFromApi, 15000);
      setTimeout(updateFromApi, 800);
    });
  </script>
</head>

<body class="page_body">

  <!-- 1) Header with Logo (unchanged) -->
  <div class="section_01">
    <div class="navbar w-container">
      <div class="navbar_layout w-nav">
        <div class="w-container">
          <a href="#" class="w-nav-brand">
            <img src="images/IPCA_Website_Layout_.png" loading="lazy" alt="" class="header_logo_layout">
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="main_section">
    <div class="data_container_quiz_a w-container">
      <div class="question_div">

        <!-- 2) Widget -->
        <?php include __DIR__ . '/asos_compass_widget.php'; ?>

        <!-- 3) Actual Weather (green section) -->
        <div class="pm_actual_box">
          <div><b>Recorded at:</b><span id="recorded_at_value"><?php echo " " . ($bundle['ok']?$bundle['recorded_at']:"N/A"); ?></span></div>
          <div id="pm_now" style="margin-top:6px;font-weight:900;"><?php echo $bundle['ok'] ? ("NOW: ".$bundle['wind_dir_deg']."° / ".$bundle['wind_speed_kt']." kt (G ".$bundle['wind_gust_kt'].")") : "NOW: n/a"; ?></div>
          <div id="pm_fcst" style="margin-top:6px;font-weight:900;"><?php echo ($bundle['ok'] && $bundle['forecast_wind_dir_deg']!==null) ? ("FORECAST: ".$bundle['forecast_wind_dir_deg']."° / ".$bundle['forecast_wind_speed_kt']." kt") : "FORECAST: n/a"; ?></div>
          <div id="pm_risk" style="margin-top:6px;font-weight:900;"><?php echo $bundle['ok'] ? ("RISK: ".$bundle['risk']." (gust factor ".$bundle['gust_factor']." kt)") : "RISK: n/a"; ?></div>
        </div>

        <!-- 4) Forecast (gradient matching header) -->
        <div class="pm_forecast_box" id="pm_forecast_box">
          <div style="font-weight:900;margin-bottom:6px;">24h Forecast (2h steps)</div>
          <div id="pm_fcst24_row"></div>
        </div>

        <div class="question_text questions_nr" style="max-width:520px;margin:14px auto 0 auto;">
          <strong>NOTE:</strong> FOR INFORMATION ONLY. PARAMOTOR OPERATIONS REQUIRE PILOT JUDGMENT.
        </div>

      </div>
    </div>
  </div>

  <!-- Listen/Stop pill -->
  <div id="spc_audio_pill_wrap" style="position: fixed; left: 50%; bottom: 18px; transform: translateX(-50%); z-index: 9999;">
    <button id="spc_audio_pill" type="button" aria-pressed="false" style="
      border: 0; padding: 12px 18px; border-radius: 999px; font-family: inherit; font-size: 16px;
      font-weight: 600; cursor: pointer; box-shadow: 0 8px 20px rgba(0,0,0,0.25);
      background: rgba(30,60,114,0.95); color: #fff;
    ">▶ Listen</button>
  </div>

</body>
</html>