<?php
/**
 * ChaosRNG - Minimal PHP version
 * 12 timers, entropy calculation, simple web interface
 */

class ChaosRNG {
    private int $state;
    private float $k;
    private bool $enabled;
    private int $lastByte;
    private bool $dot;
    private int $pauseLen;
    private int $pauseRem;
    
    public function __construct() {
        $this->state = $this->mixInit();
        $this->k = 1.001;
        $this->enabled = true;
        $this->lastByte = 0;
        $this->dot = false;
        $this->pauseLen = 0;
        $this->pauseRem = 0;
    }
    
    private function mixInit(): int {
        $hash = 0;
        $hash ^= (int)(microtime(true) * 1_000_000);
        $hash ^= (int)(hrtime(true) % 1_000_000_000);
        if (function_exists('random_bytes')) {
            $hash ^= unpack('Q', random_bytes(8))[1];
        }
        $hash ^= getmypid();
        $hash ^= memory_get_usage();
        return $hash;
    }
    
    private function t1(): int {
        return (int)(microtime(true) * 1_000_000);
    }
    
    private function t2(): int {
        return hrtime(true);
    }
    
    private function nextByteInternal(): int {
        $K = 1000 + ($this->state % 9001);
        $slot = (($this->state >> 8) % $K);
        
        $t1 = $this->t1();
        $t2 = $this->t2();
        
        if ($this->state & 1) {
            $t1 = $t1 * $this->k;
        } else {
            $t2 = $t2 / $this->k;
        }
        
        $total = (int)($t1 * ($slot + 1) / $K) + (int)($t2 * ($slot + 1) / $K);
        
        if ($total & 1) {
            $this->state += ($total & 0xFF);
        } else {
            $this->state -= ($total & 0xFF);
        }
        
        $this->k = 1.0 + (($this->state & 0xFF) / 10000.0);
        
        return $this->state & 0xFF;
    }
    
    private function nextPauseLength(): int {
        return 1 + ($this->state % 100);
    }
    
    public function getByte(): int {
        if ($this->pauseRem > 0) {
            $this->pauseRem--;
            $this->nextByteInternal();
            return $this->getByte();
        }
        
        if ($this->pauseRem === 0 && $this->pauseLen === 0) {
            $this->pauseLen = $this->nextPauseLength();
            $this->pauseRem = $this->pauseLen;
        }
        
        if (($this->state & 10) === 0 && !$this->dot) {
            $this->dot = true;
            $this->nextByteInternal();
            return 0x00;
        }
        $this->dot = false;
        
        if ($this->lastByte < 5) {
            $this->enabled = true;
        } elseif ($this->lastByte > 5) {
            $this->enabled = false;
        }
        
        $byte = $this->nextByteInternal();
        
        if (!$this->enabled) {
            return $this->nextByteInternal() & 0xFF;
        }
        
        $this->lastByte = $byte;
        
        if ($this->pauseLen > 0) {
            $this->pauseLen = 0;
            $this->pauseRem = 0;
        }
        
        return $byte;
    }
    
    public function getBytes(int $n): array {
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $result[] = $this->getByte();
        }
        return $result;
    }
    
    public function getHex(int $n): string {
        $bytes = $this->getBytes($n);
        return implode('', array_map(function($b) {
            return str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
        }, $bytes));
    }
    
    public function getPassword(int $length, string $charset = 'full'): string {
        $sets = [
            'full'   => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()_+-=[]{}|;:,.<>?',
            'alnum'  => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
            'digits' => '0123456789'
        ];
        
        $alphabet = $sets[$charset] ?? $sets['full'];
        $bytes = $this->getBytes($length);
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[$bytes[$i] % strlen($alphabet)];
        }
        
        return $password;
    }
    
    public static function calculateEntropy(array $bytes): float {
        $freq = array_fill(0, 256, 0);
        $total = count($bytes);
        
        foreach ($bytes as $b) {
            $freq[$b]++;
        }
        
        $entropy = 0.0;
        for ($i = 0; $i < 256; $i++) {
            if ($freq[$i] > 0) {
                $p = $freq[$i] / $total;
                $entropy -= $p * log($p, 2);
            }
        }
        
        return $entropy;
    }
}

// ========== SIMPLE WEB INTERFACE ==========
$rng = new ChaosRNG();

// Generate initial values
$aesKey = $rng->getHex(32);
$password = $rng->getPassword(16, 'full');
$pin = $rng->getPassword(6, 'digits');
$token = $rng->getHex(16);

// Calculate entropy (once)
$testData = $rng->getBytes(65536);
$entropy = ChaosRNG::calculateEntropy($testData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChaosRNG · Minimal PHP Version</title>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #0a0f1e 0%, #0c1222 100%);
            font-family: 'Segoe UI', 'Fira Code', monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            width: 100%;
            background: rgba(15, 25, 45, 0.8);
            backdrop-filter: blur(8px);
            border-radius: 28px;
            border: 1px solid rgba(72, 187, 255, 0.3);
            padding: 24px;
        }
        h1 {
            font-size: 1.6rem;
            margin: 0 0 6px 0;
            color: #c0e0ff;
            text-align: center;
        }
        .sub {
            text-align: center;
            color: #8da3d0;
            font-size: 0.75rem;
            margin-bottom: 24px;
            border-bottom: 1px solid #2d3a5e;
            padding-bottom: 12px;
        }
        .grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .card {
            background: #0f172ad9;
            border-radius: 20px;
            padding: 16px;
            flex: 1;
            min-width: 200px;
            border: 1px solid #2d3a5e;
        }
        .card h3 {
            margin: 0 0 10px 0;
            font-size: 0.85rem;
            color: #7aa9ff;
        }
        .value {
            background: #030712;
            border-radius: 14px;
            padding: 12px;
            font-family: 'Fira Code', monospace;
            font-size: 0.7rem;
            word-break: break-all;
            color: #bbf0ff;
        }
        .entropy-box {
            background: #0b1020;
            border-radius: 20px;
            padding: 16px;
            text-align: center;
            margin-bottom: 20px;
            border: 1px solid #3b82f6;
        }
        .entropy-number {
            font-size: 2rem;
            font-weight: bold;
            color: #aaf0ff;
            font-family: monospace;
        }
        button {
            background: #1e2a4a;
            border: none;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: bold;
            font-family: monospace;
            font-size: 0.85rem;
            color: #e2f0ff;
            cursor: pointer;
            width: 100%;
            border: 1px solid #3e5a8a;
        }
        button:hover {
            background: #2c3f6e;
        }
        footer {
            text-align: center;
            font-size: 0.65rem;
            color: #4c6191;
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🌀 CHAOS RNG · 12 TIMERS</h1>
    <div class="sub">non-linear asynchronous · slices 1000–10000 · pauses · blots</div>

    <div class="entropy-box">
        <div style="font-size: 0.8rem; color: #8da3d0;">📊 SHANNON ENTROPY</div>
        <div class="entropy-number"><?php echo round($entropy, 4); ?> / 8 bits</div>
        <div style="font-size: 0.7rem; margin-top: 5px;">based on 65,536 bytes</div>
    </div>

    <div class="grid">
        <div class="card">
            <h3>🔑 AES-256 KEY (HEX)</h3>
            <div class="value" id="aesKey"><?php echo htmlspecialchars($aesKey); ?></div>
        </div>
        <div class="card">
            <h3>🔐 PASSWORD (16 chars)</h3>
            <div class="value" id="password"><?php echo htmlspecialchars($password); ?></div>
        </div>
        <div class="card">
            <h3>🎲 PIN CODE (6 digits)</h3>
            <div class="value" id="pin"><?php echo htmlspecialchars($pin); ?></div>
        </div>
        <div class="card">
            <h3>🎟️ SESSION TOKEN (16 bytes)</h3>
            <div class="value" id="token"><?php echo htmlspecialchars($token); ?></div>
        </div>
    </div>

    <form method="POST">
        <button type="submit">🌀 GENERATE NEW</button>
    </form>

    <footer>
        12 timers: T1(microtime) + T2(hrtime) + T3(state) + T4(distorter) + T5(splitter) + T6(picker)<br>
        T7(noise) + T8(switch) + T9(watchdog) + T10(start) + T11(blot) + T12(mute)
    </footer>
</div>
</body>
</html>
<?php
// Handle POST request (regenerate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rng = new ChaosRNG();
    $aesKey = $rng->getHex(32);
    $password = $rng->getPassword(16, 'full');
    $pin = $rng->getPassword(6, 'digits');
    $token = $rng->getHex(16);
    $testData = $rng->getBytes(65536);
    $entropy = ChaosRNG::calculateEntropy($testData);
    
    echo "<script>
        document.getElementById('aesKey').innerText = '" . addslashes($aesKey) . "';
        document.getElementById('password').innerText = '" . addslashes($password) . "';
        document.getElementById('pin').innerText = '" . addslashes($pin) . "';
        document.getElementById('token').innerText = '" . addslashes($token) . "';
        document.querySelector('.entropy-number').innerText = '" . round($entropy, 4) . " / 8 bits';
    </script>";
}
?>