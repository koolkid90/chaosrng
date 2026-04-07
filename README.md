# chaosrng
ChaosRNG is an experimental random byte generator built on 12 independent timers, non‑linear transformations, and feedback loops. It does not rely on standard PRNGs (mt_rand, random_bytes) or special hardware — just JavaScript and a browser. Not for cryptographic use without independent audit.

* ⚠️ LEGAL NOTICE:
 * ChaosRNG is a research/experimental generator.
 * It is NOT certified for cryptographic use under FIPS 140-3, 
 * NIST SP 800-90A, or other regulatory standards.
 * Use at your own risk. For production crypto, prefer:
 * - Web Crypto API (crypto.getRandomValues)
 * - libsodium, OpenSSL, or other audited libraries.
 */

 ------------------------------------------------------------------------------------------------------------
🔧 How it works

The generator mixes two independent time sources (Date.now and performance.now), passes them through a distorter, splits into a random number of slices (1000–10000), adds random pauses (T12), random zero bytes (T11), and a controlled switch (T8/T9) that protects against timing attacks.

The internal state (T3) is 64‑bit, updated non‑linearly and irreversibly.

🧠 12 timers
Timer	Name	Function |||
T1	System Clock	Date.now() — milliseconds since 1970 |||
T2	Precision Timer	performance.now() — microseconds |||
T3	Memory	64‑bit internal state |||
T4	Distorter	Time distortion (multiply/divide) |||
T5	Splitter	Slice count (1000–10000) |||
T6	Picker	Selects a specific slice |||
T7	Hidden Noise	Jitter, interrupts, OS noise |||
T8	Switch	Enables/disables byte output |||
T9	Watchdog	Controls the switch based on the last byte |||
T10	Start Point	Initialization moment (unique) |||
T11	Blot	Random 0x00 insertion |||
T12	Mute	Pauses of 1–100 ticks |||

📊 Entropy

In a browser, entropy reaches 6.6–7.1 bits per byte (depending on the environment).
In Node.js — up to 7.2–7.3 bits.
Tests are performed on 65,536 bytes using the Shannon formula.

🚀 Features

    AES‑256 key generation (32 bytes / 64 hex)

    Password generation (16 characters from a 95‑symbol set)

    PIN code generation (6 digits)

    Real‑time Shannon entropy calculation

    Real‑time visualization of all 12 timers

    Timing‑attack protection (T8, T9, T12)

🖥 Usage

Simply open index.html in a browser. No dependencies, builds, or servers required.

⚠️ Important

This project is experimental and not certified. Not recommended for use in systems that require official cryptographic approval (banking, medical, government). For educational purposes and experiments — feel free to use it.
📄 License

MIT — free to use, modify, and distribute.

Attention: Built using the power of vibe coding :)

Author: Pavel Bobkin
Github: koolkid90


