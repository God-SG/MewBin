<?php

class MewBinWAF_Ultimate {
    private $blockedIPs = [];
    private $requestCounts = [];
    private $threatLevels = [];
    private $logFile = 'waf_security.log';
    private $blockFile = 'waf_ip_blocks.dat';
    private $maxRequestsPerMinute = 30;
    private $maxRequestsPerHour = 500;
    private $maxFailedLogins = 3;
    
    // malicious patterns - fuck dem hekkers!
    private $maliciousPatterns = [
        '/union[\s\S]{1,100}select/i',
        '/insert[\s\S]{1,100}into/i',
        '/update[\s\S]{1,100}set/i',
        '/delete[\s\S]{1,100}from/i',
        '/drop[\s\S]{1,100}(table|database)/i',
        '/or\s+1=1/i',
        '/exec(\s|\()/i',
        '/xp_cmdshell/i',
        '/load_file/i',
        '/outfile|dumpfile/i',
        '/benchmark\s*\(/i',
        '/sleep\s*\(/i',
        '/waitfor\s+delay/i',
        '/shutdown/i',
        '/--[\s\S]*$/m',
        '/\/\*[\s\S]*\*\//m',
        '/;\s*(drop|delete|update|insert)/i',
        '/<script[^>]*>/i',
        '/vbscript:/i',
        '/on\w+\s*=/i',
        '/alert\s*\(/i',
        '/confirm\s*\(/i',
        '/prompt\s*\(/i',
        '/document\./i',
        '/window\./i',
        '/eval\s*\(/i',
        '/setTimeout\s*\(/i',
        '/setInterval\s*\(/i',
        '/Function\s*\(/i',
        '/\.\.\//',
        '/\.\.\\\/',
        '/etc\/passwd/i',
        '/proc\/self/i',
        '/\/etc\/shadow/i',
        '/\/bin\/(sh|bash)/i',
        '/\/etc\/hosts/i',
        '/\/proc\/version/i',
        '/\|\s*\w+/',
        '/;\s*\w+/',
        '/`\s*\w+/',
        '/\$\s*\(/',
        '/\$\{/',
        '/base64_decode/i',
        '/gzinflate/i',
        '/eval\s*\(\s*base64_decode/i',
        '/system\s*\(/i',
        '/exec\s*\(/i',
        '/shell_exec\s*\(/i',
        '/passthru\s*\(/i',
        '/proc_open/i',
        '/popen/i',
        '/curl_exec/i',
        '/file_get_contents/i',
        '/fopen/i',
        '/readfile/i',
        '/include\s*\(/i',
        '/require\s*\(/i',
        '/php:\/\//i',
        '/data:\/\//i',
        '/expect:\/\//i',
        '/phar:\/\//i',
        '/zip:\/\//i',
        '/glob:\/\//i',
        '/\b(and|or)\b\s+[\d\w]\s*=\s*[\d\w]/i',
        '/order\s+by\s+[\d+]/i',
        '/group\s+by\s+[\d+]/i',
        '/into\s+(outfile|dumpfile)/i',
        '/load\s+data/i',
        '/0x[0-9a-f]+/i',
        '/char\s*\(/i',
        '/concat\s*\(/i',
        '/@@version/i',
        '/user\s*\(/i',
        '/current_user/i',
        '/database\s*\(/i',
        '/version\s*\(/i',
        '/information_schema/i',
        '/mysql\./i',
        '/pg_/i',
        '/mssql/i',
        '/oracle/i',
        '/sqlite/i',
        '/\b(XMLHttpRequest|ActiveXObject|fetch)\b/i',
        '/\b(FileReader|Blob|ArrayBuffer)\b/i',
        '/\b(WebRTC|RTCPeerConnection)\b/i',
        '/\b(ServiceWorker|Worker)\b/i',
        '/\b(crypto|getRandomValues)\b/i',
        '/\b(performance|memory)\b/i',
        '/\b(geolocation|GPS)\b/i',
        '/\b(device|orientation)\b/i',
        '/\b(bluetooth|nfc)\b/i',
        '/\b(gps|location)\b/i',
        '/\b(network|connection)\b/i',
        '/\b(ip|mac)\b/i',
        '/\b(port|socket)\b/i',
        '/\b(http|https|ftp)\b.*(get|post)/i',
        '/\b(ws|wss)\b/i',
        '/\b(eval|setTimeout)\b/i',
        '/\b(document|window)\b/i',
        '/\b(localStorage|sessionStorage)\b/i',
        '/\b(cookie|indexedDB)\b/i',
        '/\b(history|pushState)\b/i',
        '/\b(beforeunload|unload)\b/i',
        '/\b(resize|scroll)\b/i',
        '/\b(mouse|click)\b/i',
        '/\b(key|keydown)\b/i',
        '/\b(touch|gesture)\b/i',
        '/\b(drag|drop)\b/i',
        '/\b(input|change)\b/i',
        '/\b(load|error)\b/i',
        '/\b(online|offline)\b/i',
        '/\b(install|activate)\b/i',
        '/\b(notification|permission)\b/i',
        '/\b(clipboard|copy)\b/i',
        '/\b(vibration)\b/i',
        '/\b(beacon|ping)\b/i',
        '/\b(cache|storage)\b/i',
        '/\b(applicationCache)\b/i',
        '/\b(serviceWorker)\b/i',
        '/\b(webworker)\b/i',
        '/\b(pwa)\b/i',
        '/\b(manifest)\b/i',
        '/\b(install)\b/i',
        '/\b(webapk)\b/i',
        '/\b(notification)\b/i',
        '/\b(push)\b/i',
        '/\b(background)\b/i',
        '/\b(suspend)\b/i',
        '/\b(resume)\b/i',
        '/\b(memory)\b/i',
        '/\b(heap)\b/i',
        '/\b(cpu)\b/i',
        '/\b(processor)\b/i',
        '/\b(gpu)\b/i',
        '/\b(storage)\b/i',
        '/\b(battery)\b/i',
        '/\b(power)\b/i',
        '/\b(thermal)\b/i',
        '/\b(temperature)\b/i',
        '/\b(volume)\b/i',
        '/\b(play)\b/i',
        '/\b(pause)\b/i',
        '/\b(crossorigin)\b/i',
        '/\b(anonymous)\b/i',
        '/\b(referrer)\b/i',
        '/\b(integrity)\b/i',
        '/\b(csp)\b/i',
        '/\b(content-security-policy)\b/i',
        '/\b(hsts)\b/i',
        '/\b(xss)\b/i',
        '/\b(x-frame-options)\b/i',
        '/\b(feature-policy)\b/i',
        '/\b(permissions-policy)\b/i',
        '/\b(upgrade-insecure-requests)\b/i',
        '/\b(wasm)\b/i',
        '/\b(webassembly)\b/i',
        '/\b(emscripten)\b/i',
        '/\b(vr|ar|mr)\b/i',
        '/\b(webxr|webvr)\b/i',
        '/\b(sensor)\b/i',
        '/\b(accelerometer)\b/i',
        '/\b(gyroscope)\b/i',
        '/\b(magnetometer)\b/i',
        '/\b(compass)\b/i',
        '/\b(ambient)\b/i',
        '/\b(light)\b/i',
        '/\b(proximity)\b/i',
        '/\b(heart)\b/i',
        '/\b(rate)\b/i',
        '/\b(bluetooth)\b/i',
        '/\b(ble)\b/i',
        '/\b(nfc)\b/i',
        '/\b(wifi)\b/i',
        '/\b(cellular)\b/i',
        '/\b(gps)\b/i',
        '/\b(port)\b/i',
        '/\b(socket)\b/i',
        '/\b(tcp)\b/i',
        '/\b(udp)\b/i',
        '/\b(dns)\b/i',
        '/\b(domain)\b/i',
        '/\b(websocket)\b/i',
        '/\b(webrtc)\b/i',
        '/\b(rtcpeerconnection)\b/i',
        '/\b(stream)\b/i',
        '/\b(track)\b/i',
        '/\b(codec)\b/i',
        '/\b(encoder)\b/i',
        '/\b(latency)\b/i',
        '/\b(jitter)\b/i',
        '/\b(packet)\b/i',
        '/\b(bandwidth)\b/i',
        '/\b(throughput)\b/i',
        '/\b(buffer)\b/i',
        '/\b(cache)\b/i',
        '/\b(memory)\b/i',
        '/\b(heap)\b/i',
        '/\b(stack)\b/i',
        '/\b(pointer)\b/i',
        '/\b(malloc)\b/i',
        '/\b(free)\b/i',
        '/\b(garbage)\b/i',
        '/\b(leak)\b/i',
        '/\b(overflow)\b/i',
        '/\b(underflow)\b/i',
        '/\b(race)\b/i',
        '/\b(condition)\b/i',
        '/\b(deadlock)\b/i',
        '/\b(semaphore)\b/i',
        '/\b(mutex)\b/i',
        '/\b(lock)\b/i',
        '/\b(thread)\b/i',
        '/\b(process)\b/i',
        '/\b(async)\b/i',
        '/\b(await)\b/i',
        '/\b(promise)\b/i',
        '/\b(observable)\b/i',
        '/\b(hash)\b/i',
        '/\b(map)\b/i',
        '/\b(set)\b/i',
        '/\b(list)\b/i',
        '/\b(array)\b/i',
        '/\b(tree)\b/i',
        '/\b(graph)\b/i',
        '/\b(stack)\b/i',
        '/\b(queue)\b/i',
        '/\b(heap)\b/i',
        '/\b(linked-list)\b/i',
        '/\b(binary)\b/i',
        '/\b(avl)\b/i',
        '/\b(red-black)\b/i',
        '/\b(b-tree)\b/i',
        '/\b(trie)\b/i',
        '/\b(bloom)\b/i',
        '/\b(filter)\b/i',
        '/\b(sketch)\b/i',
        '/\b(quantile)\b/i',
        '/\b(histogram)\b/i',
        '/\b(distribution)\b/i',
        '/\b(mean)\b/i',
        '/\b(median)\b/i',
        '/\b(variance)\b/i',
        '/\b(correlation)\b/i',
        '/\b(regression)\b/i',
        '/\b(clustering)\b/i',
        '/\b(encoding)\b/i',
        '/\b(decoding)\b/i',
        '/\b(embedding)\b/i',
        '/\b(vector)\b/i',
        '/\b(similarity)\b/i',
        '/\b(distance)\b/i',
        '/\b(euclidean)\b/i',
        '/\b(manhattan)\b/i',
        '/\b(cosine)\b/i',
        '/\b(jaccard)\b/i',
        '/\b(hamming)\b/i',
        '/\b(levenshtein)\b/i',
        '/\b(pearson)\b/i',
        '/\b(spearman)\b/i',
        '/\b(entropy)\b/i',
        '/\b(cross-entropy)\b/i',
        '/\b(chi-square)\b/i',
        '/\b(fisher)\b/i',
        '/\b(t-test)\b/i',
        '/\b(anova)\b/i',
        '/\b(kolmogorov-smirnov)\b/i',
        '/\b(shapiro-wilk)\b/i',
        '/bot|spider|crawl|scraper/i',
        '/curl|wget|python|java|php-client/i'
    ];
    
    // Sussy user agents - all the hekker tools
    private $suspiciousUserAgents = [
        'sqlmap', 'nmap', 'metasploit', 'burpsuite', 'nikto',
        'w3af', 'acunetix', 'appscan', 'nessus', 'openvas',
        'havij', 'zap', 'dirbuster', 'gobuster', 'wfuzz',
        'hydra', 'medusa', 'patator', 'crowbar', 'thc-hydra',
        'aircrack', 'reaver', 'bully', 'pyrit', 'cowpatty',
        'kismet', 'wireshark', 'tshark', 'tcpdump', 'ncat',
        'netcat', 'socat', 'curl', 'wget', 'python-requests',
        'python-urllib', 'go-http-client', 'java', 'perl',
        'ruby', 'lwp', 'mechanize', 'phantomjs', 'slimerjs',
        'casperjs', 'nightmare', 'puppeteer', 'playwright',
        'selenium', 'webdriver', 'chromedriver', 'geckodriver',
        'iedriver', 'safaridriver', 'appium', 'robotframework',
        'testcafe', 'cypress', 'protractor', 'karma', 'jasmine',
        'mocha', 'jest', 'ava', 'tape', 'qunit', 'enzyme',
        'sinon', 'chai', 'should', 'expect', 'assert',
        'powerassert', 'unexpected', 'proxy', 'tor',
        'anonymizer', 'shield', 'guard', 'firewall', 'ips',
        'ids', 'waf', 'scanner', 'crawler', 'spider', 'bot'
    ];
    
    // Whitelisted IPs - just incase ;)
    private $whitelisted_ips = [
        '127.0.0.1',
        '::1'
    ];
    
    // Blacklisted IPs - fuck these hekkers!
    private $blacklisted_ips = [];
    
    public function __construct() {
        $this->loadBlockedIPs();
        $this->cleanupOldData();
        $this->applySecurityHeaders();
    }
    
    public function protect() {
        // Anti-console protection - fuck dem skids!
        $this->antiConsole();
        
        // Get client information - just incase ;)
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Check if IP is blocked!
        if ($this->isIPBlocked($ip)) {
            $this->logThreat($ip, "Blocked IP attempted access", "HIGH");
            $this->showBlockPage("Your IP address has been temporarily blocked due to suspicious activity.");
            exit;
        }
        
        // Rate limiting check!
        if (!$this->checkRateLimit($ip)) {
            $this->blockIP($ip, 30);
            $this->logThreat($ip, "Rate limit exceeded", "MEDIUM");
            $this->showBlockPage("Too many requests from your IP address. Please try again later.");
            exit;
        }
        
        // User agent analysis!
        if ($this->isSuspiciousUserAgent($userAgent)) {
            $this->logThreat($ip, "Suspicious user agent: " . $userAgent, "MEDIUM");
            $this->increaseThreatLevel($ip, 2);
        }
        
        // Input validation - stop that skid!
        if (!$this->validateInputs()) {
            $this->logThreat($ip, "Malicious input detected", "HIGH");
            $this->increaseThreatLevel($ip, 3);
            $this->showBlockPage("Suspicious activity detected. Your request has been blocked.");
            exit;
        }
        
        // Pattern detection - catch all the bad stuff
        if ($this->detectMaliciousPatterns($requestUri, $_GET, $_POST)) {
            $this->logThreat($ip, "Malicious patterns detected in request", "HIGH");
            $this->blockIP($ip, 60);
            $this->showBlockPage("Malicious activity detected. Your IP has been blocked.");
            exit;
        }
        
        // Account creation protection - hopefully stops spam botting :p
        if (strpos($requestUri, 'register.php') !== false || strpos($requestUri, 'signup') !== false) {
            if (!$this->validateAccountCreation($ip)) {
                $this->logThreat($ip, "Suspicious account creation attempt", "MEDIUM");
                $this->showBlockPage("Account creation temporarily restricted from your IP.");
                exit;
            }
        }
        
        $this->updateThreatLevel($ip);
    }
    
    private function antiConsole() {
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            echo '<script>
            // Anti-console protection
            (function() {
                var methods = ["log", "debug", "info", "warn", "error", "assert", "clear", "dir", "dirxml", "table", "trace", "group", "groupCollapsed", "groupEnd", "count", "countReset", "context"];
                methods.forEach(function(method) {
                    console[method] = function() {};
                });
                
                // Detect DevTools
                var element = new Image();
                Object.defineProperty(element, "id", {
                    get: function() {
                        window.location.reload();
                    }
                });
                console.log(element);
                
                // Block right-click
                document.addEventListener("contextmenu", function(e) {
                    e.preventDefault();
                    return false;
                });
                
                // Block F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U
                document.addEventListener("keydown", function(e) {
                    if (e.keyCode == 123 || 
                        (e.ctrlKey && e.shiftKey && e.keyCode == 73) ||
                        (e.ctrlKey && e.shiftKey && e.keyCode == 74) ||
                        (e.ctrlKey && e.keyCode == 85)) {
                        e.preventDefault();
                        return false;
                    }
                });
            })();
            </script>';
        }
    }
    
    private function checkRateLimit($ip) {
        $currentMinute = floor(time() / 60);
        $currentHour = floor(time() / 3600);
        
        $minuteKey = $ip . '_minute_' . $currentMinute;
        $hourKey = $ip . '_hour_' . $currentHour;
        
        if (!isset($this->requestCounts[$minuteKey])) $this->requestCounts[$minuteKey] = 0;
        if (!isset($this->requestCounts[$hourKey])) $this->requestCounts[$hourKey] = 0;
        
        $this->requestCounts[$minuteKey]++;
        $this->requestCounts[$hourKey]++;
        
        // Clean up old entries (older than 10 minutes) > Make site stay fast.
        foreach ($this->requestCounts as $timeKey => $count) {
            $parts = explode('_', $timeKey);
            $time = end($parts);
            $type = $parts[1];
            
            if (($type === 'minute' && $currentMinute - $time > 10) || 
                ($type === 'hour' && $currentHour - $time > 24)) {
                unset($this->requestCounts[$timeKey]);
            }
        }
        
        return $this->requestCounts[$minuteKey] <= $this->maxRequestsPerMinute &&
               $this->requestCounts[$hourKey] <= $this->maxRequestsPerHour;
    }
    
    private function validateInputs() {
        foreach ($_GET as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subValue) {
                    if (!$this->isSafeInput($subValue)) return false;
                }
            } else {
                if (!$this->isSafeInput($value)) return false;
            }
        }
        
        foreach ($_POST as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subValue) {
                    if (!$this->isSafeInput($subValue)) return false;
                }
            } else {
                if (!$this->isSafeInput($value)) return false;
            }
        }
        
        foreach ($_COOKIE as $key => $value) {
            if (!$this->isSafeInput($value)) return false;
        }
        
        return true;
    }
    
    private function isSafeInput($input) {
        if (is_array($input)) {
            foreach ($input as $value) {
                if (!$this->isSafeInput($value)) return false;
            }
            return true;
        }
        
        $input = strtolower($input);
        
        foreach ($this->maliciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return false;
            }
        }
        
        // Check for excessive length in single fields
        if (strlen($input) > 1000) {
            return false;
        }
        
        return true;
    }
    
    private function detectMaliciousPatterns($uri, $get, $post) {
        $allData = $uri . ' ' . implode(' ', $get) . ' ' . implode(' ', $post);
        
        foreach ($this->maliciousPatterns as $pattern) {
            if (preg_match($pattern, $allData)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isSuspiciousUserAgent($userAgent) {
        $userAgent = strtolower($userAgent);
        
        foreach ($this->suspiciousUserAgents as $suspicious) {
            if (strpos($userAgent, $suspicious) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function validateAccountCreation($ip) {
        $key = 'acc_create_' . $ip;
        $currentHour = floor(time() / 3600);
        
        if (!isset($this->requestCounts[$key])) {
            $this->requestCounts[$key] = ['count' => 0, 'hour' => $currentHour];
        }

        if ($this->requestCounts[$key]['hour'] != $currentHour) {
            $this->requestCounts[$key] = ['count' => 0, 'hour' => $currentHour];
        }
        
        $this->requestCounts[$key]['count']++;
        
        return $this->requestCounts[$key]['count'] <= 3; // Max 3 accounts per hour per IP
    }
    
    private function getClientIP() {
        $ip = $_SERVER['HTTP_CLIENT_IP'] ?? 
              $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_X_FORWARDED'] ?? 
              $_SERVER['HTTP_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_FORWARDED'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? 
              '0.0.0.0';

        if (strpos($ip, ',') !== false) {
            $ips = explode(',', $ip);
            $ip = trim($ips[0]);
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    private function isIPBlocked($ip) {
        if (in_array($ip, $this->whitelisted_ips)) return false;
        if (in_array($ip, $this->blacklisted_ips)) return true;
        
        if (isset($this->blockedIPs[$ip])) {
            if ($this->blockedIPs[$ip] > time()) {
                return true;
            } else {
                unset($this->blockedIPs[$ip]);
                $this->saveBlockedIPs();
            }
        }
        return false;
    }
    
    private function blockIP($ip, $minutes = 30) {
        $this->blockedIPs[$ip] = time() + ($minutes * 60);
        $this->saveBlockedIPs();
    }
    
    private function increaseThreatLevel($ip, $points = 1) {
        if (!isset($this->threatLevels[$ip])) {
            $this->threatLevels[$ip] = 0;
        }
        
        $this->threatLevels[$ip] += $points;
        
        // Auto-block if threat level too high
        if ($this->threatLevels[$ip] >= 10) {
            $this->blockIP($ip, 60);
        }
    }
    
    private function updateThreatLevel($ip) {
        if (isset($this->threatLevels[$ip])) {
            // Reduce threat level over time (1 point per hour)
            $this->threatLevels[$ip] = max(0, $this->threatLevels[$ip] - 0.00028);
        }
    }
    
    private function loadBlockedIPs() {
        if (file_exists($this->blockFile)) {
            $data = file_get_contents($this->blockFile);
            $this->blockedIPs = unserialize($data) ?: [];
        }
    }
    
    private function saveBlockedIPs() {
        file_put_contents($this->blockFile, serialize($this->blockedIPs), LOCK_EX);
    }
    
    private function cleanupOldData() {
        $currentTime = time();
        $cleaned = false;
        
        foreach ($this->blockedIPs as $ip => $expiry) {
            if ($expiry < $currentTime) {
                unset($this->blockedIPs[$ip]);
                $cleaned = true;
            }
        }
        
        if ($cleaned) {
            $this->saveBlockedIPs();
        }
    }
    
    private function applySecurityHeaders() {
        if (!headers_sent()) {
            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
    
    private function logThreat($ip, $reason, $severity) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$severity] $ip - $reason\n";
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    private function showBlockPage($message) {
        if (headers_sent()) return;
        
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Blocked - MewBin WAF</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    background: #000000;
                    color: #ffffff;
                    font-family: 'Courier New', monospace;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    overflow: hidden;
                    position: relative;
                }
                
                body::before {
                    content: '';
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: 
                        radial-gradient(circle at 10% 20%, rgba(90, 13, 122, 0.2) 0%, transparent 20%),
                        radial-gradient(circle at 90% 80%, rgba(138, 43, 226, 0.15) 0%, transparent 20%),
                        radial-gradient(circle at 50% 50%, rgba(58, 0, 82, 0.1) 0%, transparent 50%);
                    z-index: -1;
                }
                
                .block-container {
                    background: rgba(10, 0, 15, 0.95);
                    border: 2px solid #5a0d7a;
                    border-radius: 10px;
                    padding: 40px;
                    text-align: center;
                    max-width: 500px;
                    width: 90%;
                    box-shadow: 
                        0 0 30px rgba(90, 13, 122, 0.5),
                        inset 0 0 20px rgba(138, 43, 226, 0.1);
                    position: relative;
                    overflow: hidden;
                }
                
                .block-container::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    height: 4px;
                    background: linear-gradient(90deg, #5a0d7a, #8a2be2, #5a0d7a);
                    box-shadow: 0 0 10px #8a2be2;
                }
                
                .warning-icon {
                    font-size: 4rem;
                    color: #8a2be2;
                    margin-bottom: 20px;
                    text-shadow: 0 0 20px #8a2be2;
                    animation: pulse 2s infinite;
                }
                
                @keyframes pulse {
                    0%, 100% { opacity: 1; }
                    50% { opacity: 0.7; }
                }
                
                h1 {
                    color: #8a2be2;
                    margin-bottom: 20px;
                    font-size: 2rem;
                    text-shadow: 0 0 10px #8a2be2;
                }
                
                .message {
                    color: #cccccc;
                    margin-bottom: 30px;
                    line-height: 1.6;
                    font-size: 1.1rem;
                }
                
                .details {
                    background: rgba(90, 13, 122, 0.2);
                    border: 1px solid #5a0d7a;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
                    text-align: left;
                    font-size: 0.9rem;
                }
                
                .ip-address {
                    color: #8a2be2;
                    font-weight: bold;
                }
                
                .timestamp {
                    color: #cccccc;
                    font-size: 0.8rem;
                    margin-top: 10px;
                }
                
                .contact {
                    margin-top: 20px;
                    color: #999999;
                    font-size: 0.9rem;
                }
                
                .glitch {
                    position: relative;
                    display: inline-block;
                }
                
                .glitch::before,
                .glitch::after {
                    content: attr(data-text);
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                }
                
                .glitch::before {
                    left: 2px;
                    text-shadow: -2px 0 #ff00ff;
                    animation: glitch-anim 5s infinite linear alternate-reverse;
                }
                
                .glitch::after {
                    left: -2px;
                    text-shadow: -2px 0 #00ffff;
                    animation: glitch-anim2 5s infinite linear alternate-reverse;
                }
                
                @keyframes glitch-anim {
                    0% { clip: rect(31px, 9999px, 15px, 0); }
                    5% { clip: rect(12px, 9999px, 89px, 0); }
                    10% { clip: rect(46px, 9999px, 12px, 0); }
                    100% { clip: rect(8px, 9999px, 84px, 0); }
                }
                
                @keyframes glitch-anim2 {
                    0% { clip: rect(85px, 9999px, 95px, 0); }
                    5% { clip: rect(66px, 9999px, 21px, 0); }
                    100% { clip: rect(22px, 9999px, 81px, 0); }
                }
            </style>
        </head>
        <body>
            <div class="block-container">
                <div class="warning-icon">⚠️</div>
                <h1 class="glitch" data-text="ACCESS BLOCKED">ACCESS BLOCKED</h1>
                <h3 class="glitch" data-text="WAF BY SPADES">WAF BY SPADES</h3>
                <div class="message">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="details">
                    <strong>Client IP:</strong> <span class="ip-address"><?php echo htmlspecialchars($this->getClientIP(), ENT_QUOTES, 'UTF-8'); ?></span><br>
                    <strong>Reason:</strong> Security Policy Violation<br>
                    <strong>Action:</strong> Request Blocked by MewBin WAF
                </div>
                <div class="timestamp">
                    Blocked at: <?php echo date('Y-m-d H:i:s'); ?>
                </div>
                <div class="contact">
                    If you believe this is an error, contact the administrator.
                </div>
            </div>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    document.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                    });
                    
                    setInterval(function() {
                        debugger;
                    }, 100);
                });
            </script>
        </body>
        </html>
        <?php
        exit;
    }
}

$waf = new MewBinWAF_Ultimate();
$waf->protect();
?>