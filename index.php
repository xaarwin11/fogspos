<?php
require_once 'db.php';
session_start();

if (!empty($_SESSION['user_id'])) {
    header("Location: pos/index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>FogsTasa Login</title>
    
    <link rel="stylesheet" href="assets/css/main.css">
    <script src="assets/js/sweetalert2.js"></script> 
    <style>
        .pin-btn { touch-action: manipulation; }
    </style>
</head>

<body class="login-page">

    <div class="login-card">
        <img src="assets/img/logo.jpg" class="logo-img" alt="Logo">
        
        <h2>Welcome</h2>
        
        <input type="password" id="display" class="pin-display" readonly 
       placeholder="••••" inputmode="numeric" autocomplete="one-time-code">
        
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
            // Temporarily shows numbers instead of dots so you can verify your thumbs!
            document.getElementById('display').value = pin;
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
                    window.location.href = 'pos/index.php';
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
    </script>
</body>
</html>