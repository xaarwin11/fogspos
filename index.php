<?php
require_once 'db.php';
session_start();

$remote_ip = $_SERVER['REMOTE_ADDR'];
$is_local = (strpos($remote_ip, '192.168.') === 0 || $remote_ip === '127.0.0.1' || $remote_ip === '::1');

/* if (!$is_local) {
    // This is a customer from the internet! Send them to the public menu.
    header("Location: public/");
    exit;
} */

// 2. If they are already logged in, send them straight to the POS
if (isset($_SESSION['user_id'])) {
    header("Location: pos/");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>FogsTasa's Cafe'</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/main.css">
    <script src="assets/js/sweetalert2.js"></script> 
    <style>
        .pin-btn { touch-action: manipulation; }
    </style>
</head>

<body class="login-page">

    <div class="login-card landscape-card">
        <div class="login-left">
            <img src="assets/img/logo.jpg" class="logo-img" alt="Logo">
            <h2 style="margin-bottom: 5px;">FogsTasa's Cafe</h2>
            <p style="color:var(--text-muted); margin-top:0; margin-bottom: 20px;">Staff Login</p>
            
            <input type="password" id="display" class="pin-display" readonly 
                   placeholder="••••" inputmode="numeric" autocomplete="one-time-code">
        </div>
        
        <div class="pin-grid">
            <button class="pin-btn" onclick="add(1)">1</button>
            <button class="pin-btn" onclick="add(2)">2</button>
            <button class="pin-btn" onclick="add(3)">3</button>
            <button class="pin-btn" onclick="add(4)">4</button>
            <button class="pin-btn" onclick="add(5)">5</button>
            <button class="pin-btn" onclick="add(6)">6</button>
            <button class="pin-btn" onclick="add(7)">7</button>
            <button class="pin-btn" onclick="add(8)">8</button>
            <button class="pin-btn" onclick="add(9)">9</button>
            <button class="pin-btn clear" onclick="clearPin()">C</button>
            <button class="pin-btn" onclick="add(0)">0</button>
            <button class="pin-btn" onclick="backspace()">⌫</button>
            
            <button class="pin-btn enter" onclick="login()">LOGIN</button>
            <button class="pin-btn time" style="grid-column: span 3; background: #8D6E63; color: white; border: none; width: 200px; height: 60px; border-radius: 12px; font-size: 1rem; letter-spacing: 1px; text-transform: uppercase; margin-top: 5px;" onclick="clockIn()">TIME CLOCK</button>
        </div>
    </div>

    <script>
        let pin = "";
        
        function add(num) {
            if(pin.length < 8) { 
                pin += num;
                update();
            }
        }
        
        function clearPin() {
            pin = ""; update();
        }
        
        function backspace() {
            pin = pin.slice(0, -1); update();
        }
        
        function update() {
            // SECURITY FIX: Replaces the actual digits with standard password dots
            document.getElementById('display').value = '•'.repeat(pin.length);
        }
        
        function login() {
            if(pin.length < 1) return;

            const btn = document.querySelector('.enter');
            const originalText = btn.innerText;
            btn.innerText = "Checking...";
            
            // CACHE NUKE: The "?nocache=" guarantees the phone cannot use an old saved response
            fetch('api/auth_login.php?nocache=' + Date.now(), { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ passcode: pin }) 
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    window.location.href = 'pos/';
                } else {
                    btn.innerText = originalText;
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Access Denied',
                        text: data.error || 'Invalid Passcode',
                        confirmButtonColor: '#6B4226'
                    });
                    clearPin();
                }
            })
            .catch(err => {
                btn.innerText = originalText;
                alert("Connection Error: " + err.message);
            });
        }
        function clockIn() {
            if(pin.length < 1) {
                Swal.fire({icon: 'warning', title: 'Enter PIN', text: 'Enter your PIN before clocking in or out.', confirmButtonColor: '#6B4226'});
                return;
            }

            Swal.fire({title:'Checking status...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});

            // STEP 1: Fetch their current status first
            fetch('api/timeclock_pin.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ passcode: pin, action: 'status' }) 
            })
            .then(r => r.json())
            .then(statusData => {
                if (!statusData.success) {
                    Swal.fire({icon: 'error', title: 'Denied', text: statusData.error, confirmButtonColor: '#6B4226'});
                    clearPin();
                    return;
                }

                // STEP 2: Dynamically build the popup based on their status!
                const isClockedIn = statusData.is_clocked_in;
                const actionText = isClockedIn ? 'Clock Out' : 'Clock In';
                const actionColor = isClockedIn ? '#d33' : '#2e7d32';
                
                Swal.fire({
                    title: actionText + '?',
                    html: `Hello <b>${statusData.username}</b>!<br>Are you sure you want to <b>${actionText.toUpperCase()}</b>?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: `Yes, ${actionText}`,
                    confirmButtonColor: actionColor
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({title:'Processing...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
                        
                        // STEP 3: Execute the actual timeclock punch
                        fetch('api/timeclock_pin.php', { 
                            method: 'POST', 
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ passcode: pin, action: 'toggle' }) 
                        })
                        .then(r => r.json())
                        .then(data => {
                            if(data.success) {
                                Swal.fire({
                                    icon: 'success', title: data.action === 'clocked_in' ? 'Clocked In' : 'Clocked Out',
                                    text: data.message, timer: 3000, showConfirmButton: false
                                });
                            } else { 
                                Swal.fire({icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#6B4226'}); 
                            }
                            clearPin();
                        })
                        .catch(err => { alert("Connection Error: " + err.message); clearPin(); });
                    } else {
                        clearPin(); // clear PIN if they click Cancel
                    }
                });
            })
            .catch(err => { alert("Connection Error: " + err.message); clearPin(); });
        }
    </script>
</body>
</html>