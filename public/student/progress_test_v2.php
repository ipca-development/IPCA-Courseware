<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$cohortId = (int)($_GET['cohort_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
if ($cohortId <= 0 || $lessonId <= 0) exit('Missing cohort_id or lesson_id');

if ($role === 'student') {
    $check = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
    $check->execute([$cohortId, (int)$u['id']]);
    if (!$check->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }
}

$userName = trim((string)($u['name'] ?? 'Student'));
$firstName = trim(explode(' ', $userName)[0] ?? 'Student');

$INSTRUCTOR_NAME = 'Maya';
$INSTRUCTOR_AVATAR = '/assets/avatars/maya.png';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function pt_client_ip(): string {
    $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim((string)$_SERVER[$k]);
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $v);
                $v = trim((string)$parts[0]);
            }
            if ($v !== '') return $v;
        }
    }
    return '';
}

function pt_ip_in_cidr(string $ip, string $cidr): bool {
    if ($ip === '' || $cidr === '') return false;

    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }

    list($subnet, $mask) = explode('/', $cidr, 2);

    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $mask = (int)$mask;

    if ($ipLong === false || $subnetLong === false || $mask < 0 || $mask > 32) {
        return false;
    }

    $maskLong = $mask === 0 ? 0 : (-1 << (32 - $mask));
    return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
}

function pt_ip_matches_cidrs(string $ip, string $cidrs): bool {
    $cidrs = trim($cidrs);
    if ($ip === '' || $cidrs === '') return false;

    $parts = preg_split('/[\s,;]+/', $cidrs);
    if (!is_array($parts)) return false;

    foreach ($parts as $rule) {
        $rule = trim((string)$rule);
        if ($rule !== '' && pt_ip_in_cidr($ip, $rule)) {
            return true;
        }
    }
    return false;
}

function pt_load_access_policy(PDO $pdo, int $userId, int $cohortId): ?array {
    $sql = "
        SELECT *
        FROM progress_test_access_policy
        WHERE
            (scope_type='user'   AND scope_id=:user_id)
            OR
            (scope_type='cohort' AND scope_id=:cohort_id)
            OR
            (scope_type='global' AND scope_id IS NULL)
        ORDER BY
            CASE scope_type
                WHEN 'user' THEN 1
                WHEN 'cohort' THEN 2
                WHEN 'global' THEN 3
                ELSE 9
            END
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array(
        ':user_id' => $userId,
        ':cohort_id' => $cohortId
    ));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pt_session_key(int $cohortId): string {
    return 'progress_test_access_ok_' . $cohortId;
}

$userId = (int)($u['id'] ?? 0);
$clientIp = pt_client_ip();
$policy = pt_load_access_policy($pdo, $userId, $cohortId);
$gateError = '';

if ($policy) {
    $mode = (string)($policy['mode'] ?? 'any');
    $allowedCidrs = (string)($policy['allowed_cidrs'] ?? '');
    $pinHash = (string)($policy['pin_hash'] ?? '');
    $sessionKey = pt_session_key($cohortId);

    $ipAllowed = false;
    if ($allowedCidrs !== '') {
        $ipAllowed = pt_ip_matches_cidrs($clientIp, $allowedCidrs);
    }

    $pinVerified = !empty($_SESSION[$sessionKey]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['progress_test_pin'])) {
        $submittedPin = trim((string)$_POST['progress_test_pin']);
        if ($pinHash !== '' && $submittedPin !== '' && password_verify($submittedPin, $pinHash)) {
            $_SESSION[$sessionKey] = 1;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $gateError = 'Invalid access code.';
        }
    }

    $allowed = true;

    if ($mode === 'any') {
        $allowed = true;
    } elseif ($mode === 'school_ip') {
        $allowed = $ipAllowed || ($pinHash !== '' && $pinVerified);
    } elseif ($mode === 'pin') {
        $allowed = $pinVerified;
    }

    if (!$allowed) {
        cw_header('Progress Test Access');
        ?>
        <style>
          body{ background:#fff; }
          .gate-wrap{ max-width:980px; margin:0 auto; }
          .gate-card{
            max-width:560px;
            margin:38px auto;
            padding:24px;
            border:1px solid #e8e8e8;
            border-radius:18px;
            background:#fff;
            box-shadow:0 10px 30px rgba(0,0,0,0.06);
          }
          .gate-title{
            font-size:24px;
            font-weight:900;
            color:#1e3c72;
            margin-bottom:8px;
          }
          .gate-text{
            color:#334155;
            line-height:1.5;
          }
          .gate-input{
            width:100%;
            margin-top:14px;
            padding:14px 16px;
            border:1px solid #d6d6d6;
            border-radius:12px;
            font-size:16px;
            box-sizing:border-box;
          }
          .gate-btn{
            width:100%;
            margin-top:14px;
            padding:16px 14px;
            border-radius:16px;
            border:2px solid rgba(30,60,114,0.25);
            background:rgba(30,60,114,0.08);
            color:#1e3c72;
            font-weight:900;
            font-size:18px;
            cursor:pointer;
          }
          .gate-error{
            margin-top:12px;
            color:#b91c1c;
            font-weight:800;
          }
        </style>

        <div class="gate-wrap">
          <div class="gate-card">
            <div class="gate-title">Progress Test Access Required</div>

            <?php if ($mode === 'school_ip'): ?>
              <div class="gate-text">
                This progress test normally requires the approved school network.<br>
                Since you are not on the approved IP, enter the access code to continue.
              </div>
            <?php else: ?>
              <div class="gate-text">
                Enter the progress test access code to continue.
              </div>
            <?php endif; ?>

            <?php if ($gateError !== ''): ?>
              <div class="gate-error"><?= h($gateError) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
              <input
                class="gate-input"
                type="password"
                name="progress_test_pin"
                placeholder="Access code"
                autofocus
              >
              <button class="gate-btn" type="submit">Continue</button>
            </form>
          </div>
        </div>
        <?php
        cw_footer();
        exit;
    }
}

cw_header('Progress Test');
?>
<style>
  body{ background:#fff; }
  .wrap{ max-width:980px; margin:0 auto; }

  .hero{
    display:flex;
    gap:16px;
    align-items:center;
    flex-wrap:wrap;
    margin-top:12px;
  }

  .circle-lock{
    width:120px; height:120px;
    min-width:120px; min-height:120px;
    max-width:120px; max-height:120px;
    border-radius:999px;
    clip-path:circle(50% at 50% 50%);
    overflow:hidden;
    transform:translateZ(0);
    flex:0 0 120px;
    line-height:0;
    position:relative;
  }

  .ring-wrap{
    width:120px; height:120px;
    min-width:120px; min-height:120px;
    flex:0 0 120px;
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    transform:translateZ(0);
  }

  .ring-wrap.talking::after{
    content:"";
    position:absolute; inset:-10px;
    border-radius:999px;
    border:4px solid rgba(22,163,74,0.70);
    box-shadow:0 0 24px rgba(22,163,74,0.45);
    animation:pulseG .95s infinite;
    pointer-events:none;
  }

  .ring-wrap.rec::after{
    content:"";
    position:absolute; inset:-10px;
    border-radius:999px;
    border:4px solid rgba(220,38,38,0.78);
    box-shadow:0 0 24px rgba(220,38,38,0.45);
    animation:pulseR .85s infinite;
    pointer-events:none;
  }

  @keyframes pulseG{
    0%{ transform:scale(.98); opacity:.25; }
    50%{ transform:scale(1.06); opacity:.98; }
    100%{ transform:scale(.98); opacity:.25; }
  }
  @keyframes pulseR{
    0%{ transform:scale(.98); opacity:.25; }
    50%{ transform:scale(1.06); opacity:1; }
    100%{ transform:scale(.98); opacity:.25; }
  }

  .avatar-badge{
    background:linear-gradient(135deg,#1e3c72,#2a5298);
    border:4px solid rgba(255,255,255,0.85);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
  }
  .avatar-badge img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }

  .cam{
    background:#000;
    border:4px solid rgba(255,255,255,0.85);
    box-shadow:0 10px 30px rgba(0,0,0,0.12);
  }
  .cam video{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .cam .fallback{
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:900; opacity:.85;
  }
  .cam .label{
    display:none;
  }

  .hero{
    display:flex;
    gap:20px;
    align-items:flex-start;
    flex-wrap:wrap;
    margin-top:12px;
  }

  .person-block{
    width:120px;
    flex:0 0 120px;
    display:flex;
    flex-direction:column;
    align-items:center;
    text-align:center;
  }

  .person-name{
    margin-top:10px;
    font-weight:900;
    color:#1e3c72;
    font-size:18px;
    line-height:1.15;
    text-align:center;
  }

  .person-role{
    margin-top:4px;
    font-size:12px;
    color:#64748b;
    line-height:1.15;
    text-align:center;
  }

  .hero-status{
    min-width:260px;
    flex:1 1 260px;
    padding-top:8px;
  }

  .hero-status .muted{
    margin-top:6px;
  }

  .sysline{
    margin-top:12px;
    padding:10px 12px;
    border:1px solid #eee;
    border-radius:12px;
    background:#fafafa;
    font-weight:800;
    color:#1e3c72;
  }

  .ptt{
    width:100%;
    margin-top:14px;
    padding:16px 14px;
    border-radius:16px;
    border:2px solid rgba(30,60,114,0.25);
    background:rgba(30,60,114,0.08);
    color:#1e3c72;
    font-weight:900;
    font-size:18px;
    cursor:pointer;
    user-select:none;
  }
  .ptt:hover{ background:rgba(30,60,114,0.12); }
  .ptt:disabled{ opacity:.5; cursor:not-allowed; }
  .ptt.rec{
    background:rgba(220,38,38,0.12);
    border-color:rgba(220,38,38,0.35);
    color:#b91c1c;
  }

  .btn-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:14px;
  }
  .btn-half{
    flex:1 1 220px;
    width:auto;
    margin-top:0;
  }

  .timer-wrap{ margin-top:12px; }
  .timer-pill{
    height:14px;
    border-radius:999px;
    background:#eee;
    overflow:hidden;
    border:1px solid #e6e6e6;
  }
  .timer-fill{
    height:14px;
    width:0%;
    background:#1e3c72;
    transition:width .25s linear;
  }
  .timer-fill.danger{ background:#dc2626; }
  .timer-meta{
    display:flex;
    justify-content:space-between;
    font-size:12px;
    opacity:.75;
    margin-top:6px;
  }

  .qstrip{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    margin-top:12px;
  }
  .qdot{
    width:28px;
    height:28px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
    background:rgba(30,60,114,0.10);
    border:2px solid rgba(30,60,114,0.35);
    color:#1e3c72;
  }
  .qdot.done{
    background:rgba(22,163,74,0.14);
    border-color:rgba(22,163,74,0.55);
    color:#166534;
  }
  .qdot.ready{
    background:rgba(37,99,235,0.12);
    border-color:rgba(37,99,235,0.45);
    color:#1d4ed8;
  }

  .result-box{
    margin-top:16px;
    padding:14px;
    border:1px solid #eee;
    border-radius:12px;
    background:#fff;
  }
</style>

<div class="wrap">
  <div class="card">
    <div class="hero">
      <div class="person-block">
        <div class="ring-wrap" id="instructorRing">
          <div class="avatar-badge circle-lock">
            <img src="<?= h($INSTRUCTOR_AVATAR) ?>" alt="Instructor">
          </div>
        </div>
        <div class="person-name"><?= h($INSTRUCTOR_NAME) ?></div>
        <div class="person-role">IPCA Instructor</div>
      </div>

      <div class="person-block">
        <div class="ring-wrap" id="studentRing">
          <div class="cam circle-lock">
            <video id="studentCam" autoplay playsinline muted></video>
            <div class="fallback" id="camFallback">CAM</div>
            <div class="label"><?= h($firstName) ?></div>
          </div>
        </div>
        <div class="person-name"><?= h($userName) ?></div>
        <div class="person-role">Student</div>
      </div>

      <div class="hero-status">
        <div class="muted" id="camStatus">Camera permission requested on load.</div>
      </div>
    </div>

    <div class="sysline" id="sysline"><?= h($firstName) ?>, we are loading your progress test...</div>

    <div class="timer-wrap" style="margin-top:12px;">
      <div class="timer-pill"><div class="timer-fill" id="prepFill"></div></div>
      <div class="timer-meta">
        <div id="prepLabel">Preparation progress</div>
        <div id="prepText">0%</div>
      </div>
    </div>

    <div class="qstrip" id="qstrip" style="display:none;"></div>

    <button class="ptt" id="btnStart" type="button" disabled>Start Progress Test</button>

    <div class="btn-row" id="questionBtns" style="display:none;">
      <button class="ptt btn-half" id="btnReady" type="button" disabled>Ready for First Question</button>
      <button class="ptt btn-half" id="btnReplay" type="button" disabled>&#8634; Replay Question</button>
    </div>

    <div class="btn-row" id="answerBtns" style="display:none;">
      <button class="ptt btn-half" id="btnPTT" type="button" disabled>Tap to Start Talking</button>
      <button class="ptt btn-half" id="btnNext" type="button" disabled>Next Question</button>
    </div>

    <div class="timer-wrap" id="answerWrap" style="display:none;">
      <div class="timer-pill"><div class="timer-fill" id="answerFill"></div></div>
      <div class="timer-meta">
        <div>Time to answer</div>
        <div id="answerText">60s</div>
      </div>
    </div>

    <div class="result-box" id="resultBox" style="display:none;"></div>

    <div class="btn-row" id="doneBtns" style="display:none;">
      <button class="ptt btn-half" id="btnLessonMenu" type="button">Back to Lesson Menu</button>
    </div>
  </div>
</div>

<audio id="audioPlayer" preload="auto"></audio>

<script>
const COHORT_ID = <?= (int)$cohortId ?>;
const LESSON_ID = <?= (int)$lessonId ?>;
const FIRST_NAME = <?= json_encode($firstName) ?>;

let TEST_ID = 0;
let TOTAL_QUESTIONS = 0;
let ITEM_IDS = [];
let CUR_POS = 0;
let isRecording = false;
let isStopping = false;
let mediaStream = null;
let recorder = null;
let chunks = [];
let answerLeft = 60;
let answerInt = null;

let INTRO_URL = '';
let QUESTION_URLS = {};
let RESULT_URL = '';

let CURRENT_PROMPT_URL = '';
let CURRENT_PROMPT_TYPE = '';
let CURRENT_PROMPT_ITEM_ID = 0;
let READY_FOR_NEXT = false;
let FIRST_QUESTION_READY = false;
let NEXT_QUESTION_READY = false;
let prepareStatusPoll = null;
let prepareStatusStarted = false;
let prepareRunStarted = false;
let PREPARE_IS_READY = false;

const btnStart = document.getElementById('btnStart');
const btnReady = document.getElementById('btnReady');
const btnReplay = document.getElementById('btnReplay');
const btnPTT = document.getElementById('btnPTT');
const btnNext = document.getElementById('btnNext');
const btnLessonMenu = document.getElementById('btnLessonMenu');
const questionBtns = document.getElementById('questionBtns');
const answerBtns = document.getElementById('answerBtns');
const doneBtns = document.getElementById('doneBtns');

const sysline = document.getElementById('sysline');
const prepFill = document.getElementById('prepFill');
const prepText = document.getElementById('prepText');
const prepLabel = document.getElementById('prepLabel');
const answerWrap = document.getElementById('answerWrap');
const answerFill = document.getElementById('answerFill');
const answerText = document.getElementById('answerText');
const audioPlayer = document.getElementById('audioPlayer');
const qstrip = document.getElementById('qstrip');
const instructorRing = document.getElementById('instructorRing');
const studentRing = document.getElementById('studentRing');
const studentCam = document.getElementById('studentCam');
const camFallback = document.getElementById('camFallback');
const camStatus = document.getElementById('camStatus');
const resultBox = document.getElementById('resultBox');

function setSys(t){ sysline.textContent = t; }
function setPrep(p){ prepFill.style.width = p + '%'; prepText.textContent = p + '%'; }
function setSpeaking(on){ on ? instructorRing.classList.add('talking') : instructorRing.classList.remove('talking'); }
function setRec(on){ on ? studentRing.classList.add('rec') : studentRing.classList.remove('rec'); }

function setQuestionButtonLabel(){
  btnReady.textContent = (CUR_POS <= 1) ? 'Ready for First Question' : 'Ready for Next Question';
}

function markDone(i){
  const el = document.getElementById('qd_' + i);
  if(el){
    el.classList.remove('ready');
    el.classList.add('done');
  }
}

function markReady(i){
  const el = document.getElementById('qd_' + i);
  if(el && !el.classList.contains('done')){
    el.classList.add('ready');
  }
}

function sleep(ms){
  return new Promise(resolve => setTimeout(resolve, ms));
}

function applyPrepareManifest(j){
  if (!j || typeof j !== 'object') return;

  let manifest = null;

  if (j.manifest && typeof j.manifest === 'object') {
    manifest = j.manifest;
  } else if (j.manifest_json) {
    if (typeof j.manifest_json === 'string') {
      try { manifest = JSON.parse(j.manifest_json); } catch(e) {}
    } else if (typeof j.manifest_json === 'object') {
      manifest = j.manifest_json;
    }
  } else if (j.item_ids || j.question_urls || j.intro_url) {
    manifest = j;
  }

  if (!manifest || typeof manifest !== 'object') return;

  if (Array.isArray(manifest.item_ids) && manifest.item_ids.length) {
    ITEM_IDS = manifest.item_ids.map(x => parseInt(x, 10)).filter(Boolean);
    TOTAL_QUESTIONS = parseInt(manifest.total_questions || ITEM_IDS.length, 10) || ITEM_IDS.length;
  }

  if (manifest.question_urls && typeof manifest.question_urls === 'object') {
    QUESTION_URLS = manifest.question_urls;
  }

  if (typeof manifest.intro_url === 'string' && manifest.intro_url) {
    INTRO_URL = manifest.intro_url;
  }

  if (TOTAL_QUESTIONS > 0 && qstrip.children.length === 0) {
    renderDots(TOTAL_QUESTIONS);
  }
}

function stopPrepareStatusPolling(){
  if (prepareStatusPoll) {
    clearInterval(prepareStatusPoll);
    prepareStatusPoll = null;
  }
}

async function pollPrepareStatusOnce(){
  if (!TEST_ID) return;

  try {
    const res = await fetch('/student/api/test_prepare_status_v2.php?test_id=' + encodeURIComponent(TEST_ID), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    });

    const txt = await res.text();
    let j = null;
    try {
      j = JSON.parse(txt);
    } catch(e) {
      return;
    }

    if (!j || !j.ok) return;

    const pct = Math.max(0, Math.min(100, parseInt(j.progress_pct || 0, 10)));
    const statusText = String(j.status_text || '');

    if (pct > 0) setPrep(pct);
    if (statusText) setSys(statusText);

	  
if (String(j.status || '') === 'ready' || pct >= 100) {

  const hydrated = await hydrateReadyManifest();

  if (hydrated) {

    stopPrepareStatusPolling();

    setPrep(100);
    setSys(FIRST_NAME + ', your progress test is ready.');

    // ensure dots are visible
    if (TOTAL_QUESTIONS > 0 && qstrip.children.length === 0) {
        renderDots(TOTAL_QUESTIONS);
    }

    // unlock start button
    btnStart.disabled = false;

    PREPARE_IS_READY = true;
  }
}	  
	  
  } catch (e) {
  }
}

function startPrepareStatusPolling(){
  if (prepareStatusStarted) return;
  prepareStatusStarted = true;

  stopPrepareStatusPolling();
  prepareStatusPoll = setInterval(pollPrepareStatusOnce, 1000);
}

async function hydrateReadyManifest(){
  if (!TEST_ID) return false;

  try {
    const res = await fetch('/student/api/test_prepare_status_v2.php?test_id=' + encodeURIComponent(TEST_ID), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    });

    const txt = await res.text();
    let j = null;
    try {
      j = JSON.parse(txt);
    } catch(e) {
      return false;
    }

    if (!j || !j.ok) return false;

    applyPrepareManifest(j);

    if (
      TOTAL_QUESTIONS > 0 &&
      ITEM_IDS.length > 0 &&
      typeof INTRO_URL === 'string' &&
      INTRO_URL !== '' &&
      QUESTION_URLS &&
      typeof QUESTION_URLS === 'object' &&
      Object.keys(QUESTION_URLS).length > 0
    ) {
      PREPARE_IS_READY = true;
      return true;
    }

    return false;

  } catch(e) {
    return false;
  }
}	
	


function restoreAfterUploadFailure() {
  btnReplay.disabled = false;
  btnPTT.disabled = false;
  btnNext.disabled = true;
  btnNext.style.display = 'none';
  if (CURRENT_PROMPT_TYPE === 'question') {
    answerWrap.style.display = 'block';
    startAnswerTimer();
  }
}

async function startCam(){
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    camStatus.textContent = 'Camera not supported.';
    return;
  }
  try{
    const stream = await navigator.mediaDevices.getUserMedia({ video:true, audio:false });
    studentCam.srcObject = stream;
    camFallback.style.display = 'none';
    camStatus.textContent = 'Camera active.';
  }catch(e){
    camStatus.textContent = 'Camera denied (ok).';
  }
}

function renderDots(n){
  qstrip.innerHTML = '';
  for(let i=1;i<=n;i++){
    const d = document.createElement('div');
    d.className = 'qdot';
    d.id = 'qd_' + i;
    d.textContent = String(i);
    qstrip.appendChild(d);
  }
  qstrip.style.display = 'flex';
}

async function waitUntilAudioReady(url, timeoutMs = 20000){
  return new Promise((resolve) => {
    if (!url) return resolve(false);

    const a = new Audio();
    let settled = false;
    const done = (ok) => {
      if (settled) return;
      settled = true;
      a.src = '';
      resolve(ok);
    };

    const timer = setTimeout(() => done(false), timeoutMs);

    a.preload = 'auto';
    a.oncanplaythrough = () => {
      clearTimeout(timer);
      done(true);
    };
    a.onerror = () => {
      clearTimeout(timer);
      done(false);
    };

    a.src = url;
    try { a.load(); } catch(e) {}
  });
}

async function playAudio(url){
  if (!url) return false;

  return new Promise((resolve)=>{
    setSpeaking(true);
    audioPlayer.pause();
    audioPlayer.currentTime = 0;
    audioPlayer.src = url;
    audioPlayer.onended = ()=>{ setSpeaking(false); resolve(true); };
    audioPlayer.onerror = ()=>{ setSpeaking(false); resolve(false); };
    const p = audioPlayer.play();
    if (p && p.catch) p.catch(()=>{ setSpeaking(false); resolve(false); });
  });
}

async function prepareFirstQuestionReady(){
  if (!ITEM_IDS.length) return false;
  const itemId = ITEM_IDS[0];
  const url = QUESTION_URLS[String(itemId)] || QUESTION_URLS[itemId] || '';
  setSys('Buffering first question...');
  const ok = await waitUntilAudioReady(url);
  if (ok) {
    FIRST_QUESTION_READY = true;
    markReady(1);
    setQuestionButtonLabel();
    btnReady.disabled = false;
    questionBtns.style.display = 'flex';
    setSys(FIRST_NAME + ', your progress test is ready.');
    return true;
  }
  setSys('Loading audio failed for the first question. Please refresh the page.');
  return false;
}

async function prepareNextQuestionReady(){
  if (CUR_POS > TOTAL_QUESTIONS) return false;

  const itemId = ITEM_IDS[CUR_POS - 1];
  const url = QUESTION_URLS[String(itemId)] || QUESTION_URLS[itemId] || '';
  if (!url) {
    setSys('Next question audio not found.');
    return false;
  }

  setSys('Preparing next question...');
  NEXT_QUESTION_READY = false;
  btnNext.disabled = true;

  const ok = await waitUntilAudioReady(url);
  if (ok) {
    NEXT_QUESTION_READY = true;
    markReady(CUR_POS);
    btnNext.disabled = false;
    btnNext.style.display = 'block';
    setSys('Next question is ready.');
    return true;
  }

  setSys('Next question audio failed to load.');
  return false;
}

async function prepareTest(){
  await startCam();

  setPrep(1);
  setSys('Starting preparation...');

  const res = await fetch('/student/api/test_prepare_start_v2.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ cohort_id: COHORT_ID, lesson_id: LESSON_ID })
  });

  const txt = await res.text();
  let j = null;
  try {
    j = JSON.parse(txt);
  } catch(e) {
    j = {ok:false,error:'Non-JSON: ' + txt.slice(0,200)};
  }

  if (!j.ok) {
    stopPrepareStatusPolling();
    setPrep(100);
    setSys('Preparation failed: ' + (j.error || 'Unknown error'));
    return;
  }

  TEST_ID = parseInt(j.test_id || 0, 10);
  if (!TEST_ID) {
    setPrep(100);
    setSys('Preparation failed: invalid test id.');
    return;
  }

  if (j.progress_pct) setPrep(parseInt(j.progress_pct, 10) || 1);
  if (j.status_text) setSys(String(j.status_text || ''));

  startPrepareStatusPolling();
  await pollPrepareStatusOnce();
}

async function playIntroThenEnableFirstQuestion(){
  CURRENT_PROMPT_URL = INTRO_URL;
  CURRENT_PROMPT_TYPE = 'intro';
  CURRENT_PROMPT_ITEM_ID = 0;

  setSys('Maya is speaking...');
  const ok = await playAudio(INTRO_URL);
  if (!ok) {
    setSys('Intro audio failed.');
    btnStart.disabled = false;
    return false;
  }

questionBtns.style.display = 'flex';
btnReplay.disabled = false;
setQuestionButtonLabel();

if (!FIRST_QUESTION_READY) {
  await prepareFirstQuestionReady();
}

btnReady.disabled = !FIRST_QUESTION_READY;
setSys('Click when you are ready for the first question.');
return true;
}

async function playCurrentQuestion(){
  const itemId = ITEM_IDS[CUR_POS - 1];
  const url = QUESTION_URLS[String(itemId)] || QUESTION_URLS[itemId] || '';
  if (!itemId || !url) {
    setSys('Question audio not found.');
    return false;
  }

  CURRENT_PROMPT_URL = url;
  CURRENT_PROMPT_TYPE = 'question';
  CURRENT_PROMPT_ITEM_ID = itemId;

  setSys('Maya is speaking...');
  const ok = await playAudio(url);
  if (!ok) {
    setSys('Question audio failed.');
    return false;
  }

  questionBtns.style.display = 'flex';
  answerBtns.style.display = 'flex';
  answerWrap.style.display = 'block';

  btnReplay.disabled = false;
  btnPTT.disabled = false;
  btnNext.disabled = true;
  btnNext.style.display = 'none';

  setSys('Your turn.');
  startAnswerTimer();
  return true;
}

function startAnswerTimer(){
  stopAnswerTimer();
  answerLeft = 60;
  answerFill.style.width = '0%';
  answerFill.classList.remove('danger');
  answerText.textContent = '60s';

  answerInt = setInterval(()=>{
    answerLeft--;
    if(answerLeft < 0) answerLeft = 0;
    const pct = Math.round(((60 - answerLeft) / 60) * 100);
    answerFill.style.width = pct + '%';
    answerText.textContent = answerLeft + 's';
    if(answerLeft <= 10) answerFill.classList.add('danger');

    if(answerLeft <= 0){
      stopAnswerTimer();
      if(!isRecording && !isStopping){
        uploadAnswerBlob(null, true);
      }
    }
  }, 1000);
}

function stopAnswerTimer(){
  if(answerInt) clearInterval(answerInt);
  answerInt = null;
}

async function startRecording(){
  try{
    chunks = [];
    mediaStream = await navigator.mediaDevices.getUserMedia({ audio:true });
    const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
    recorder = new MediaRecorder(mediaStream, mime ? { mimeType:mime } : undefined);

    recorder.ondataavailable = (e)=>{ if(e.data && e.data.size > 0) chunks.push(e.data); };
    recorder.onstop = async ()=>{
      const blob = chunks.length ? new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }) : null;
      if(mediaStream) mediaStream.getTracks().forEach(t=>t.stop());
      mediaStream = null;
      setRec(false);
      btnPTT.classList.remove('rec');
      btnPTT.textContent = 'Tap to Start Talking';
      isStopping = false;
      await uploadAnswerBlob(blob, false);
    };

    recorder.start();
    isRecording = true;
    setRec(true);
    btnPTT.classList.add('rec');
    btnPTT.textContent = 'Tap to Stop (finishes in 1 sec)';
  }catch(e){
    setSys('Microphone access failed.');
  }
}

async function delayedStopRecording(){
  if (!recorder || recorder.state === 'inactive') return;
  isRecording = false;
  isStopping = true;
  btnPTT.disabled = true;
  btnPTT.textContent = 'Finishing recording...';
  setSys('Finishing recording...');

  setTimeout(()=>{
    try{
      if(recorder && recorder.state !== 'inactive'){
        recorder.stop();
      }
    }catch(e){
      isStopping = false;
      setSys('Stop recording failed.');
    }
  }, 1000);
}

async function replayCurrentPrompt(){
  if(isRecording || isStopping) return;
  if(!CURRENT_PROMPT_URL){
    setSys('No question to replay.');
    return;
  }

  stopAnswerTimer();

  btnReplay.disabled = true;
  btnReady.disabled = true;
  btnPTT.disabled = true;
  btnNext.disabled = true;

  setSys('Maya is speaking...');
  const ok = await playAudio(CURRENT_PROMPT_URL);

  btnReplay.disabled = false;

  if (!ok) {
    setSys('Replay failed.');
    return;
  }

  if (CURRENT_PROMPT_TYPE === 'question') {
    btnPTT.disabled = false;
    if (READY_FOR_NEXT) {
      btnNext.disabled = false;
    }
    setSys('Your turn.');
    startAnswerTimer();
  } else {
    btnReady.disabled = false;
    setSys('Click when you are ready for the first question.');
  }
}

btnStart.addEventListener('click', async ()=>{
  btnStart.disabled = true;
  questionBtns.style.display = 'none';
  answerBtns.style.display = 'none';
  answerWrap.style.display = 'none';
  btnReplay.disabled = true;
  btnReady.disabled = true;
  await playIntroThenEnableFirstQuestion();
});

btnReady.addEventListener('click', async ()=>{
  if (CUR_POS <= 0) CUR_POS = 1;

  btnReady.disabled = true;
  btnReplay.disabled = true;
  answerBtns.style.display = 'none';
  answerWrap.style.display = 'none';

  await playCurrentQuestion();
});

btnReplay.addEventListener('click', replayCurrentPrompt);

btnPTT.addEventListener('click', async ()=>{
  if (!TEST_ID) return;
  if (isStopping) return;

  if (!isRecording) {
    stopAnswerTimer();
    btnReplay.disabled = true;
    btnNext.disabled = true;
    await startRecording();
  } else {
    await delayedStopRecording();
  }
});

btnNext.addEventListener('click', async ()=>{
  btnNext.disabled = true;
  btnReplay.disabled = true;
  READY_FOR_NEXT = false;
  await playCurrentQuestion();
});

btnLessonMenu.addEventListener('click', ()=>{
  window.history.back();
});

async function uploadAnswerBlob(blob, timeoutOnly){
  btnPTT.disabled = true;
  btnReplay.disabled = true;
  btnNext.disabled = true;
  btnNext.style.display = 'none';
  setSys('STEP 1: Saving your answer...');

  const fd = new FormData();
  fd.append('test_id', String(TEST_ID));
  fd.append('idx', String(CUR_POS));

  if (timeoutOnly) {
    fd.append('timeout', '1');
  } else if (blob) {
    fd.append('audio', blob, 'q' + String(CUR_POS).padStart(2,'0') + '.webm');
  }

  const controller = new AbortController();
  const timeoutMs = 15000;
  const timeoutHandle = setTimeout(() => controller.abort(), timeoutMs);

  try {
    setSys('STEP 2: Sending upload request...');
    const res = await fetch('/student/api/test_upload_answer_v2.php', {
      method:'POST',
      credentials:'same-origin',
      body: fd,
      signal: controller.signal
    });

    clearTimeout(timeoutHandle);

    setSys('STEP 3: Reading upload response...');
    const txt = await res.text();

    let j = null;
    try {
      j = JSON.parse(txt);
    } catch(e) {
      j = {ok:false,error:'Non-JSON: ' + txt.slice(0,200)};
    }

    if (!j.ok) {
      setSys('STEP 4A: Upload failed: ' + (j.error || 'Unknown error'));
      restoreAfterUploadFailure();
      return;
    }

    setSys('STEP 4B: Upload OK. Preparing next question...');
    markDone(CUR_POS);
    CUR_POS++;

    if (CUR_POS > TOTAL_QUESTIONS) {
      await finalizeTest();
      return;
    }

    READY_FOR_NEXT = false;
    btnReplay.disabled = false;
    answerWrap.style.display = 'none';

    await prepareNextQuestionReady();

    READY_FOR_NEXT = NEXT_QUESTION_READY;
    btnNext.disabled = !READY_FOR_NEXT;

    if (READY_FOR_NEXT) {
      setSys('STEP 5: Next question ready.');
    } else {
      setSys('STEP 5: Next question failed to prepare.');
      restoreAfterUploadFailure();
    }

  } catch (err) {
    clearTimeout(timeoutHandle);

    let msg = 'STEP X: Upload failed.';
    if (err && err.name === 'AbortError') {
      msg = 'STEP X: Upload timed out after 15 seconds.';
    } else if (err && err.message) {
      msg = 'STEP X: Upload failed: ' + err.message;
    }

    setSys(msg);
    restoreAfterUploadFailure();
  }
}

async function finalizeTest(){
  btnPTT.disabled = true;
  btnReplay.disabled = true;
  btnReady.disabled = true;
  btnNext.disabled = true;
  prepLabel.textContent = 'Evaluation progress';
  setPrep(10);
  setSys('I am evaluating your answers... please standby.');

  let p = 10;
  const tick = setInterval(()=>{
    p = Math.min(92, p + 4);
    setPrep(p);
  }, 300);

  const res = await fetch('/student/api/test_finalize_v2.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify({ test_id: TEST_ID })
  });

  clearInterval(tick);

  const txt = await res.text();
  let j = null;
  try { j = JSON.parse(txt); } catch(e) { j = {ok:false,error:'Non-JSON: ' + txt.slice(0,200)}; }

  if (!j.ok) {
    setPrep(100);
    setSys('Evaluation failed: ' + (j.error || 'Unknown error'));
    return;
  }

  setPrep(100);
  RESULT_URL = String(j.result_audio || '');

  CURRENT_PROMPT_URL = RESULT_URL;
  CURRENT_PROMPT_TYPE = 'result';
  CURRENT_PROMPT_ITEM_ID = 0;

  setSys('Maya is speaking...');
  const ok = await playAudio(RESULT_URL || '');
  if (!ok) {
    setSys('Result audio failed, but written results are available.');
  }

  const sections = [
    ['Debrief', j.ai_summary || ''],
    ['Weak Areas', j.weak_areas || ''],
    ['Summary Quality', j.summary_quality || ''],
    ['Summary Issues', j.summary_issues || ''],
    ['Suggested Summary Corrections', j.summary_corrections || ''],
    ['Confirmed Misunderstandings', j.confirmed_misunderstandings || '']
  ];

  let html = `<div><strong>Score:</strong> ${escapeHtml(String(j.score_pct || 0))}%</div>`;

  sections.forEach(([title, value]) => {
    if (!String(value || '').trim()) return;
    html += `
      <div style="margin-top:10px;">
        <strong>${escapeHtml(title)}</strong><br>
        <div style="white-space:pre-wrap;">${escapeHtml(value)}</div>
      </div>
    `;
  });

  resultBox.style.display = 'block';
  resultBox.innerHTML = html;
  doneBtns.style.display = 'flex';
  setSys('Completed.');
}

function escapeHtml(s){
  return (s || '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;');
}

prepareTest();
</script>

<?php cw_footer(); ?>