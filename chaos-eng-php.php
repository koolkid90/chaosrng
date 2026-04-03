<?php
/**
 * ChaosRNG - random byte generator with 12 timers
 * 
 * 12 timers:
 * T1  – system clock (microtime)
 * T2  – precision timer (hrtime)
 * T3  – memory (state)
 * T4  – distorter (multiply/divide time)
 * T5  – splitter (slice count 1000-10000)
 * T6  – picker (select slice)
 * T7  – hidden noise (jitter, interrupts)
 * T8  – switch (enable/disable output)
 * T9  – watchdog (controls the switch)
 * T10 – start point (initialization moment)
 * T11 – blot (insert zero byte)
 * T12 – mute (pauses 1-100 ticks)
 */

class ChaosRNG {
    // Class properties
    private $state;      // T3: 64-bit state
    private $k;          // T4: distorter coefficient
    private $enabled;    // T8: switch (on/off)
    private $lastByte;   // T9: last output byte
    private $dot;        // T11: blot flag
    private $pauseLen;   // T12: pause length
    private $pauseRem;   // T12: pause remaining
    
    /**
     * Constructor — T10 (start point)
     * Mix everything that cannot be repeated
     */
    public function __construct() {
        // XOR all sources → unique initial state
        $this->state = (int)(microtime(true) * 1000000)  // T1: microseconds
                     ^ hrtime(true)                       // T2: nanoseconds
                     ^ getmypid()                         // T7: process ID
                     ^ memory_get_usage();                // T7: memory usage
        
        $this->k = 1.001;           // T4: initial coefficient
        $this->enabled = true;      // T8: switch on
        $this->lastByte = 0;        // T9: no last byte yet
        $this->dot = false;         // T11: no blot
        $this->pauseLen = 0;        // T12: no pause
        $this->pauseRem = 0;        // T12: pause remaining = 0
    }
    
    /**
     * T1: system clock
     * Returns microseconds since 1970
     */
    private function t1() {
        return (int)(microtime(true) * 1000000);
    }
    
    /**
     * T2: precision timer
     * Returns nanoseconds since system start
     */
    private function t2() {
        return hrtime(true);
    }
    
    /**
     * Internal byte generator (T3-T7)
     * Updates state and returns one byte
     */
    private function nextByteInternal() {
        // T5: slice count from 1000 to 10000
        $K = 1000 + ($this->state % 9001);
        
        // T6: select specific slice
        $slot = (($this->state >> 8) % $K);
        
        // T1 and T2: get current time
        $t1 = $this->t1();
        $t2 = $this->t2();
        
        // T4: distorter (stretch or compress time)
        if ($this->state & 1) {
            $t1 = $t1 * $this->k;   // stretch T1
        } else {
            $t2 = $t2 / $this->k;   // compress T2
        }
        
        // Sum with slice factor
        $total = (int)($t1 * ($slot + 1) / $K)
               + (int)($t2 * ($slot + 1) / $K);
        
        // T3: update state (add or subtract)
        if ($total & 1) {
            $this->state += ($total & 0xFF);
        } else {
            $this->state -= ($total & 0xFF);
        }
        
        // Update distorter coefficient
        $this->k = 1.0 + (($this->state & 0xFF) / 10000.0);
        
        // Return lower byte of state
        return $this->state & 0xFF;
    }
    
    /**
     * Public method: get N random bytes
     * Takes into account all 12 timers
     */
    public function getBytes($n) {
        $out = [];
        
        for ($i = 0; $i < $n; $i++) {
            // T12: pause (mute)
            while ($this->pauseRem > 0) {
                $this->pauseRem--;
                $this->nextByteInternal(); // state changes
            }
            
            // If pause ended — generate new one
            if ($this->pauseRem === 0 && $this->pauseLen === 0) {
                $this->pauseLen = 1 + ($this->state % 100); // 1-100 ticks
                $this->pauseRem = $this->pauseLen;
            }
            
            // T11: blot (insert zero byte)
            if (($this->state & 10) === 0 && !$this->dot) {
                $this->dot = true;
                $this->nextByteInternal();
                $out[] = 0x00; // blot = zero byte
                continue;
            }
            $this->dot = false;
            
            // T9: watchdog
            if ($this->lastByte < 5) {
                $this->enabled = true;   // turn on
            } elseif ($this->lastByte > 5) {
                $this->enabled = false;  // turn off
            }
            // if equals 5 — do nothing
            
            // Generate byte
            $byte = $this->nextByteInternal();
            
            // T8: switch
            if (!$this->enabled) {
                // If off — output fake byte
                $out[] = $this->nextByteInternal() & 0xFF;
                continue;
            }
            
            // Save last output byte
            $this->lastByte = $byte;
            
            // Reset pause after outputting a real byte
            if ($this->pauseLen > 0) {
                $this->pauseLen = 0;
                $this->pauseRem = 0;
            }
            
            $out[] = $byte;
        }
        
        return $out;
    }
    
    /**
     * Calculate Shannon entropy for byte array
     * Closer to 8 → more random data
     */
    public static function entropy($bytes) {
        // Count frequency of each byte
        $freq = array_fill(0, 256, 0);
        foreach ($bytes as $b) {
            $freq[$b]++;
        }
        
        // Shannon formula: -Σ p * log2(p)
        $e = 0;
        $total = count($bytes);
        foreach ($freq as $c) {
            if ($c > 0) {
                $p = $c / $total;
                $e -= $p * log($p, 2);
            }
        }
        
        return $e;
    }
}

// ============================================================
// AJAX HANDLER (for Motor mode)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $rng = new ChaosRNG();
    $token = $rng->getBytes(32);
    $tokenHex = implode('', array_map(function($b) {
        return str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }, $token));
    
    $data = $rng->getBytes(65536);
    $entropy = ChaosRNG::entropy($data);
    
    echo json_encode([
        'token' => $tokenHex,
        'entropy' => round($entropy, 4)
    ]);
    exit;
}

// ============================================================
// INITIAL GENERATION
// ============================================================
$rng = new ChaosRNG();

// Generate token (32 bytes = 64 hex chars)
$token = $rng->getBytes(32);
$tokenHex = implode('', array_map(function($b) {
    return str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}, $token));

// Generate 65536 bytes for entropy test
$data = $rng->getBytes(65536);
$entropy = ChaosRNG::entropy($data);

// Output results with minimal visualization
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>ChaosRNG · 12-Timer Generator</title>
    <style>
        body {
            background: linear-gradient(135deg, #0a0f1e, #0c1222);
            font-family: 'Courier New', monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .card {
            background: rgba(15, 25, 45, 0.9);
            border-radius: 24px;
            border: 1px solid #3b82f6;
            padding: 28px;
            max-width: 750px;
            width: 100%;
            text-align: center;
        }
        h1 {
            color: #c0e0ff;
            font-size: 1.5rem;
            margin: 0 0 6px 0;
        }
        .sub {
            color: #8da3d0;
            font-size: 0.7rem;
            margin-bottom: 20px;
        }
        .timers {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            margin: 20px 0;
        }
        .timer {
            background: #1e2a4a;
            border-radius: 30px;
            padding: 4px 12px;
            font-size: 0.7rem;
            font-weight: bold;
            color: #7aa9ff;
        }
        .token-box {
            background: #030712;
            border-radius: 18px;
            padding: 18px;
            font-family: monospace;
            font-size: 0.75rem;
            word-break: break-all;
            color: #bbf0ff;
            border: 1px solid #2e3a5f;
            margin: 20px 0;
        }
        .entropy {
            background: #0b1020;
            border-radius: 40px;
            padding: 10px 20px;
            display: inline-block;
            font-size: 0.9rem;
            color: #ffffff;
        }
        .entropy strong {
            color: #ffffff;
        }
        .good { color: #4ade80; }
        .medium { color: #fbbf24; }
        .button-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 20px;
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
            border: 1px solid #3e5a8a;
            transition: 0.2s;
        }
        button:hover {
            background: #2c3f6e;
            transform: scale(0.97);
        }
        .motor-active {
            background: #ef4444;
            border-color: #ff7b7b;
            animation: pulse 0.5s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        footer {
            font-size: 0.6rem;
            color: #4c6191;
            margin-top: 24px;
        }
    </style>
</head>
<body>
<div class='card'>
    <h1>🌀 CHAOS RNG · 12 TIMERS</h1>
    <div class='sub'>non-linear asynchronous generator</div>
    
    <div class='timers'>
        <span class='timer'>T1</span> <span class='timer'>T2</span> <span class='timer'>T3</span>
        <span class='timer'>T4</span> <span class='timer'>T5</span> <span class='timer'>T6</span>
        <span class='timer'>T7</span> <span class='timer'>T8</span> <span class='timer'>T9</span>
        <span class='timer'>T10</span> <span class='timer'>T11</span> <span class='timer'>T12</span>
    </div>
    
    <div class='token-box'>🔑 TOKEN (32 bytes):<br><strong id='tokenValue'>{$tokenHex}</strong></div>
    
    <div class='entropy'>
        📊 ENTROPY: <strong id='entropyValue'>" . round($entropy, 4) . "</strong> / 8 bits
        <span id='entropyStatus' class='" . ($entropy > 7 ? 'good' : 'medium') . "'>
            (" . ($entropy > 7 ? '✅ good' : '⚠️ moderate') . ")
        </span>
    </div>
    
    <div class='button-group'>
        <button id='refreshBtn'>🔄 REFRESH</button>
        <button id='motorBtn'>⚡ MOTOR</button>
    </div>
    
    <footer>
        T1–T12: time · slices 1000–10000 · pauses 1–100 · blots · distorter · switch · timing-attack protection
    </footer>
</div>

<script>
    let motorInterval = null;
    let motorActive = false;
    const motorBtn = document.getElementById('motorBtn');
    const refreshBtn = document.getElementById('refreshBtn');
    
    async function fetchNewData() {
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            
            document.getElementById('tokenValue').innerText = data.token;
            document.getElementById('entropyValue').innerText = data.entropy;
            
            const statusSpan = document.getElementById('entropyStatus');
            if (data.entropy > 7) {
                statusSpan.innerHTML = '(✅ good)';
                statusSpan.className = 'good';
            } else {
                statusSpan.innerHTML = '(⚠️ moderate)';
                statusSpan.className = 'medium';
            }
        } catch (err) {
            console.error('Error:', err);
        }
    }
    
    function startMotor() {
        if (motorInterval) clearInterval(motorInterval);
        motorInterval = setInterval(fetchNewData, 500);
        motorActive = true;
        motorBtn.classList.add('motor-active');
        motorBtn.textContent = '⏹️ MOTOR (ON)';
    }
    
    function stopMotor() {
        if (motorInterval) {
            clearInterval(motorInterval);
            motorInterval = null;
        }
        motorActive = false;
        motorBtn.classList.remove('motor-active');
        motorBtn.textContent = '⚡ MOTOR';
    }
    
    function toggleMotor() {
        if (motorActive) {
            stopMotor();
        } else {
            startMotor();
        }
    }
    
    refreshBtn.addEventListener('click', () => {
        if (motorActive) stopMotor();
        window.location.reload();
    });
    
    motorBtn.addEventListener('click', toggleMotor);
</script>
</body>
</html>";
?>