<?php
/**
 * 条形码 + 二维码 生成器（单一入口）
 * 模式：mode=1（条形码）, mode=2（二维码）, mode=3（组合）
 * 二维码依赖：同目录下的 qrcode.php
 */

// ---------- 清理输出缓冲 ----------
if (ob_get_level()) ob_end_clean();

// ---------- 检查并载入二维码库 ----------
if (!class_exists('QRcode')) {
    $qrFile = __DIR__ . '/qrcode.php';
    if (!file_exists($qrFile)) {
        die('错误：找不到 qrcode.php，请将 php.txt 重命名为 qrcode.php 并放在同一目录。');
    }
    require_once $qrFile;
}

// ---------- 参数获取 ----------
$mode   = isset($_GET['mode']) ? intval($_GET['mode']) : 1;
$content= isset($_GET['content']) ? trim($_GET['content']) : '';
$text   = isset($_GET['text']) ? trim($_GET['text']) : '';
$size   = isset($_GET['size']) ? intval($_GET['size']) : 0;
// 编码策略：空/auto=动态规划最优(默认)；c=等宽时优先切Code C(高密度观感)；b=全程逐字符不切C；compare=两种并排对比
$enc    = isset($_GET['enc']) ? strtolower(trim($_GET['enc'])) : '';
if (!in_array($enc, ['', 'auto', 'c', 'b', 'compare'], true)) $enc = '';

if (!$content) {
    die('缺少必要参数 content');
}

// 解析多行文字
$extraLines = $text ? array_map('trim', explode('|', $text)) : [];

if ($mode == 1 || $mode == 3) {
    $lines = array_merge([$content], $extraLines);
} else {
    $lines = $extraLines;
}

// ---------- 二维码像素映射 ----------
if ($size <= 0) {
    if ($mode == 2) {
        $size = 35;   // 模式2 单二维码：小巧为主
    } elseif ($mode == 3) {
        $size = 36;   // 模式3 二维码：整体缩小一圈
    } else {
        $size = 25;
    }
}
$pixelSize = max(6, min(18, ceil($size / 5)));
if ($size > 60) $pixelSize = min(24, ceil($size / 4));

// ---------- 条形码生成（使用标准 Code128 编码表 + 静区） ----------
function code128Patterns() {
    // 标准 Code128 编码表（码值 0~106 的模块序列，已按权威实现逐条核对）
    // 0~94 为字符码，95~98=FNC3/FNC2/SHIFT/FNC4，99=CodeC 100=CodeB 101=CodeA 102=FNC1
    // 103/104/105=起始符 A/B/C，106=停止符（13 模块）
    return [
        0=>"11011001100", 1=>"11001101100", 2=>"11001100110", 3=>"10010011000", 4=>"10010001100",
        5=>"10001001100", 6=>"10011001000", 7=>"10011000100", 8=>"10001100100", 9=>"11001001000",
        10=>"11001000100", 11=>"11000100100", 12=>"10110011100", 13=>"10011011100", 14=>"10011001110",
        15=>"10111001100", 16=>"10011101100", 17=>"10011100110", 18=>"11001110010", 19=>"11001011100",
        20=>"11001001110", 21=>"11011100100", 22=>"11001110100", 23=>"11101101110", 24=>"11101001100",
        25=>"11100101100", 26=>"11100100110", 27=>"11101100100", 28=>"11100110100", 29=>"11100110010",
        30=>"11011011000", 31=>"11011000110", 32=>"11000110110", 33=>"10100011000", 34=>"10001011000",
        35=>"10001000110", 36=>"10110001000", 37=>"10001101000", 38=>"10001100010", 39=>"11010001000",
        40=>"11000101000", 41=>"11000100010", 42=>"10110111000", 43=>"10110001110", 44=>"10001101110",
        45=>"10111011000", 46=>"10111000110", 47=>"10001110110", 48=>"11101110110", 49=>"11010001110",
        50=>"11000101110", 51=>"11011101000", 52=>"11011100010", 53=>"11011101110", 54=>"11101011000",
        55=>"11101000110", 56=>"11100010110", 57=>"11101101000", 58=>"11101100010", 59=>"11100011010",
        60=>"11101111010", 61=>"11001000010", 62=>"11110001010", 63=>"10100110000", 64=>"10100001100",
        65=>"10010110000", 66=>"10010000110", 67=>"10000101100", 68=>"10000100110", 69=>"10110010000",
        70=>"10110000100", 71=>"10011010000", 72=>"10011000010", 73=>"10000110100", 74=>"10000110010",
        75=>"11000010010", 76=>"11001010000", 77=>"11110111010", 78=>"11000010100", 79=>"10001111010",
        80=>"10100111100", 81=>"10010111100", 82=>"10010011110", 83=>"10111100100", 84=>"10011110100",
        85=>"10011110010", 86=>"11110100100", 87=>"11110010100", 88=>"11110010010", 89=>"11011011110",
        90=>"11011110110", 91=>"11110110110", 92=>"10101111000", 93=>"10100011110", 94=>"10001011110",
        95=>"10111101000", 96=>"10111100010", 97=>"11110101000", 98=>"11110100010", 99=>"10111011110",
        100=>"10111101110", 101=>"11101011110", 102=>"11110101110",
        103=>"11010000100",  // START A
        104=>"11010010000",  // START B
        105=>"11010011100",  // START C
        106=>"1100011101011" // STOP（13 模块，其余均为 11 模块）
    ];
}

// Code128 仅能编码 ASCII 0~127；超出时报错而不是静默丢弃（原实现会把非 ASCII 字符跳过，
// 造成“条码内容和实际文字对不上”）
function code128Validate($content) {
    $len = strlen($content);
    for ($i = 0; $i < $len; $i++) {
        if (ord($content[$i]) > 127) {
            return 'Code128 条形码内容只能为 ASCII 字符（0~127），请勿包含中文等字符。';
        }
    }
    return '';
}

// 按策略编码 Code128（A/B/C 自动选择，支持 SHIFT 完整 ASCII 0~127）
// 返回符号值序列（已含起始符）。
//  $enc: 'auto' 动态规划求符号总数最少，平手时优先 Start B（默认，贴近真品）；
//        'c'   平手时优先切 Code C（只作对比用）；
//        'b'   禁用 Code C、全程逐字符（仅 A/B）。
function code128EncodeToSymbolsEx($content, $enc) {
    $n = strlen($content);
    $ascii = [];
    for ($i = 0; $i < $n; $i++) $ascii[$i] = ord($content[$i]);

    $isDigit = function ($v) { return $v >= 48 && $v <= 57; };
    $fits = function ($v, $set) {
        if ($set === 'A') return $v >= 0 && $v <= 95;    // ASCII 0~95
        if ($set === 'B') return $v >= 32 && $v <= 127;  // ASCII 32~127
        return false;
    };
    $codeVal = function ($v, $set) {
        if ($set === 'A') return $v >= 32 ? $v - 32 : $v + 64;
        return $v - 32; // B 码集
    };
    $switchVal = ['A' => 101, 'B' => 100, 'C' => 99];

    $allowC   = $enc !== 'b';
    $preferC  = $enc === 'c';
    $sets = $allowC ? ['A', 'B', 'C'] : ['A', 'B'];
    $INF = PHP_INT_MAX >> 2;

    $cost = [];
    $parent = [];
    foreach ($sets as $s) {
        $parent[$s] = array_fill(0, $n + 1, null);
        if ($preferC) {
            // 评分向量 = [符号总数, 用 Code C 编码的字符数]，符号数相同则 C 字符数更多者优先
            $cost[$s] = array_fill(0, $n + 1, [$INF, 0]);
            $cost[$s][0] = [1, 0];
        } else {
            $cost[$s] = array_fill(0, $n + 1, $INF);
            $cost[$s][0] = 1;
        }
    }

    $better = function ($a, $b) use ($preferC) {
        if ($preferC) {
            return $a[0] < $b[0] || ($a[0] == $b[0] && $a[1] > $b[1]);
        }
        return $a < $b;
    };
    $isINF = function ($v) use ($preferC, $INF) {
        return $preferC ? $v[0] >= $INF : $v >= $INF;
    };

    for ($p = 0; $p < $n; $p++) {
        foreach ($sets as $cur) {
            $c0 = $cost[$cur][$p];
            if ($isINF($c0)) continue;
            $ch = $ascii[$p];
            $actions = [];

            if ($cur === 'C') {
                // C 码集：连续两位数字合成一个码
                if ($p + 1 < $n && $isDigit($ch) && $isDigit($ascii[$p + 1])) {
                    $actions[] = ['ns' => 'C', 'np' => $p + 2, 'sym' => [(int)substr($content, $p, 2)], 'used' => 2];
                }
            } else {
                if ($fits($ch, $cur)) {
                    $actions[] = ['ns' => $cur, 'np' => $p + 1, 'sym' => [$codeVal($ch, $cur)], 'used' => 0];
                }
                // SHIFT：A/B 之间临时借用，仅处理本字符后自动回到原码集
                $other = $cur === 'A' ? 'B' : 'A';
                if ($fits($ch, $other) && !$fits($ch, $cur)) {
                    $actions[] = ['ns' => $cur, 'np' => $p + 1, 'sym' => [98, $codeVal($ch, $other)], 'used' => 0];
                }
            }

            // 永久切换到其它码集
            foreach ($sets as $t) {
                if ($t === $cur) continue;
                $sv = $switchVal[$t];
                if ($t === 'C') {
                    if ($p + 1 < $n && $isDigit($ch) && $isDigit($ascii[$p + 1])) {
                        $actions[] = ['ns' => 'C', 'np' => $p + 2, 'sym' => [$sv, (int)substr($content, $p, 2)], 'used' => 2];
                    }
                } else {
                    if ($fits($ch, $t)) {
                        $actions[] = ['ns' => $t, 'np' => $p + 1, 'sym' => [$sv, $codeVal($ch, $t)], 'used' => 0];
                    }
                }
            }

            foreach ($actions as $a) {
                if ($preferC) {
                    $nw = [$c0[0] + count($a['sym']), $c0[1] + $a['used']];
                } else {
                    $nw = $c0 + count($a['sym']);
                }
                if ($better($nw, $cost[$a['ns']][$a['np']])) {
                    $cost[$a['ns']][$a['np']] = $nw;
                    $parent[$a['ns']][$a['np']] = ['ps' => $cur, 'pp' => $p, 'sym' => $a['sym']];
                }
            }
        }
    }

    // 取最优终态：符号总数最小优先；符号数打平时一律优先 Start B（多数厂商/真品习惯）。
    // Start A/B 对 32~95 的可打印字符数据值相同，只影响起始符与校验码两处。
    $order = $allowC ? ['B', 'A', 'C'] : ['B', 'A'];
    $bestS = $order[0];
    $bestC = $cost[$bestS][$n];
    if ($isINF($bestC)) {
        foreach ($sets as $s) {
            if (!$isINF($cost[$s][$n])) { $bestS = $s; $bestC = $cost[$s][$n]; break; }
        }
    }
    foreach ($order as $s) {
        if ($s === $bestS) continue;
        if ($better($cost[$s][$n], $bestC)) { $bestC = $cost[$s][$n]; $bestS = $s; }
    }

    $symbols = [];
    $s = $bestS;
    $p = $n;
    while ($p > 0) {
        $par = $parent[$s][$p];
        $symbols = array_merge($par['sym'], $symbols);
        $s = $par['ps'];
        $p = $par['pp'];
    }
    $startMap = ['A' => 103, 'B' => 104, 'C' => 105];
    array_unshift($symbols, $startMap[$s]);
    return $symbols;
}

// 兼容原调用：不传策略 = 原“自动最优”行为
function code128EncodeToSymbols($content) {
    return code128EncodeToSymbolsEx($content, 'auto');
}

// 拼接起始符+数据+校验码+停止符，并加上左右各 10 模块的静区
function code128Binary($content, $enc = 'auto') {
    $patterns = code128Patterns();
    $symbols = code128EncodeToSymbolsEx($content, $enc);

    // 校验码 = (起始符码值 + Σ 第i位数据码值×i) mod 103
    $sum = $symbols[0];
    for ($i = 1; $i < count($symbols); $i++) $sum += $i * $symbols[$i];
    $check = $sum % 103;

    $binary = '';
    foreach ($symbols as $v) $binary .= $patterns[$v];
    $binary .= $patterns[$check] . $patterns[106];
    return str_repeat('0', 10) . $binary . str_repeat('0', 10);
}

// 绘制条形码：modulePx 为“每模块像素宽”（整数），保证所有条/空粗细严格一致，
// 不再像旧版用浮点模块宽 floor 取整导致条宽忽粗忽细。
function generateCode128Image($content, $height = 200, $modulePx = 0, $enc = 'auto') {
    if (code128Validate($content) !== '') return null;
    $binary = code128Binary($content, $enc);
    $totalModules = strlen($binary);
    if ($modulePx <= 0) $modulePx = 4;
    $imgW = $totalModules * $modulePx;

    $img = imagecreatetruecolor($imgW, $height);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $imgW - 1, $height - 1, $white);
    for ($i = 0; $i < $totalModules; $i++) {
        if ($binary[$i] === '1') {
            $x = $i * $modulePx;
            imagefilledrectangle($img, $x, 0, $x + $modulePx - 1, $height - 1, $black);
        }
    }
    return $img;
}

function barcodeToBase64($content, $enc = 'auto') {
    $img = generateCode128Image($content, 200, 0, $enc);
    if (!$img) return false;
    ob_start();
    imagepng($img);
    $data = ob_get_clean();
    imagedestroy($img);
    return 'data:image/png;base64,' . base64_encode($data);
}

// ---------- 二维码生成（临时文件法） ----------
// $version > 0 时固定最小版本号（短内容也会升到该版本；内容过长时库会自动继续加版本）
function qrCodeToBase64($content, $pixelSize, $version = 0) {
    if (!extension_loaded('gd') || !function_exists('imagepng')) {
        return false;
    }
    if (!class_exists('QRcode') || !method_exists('QRcode', 'png')) {
        return false;
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'qr_');
    if ($tempFile === false) {
        return false;
    }

    try {
        if ($version > 0 && class_exists('QRencode') && method_exists('QRencode', 'factory')) {
            $enc = QRencode::factory(QR_ECLEVEL_L, $pixelSize, 2);
            $enc->version = (int)$version;
            $enc->encodePNG($content, $tempFile);
        } else {
            QRcode::png($content, $tempFile, QR_ECLEVEL_L, $pixelSize, 2);
        }
        $imageData = file_get_contents($tempFile);
        unlink($tempFile);
        if (empty($imageData)) {
            return false;
        }
        return 'data:image/png;base64,' . base64_encode($imageData);
    } catch (Exception $e) {
        @unlink($tempFile);
        return false;
    }
}

// ---------- 生成二维码 ----------
$qrData = false;
if ($mode == 2) {
    // 模式2 固定 29×29（版本3）：保证定位块之间间隔 15 格，与星巴克等参照样式一致
    $qrData = qrCodeToBase64($content, $pixelSize, 3);
} elseif ($mode == 3) {
    $qrData = qrCodeToBase64($content, $pixelSize);
}

// ---------- 输出 HTML ----------
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>码生成器</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f5f7fa; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; padding:20px; }
        .card { max-width:650px; width:100%; background:#fff; border-radius:20px; padding:30px 24px; box-shadow:0 8px 30px rgba(0,0,0,0.06); text-align:center; border:1px solid rgba(0,0,0,0.03); }
        .code-img { max-width:100%; height:auto; display:block; margin:0 auto; }
        .barcode-img { max-height:220px; }
        .qrcode-img { max-width:340px; max-height:340px; width:auto; height:auto; }
        .combine-barcode { max-height:160px; }
        .barcode-img, .combine-barcode { width:auto; height:auto; max-width:100%; image-rendering:pixelated; }
        .combine-qrcode { max-width:210px; max-height:210px; width:auto; height:auto; }
        .text-lines { margin-top:18px; }
        .line { font-size:16px; font-weight:500; padding:4px 0; color:#1a2332; word-break:break-all; }
        .line:not(:last-child) { border-bottom:1px dashed #e8ebf0; padding-bottom:6px; margin-bottom:6px; }
        .divider { width:60%; height:2px; background:#e8ebf0; margin:16px auto; }
        .error-msg { color:#d32f2f; background:#fde8e8; padding:12px; border-radius:10px; margin:10px 0; }
        .compare-row { display:flex; gap:20px; justify-content:center; align-items:flex-start; flex-wrap:wrap; }
        .compare-box { border:1px solid #e2e6ee; border-radius:12px; padding:14px; text-align:center; max-width:100%; }
        .compare-tag { font-size:14px; font-weight:600; color:#42526e; margin-bottom:10px; }
    </style>
</head>
<body>
<div class="card">
<?php
// compare 只是对比视图，条形码本体仍用 auto
$barEnc = ($enc === '' || $enc === 'compare') ? 'auto' : $enc;

if ($mode == 1) {
    $barErr = code128Validate($content);
    if ($barErr !== '') {
        echo '<div class="error-msg">' . $barErr . '</div>';
    } elseif ($enc === 'compare') {
        $b64a = barcodeToBase64($content, 'auto');
        $b64c = barcodeToBase64($content, 'c');
        echo '<div class="compare-row">';
        echo '<div class="compare-box"><div class="compare-tag">A：自动（默认，平手取Start B）</div>';
        echo $b64a ? '<img class="code-img barcode-img" src="' . $b64a . '" alt="auto">' : '<div class="error-msg">生成失败</div>';
        echo '</div>';
        echo '<div class="compare-box"><div class="compare-tag">B：平手时强制切C（对比用）</div>';
        echo $b64c ? '<img class="code-img barcode-img" src="' . $b64c . '" alt="c">' : '<div class="error-msg">生成失败</div>';
        echo '</div></div>';
    } else {
        $b64 = barcodeToBase64($content, $barEnc);
        if ($b64 === false) {
            echo '<div class="error-msg">条形码生成失败，请检查 GD 扩展。</div>';
        } else {
            echo '<img class="code-img barcode-img" src="' . $b64 . '" alt="Code128">';
        }
    }
} elseif ($mode == 2) {
    if ($qrData === false) {
        echo '<div class="error-msg">二维码生成失败。请检查 GD 扩展和 qrcode.php 文件。</div>';
    } else {
        echo '<img class="code-img qrcode-img" src="' . $qrData . '" alt="QR Code">';
    }
} elseif ($mode == 3) {
    $barErr = code128Validate($content);
    $b64 = $barErr === '' ? barcodeToBase64($content, $barEnc) : false;
    if ($b64 === false) {
        echo '<div class="error-msg">' . ($barErr !== '' ? $barErr : '条形码生成失败，请检查 GD 扩展。') . '</div>';
    } else {
        echo '<img class="code-img combine-barcode" src="' . $b64 . '" alt="Code128">';
    }
    echo '<div class="divider"></div>';
    if ($qrData === false) {
        echo '<div class="error-msg">二维码生成失败。请检查 GD 扩展和 qrcode.php 文件。</div>';
    } else {
        echo '<img class="code-img combine-qrcode" src="' . $qrData . '" alt="QR Code">';
    }
} else {
    echo '未知模式';
}

if (!empty($lines)) {
    echo '<div class="text-lines">';
    foreach ($lines as $line) {
        echo '<div class="line">' . htmlspecialchars($line) . '</div>';
    }
    echo '</div>';
}
?>
</div>
</body>
</html>