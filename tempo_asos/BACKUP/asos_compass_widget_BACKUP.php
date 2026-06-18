<?php
/**
 * Paramotor Launch Satellite Widget (Latest working version + 24h forecast row)
 * Uses:
 * - launch_satellite.png
 * - Paramotor_Top_View.png
 * - wind_arrow.png (for mini forecast icons)
 */
?>
<style>
  .pm_wrap{max-width:520px;margin:0 auto 14px auto;}

  .pm_card{
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 10px 24px rgba(0,0,0,0.25);
    position:relative;
    background:#0b1e3a;
  }

  .pm_viewport{
    position:relative;
    width:100%;
    height:360px;
    overflow:hidden;
    background:#0b1e3a;
  }

  .pm_rot{
    position:absolute;
    inset:0;
    transform-origin:50% 50%;
    will-change:transform;
  }

  .pm_sat{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    object-fit:cover;
    object-position:center;
    display:block;
    pointer-events:none;
    transform: scale(1.3);
    transform-origin: 50% 50%;
    will-change: transform;
  }

  .pm_overlay_map{
    position:absolute;
    inset:0;
    pointer-events:none;
  }

  .pm_fixed_center{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    pointer-events:none;
  }

  .pm_pm_icon{
    width:120px;
    height:120px;
    object-fit:contain;
    display:block;
    filter: drop-shadow(0 8px 18px rgba(0,0,0,0.35));
    opacity:0.98;
  }

  .pm_controls_abs{
    position: relative;
    width: 100%;
    text-align: center;
    margin-top: 14px;
  }

  .pm_btn{
    display: inline-block;
    padding: 12px 22px;
    border-radius: 999px;
    font-size: 15px;
    font-weight: 900;
    background: rgba(90,170,255,0.9);
    color: #fff;
    border: 0;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(0,0,0,0.25);
  }

  /* Forecast row */
  #pm_fcst24_row{
    max-width:520px;
    margin:12px auto 0 auto;
    overflow-x:auto;
    white-space:nowrap;
    padding:10px 8px 6px 8px;
    background:#0b1e3a;
    border-radius:12px;
  }
  .pm_fcst_item{
    display:inline-block;
    width:68px;
    margin-right:8px;
    text-align:center;
    color:#fff;
  }
  .pm_fcst_svg{
    width:68px;
    height:68px;
    display:block;
    margin:0 auto;
  }
  .pm_fcst_txt{
    font-size:12px;
    font-weight:900;
    line-height:1.1;
    margin-top:4px;
  }
  .pm_fcst_temp{
    font-size:12px;
    font-weight:900;
    opacity:0.9;
    margin-top:2px;
  }
  .pm_fcst_time{
    font-size:11px;
    font-weight:700;
    opacity:0.75;
    margin-top:2px;
  }
</style>

<div class="pm_wrap">
  <div class="pm_card">
    <div class="pm_viewport">
      <div id="pm_rot" class="pm_rot">
        <img class="pm_sat" src="launch_satellite.png" alt="Launch satellite">
        <svg id="pm_overlay_svg" class="pm_overlay_map" viewBox="0 0 320 360" preserveAspectRatio="xMidYMid meet"></svg>
      </div>

      <div class="pm_fixed_center">
        <img class="pm_pm_icon" src="Paramotor_Top_View.png" alt="Paramotor">
      </div>
    </div>
  </div>

  <div class="pm_controls_abs">
    <button id="pm_enable_compass" class="pm_btn" type="button">Enable Compass</button>
  </div>
</div>

<script type="text/javascript">
(function(){
  var windFrom = 0;
  var forecastFrom = null;
  var headingDeg = null;

  var animRaf = null;
  var animStart = null;
  var animBaseAngle = 0;

  var PM_CX = 160;
  var PM_CY = 180;

  // 10-minute average wind buffer
  var windHistory = [];
  var WIND_WINDOW_MS = 10 * 60 * 1000;

  function degToRad(d){ return d * Math.PI / 180; }
  function norm360(d){ d = d % 360; if (d < 0) d += 360; return d; }

  function angDiff(a,b){
    var d = Math.abs(a-b) % 360;
    return (d > 180) ? (360 - d) : d;
  }

  function circularMean(degList){
    if (!degList.length) return null;
    var s=0, c=0;
    for (var i=0;i<degList.length;i++){
      var r = degToRad(degList[i]);
      s += Math.sin(r);
      c += Math.cos(r);
    }
    if (s===0 && c===0) return degList[degList.length-1];
    var m = Math.atan2(s, c) * 180/Math.PI;
    return norm360(m);
  }

  function arrowGeometry(cx, R){
    return {
      outerX: cx + R - 6,
      innerX: cx + Math.round(R * 0.4)
    };
  }

  function applyRotation(){
    var rot = document.getElementById("pm_rot");
    var btn = document.getElementById("pm_enable_compass");
    if (!rot) return;

    if (headingDeg === null || isNaN(headingDeg)){
      rot.style.transform = "rotate(0deg)";
      return;
    }

    rot.style.transform = "rotate(" + (-headingDeg) + "deg)";

    var hdgRounded = Math.round(headingDeg);
    if (btn){
      var hdg3 = ("000" + hdgRounded).slice(-3);
      btn.innerHTML = "HDG " + hdg3;
    }
  }

  function enableCompass(){
    if (typeof DeviceOrientationEvent !== "undefined" &&
        typeof DeviceOrientationEvent.requestPermission === "function") {
      DeviceOrientationEvent.requestPermission().then(function(state){
        if (state === "granted") startCompassListener();
      }).catch(function(){});
    } else {
      startCompassListener();
    }
  }

  function startCompassListener(){
    window.addEventListener("deviceorientation", function(e){
      if (typeof e.webkitCompassHeading !== "undefined" && e.webkitCompassHeading !== null) {
        headingDeg = e.webkitCompassHeading;
      } else if (e.alpha !== null) {
        headingDeg = 360 - e.alpha;
      }
      headingDeg = (headingDeg === null) ? null : norm360(headingDeg);
      applyRotation();
    }, true);
  }

  function stopWobble(){
    if (animRaf) cancelAnimationFrame(animRaf);
    animRaf = null;
    animStart = null;
  }

  function startWobble(){
    stopWobble();
    animStart = null;

    function step(ts){
      if (!animStart) animStart = ts;
      var t = (ts - animStart) / 1000.0;
      var wobble = Math.sin(t * 1.25) * 3.0;

      var g = document.getElementById("pm_now_arrow_group");
      if (g) {
        g.setAttribute("transform", "rotate("+(animBaseAngle + wobble)+" "+PM_CX+" "+PM_CY+")");
      }
      animRaf = requestAnimationFrame(step);
    }
    animRaf = requestAnimationFrame(step);
  }

  function renderOverlay(){
    var svg = document.getElementById("pm_overlay_svg");
    if (!svg) return;

    var cx = 160;
    var cy = 180;

    var CY_OFFSET = -30;
    cy = cy + CY_OFFSET;

    var R  = 125;

    PM_CX = cx;
    PM_CY = cy;

    var circleStroke = 2.4;
    var tickSmall = 0.8;
    var tickBig = 1.4;

    var ticks = "";
    for (var d=0; d<360; d+=10){
      var big = (d % 30 === 0);
      var len = big ? 12 : 7;
      var a = degToRad(d - 90);
      var x1 = cx + (R - len) * Math.cos(a);
      var y1 = cy + (R - len) * Math.sin(a);
      var x2 = cx + R * Math.cos(a);
      var y2 = cy + R * Math.sin(a);
      ticks += '<line x1="'+x1+'" y1="'+y1+'" x2="'+x2+'" y2="'+y2+'" stroke="#ffffff" stroke-width="'+(big?tickBig:tickSmall)+'" stroke-linecap="round"/>';
    }

    var nowAngle = (windFrom - 90);
    animBaseAngle = nowAngle;

    var fcstAngle = null;
    if (forecastFrom !== null && !isNaN(forecastFrom)) {
      fcstAngle = (forecastFrom - 90);
    }

    var g = arrowGeometry(cx, R);

    // 10-minute average range ticks
    var avgTicks = "";
    if (windHistory.length >= 3) {
      var dirs = [];
      for (var i=0;i<windHistory.length;i++) dirs.push(windHistory[i].dir);

      var meanDir = circularMean(dirs);
      if (meanDir !== null) {
        var maxDev = 0;
        for (var j=0;j<dirs.length;j++){
          var dev = angDiff(dirs[j], meanDir);
          if (dev > maxDev) maxDev = dev;
        }

        var a1 = norm360(meanDir - maxDev);
        var a2 = norm360(meanDir + maxDev);

        var rot1 = (a1 - 90);
        var rot2 = (a2 - 90);

        var tickLen2 = 16;
        var tickW2 = 4;

        var xOuter = cx + R - 2;
        var xInner = xOuter - tickLen2;

        avgTicks =
          '<g opacity="0.95">' +
            '<g transform="rotate('+rot1+' '+cx+' '+cy+')">' +
              '<line x1="'+xOuter+'" y1="'+cy+'" x2="'+xInner+'" y2="'+cy+'" stroke="#5AAAFF" stroke-width="'+tickW2+'" stroke-linecap="round"/>' +
            '</g>' +
            '<g transform="rotate('+rot2+' '+cx+' '+cy+')">' +
              '<line x1="'+xOuter+'" y1="'+cy+'" x2="'+xInner+'" y2="'+cy+'" stroke="#5AAAFF" stroke-width="'+tickW2+'" stroke-linecap="round"/>' +
            '</g>' +
          '</g>';
      }
    }

    var fcstArrow = "";
    if (fcstAngle !== null && !isNaN(fcstAngle)) {
      fcstArrow =
        '<g transform="rotate('+fcstAngle+' '+cx+' '+cy+')" opacity="0.70">' +
          '<line x1="'+g.outerX+'" y1="'+cy+'" x2="'+g.innerX+'" y2="'+cy+'" stroke="#FFD400" stroke-width="5" stroke-linecap="round"/>' +
          '<polygon points="'+g.innerX+','+cy+' '+(g.innerX+14)+','+(cy-8)+' '+(g.innerX+11)+','+cy+' '+(g.innerX+14)+','+(cy+8)+'" fill="#FFD400"/>' +
        '</g>';
    }

    var nowArrow =
      '<g id="pm_now_arrow_group" transform="rotate('+nowAngle+' '+cx+' '+cy+')">' +
        '<line x1="'+g.outerX+'" y1="'+cy+'" x2="'+g.innerX+'" y2="'+cy+'" stroke="#5AAAFF" stroke-width="5" stroke-linecap="round"/>' +
        '<polygon points="'+g.innerX+','+cy+' '+(g.innerX+14)+','+(cy-8)+' '+(g.innerX+11)+','+cy+' '+(g.innerX+14)+','+(cy+8)+'" fill="#5AAAFF"/>' +
      '</g>';

    svg.innerHTML =
      '<rect x="0" y="0" width="320" height="360" fill="transparent"/>' +
      '<circle cx="'+cx+'" cy="'+cy+'" r="'+R+'" fill="none" stroke="#ffffff" stroke-width="'+circleStroke+'"/>' +
      ticks +
      '<text x="'+cx+'" y="'+(cy-R+26)+'" text-anchor="middle" font-size="16" fill="#ffffff" font-weight="800">N</text>' +
      '<text x="'+(cx+R-20)+'" y="'+(cy+6)+'" text-anchor="middle" font-size="16" fill="#ffffff" font-weight="800">E</text>' +
      '<text x="'+cx+'" y="'+(cy+R-8)+'" text-anchor="middle" font-size="16" fill="#ffffff" font-weight="800">S</text>' +
      '<text x="'+(cx-R+20)+'" y="'+(cy+6)+'" text-anchor="middle" font-size="16" fill="#ffffff" font-weight="800">W</text>' +
      avgTicks +
      fcstArrow +
      nowArrow;

    startWobble();
  }

  // ----- Mini forecast (uses your wind_arrow.png, no circle) -----
  function pmMiniSvg(wd, ws){
    var safeWd = (wd === null || typeof wd === "undefined") ? null : parseInt(wd,10);
    if (isNaN(safeWd)) safeWd = null;

    // if your PNG points north/up, ang = wd. If it points east/right, change to (wd - 90).
    var ang = (safeWd === null) ? 0 : safeWd;

    return ''
      + '<svg class="pm_fcst_svg" viewBox="0 0 100 100">'
      + '  <g transform="rotate('+ang+' 50 50)">'
      + '    <image href="wind_arrow.png" xlink:href="wind_arrow.png" '
      + '           x="32" y="22" width="36" height="56" preserveAspectRatio="xMidYMid meet" />'
      + '  </g>'
      + '</svg>';
  }

  function renderForecast24(points){
    var row = document.getElementById("pm_fcst24_row");
    if (!row) return;

    var html = "";
    for (var i=0;i<points.length;i++){
      var p = points[i];
      var wd = (typeof p.wd !== "undefined") ? p.wd : null;
      var ws = (typeof p.ws !== "undefined") ? p.ws : null;
      var tc = (typeof p.tempC !== "undefined") ? p.tempC : null;
      var t  = (typeof p.t !== "undefined") ? p.t : "";

      var ddd = (wd===null) ? "—" : ("000"+wd).slice(-3);
      var ss  = (ws===null) ? "—" : ("0"+ws).slice(-2);

      html += '<div class="pm_fcst_item">'
            + pmMiniSvg(wd, ws)
            + '<div class="pm_fcst_txt">'+ddd+'/'+ss+'</div>'
            + '<div class="pm_fcst_temp">'+(tc===null?'—':(tc+'°C'))+'</div>'
            + '<div class="pm_fcst_time">'+t+'</div>'
            + '</div>';
    }
    row.innerHTML = html;
  }

  function fetchForecast24(){
    // same page endpoint
    var url = window.location.pathname + "?forecast24=1&_=" + new Date().getTime();
    var xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.onreadystatechange = function(){
      if (xhr.readyState === 4 && xhr.status >= 200 && xhr.status < 300){
        try{
          var j = JSON.parse(xhr.responseText);
          if (j && j.ok && j.points) renderForecast24(j.points);
        }catch(e){}
      }
    };
    xhr.send();
  }

  // KEEP MAIN PAGE INTACT
  window.updateRunwayUI = function(windFromDeg, windKtIn, forecastWindFromDeg){
    windFromDeg = parseInt(windFromDeg,10);
    if (isNaN(windFromDeg)) return;
    windFrom = norm360(windFromDeg);

    // store sample for 10-minute avg
    var nowMs = Date.now();
    windHistory.push({ t: nowMs, dir: windFrom });
    var cutoff = nowMs - WIND_WINDOW_MS;
    while (windHistory.length && windHistory[0].t < cutoff) windHistory.shift();

    if (typeof forecastWindFromDeg !== "undefined" && forecastWindFromDeg !== null) {
      var f = parseInt(forecastWindFromDeg,10);
      forecastFrom = isNaN(f) ? null : norm360(f);
    } else {
      forecastFrom = null;
    }

    renderOverlay();
  };

  var btn = document.getElementById("pm_enable_compass");
  var compassEnabled = false;
  if (btn){
    btn.addEventListener("click", function(e){
      e.preventDefault();
      if (!compassEnabled){
        enableCompass();
        compassEnabled = true;
      }
    });
  }

  // init
  renderOverlay();
  applyRotation();

  // forecast row init + refresh
  fetchForecast24();
  setInterval(fetchForecast24, 10 * 60 * 1000);

})();
</script>