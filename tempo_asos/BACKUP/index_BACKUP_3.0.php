<?php
// ============================================================
// Paramotor WX - Thermal Area (Tempest + NBM forecast)
// - NOW wind (Tempest) + FORECAST wind (NBM +2h)
// - 15s AJAX refresh, Listen/Stop pill
// - Adds: 24h forecast endpoint (?forecast24=1) cached 10 minutes
// - Adds: ACTUAL + 2H forecast detailed WX fields
// - Adds: Wind Shear (Layer A/B method) + Risk Scoring (ACTUAL + 2H)
// - Upgrades: 24h forecast endpoint returns full per-point fields (incl. risk)
// - Times shown in California local time (America/Los_Angeles)
// PHP 5.3 compatible
// ============================================================

// ✅ California local time
date_default_timezone_set('America/Los_Angeles');

// ---- Config ----
$accessToken = '70bd2c96-f363-4d3d-8ae1-ef5724a63e94';
$stationId   = '161239';
$tempestUrl  = "https://swd.weatherflow.com/swd/rest/observations/station/$stationId?token=$accessToken";
$nbmGridUrl  = "https://api.weather.gov/gridpoints/SGX/100,48";

// Field elevation + altimeter calibration
$fieldElevationFt = 115;
$ALT_CAL_INHG = -0.03;

// ---------------------- HTTP helpers ----------------------
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
  $len = strlen($str);
  for ($i=0;$i<$len;$i++){
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

// ---------------------- NBM interval helpers ----------------------
function isoDurationToSeconds($dur) {
  $days = 0; $hours = 0; $mins = 0; $secs = 0;
  $parts = explode('T', $dur);
  $datePart = $parts[0];
  $timePart = (count($parts) > 1) ? $parts[1] : "";

  if (preg_match('/P(\d+)D/', $datePart, $m)) $days = intval($m[1]);

  if ($timePart) {
    if (preg_match('/(\d+)H/', $timePart, $m)) $hours = intval($m[1]);
    if (preg_match('/(\d+)M/', $timePart, $m)) $mins  = intval($m[1]);
    if (preg_match('/(\d+)S/', $timePart, $m)) $secs  = intval($m[1]);
  }
  return ($days * 86400) + ($hours * 3600) + ($mins * 60) + $secs;
}

function gridValueAtTime($values, $targetUnix) {
  if (!$values || !is_array($values)) return null;

  $bestPast = null;
  $bestPastStart = null;

  foreach ($values as $item) {
    if (!is_array($item) || !isset($item['validTime'])) continue;
    $vt = $item['validTime'];
    $parts = explode('/', $vt);
    if (count($parts) < 2) continue;

    $start = strtotime($parts[0]);
    if (!$start) continue;

    $durSec = isoDurationToSeconds($parts[1]);
    $end = $start + $durSec;

    if ($targetUnix >= $start && $targetUnix < $end) {
      return isset($item['value']) ? $item['value'] : null;
    }

    if ($start <= $targetUnix) {
      if ($bestPastStart === null || $start > $bestPastStart) {
        $bestPastStart = $start;
        $bestPast = isset($item['value']) ? $item['value'] : null;
      }
    }
  }
  return $bestPast;
}

function unitToKnots($value, $unitCode) {
  if ($value === null) return null;
  $v = floatval($value);
  if (strpos($unitCode,'km_h-1')!==false) return $v * 0.5399568;
  if (strpos($unitCode,'m_s-1')!==false)  return $v * 1.943844;
  return $v;
}

// ---------------------- Weather math helpers ----------------------
function hpaToInHg($hpa) { return floatval($hpa) / 33.8639; }
function inHgToHpa($inhg) { return floatval($inhg) * 33.8639; }
function metersToFeet($m) { return floatval($m) * 3.28084; }

function rhFromTempDew($tC, $tdC) {
  $tC = floatval($tC); $tdC = floatval($tdC);
  $a = 17.625; $b = 243.04;
  $es = 6.1094 * exp(($a * $tC) / ($b + $tC));
  $e  = 6.1094 * exp(($a * $tdC) / ($b + $tdC));
  if ($es <= 0) return null;
  $rh = ($e / $es) * 100.0;
  if ($rh < 0) $rh = 0;
  if ($rh > 100) $rh = 100;
  return intval(round($rh, 0));
}

function densityAltitudeFt($fieldElevFt, $altimeterInHg, $oatC) {
  $fieldElevFt = floatval($fieldElevFt);
  $altimeterInHg = floatval($altimeterInHg);
  $oatC = floatval($oatC);

  $pa = $fieldElevFt + (29.92 - $altimeterInHg) * 1000.0;
  $isa = 15.0 - 2.0 * ($fieldElevFt / 1000.0);
  $da = $pa + 120.0 * ($oatC - $isa);

  return intval(round($da / 100.0) * 100);
}

// ---------------------- Wind shear helpers (Layer A/B method) ----------------------
function norm360_php($d){
  $d = fmod(floatval($d), 360.0);
  if ($d < 0) $d += 360.0;
  return $d;
}

function dirDiffDeg($a, $b){
  if ($a === null || $b === null) return null;
  $a = norm360_php($a);
  $b = norm360_php($b);
  $d = abs($a - $b);
  if ($d > 180) $d = 360 - $d;
  return $d;
}

// meteorological wind FROM => u/v in kt (u east+, v north+)
function windUvFromDirSpd($dirFromDeg, $spdKt){
  if ($dirFromDeg === null || $spdKt === null) return null;
  $dir = deg2rad(norm360_php($dirFromDeg));
  $v = floatval($spdKt);
  $u = -$v * sin($dir);
  $w = -$v * cos($dir);
  return array('u'=>$u, 'v'=>$w);
}

function vectorShearMagKt($dir1, $spd1, $dir2, $spd2){
  $a = windUvFromDirSpd($dir1, $spd1);
  $b = windUvFromDirSpd($dir2, $spd2);
  if ($a === null || $b === null) return null;
  $du = $b['u'] - $a['u'];
  $dv = $b['v'] - $a['v'];
  return sqrt($du*$du + $dv*$dv);
}

function shearLevelFromKt($shearKt){
  if ($shearKt === null) return null;
  $x = floatval($shearKt);
  if ($x < 5.0) return "Light";
  if ($x <= 10.0) return "Moderate";
  return "Severe";
}

// Returns worst of Layer A and Layer B below 1000 ft AGL
function buildWorstShear($surfDir, $surfKt, $w20Dir, $w20Kt, $trDir, $trKt, $mixingHeightFt){
  $A_mag = vectorShearMagKt($surfDir, $surfKt, $w20Dir, $w20Kt);
  $A_dd  = dirDiffDeg($surfDir, $w20Dir);
  $A_alt = 20;

  $B_mag = vectorShearMagKt($w20Dir, $w20Kt, $trDir, $trKt);
  $B_dd  = dirDiffDeg($w20Dir, $trDir);

  $mh = ($mixingHeightFt !== null) ? intval(round($mixingHeightFt,0)) : null;
  $B_alt = ($mh !== null) ? min($mh, 1000) : 1000;

  $pick = null;
  if ($A_mag !== null && $B_mag !== null) $pick = ($B_mag > $A_mag) ? "B" : "A";
  else if ($B_mag !== null) $pick = "B";
  else if ($A_mag !== null) $pick = "A";

  if ($pick === null){
    return array('mag'=>null,'dd'=>null,'alt'=>null,'sev'=>null);
  }

  if ($pick === "A"){
    $mag = $A_mag; $dd = $A_dd; $alt = $A_alt;
  } else {
    $mag = $B_mag; $dd = $B_dd; $alt = $B_alt;
  }

  return array(
    'mag' => ($mag !== null ? floatval($mag) : null),
    'dd'  => ($dd !== null ? intval(round($dd,0)) : null),
    'alt' => ($alt !== null ? intval($alt) : null),
    'sev' => shearLevelFromKt($mag)
  );
}

// ---------------------- Thermal helper + history ----------------------
function clamp01($x){
  $x = floatval($x);
  if ($x < 0) return 0.0;
  if ($x > 1) return 1.0;
  return $x;
}

function pmHistLoad($file){
  if (!file_exists($file)) return array();
  $raw = @file_get_contents($file);
  $j = $raw ? json_decode($raw, true) : null;
  return is_array($j) ? $j : array();
}

function pmHistSave($file, $arr){
  @file_put_contents($file, json_encode($arr));
}

function pmHistPrune(&$arr, $cutoffTs){
  $out = array();
  $n = count($arr);
  for ($i=0; $i<$n; $i++){
    if (!isset($arr[$i]['t'])) continue;
    if (intval($arr[$i]['t']) >= $cutoffTs) $out[] = $arr[$i];
  }
  $arr = $out;
}

function pmHistNearestBefore($arr, $targetTs, $key){
  $best = null;
  $bestT = null;
  $n = count($arr);
  for ($i=0; $i<$n; $i++){
    if (!isset($arr[$i]['t'])) continue;
    $t = intval($arr[$i]['t']);
    if ($t > $targetTs) continue;
    if ($bestT === null || $t > $bestT){
      $bestT = $t;
      $best = isset($arr[$i][$key]) ? $arr[$i][$key] : null;
    }
  }
  return $best;
}

function pmSolarNorm($solarWm2){
  if ($solarWm2 === null) return null;
  $s = floatval($solarWm2);
  return clamp01($s / 900.0);
}

// ---------------------- PPG Risk Scoring ----------------------
function clampf($x,$a,$b){
  $x=floatval($x);
  if($x<$a) return $a;
  if($x>$b) return $b;
  return $x;
}

// Linear penalty: 0 below greenMax, ramps to maxPts at redMin, stays maxPts above.
function rampPenalty($val, $greenMax, $redMin, $maxPts){
  if($val===null) return 0;
  $v=floatval($val);
  if($v <= $greenMax) return 0;
  if($v >= $redMin) return floatval($maxPts);
  $t = ($v - $greenMax) / max(0.0001, ($redMin - $greenMax));
  return floatval($maxPts) * clampf($t,0,1);
}

function bandFromScore($score){
  $s=intval(round($score,0));
  if($s >= 60) return "RED";
  if($s >= 30) return "ORANGE";
  return "GREEN";
}

// Main scorer: feed whatever you have (nulls OK)
function pmScorePPG($windKt, $gustKt, $gustFactorKt, $shearKt, $shearLevel, $thermFpm, $daFt, $invFtAgl, $tempC, $dewC){
  $reasons = array();
  $score = 0.0;

  $pWind = rampPenalty($windKt, 7, 13, 30);
  if($pWind > 0) $reasons[] = "Wind";

  $pGust = rampPenalty($gustKt, 12, 20, 20);
  if($pGust > 0) $reasons[] = "Gusts";

  $pGF = rampPenalty($gustFactorKt, 5, 8, 20);
  if($pGF > 0) $reasons[] = "Gust spread";

  $pShear = 0.0;
  if($shearKt !== null){
    $pShear = rampPenalty($shearKt, 5, 10, 20);
  }
  if($shearLevel === "Severe") $pShear = max($pShear, 18.0);
  if($shearLevel === "Moderate") $pShear = max($pShear, 10.0);
  if($pShear > 0) $reasons[] = "Wind shear";

  $pTherm = rampPenalty($thermFpm, 200, 500, 20);
  if($pTherm > 0) $reasons[] = "Thermals";

  $pDA = rampPenalty($daFt, 3000, 6000, 10);
  if($pDA > 0) $reasons[] = "Density Alt";

  $pInv = rampPenalty($invFtAgl, 1200, 2500, 8);
  if($pInv > 0) $reasons[] = "Mixing";

  $pMoist = 0.0;
  if($tempC !== null && $dewC !== null){
    $spreadC = floatval($tempC) - floatval($dewC);
    if($spreadC < 6.0){
      $pMoist = rampPenalty((6.0 - $spreadC), 0.0, 3.0, 10);
      if($pMoist > 0) $reasons[] = "Low T-Dp spread";
    }
  }

  $score = $pWind + $pGust + $pGF + $pShear + $pTherm + $pDA + $pInv + $pMoist;

  if($windKt !== null && $gustFactorKt !== null && $windKt >= 10 && $gustFactorKt >= 6){
    $score += 6.0;
    $reasons[] = "Wind+gust combo";
  }
  if($thermFpm !== null && $shearKt !== null && $thermFpm >= 350 && $shearKt >= 7){
    $score += 6.0;
    $reasons[] = "Thermals+shear";
  }
  if($daFt !== null && $daFt >= 6000){
    $score += 3.0;
    $reasons[] = "Low climb margin";
  }

  $score = clampf($score, 0.0, 100.0);
  $band  = bandFromScore($score);

  $uniq = array();
  foreach($reasons as $r){ $uniq[$r]=1; }
  $reasons = array_keys($uniq);
  if(count($reasons) > 4) $reasons = array_slice($reasons, 0, 4);

  return array(
    'score' => intval(round($score,0)),
    'band'  => $band,
    'reasons' => $reasons
  );
}

// ---------------------- NBM 2-hour forecast (interval-based) ----------------------
function nbmForecast2h($nbmGridUrl) {
  $grid = fetchJson($nbmGridUrl, 10, "application/geo+json, application/json");
  if (!$grid || !isset($grid['properties'])) return array('ok'=>false);

  $p = $grid['properties'];
  $wdVals = isset($p['windDirection']['values']) ? $p['windDirection']['values'] : null;
  $wsVals = isset($p['windSpeed']['values']) ? $p['windSpeed']['values'] : null;
  $wsUom  = isset($p['windSpeed']['uom']) ? $p['windSpeed']['uom'] : '';

  $t2h = time() + 2*3600;

  $wd = gridValueAtTime($wdVals, $t2h);
  $ws = gridValueAtTime($wsVals, $t2h);

  $wd = ($wd !== null) ? intval(round($wd,0)) : null;
  $ws = ($ws !== null) ? intval(round(unitToKnots($ws, $wsUom),0)) : null;

  return array('ok'=>true, 'wd2'=>$wd, 'ws2'=>$ws);
}

// ---------------------- Tempest SLP fallback for DA/pressure ----------------------
function getTempestSlpHpa($tempestUrl){
  $data = fetchJson($tempestUrl, 8, "application/json");
  if (!$data || !isset($data['obs'][0])) return null;
  $obs = $data['obs'][0];
  if (!isset($obs['sea_level_pressure'])) return null;
  $v = floatval($obs['sea_level_pressure']);
  return $v > 0 ? $v : null;
}

// ---------------------- 24h forecast row endpoint (UPGRADED) ----------------------
function getForecast24Points($nbmGridUrl, $tempestUrl) {
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

  $grid = fetchJson($nbmGridUrl, 12, "application/geo+json, application/json");
  if (!$grid || !isset($grid['properties'])) return array('ok'=>false);

  // fallback SLP for pressure/DA when NWS pressure is empty
  $fallbackSlpHpa = getTempestSlpHpa($tempestUrl);

  $p = $grid['properties'];

  $wdVals = isset($p['windDirection']['values']) ? $p['windDirection']['values'] : null;
  $wsVals = isset($p['windSpeed']['values']) ? $p['windSpeed']['values'] : null;
  $wgVals = isset($p['windGust']['values']) ? $p['windGust']['values'] : null;

  $tVals  = isset($p['temperature']['values']) ? $p['temperature']['values'] : null;
  $tdVals = isset($p['dewpoint']['values']) ? $p['dewpoint']['values'] : null;
  $rhVals = isset($p['relativeHumidity']['values']) ? $p['relativeHumidity']['values'] : null;

  $prVals = isset($p['pressure']['values']) ? $p['pressure']['values'] : null; // often empty

  $mhVals = isset($p['mixingHeight']['values']) ? $p['mixingHeight']['values'] : null;

  $w20DirVals = isset($p['twentyFootWindDirection']['values']) ? $p['twentyFootWindDirection']['values'] : null;
  $w20SpdVals = isset($p['twentyFootWindSpeed']['values']) ? $p['twentyFootWindSpeed']['values'] : null;

  $trDirVals  = isset($p['transportWindDirection']['values']) ? $p['transportWindDirection']['values'] : null;
  $trSpdVals  = isset($p['transportWindSpeed']['values']) ? $p['transportWindSpeed']['values'] : null;

  $wsUom  = isset($p['windSpeed']['uom']) ? $p['windSpeed']['uom'] : '';
  $wgUom  = isset($p['windGust']['uom']) ? $p['windGust']['uom'] : '';
  $w20Uom = isset($p['twentyFootWindSpeed']['uom']) ? $p['twentyFootWindSpeed']['uom'] : '';
  $trUom  = isset($p['transportWindSpeed']['uom']) ? $p['transportWindSpeed']['uom'] : '';

  $now = time();
  $points = array();

  for ($i=0; $i<12; $i++) {
    $target = $now + ($i * 2 * 3600);

    $wd = gridValueAtTime($wdVals, $target);
    $ws = gridValueAtTime($wsVals, $target);
    $wg = gridValueAtTime($wgVals, $target);

    $tc = gridValueAtTime($tVals,  $target);
    $td = gridValueAtTime($tdVals, $target);
    $rh = gridValueAtTime($rhVals, $target);

    $slpHpa = gridValueAtTime($prVals, $target);
    if ($slpHpa === null && $fallbackSlpHpa !== null) $slpHpa = $fallbackSlpHpa;

    $mhM = gridValueAtTime($mhVals, $target);

    $w20Dir = gridValueAtTime($w20DirVals, $target);
    $w20Spd = gridValueAtTime($w20SpdVals, $target);
    $trDir  = gridValueAtTime($trDirVals,  $target);
    $trSpd  = gridValueAtTime($trSpdVals,  $target);

    $wd = ($wd !== null) ? intval(round($wd,0)) : null;
    $ws = ($ws !== null) ? intval(round(unitToKnots($ws, $wsUom),0)) : null;
    $wg = ($wg !== null) ? intval(round(unitToKnots($wg, $wgUom),0)) : null;

    $tc = ($tc !== null) ? intval(round($tc,0)) : null;
    $td = ($td !== null) ? intval(round($td,0)) : null;
    $rh = ($rh !== null) ? intval(round($rh,0)) : null;

    $p_hpa = null;
    $p_inhg = null;
    if ($slpHpa !== null){
      $p_hpa = intval(round(floatval($slpHpa),0));
      $p_inhg = round(hpaToInHg($slpHpa) + $GLOBALS['ALT_CAL_INHG'], 2);
    }

    $da_ft = null;
    if ($p_inhg !== null && $tc !== null){
      $da_ft = densityAltitudeFt($GLOBALS['fieldElevationFt'], $p_inhg, $tc);
    }

    $inv_ft = null;
    if ($mhM !== null) $inv_ft = intval(round(metersToFeet($mhM),0));

    // Thermal heuristic (simple): based on mixing height + local daytime factor
    $th_fpm = null;
    if ($inv_ft !== null){
      $hour = intval(date('G', $target));
      $dayFactor = 0.2;
      if ($hour >= 10 && $hour <= 16) $dayFactor = 1.0;
      else if ($hour >= 8 && $hour < 10) $dayFactor = 0.6;
      else if ($hour > 16 && $hour <= 18) $dayFactor = 0.5;

      $mixNorm = clampf((floatval($inv_ft) - 500.0) / 2500.0, 0.0, 1.0);
      $th_fpm = intval(round((($mixNorm * 900.0) + 100.0) * $dayFactor, 0));
    }

    // Convert 20-ft + transport
    if ($w20Dir !== null) $w20Dir = intval(round($w20Dir,0));
    if ($w20Spd !== null) $w20Spd = intval(round(unitToKnots($w20Spd, $w20Uom),0));
    if ($trDir !== null)  $trDir  = intval(round($trDir,0));
    if ($trSpd !== null)  $trSpd  = intval(round(unitToKnots($trSpd, $trUom),0));

    $shearPick = buildWorstShear($wd, $ws, $w20Dir, $w20Spd, $trDir, $trSpd, $inv_ft);
    $wsh_mag = ($shearPick['mag'] !== null) ? intval(round($shearPick['mag'],0)) : null;
    $wsh_ddir = $shearPick['dd'];
    $wsh_level = $shearPick['sev'];
    $wsh_top = $shearPick['alt'];

    $gf = null;
    if ($wg !== null && $ws !== null) $gf = max(0, intval($wg) - intval($ws));

    $risk = pmScorePPG($ws, $wg, $gf, $wsh_mag, $wsh_level, $th_fpm, $da_ft, $inv_ft, $tc, $td);

    $points[] = array(
      'dow' => date('D', $target),
      'md'  => date('M, j', $target),
      't'   => date('H:i', $target),

      'wd'  => $wd,
      'ws'  => $ws,
      'wg'  => $wg,

      'tc'  => $tc,
      'td'  => $td,
      'rh'  => $rh,

      'p_inhg' => $p_inhg,
      'p_hpa'  => $p_hpa,
      'da_ft'  => $da_ft,
      'inv_ft' => $inv_ft,
      'th_fpm' => $th_fpm,

      'wsh_level' => $wsh_level,
      'wsh_mag'   => $wsh_mag,
      'wsh_ddir'  => ($wsh_ddir !== null ? intval($wsh_ddir) : null),
      'wsh_layer_top_ft' => ($wsh_top !== null ? intval($wsh_top) : null),

      'risk_score' => $risk['score'],
      'risk_band'  => $risk['band'],
      'risk_reasons' => $risk['reasons']
    );
  }

  $out = array('ok'=>true, 'points'=>$points);
  @file_put_contents($cacheFile, json_encode($out));
  return $out;
}

if (isset($_GET['forecast24']) && $_GET['forecast24'] == '1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(getForecast24Points($nbmGridUrl, $tempestUrl));
  exit;
}

if (isset($_GET['nbmraw']) && $_GET['nbmraw'] == '1') {
  header('Content-Type: application/json; charset=utf-8');
  $grid = fetchJson($nbmGridUrl, 15, "application/geo+json, application/json");
  echo json_encode($grid);
  exit;
}

// ---------------------- Build API bundle ----------------------
function buildBundle($tempestUrl, $nbmGridUrl) {
  $data = fetchJson($tempestUrl, 8, "application/json");
  if (!$data || !isset($data['obs'][0])) return array('ok'=>false);

  $obs = $data['obs'][0];
  $timestamp = isset($obs['timestamp']) ? intval($obs['timestamp']) : time();

  // Actual wind
  $windDir = windDirToTens(isset($obs['wind_direction']) ? $obs['wind_direction'] : 0);
  $windKt  = intval(round((isset($obs['wind_avg']) ? floatval($obs['wind_avg']) : 0) * 1.94384, 0));
  $gustKt  = intval(round((isset($obs['wind_gust']) ? floatval($obs['wind_gust']) : 0) * 1.94384, 0));

  // Actual temp/dew/RH
  $airTempC = isset($obs['air_temperature']) ? floatval($obs['air_temperature']) : null;
  $dewC     = isset($obs['dew_point']) ? floatval($obs['dew_point']) : null;

  // Solar (Tempest)
  $solarWm2 = isset($obs['solar_radiation']) ? floatval($obs['solar_radiation']) : null;

  $rh = null;
  if (isset($obs['relative_humidity'])) {
    $rh = intval(round(floatval($obs['relative_humidity']), 0));
  } elseif ($airTempC !== null && $dewC !== null) {
    $rh = rhFromTempDew($airTempC, $dewC);
  }

  // Actual pressure
  $slpHpa = isset($obs['sea_level_pressure']) ? floatval($obs['sea_level_pressure']) : null;
  $altInHg = null;
  if ($slpHpa !== null) {
    $altInHg = round(hpaToInHg($slpHpa) + $GLOBALS['ALT_CAL_INHG'], 2);
  }

  $daActual = null;
  if ($altInHg !== null && $airTempC !== null) {
    $daActual = densityAltitudeFt($GLOBALS['fieldElevationFt'], $altInHg, $airTempC);
  }

  // NBM +2h detailed forecast
  $grid = fetchJson($nbmGridUrl, 10, "application/geo+json, application/json");

  $fc = array('wd'=>null,'ws'=>null,'wg'=>null,'t'=>null,'td'=>null,'rh'=>null,'slpHpa'=>null,'altInHg'=>null,'da'=>null);

  $actInvFt = null;
  $fcInvFt  = null;

  $actThermFpm = null;
  $fcThermFpm  = null;

  $actShearMag = null; $actShearDir = null; $actShearLvl = null; $actShearAlt = null;
  $fcShearMag  = null; $fcShearDir  = null; $fcShearLvl  = null; $fcShearAlt  = null;

  if ($grid && isset($grid['properties'])) {
    $p = $grid['properties'];
    $t2h = time() + 2*3600;

    $wdVals = isset($p['windDirection']['values']) ? $p['windDirection']['values'] : null;
    $wsVals = isset($p['windSpeed']['values']) ? $p['windSpeed']['values'] : null;
    $wgVals = isset($p['windGust']['values']) ? $p['windGust']['values'] : null;
    $tVals  = isset($p['temperature']['values']) ? $p['temperature']['values'] : null;
    $tdVals = isset($p['dewpoint']['values']) ? $p['dewpoint']['values'] : null;
    $rhVals = isset($p['relativeHumidity']['values']) ? $p['relativeHumidity']['values'] : null;
    $prVals = isset($p['pressure']['values']) ? $p['pressure']['values'] : null;

    $mhVals = isset($p['mixingHeight']['values']) ? $p['mixingHeight']['values'] : null;

    $mhNow = gridValueAtTime($mhVals, time());
    $mh2h  = gridValueAtTime($mhVals, $t2h);
    $actInvFt = ($mhNow !== null) ? intval(round(metersToFeet($mhNow), 0)) : null;
    $fcInvFt  = ($mh2h  !== null) ? intval(round(metersToFeet($mh2h), 0))  : null;

    // Shear layer winds: 20-ft + transport
    $w20DirVals = isset($p['twentyFootWindDirection']['values']) ? $p['twentyFootWindDirection']['values'] : null;
    $w20SpdVals = isset($p['twentyFootWindSpeed']['values']) ? $p['twentyFootWindSpeed']['values'] : null;

    $trDirVals  = isset($p['transportWindDirection']['values']) ? $p['transportWindDirection']['values'] : null;
    $trSpdVals  = isset($p['transportWindSpeed']['values']) ? $p['transportWindSpeed']['values'] : null;

    $w20Uom = isset($p['twentyFootWindSpeed']['uom']) ? $p['twentyFootWindSpeed']['uom'] : '';
    $trUom  = isset($p['transportWindSpeed']['uom']) ? $p['transportWindSpeed']['uom'] : '';

    // NOW (NWS)
    $w20_dir_now = gridValueAtTime($w20DirVals, time());
    $w20_spd_now = gridValueAtTime($w20SpdVals, time());
    $tr_dir_now  = gridValueAtTime($trDirVals,  time());
    $tr_spd_now  = gridValueAtTime($trSpdVals,  time());

    if ($w20_dir_now !== null) $w20_dir_now = intval(round($w20_dir_now,0));
    if ($w20_spd_now !== null) $w20_spd_now = intval(round(unitToKnots($w20_spd_now, $w20Uom),0));
    if ($tr_dir_now  !== null) $tr_dir_now  = intval(round($tr_dir_now,0));
    if ($tr_spd_now  !== null) $tr_spd_now  = intval(round(unitToKnots($tr_spd_now, $trUom),0));

    // +2h (NWS)
    $w20_dir_2h = gridValueAtTime($w20DirVals, $t2h);
    $w20_spd_2h = gridValueAtTime($w20SpdVals, $t2h);
    $tr_dir_2h  = gridValueAtTime($trDirVals,  $t2h);
    $tr_spd_2h  = gridValueAtTime($trSpdVals,  $t2h);

    if ($w20_dir_2h !== null) $w20_dir_2h = intval(round($w20_dir_2h,0));
    if ($w20_spd_2h !== null) $w20_spd_2h = intval(round(unitToKnots($w20_spd_2h, $w20Uom),0));
    if ($tr_dir_2h  !== null) $tr_dir_2h  = intval(round($tr_dir_2h,0));
    if ($tr_spd_2h  !== null) $tr_spd_2h  = intval(round(unitToKnots($tr_spd_2h, $trUom),0));

    // Forecast surface wind (+2h)
    $wsUom = isset($p['windSpeed']['uom']) ? $p['windSpeed']['uom'] : '';
    $wgUom = isset($p['windGust']['uom']) ? $p['windGust']['uom'] : '';

    $fc['wd'] = gridValueAtTime($wdVals, $t2h);
    $fc['ws'] = gridValueAtTime($wsVals, $t2h);
    $fc['wg'] = gridValueAtTime($wgVals, $t2h);
    $fc['t']  = gridValueAtTime($tVals,  $t2h);
    $fc['td'] = gridValueAtTime($tdVals, $t2h);
    $fc['rh'] = gridValueAtTime($rhVals, $t2h);
    $fc['slpHpa'] = gridValueAtTime($prVals, $t2h);

    if ($fc['wd'] !== null) $fc['wd'] = intval(round($fc['wd'],0));
    if ($fc['ws'] !== null) $fc['ws'] = intval(round(unitToKnots($fc['ws'], $wsUom),0));
    if ($fc['wg'] !== null) $fc['wg'] = intval(round(unitToKnots($fc['wg'], $wgUom),0));
    if ($fc['t']  !== null) $fc['t']  = intval(round($fc['t'],0));
    if ($fc['td'] !== null) $fc['td'] = intval(round($fc['td'],0));
    if ($fc['rh'] !== null) $fc['rh'] = intval(round($fc['rh'],0));

    // DA always filled: if NWS pressure missing, use Tempest SLP
    if ($fc['slpHpa'] === null && $slpHpa !== null) $fc['slpHpa'] = $slpHpa;

    if ($fc['slpHpa'] !== null) {
      $fc['altInHg'] = round(hpaToInHg($fc['slpHpa']) + $GLOBALS['ALT_CAL_INHG'], 2);
    }
    if ($fc['altInHg'] !== null && $fc['t'] !== null) {
      $fc['da'] = densityAltitudeFt($GLOBALS['fieldElevationFt'], $fc['altInHg'], $fc['t']);
    }

    // ACTUAL worst shear (Layer A/B)
    $actPick = buildWorstShear($windDir, $windKt, $w20_dir_now, $w20_spd_now, $tr_dir_now, $tr_spd_now, $actInvFt);
    $actShearMag = $actPick['mag'];
    $actShearDir = $actPick['dd'];
    $actShearAlt = $actPick['alt'];
    $actShearLvl = $actPick['sev'];

    // FORECAST worst shear (Layer A/B)
    $fcPick = buildWorstShear($fc['wd'], $fc['ws'], $w20_dir_2h, $w20_spd_2h, $tr_dir_2h, $tr_spd_2h, $fcInvFt);
    $fcShearMag = $fcPick['mag'];
    $fcShearDir = $fcPick['dd'];
    $fcShearAlt = $fcPick['alt'];
    $fcShearLvl = $fcPick['sev'];

    // Thermal Activity (estimate, ft/min)
    $histFile = '/tmp/pm_thermal_hist.json';
    $hist = pmHistLoad($histFile);

    $nowTs = time();
    pmHistPrune($hist, $nowTs - 3600);

    $hist[] = array(
      't'     => $nowTs,
      'temp'  => ($airTempC !== null ? floatval($airTempC) : null),
      'solar' => ($solarWm2 !== null ? floatval($solarWm2) : null),
      'mhft'  => ($actInvFt !== null ? floatval($actInvFt) : null)
    );

    pmHistSave($histFile, $hist);

    $temp15 = pmHistNearestBefore($hist, $nowTs - 900, 'temp');
    $tempNow = ($airTempC !== null ? floatval($airTempC) : null);
    $tempTrendCph = null;
    if ($tempNow !== null && $temp15 !== null) {
      $tempTrendCph = ($tempNow - floatval($temp15)) / 0.25;
    }

    $mh30 = pmHistNearestBefore($hist, $nowTs - 1800, 'mhft');
    $mhNowFt = ($actInvFt !== null ? floatval($actInvFt) : null);
    $mhTrendFpm = null;
    if ($mhNowFt !== null && $mh30 !== null) {
      $mhTrendFpm = ($mhNowFt - floatval($mh30)) / 30.0;
    }

    $solNorm = pmSolarNorm($solarWm2);

    if ($mhTrendFpm !== null || $solNorm !== null || $tempTrendCph !== null) {
      $base = 0.0;
      if ($mhTrendFpm !== null) $base += max(0.0, floatval($mhTrendFpm)) * 1.2;
      if ($solNorm !== null)    $base += 350.0 * floatval($solNorm);
      if ($tempTrendCph !== null) $base += 60.0 * max(0.0, floatval($tempTrendCph));
      if ($base < 0) $base = 0;
      if ($base > 1500) $base = 1500;
      $actThermFpm = intval(round($base, 0));
    }

    if ($fcInvFt !== null && $actInvFt !== null) {
      $deltaFt = floatval($fcInvFt) - floatval($actInvFt);
      $trend2h = max(0.0, $deltaFt) / 120.0;

      $solarProxy = ($solNorm !== null) ? floatval($solNorm) : 0.0;
      $baseF = $trend2h * 1.2 + 250.0 * $solarProxy;
      if ($baseF < 0) $baseF = 0;
      if ($baseF > 1500) $baseF = 1500;

      $fcThermFpm = intval(round($baseF, 0));
    }
  }

  // Gust factor
  $gustFactor = max(0, $gustKt - $windKt);

  // Risk Scoring (ACTUAL + FORECAST)
  $actRisk = pmScorePPG(
    $windKt,
    $gustKt,
    $gustFactor,
    ($actShearMag !== null ? intval(round($actShearMag,0)) : null),
    $actShearLvl,
    $actThermFpm,
    $daActual,
    $actInvFt,
    ($airTempC!==null ? intval(round($airTempC,0)) : null),
    ($dewC!==null ? intval(round($dewC,0)) : null)
  );

  $fcGustFactor = null;
  if ($fc['wg'] !== null && $fc['ws'] !== null) $fcGustFactor = max(0, intval($fc['wg']) - intval($fc['ws']));

  $fcRisk = pmScorePPG(
    $fc['ws'],
    $fc['wg'],
    $fcGustFactor,
    ($fcShearMag !== null ? intval(round($fcShearMag,0)) : null),
    $fcShearLvl,
    $fcThermFpm,
    $fc['da'],
    $fcInvFt,
    $fc['t'],
    $fc['td']
  );

  // overall “risk” band follows ACTUAL
  $risk = $actRisk['band'];

 // ---------------------- NEW SPEECH BLOCK ----------------------

// Local time (California)
$localTimeStr = date('H:i', $timestamp);

// Format helpers
function speakDir($d){
  if ($d === null) return "unknown";
  return speakDigits(padInt($d,3));
}
function speakKt($k){
  if ($k === null) return "unknown";
  return speakDigits(padInt($k,2));
}
function speakFt($f){
  if ($f === null) return "unknown";
  return speakDigits(padInt($f,4));
}
function speakFpm($f){
  if ($f === null) return "unknown";
  return speakDigits($f);
}

// ACTUAL shear text
$actShearText = "unknown";
if ($actShearLvl !== null) {
  $actShearText =
    strtolower($actShearLvl) .
    " with a variation of " .
    speakKt($actShearMag !== null ? intval(round($actShearMag,0)) : null) .
    " knots and directional change of " .
    speakDir($actShearDir) .
    " degrees";
}

// Forecast gust factor
$fcGust = ($fc['wg'] !== null ? intval($fc['wg']) : null);
$fcWind = ($fc['ws'] !== null ? intval($fc['ws']) : null);

	
	
	
// Final speech (single continuous block)
$speechText =
  "SoCal Pilot Center Para Motor Weather for the Thermal Airport. " .
  $localTimeStr . " Local time. " .

  "Wind " . speakDir($windDir) .
  " degrees, " . speakKt($windKt) .
  " knots, gusting up to " . speakKt($gustKt) . " knots. " .

  "Temperature inversion at " . speakFt($actInvFt) . " feet. " .
  "Thermal activity " . speakFpm($actThermFpm) . " feet per minute. " .

  "Wind shear from the surface to one thousand feet. " .
  ucfirst($actShearText) . ". " .

  "Expected in the next two hours. " .
  "Wind " . speakDir($fc['wd']) .
  " degrees, " . speakKt($fcWind) .
  " knots, gusting up to " . speakKt($fcGust) . " knots.";

// Assign to both parts (your JS expects two variables)
$speech1 = $speechText;
$speech2 = "";

  // Yellow arrow source (+2h)
  $fcst = nbmForecast2h($nbmGridUrl);
  $fcstDir = (isset($fcst['ok']) && $fcst['ok'] && $fcst['wd2']!==null) ? intval($fcst['wd2']) : null;
  $fcstKt  = (isset($fcst['ok']) && $fcst['ok'] && $fcst['ws2']!==null) ? intval($fcst['ws2']) : null;

  if ($fcstDir !== null && $fcstKt !== null) {
    $speech2 .= "Forecast, wind " . speakDigits(padInt($fcstDir,3)) . " degrees, " . speakDigits(padInt($fcstKt,2)) . " knots. ";
  }
  $speech2 .= "Risk " . strtolower($risk) . ".";

  return array(
    'ok'=>true,

    // existing
    'recorded_at'=>date('H:i',$timestamp).' PT',
    'wind_dir_deg'=>$windDir,
    'wind_speed_kt'=>$windKt,
    'wind_gust_kt'=>$gustKt,
    'gust_factor'=>$gustFactor,
    'risk'=>$risk,
    'forecast_wind_dir_deg'=>$fcstDir,
    'forecast_wind_speed_kt'=>$fcstKt,
    'speech_part1'=>$speech1,
    'speech_part2'=>$speech2,

    // Actual
    'act_temp_c'   => ($airTempC!==null ? intval(round($airTempC,0)) : null),
    'act_dew_c'    => ($dewC!==null ? intval(round($dewC,0)) : null),
    'act_rh'       => $rh,
    'act_slp_hpa'  => ($slpHpa!==null ? intval(round($slpHpa,0)) : null),
    'act_alt_inhg' => ($altInHg!==null ? $altInHg : null),
    'act_da_ft'    => $daActual,

    // Forecast +2h detailed
    'fc_wd'        => $fc['wd'],
    'fc_ws'        => $fc['ws'],
    'fc_wg'        => $fc['wg'],
    'fc_t'         => $fc['t'],
    'fc_td'        => $fc['td'],
    'fc_rh'        => $fc['rh'],
    'fc_slp_hpa'   => ($fc['slpHpa']!==null ? intval(round($fc['slpHpa'],0)) : null),
    'fc_alt_inhg'  => ($fc['altInHg']!==null ? $fc['altInHg'] : null),
    'fc_da_ft'     => $fc['da'],

    // Inversion
    'act_inv_ft_agl' => $actInvFt,
    'fc_inv_ft_agl'  => $fcInvFt,

    // Thermal
    'act_therm_fpm' => $actThermFpm,
    'fc_therm_fpm'  => $fcThermFpm,

    // Wind shear (worst below 1000 ft AGL, Layer A/B)
    'act_shear_kt'      => ($actShearMag !== null ? intval(round($actShearMag,0)) : null),
    'act_shear_dir_deg' => ($actShearDir !== null ? intval(round($actShearDir,0)) : null),
    'act_shear_level'   => $actShearLvl,
    'act_shear_alt_ft'  => $actShearAlt,

    'fc_shear_kt'       => ($fcShearMag !== null ? intval(round($fcShearMag,0)) : null),
    'fc_shear_dir_deg'  => ($fcShearDir !== null ? intval(round($fcShearDir,0)) : null),
    'fc_shear_level'    => $fcShearLvl,
    'fc_shear_alt_ft'   => $fcShearAlt,

    // Risk scoring
    'act_score'   => $actRisk['score'],
    'act_band'    => $actRisk['band'],
    'act_reasons' => $actRisk['reasons'],

    'fc_score'    => $fcRisk['score'],
    'fc_band'     => $fcRisk['band'],
    'fc_reasons'  => $fcRisk['reasons']
  );
}

// API endpoint
if (isset($_GET['api']) && $_GET['api']=='1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(buildBundle($GLOBALS['tempestUrl'], $GLOBALS['nbmGridUrl']));
  exit;
}

// Initial render
$bundle = buildBundle($tempestUrl, $nbmGridUrl);

$initWindDir = (isset($bundle['ok']) && $bundle['ok'] && isset($bundle['wind_dir_deg'])) ? intval($bundle['wind_dir_deg']) : 0;
$initWindKt  = (isset($bundle['ok']) && $bundle['ok'] && isset($bundle['wind_speed_kt'])) ? intval($bundle['wind_speed_kt']) : 0;
$initFcstDir = (isset($bundle['ok']) && $bundle['ok'] && isset($bundle['forecast_wind_dir_deg']) && $bundle['forecast_wind_dir_deg']!==null) ? intval($bundle['forecast_wind_dir_deg']) : 'null';

$speech_part1 = (isset($bundle['ok']) && $bundle['ok'] && isset($bundle['speech_part1'])) ? $bundle['speech_part1'] : "IPCA Paramotor Weather. Data unavailable.";
$speech_part2 = (isset($bundle['ok']) && $bundle['ok'] && isset($bundle['speech_part2'])) ? $bundle['speech_part2'] : "Please try again.";
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
    .pm_actual_box{
      max-width:520px;margin:14px auto 0 auto;padding:12px 14px;border-radius:14px;color:#fff;
      background: rgba(24,160,88,0.92);box-shadow:0 10px 24px rgba(0,0,0,0.25);
    }
    .pm_forecast_box{
      max-width:520px;margin:14px auto 0 auto;padding:12px 10px 10px 10px;border-radius:14px;color:#fff;
      box-shadow:0 10px 24px rgba(0,0,0,0.25);background: linear-gradient(90deg,#1e3c72,#2a5298);
    }
    .pm_risk_line img{ height:18px; vertical-align:middle; margin-right:6px; }
    .pm_risk_line{ margin-top:8px; font-weight:900; }
  </style>

  <script type="text/javascript">
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

    function riskIcon(band){
      if (band === "RED") return "risk_red.png";
      if (band === "ORANGE") return "risk_orange.png";
      return "risk_green.png";
    }

    function updateFromApi(){
      var base = window.location.href.split('#')[0].split('?')[0];
      var url = base + "?api=1&_=" + new Date().getTime();

      httpGetJson(url, function(err, data){
        if(err || !data || !data.ok) return;

        speechPart1 = data.speech_part1;
        speechPart2 = data.speech_part2;

        function p2(n){ n=parseInt(n,10); if(isNaN(n)) return "--"; return (n<10?"0":"")+n; }
        function p3(n){ n=parseInt(n,10); if(isNaN(n)) return "---"; return ("000"+n).slice(-3); }
        function p1(n){ n=parseInt(n,10); if(isNaN(n)) return "—"; return ""+n; }

        var el;

        el = document.getElementById("act_time");
        if(el) el.innerHTML = "Time: " + (data.recorded_at || "--:-- PT");

        el = document.getElementById("act_wind");
        if(el) el.innerHTML = "Wind: " + p3(data.wind_dir_deg) + "/" + p2(data.wind_speed_kt) + "KT G" + p2(data.wind_gust_kt) + "KT";

        el = document.getElementById("act_tdrh");
        if(el) el.innerHTML = "Temp/Dp: " + p2(data.act_temp_c) + "/" + p2(data.act_dew_c) + " (RH " + (data.act_rh!==null?data.act_rh:"--") + "%)";

        el = document.getElementById("act_press");
        if(el) el.innerHTML = "Pressure: " + (data.act_alt_inhg!==null ? Number(data.act_alt_inhg).toFixed(2) : "--.--") + " inHg / " + (data.act_slp_hpa!==null?data.act_slp_hpa:"---") + " hPa";

        el = document.getElementById("act_da");
        if(el) el.innerHTML = "Density Alt: " + (data.act_da_ft!==null?data.act_da_ft:"----") + " ft";

        el = document.getElementById("act_inv");
        if(el) el.innerHTML = "Temp Inversion: " + (data.act_inv_ft_agl!==null ? data.act_inv_ft_agl : "----") + " ft AGL";

        el = document.getElementById("act_therm");
        if(el) el.innerHTML = "Thermal: " + (data.act_therm_fpm!==null ? data.act_therm_fpm : "----") + " ft/min";

        el = document.getElementById("act_shear");
        if(el){
          var lvl = (data.act_shear_level ? data.act_shear_level : "—");
          var skt = (data.act_shear_kt!==null ? p1(data.act_shear_kt) : "—");
          var sdeg = (data.act_shear_dir_deg!==null ? p1(data.act_shear_dir_deg) : "—");
          var top = (data.act_shear_alt_ft!==null ? p1(data.act_shear_alt_ft) : "1000");
          el.innerHTML = "WSH (SFC-" + top + "FT): " + lvl + " (ΔV " + skt + "KT, ΔDir " + sdeg + "°)";
        }

        el = document.getElementById("act_risk");
        if(el){
          var band = (data.act_band ? data.act_band : "—");
          var score = (data.act_score!==undefined && data.act_score!==null) ? data.act_score : "—";
          var reasons = (data.act_reasons && data.act_reasons.length) ? data.act_reasons.join(", ") : "—";
          el.innerHTML = '<img src="'+riskIcon(band)+'">Risk: ' + band + " (" + score + "/100) — " + reasons;
        }

        // Forecast block
        el = document.getElementById("fc_wind");
        if(el) el.innerHTML = "Wind: " + p3(data.fc_wd) + "/" + p2(data.fc_ws) + "KT G" + p2(data.fc_wg) + "KT";

        el = document.getElementById("fc_tdrh");
        if(el) el.innerHTML = "Temp/Dp: " + p2(data.fc_t) + "/" + p2(data.fc_td) + " (RH " + (data.fc_rh!==null?data.fc_rh:"--") + "%)";

        el = document.getElementById("fc_press");
        if(el) el.innerHTML = "Pressure: " + (data.fc_alt_inhg!==null ? Number(data.fc_alt_inhg).toFixed(2) : "--.--") + " inHg / " + (data.fc_slp_hpa!==null?data.fc_slp_hpa:"---") + " hPa";

        el = document.getElementById("fc_da");
        if(el) el.innerHTML = "Density Alt: " + (data.fc_da_ft!==null?data.fc_da_ft:"----") + " ft";

        el = document.getElementById("fc_inv");
        if(el) el.innerHTML = "Temp Inversion: " + (data.fc_inv_ft_agl!==null ? data.fc_inv_ft_agl : "----") + " ft AGL";

        el = document.getElementById("fc_therm");
        if(el) el.innerHTML = "Thermal: " + (data.fc_therm_fpm!==null ? data.fc_therm_fpm : "----") + " ft/min";

        el = document.getElementById("fc_shear");
        if(el){
          var lvl2 = (data.fc_shear_level ? data.fc_shear_level : "—");
          var skt2 = (data.fc_shear_kt!==null ? p1(data.fc_shear_kt) : "—");
          var sdeg2 = (data.fc_shear_dir_deg!==null ? p1(data.fc_shear_dir_deg) : "—");
          var top2 = (data.fc_shear_alt_ft!==null ? p1(data.fc_shear_alt_ft) : "1000");
          el.innerHTML = "WSH (SFC-" + top2 + "FT): " + lvl2 + " (ΔV " + skt2 + "KT, ΔDir " + sdeg2 + "°)";
        }

        el = document.getElementById("fc_risk");
        if(el){
          var band2 = (data.fc_band ? data.fc_band : "—");
          var score2 = (data.fc_score!==undefined && data.fc_score!==null) ? data.fc_score : "—";
          var reasons2 = (data.fc_reasons && data.fc_reasons.length) ? data.fc_reasons.join(", ") : "—";
          el.innerHTML = '<img src="'+riskIcon(band2)+'">Risk: ' + band2 + " (" + score2 + "/100) — " + reasons2;
        }

        // update widget (yellow arrow)
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
      var pill = document.getElementById("spc_audio_pill");
      if (pill) pill.addEventListener("click", function(e){ e.preventDefault(); toggleSpeech(); });

      if(window.updateRunwayUI){
        window.updateRunwayUI(<?php echo intval($initWindDir); ?>, <?php echo intval($initWindKt); ?>, <?php echo $initFcstDir; ?>);
      }

      applyHeaderGradientToForecastBox();

      setTimeout(updateFromApi, 800);
      setInterval(updateFromApi, 15000);
    });
  </script>
</head>

<body class="page_body">

  <!-- Header with Logo -->
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

        <!-- Widget -->
        <?php include __DIR__ . '/asos_compass_widget.php'; ?>

        <!-- Actual + Forecast 2h (green) -->
        <div class="pm_actual_box">
          <div style="font-weight:900;font-size:16px;margin-bottom:6px;">ACTUAL WX</div>
          <div id="act_time" style="font-weight:900;">Time: --:-- PT</div>

          <div id="act_wind" style="margin-top:8px;font-weight:900;">Wind: ---/--KT G--KT</div>
          <div id="act_tdrh" style="margin-top:6px;font-weight:900;">Temp/Dp: --/-- (RH --%)</div>
          <div id="act_press" style="margin-top:6px;font-weight:900;">Press: --.-- inHg / --- hPa</div>
          <div id="act_da" style="margin-top:6px;font-weight:900;">Density Alt: ---- ft</div>

          <div id="act_inv" style="margin-top:6px;font-weight:900;">Temp Inversion: ---- ft AGL</div>
          <div id="act_therm" style="margin-top:6px;font-weight:900;">Thermal: ---- ft/min</div>
          <div id="act_shear" style="margin-top:6px;font-weight:900;">WSH (SFC-1000FT): ----</div>

          <div id="act_risk" class="pm_risk_line">Risk: —</div>

          <hr style="margin:12px 0;border:0;border-top:1px solid rgba(255,255,255,0.25);">

          <div style="font-weight:900;font-size:16px;margin-bottom:6px;">FORECAST WX (2h)</div>

          <div id="fc_wind" style="margin-top:6px;font-weight:900;">Wind: ---/--KT G--KT</div>
          <div id="fc_tdrh" style="margin-top:6px;font-weight:900;">Temp/Dp: --/-- (RH --%)</div>
          <div id="fc_press" style="margin-top:6px;font-weight:900;">Pressure: --.-- inHg / --- hPa</div>
          <div id="fc_da" style="margin-top:6px;font-weight:900;">Density Alt: ---- ft</div>

          <div id="fc_inv" style="margin-top:6px;font-weight:900;">Temp Inversion: ---- ft AGL</div>
          <div id="fc_therm" style="margin-top:6px;font-weight:900;">Thermal: ---- ft/min</div>
          <div id="fc_shear" style="margin-top:6px;font-weight:900;">WSH (SFC-1000FT): ----</div>

          <div id="fc_risk" class="pm_risk_line">Risk: —</div>
        </div>

        <!-- 24h Forecast row -->
        <div class="pm_forecast_box" id="pm_forecast_box">
          <div style="font-weight:900;margin-bottom:6px;">24h Forecast (2h steps)</div>
          <div id="pm_fcst24_row"></div>
        </div>

        <div class="question_text questions_nr" style="max-width:520px;margin:14px auto 0 auto;">
          <strong>NOTE:</strong> FOR INFORMATION ONLY. PARAMOTOR OPERATIONS REQUIRE PILOT JUDGMENTS.
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