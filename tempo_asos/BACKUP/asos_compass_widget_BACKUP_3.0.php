<?php
/**
 * Paramotor Launch Satellite Widget (Latest working version + 24h forecast row)
 * Uses:
 * - launch_satellite.png
 * - Paramotor_Top_View.png
 * - wind_arrow.png (for mini forecast icons)
 * - risk_green.png / risk_orange.png / risk_red.png (per 2h tile)
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

  /* ============ TILE LAYOUT: ONE spacing rule ============ */
  .pm_fcst_item{
  --pm_gap: 6px;   /* was 2px ? now one clean text-line spacing */
  display:inline-block;
  width:92px;      /* was 68px ? wider tile */
  margin-right:8px;
  text-align:center;
  color:#fff;
  vertical-align:top;
}
  .pm_fcst_item > div{ margin-top: var(--pm_gap); }
  .pm_fcst_item > div:first-child{ margin-top: 0; }

  /* 1) Risk icon at VERY TOP, +20% bigger */
  .pm_fcst_risk{
    display:flex;
    align-items:center;
    justify-content:center;
  }
  .pm_fcst_risk img{
    width:22px;
    height:22px;
    display:block;
  }

  /* 3) Day + Date unchanged */
  .pm_fcst_dow{
    font-size:11px;
    font-weight:900;
    opacity:0.95;
  }
  .pm_fcst_md{
    font-size:11px;
    font-weight:800;
    opacity:0.85;
  }
  .pm_fcst_time{
    font-size:11px;
    font-weight:800;
    opacity:0.85;
  }

  /* 4) Arrow moved UP + reduce spacing below arrow */
  .pm_fcst_svg{
    width:92px;
    height:68px;
    display:block;
    margin:0 auto;
    margin-top:-6px; /* pull up */
  }

  /* 5) Wind line shows 000/00KT */
  .pm_fcst_txt{
    font-size:12px;
    font-weight:900;
    line-height:1.1;
    margin-top:4px; /* tighter under arrow */
  }

  /* 7) Keep meta fonts identical */
  .pm_fcst_meta{
    font-size:10px;
    font-weight:900;
    line-height:1.12;
    opacity:0.96;
    white-space:normal;
  }
  .pm_fcst_meta2{
    font-size:10px;
    font-weight:800;
    line-height:1.12;
    opacity:0.90;
    white-space:normal;
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

  // ---------- Forecast helpers ----------
  function p2(n){
    n = parseInt(n,10);
    if (isNaN(n)) return "--";
    return (n<10 ? "0" : "") + n;
  }
  function p3(n){
    n = parseInt(n,10);
    if (isNaN(n)) return "---";
    return ("000"+n).slice(-3);
  }

  function riskIcon(band){
    if (band === "RED") return "risk_red.png";
    if (band === "ORANGE") return "risk_orange.png";
    return "risk_green.png";
  }

  // ----- Mini wind arrow icon -----
  function pmMiniSvg(wd){
    if (wd === null || typeof wd === "undefined" || isNaN(parseInt(wd,10))) {
      return ''
        + '<svg class="pm_fcst_svg" viewBox="0 0 100 100">'
        + '  <text x="50" y="55" text-anchor="middle" font-size="22" fill="#ffffff">-</text>'
        + '</svg>';
    }

    var safeWd = parseInt(wd,10);
    var ang = (safeWd + 180) % 360;

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
    var lastValidWd = null;
    var lastValidWs = null;

    for (var i=0;i<points.length;i++){
      var p = points[i];

      var dow = (typeof p.dow !== "undefined") ? p.dow : "";
      var md  = (typeof p.md  !== "undefined") ? p.md  : "";
      var t   = (typeof p.t   !== "undefined") ? p.t   : "";

      var wd = (typeof p.wd !== "undefined") ? p.wd : null;
      var ws = (typeof p.ws !== "undefined") ? p.ws : null;

      if (wd === null || isNaN(parseInt(wd,10))) wd = lastValidWd;
      else { wd = parseInt(wd,10); lastValidWd = wd; }

      if (ws === null || isNaN(parseInt(ws,10))) ws = lastValidWs;
      else { ws = parseInt(ws,10); lastValidWs = ws; }

      var tc = (typeof p.tc !== "undefined") ? p.tc : null;
      if (tc !== null && !isNaN(parseInt(tc,10))) tc = parseInt(tc,10); else tc = null;

      var td = (typeof p.td !== "undefined") ? p.td : null;
      if (td !== null && !isNaN(parseInt(td,10))) td = parseInt(td,10); else td = null;

      var rh = (typeof p.rh !== "undefined") ? p.rh : null;
      if (rh !== null && !isNaN(parseInt(rh,10))) rh = parseInt(rh,10); else rh = null;

      var p_inhg = (typeof p.p_inhg !== "undefined") ? p.p_inhg : null;
      if (p_inhg !== null && p_inhg !== "" && !isNaN(parseFloat(p_inhg))) p_inhg = parseFloat(p_inhg);
      else p_inhg = null;

      var da_ft = (typeof p.da_ft !== "undefined") ? p.da_ft : null;
      if (da_ft !== null && !isNaN(parseInt(da_ft,10))) da_ft = parseInt(da_ft,10); else da_ft = null;

      var inv_ft = (typeof p.inv_ft !== "undefined") ? p.inv_ft : null;
      if (inv_ft !== null && !isNaN(parseInt(inv_ft,10))) inv_ft = parseInt(inv_ft,10); else inv_ft = null;

      var th_fpm = (typeof p.th_fpm !== "undefined") ? p.th_fpm : null;
      if (th_fpm !== null && !isNaN(parseInt(th_fpm,10))) th_fpm = parseInt(th_fpm,10); else th_fpm = null;

      var wsh_level = (typeof p.wsh_level !== "undefined") ? p.wsh_level : null;

      var wsh_mag = (typeof p.wsh_mag !== "undefined") ? p.wsh_mag : null;
      if (wsh_mag !== null && !isNaN(parseInt(wsh_mag,10))) wsh_mag = parseInt(wsh_mag,10); else wsh_mag = null;

      var wsh_ddir = (typeof p.wsh_ddir !== "undefined") ? p.wsh_ddir : null;
      if (wsh_ddir !== null && !isNaN(parseInt(wsh_ddir,10))) wsh_ddir = parseInt(wsh_ddir,10); else wsh_ddir = null;

      var wsh_top = (typeof p.wsh_layer_top_ft !== "undefined") ? p.wsh_layer_top_ft : null;
      if (wsh_top !== null && !isNaN(parseInt(wsh_top,10))) wsh_top = parseInt(wsh_top,10); else wsh_top = null;

      var risk_band = (typeof p.risk_band !== "undefined") ? p.risk_band : "GREEN";

      var ddd = (wd===null) ? "---" : ("000"+wd).slice(-3);
      var ss  = (ws===null) ? "--"  : ("0"+ws).slice(-2);

      var windTxt = ddd + "/" + ss + "KT";

      var tdp  = (tc===null ? "--" : p2(tc)) + "/" + (td===null ? "--" : p2(td));
      var rhStr = "RH: " + (rh===null ? "--" : (""+rh)) + "%";
      var pStr  = "P: " + (p_inhg===null ? "--.--" : p_inhg.toFixed(2));
      var daStr = "DA: " + (da_ft===null ? "----" : (""+da_ft)) + " ft";
      var invStr= "INV: " + (inv_ft===null ? "----" : (""+inv_ft)) + " ft";
      var thStr = "TH: " + (th_fpm===null ? "----" : (""+th_fpm)) + " ft/min";

      var wshLine = "WSH: " + (wsh_level ? wsh_level : "--");

      /* ASCII ONLY: no ?, no °, no ? */
      var dvLine = "dV " + (wsh_mag===null ? "--" : wsh_mag) +
             "KT, dDir " + (wsh_ddir===null ? "---" : p3(wsh_ddir));

      html += '<div class="pm_fcst_item">'
            +   '<div class="pm_fcst_risk"><img src="'+riskIcon(risk_band)+'" alt="'+risk_band+'"></div>'
            +   '<div class="pm_fcst_dow">'+dow+'</div>'
            +   '<div class="pm_fcst_md">'+md+'</div>'
            +   '<div class="pm_fcst_time">'+t+' LT</div>'
            +   '<div>'+pmMiniSvg(wd)+'</div>'
            +   '<div class="pm_fcst_txt">'+windTxt+'</div>'
            +   '<div class="pm_fcst_meta">TD: '+tdp+'</div>'
            +   '<div class="pm_fcst_meta2">'+rhStr+'</div>'
            +   '<div class="pm_fcst_meta">'+pStr+'</div>'
            +   '<div class="pm_fcst_meta2">'+daStr+'</div>'
            +   '<div class="pm_fcst_meta2">'+invStr+'</div>'
            +   '<div class="pm_fcst_meta2">'+thStr+'</div>'
            +   '<div class="pm_fcst_meta">'+wshLine+'</div>'
            +   '<div class="pm_fcst_meta2">'+dvLine+'</div>'
            + '</div>';
    }

    row.innerHTML = html;
  }

  function fetchForecast24(){
    var base = window.location.href.split('#')[0].split('?')[0];
    var url = base + "?forecast24=1&_=" + new Date().getTime();

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

  renderOverlay();
  applyRotation();

  fetchForecast24();
  setInterval(fetchForecast24, 10 * 60 * 1000);

})();
</script>