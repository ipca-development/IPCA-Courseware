<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/openai.php';
require_once __DIR__ . '/../../src/spaces.php';

cw_require_admin();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/
$prefix = 'cvr_testing/input/';

/*
|--------------------------------------------------------------------------
| LIST FILES (via CDN index assumption)
|--------------------------------------------------------------------------
*/
function list_files(string $prefix): array {
    $cfg = cw_spaces_config();
    $url = $cfg['cdnBase'] . '/' . trim($prefix,'/') . '/index.json';

    $json = @file_get_contents($url);
    if(!$json) return [];

    $data = json_decode($json,true);
    if(!is_array($data)) return [];

    return $data;
}

/*
|--------------------------------------------------------------------------
| DOWNLOAD FILE
|--------------------------------------------------------------------------
*/
function download_file(string $key): string {
    $cfg = cw_spaces_config();
    $url = $cfg['cdnBase'].'/'.$key;

    $data = @file_get_contents($url);
    if(!$data) throw new RuntimeException("Failed to download file");

    $tmp = tempnam(sys_get_temp_dir(),'cvr_');
    file_put_contents($tmp,$data);

    return $tmp;
}

/*
|--------------------------------------------------------------------------
| TRANSCRIBE
|--------------------------------------------------------------------------
*/
function transcribe(string $file,string $prompt): array {

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');

    $post = [
        'file' => new CURLFile($file),
        'model' => 'gpt-4o-transcribe',
        'prompt' => $prompt
    ];

    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.cw_openai_key()
        ],
        CURLOPT_POSTFIELDS=>$post
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    return json_decode($res,true) ?: [];
}

/*
|--------------------------------------------------------------------------
| SIMPLE CLASSIFIER
|--------------------------------------------------------------------------
*/
function classify(string $text): string {

    $t = strtolower($text);

    if(trim($t)==='' || strlen($t)<5) return 'NOISE';

    if(preg_match('/(tower|ground|cleared|runway|heading|contact|squawk|roger|wilco)/',$t))
        return 'ATC';

    return 'INTERCOM';
}

/*
|--------------------------------------------------------------------------
| PROCESS
|--------------------------------------------------------------------------
*/
$files = list_files($prefix);

$selected = $_POST['file'] ?? '';
$prompt = $_POST['prompt'] ?? 'Transcribe aviation cockpit audio accurately.';
$segments = [];

if($_SERVER['REQUEST_METHOD']==='POST' && $selected){

    $tmp = download_file($selected);
    $res = transcribe($tmp,$prompt);

    if(!empty($res['text'])){
        $lines = preg_split('/[\n\.]/',$res['text']);

        foreach($lines as $i=>$line){
            $line = trim($line);
            if($line==='') continue;

            $segments[] = [
                'time' => sprintf('%02d:%02d', floor($i/60), $i%60),
                'text' => $line,
                'type' => classify($line)
            ];
        }
    }

    unlink($tmp);
}

cw_header('CVR Transcript Test');
?>

<style>
.cvrt{display:flex;flex-direction:column;gap:20px}
.bubble{max-width:70%;padding:12px;border-radius:18px}
.left{align-self:flex-start;background:#f3f4f6;color:#111}
.right{align-self:flex-end;background:#1d4ed8;color:#fff}
.center{align-self:center;background:#e5e7eb;color:#555}
.time{font-size:11px;color:#888;margin-bottom:3px}
</style>

<div class="cvrt">

<div class="card">
<form method="post">

<select name="file">
<option value="">Select file</option>
<?php foreach($files as $f): ?>
<option value="<?=h($f)?>" <?= $selected==$f?'selected':''?>>
<?=h(basename($f))?>
</option>
<?php endforeach;?>
</select>

<br><br>

<textarea name="prompt" style="width:100%;height:150px"><?=h($prompt)?></textarea>

<br><br>

<button class="btn">Run Transcript Test</button>

</form>
</div>

<div class="card">
<?php if(!$segments): ?>
No output yet.
<?php else: ?>

<?php foreach($segments as $s):

$type = $s['type'];
$class = 'center';

if($type==='ATC') $class='left';
if($type==='INTERCOM') $class='right';
?>

<div class="bubble <?=$class?>">
<div class="time"><?=h($s['time'])?></div>
<?=h($s['text'])?>
</div>

<?php endforeach; ?>

<?php endif; ?>
</div>

</div>

<?php cw_footer(); ?>