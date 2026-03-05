<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$user_role = $_SESSION['role'] ?? 'staff';
?>
<meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
<nav>
    <div class="brand">
        <img src="../assets/img/logo.jpg" style="width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.2);">
        FogsTasa's Cafe
    </div>
    
    <button class="burger-menu" onclick="document.querySelector('.nav-links').classList.toggle('active')">☰</button>

    <div class="nav-links">
        <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager','staff'])): ?>
            <a href="../admin/dashboard.php" style="color:white; text-decoration:none; font-weight:500;">📊 Dashboard</a>
            <a href="../admin/products.php" style="color:white; text-decoration:none; font-weight:500;">📦 Menu</a>
        <?php endif; ?>
        <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager'])): ?>
            <a href="../admin/settings.php" style="color:white; text-decoration:none; font-weight:500;">⚙️ Settings</a>
        <?php endif; ?>
        <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager','staff'])): ?>
        <a href="../pos/" style="color:white; text-decoration:none; font-weight:500;">🖥️ POS</a>
        <a href="#" onclick="manageRegister()" style="color:white; text-decoration:none; padding:8px 12px; border-radius:6px; background:#4e2f1d;">💰 Register</a>
        <button id="navTimeClockBtn" onclick="toggleTimeClock()" style="background:var(--text-muted); border:none; padding:8px 15px; border-radius:6px; color:white; font-weight:bold; cursor:pointer; transition:0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
            ⏳ Checking...
        </button>

        <span class="user-greeting" style="color: rgba(255,255,255,0.9); font-size: 0.9rem;">
            👋 <?php echo htmlspecialchars($_SESSION['username'] ?? 'Staff'); ?>
        </span>
        <a href="../api/auth_logout.php" class="logout">Logout</a>
        <?php endif; ?>
    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', checkTimeClockStatus);
function checkTimeClockStatus() {
    fetch('../api/time_clock.php').then(r => r.json()).then(d => { if(d.success) updateTimeClockUI(d.is_clocked_in); });
}
function updateTimeClockUI(isClockedIn) {
    const btn = document.getElementById('navTimeClockBtn');
    if (isClockedIn) { btn.style.background = 'var(--danger)'; btn.innerHTML = '⏹️ Clock Out'; } 
    else { btn.style.background = 'var(--success)'; btn.innerHTML = '▶️ Clock In'; }
}
function toggleTimeClock() {
    const btn = document.getElementById('navTimeClockBtn');
    
    // Check what the button currently says to customize the popup
    const isClockingOut = btn.innerHTML.includes('Clock Out');
    const actionText = isClockingOut ? 'Clock Out' : 'Clock In';
    const actionColor = isClockingOut ? '#d33' : '#2e7d32';

    // NEW: Added Dynamic Confirmation Popup!
    Swal.fire({
        title: actionText + '?',
        text: `Are you sure you want to ${actionText.toLowerCase()}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, ${actionText}`,
        confirmButtonColor: actionColor
    }).then((result) => {
        if (result.isConfirmed) {
            btn.disabled = true; 
            Swal.fire({title:'Processing...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
            
            fetch('../api/time_clock.php', { 
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            }).then(r => r.json()).then(d => {
                btn.disabled = false;
                if(d.success) {
                    updateTimeClockUI(d.action === 'clocked_in');
                    Swal.fire({icon: 'success', title: d.action === 'clocked_in' ? 'Clocked In' : 'Clocked Out', text: d.message, timer: 3500, showConfirmButton: false});
                } else { 
                    Swal.fire('Error', d.error, 'error'); 
                }
            });
        }
    });
}

async function manageRegister() {
    try {
        // 1. Check current status
        const res = await fetch('../api/register.php?action=status');
        const data = await res.json();
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        if (!data.is_open) {
            // DRAWER IS CLOSED: Prompt to Open
            const { value: floatAmt } = await Swal.fire({
                title: 'Open Register',
                text: 'Enter the starting cash (Float) currently in the drawer:',
                input: 'number',
                inputAttributes: { step: '0.01', min: '0' },
                inputValue: '0.00',
                showCancelButton: true,
                confirmButtonText: 'Open Drawer',
                confirmButtonColor: '#2e7d32'
            });

            if (floatAmt) {
                const openRes = await fetch('../api/register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'open', amount: floatAmt })
                });
                const openData = await openRes.json();
                if (openData.success) Swal.fire({icon:'success', title:'Register Opened', timer:1500, showConfirmButton:false});
                else Swal.fire('Error', openData.error, 'error');
            }
        } else {
            // DRAWER IS OPEN: Show totals and prompt to Close
            const html = `
                <div style="text-align:left; background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #eee; margin-bottom:15px;">
                    <div><b>Opened By:</b> ${data.opener} at ${data.opened_at}</div>
                    <hr style="border:0; border-top:1px dashed #ccc; margin:10px 0;">
                    
                    <div style="display:flex; justify-content:space-between;">
                        <span>Opening Float:</span> <span>₱${data.opening_cash.toFixed(2)}</span>
                    </div>
                    
                    <div style="display:flex; justify-content:space-between; color:var(--success);">
                        <span>Gross Cash Sales:</span> <span>+₱${data.gross_cash.toFixed(2)}</span>
                    </div>
                    
                    ${data.cash_refunds < 0 ? `
                    <div style="display:flex; justify-content:space-between; color:var(--danger); font-weight:bold;">
                        <span>Refunds Paid Out:</span> <span>-₱${Math.abs(data.cash_refunds).toFixed(2)}</span>
                    </div>` : ''}
                    
                    <hr style="border:0; border-top:1px dashed #ccc; margin:10px 0;">
                    <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:1.2rem;">
                        <span>Expected in Drawer:</span> <span>₱${data.expected_cash.toFixed(2)}</span>
                    </div>
                </div>
                <label style="font-weight:bold; display:block; text-align:left;">Enter Physical Cash Counted:</label>
                <input type="number" id="counted-cash" class="swal2-input" placeholder="0.00" step="0.01">
            `;

            const { value: confirmed } = await Swal.fire({
                title: 'Close Register',
                html: html,
                showCancelButton: true,
                confirmButtonText: 'Close & Record Drawer',
                confirmButtonColor: '#d33',
                preConfirm: () => {
                    const counted = parseFloat(document.getElementById('counted-cash').value);
                    if (isNaN(counted) || counted < 0) { Swal.showValidationMessage('Enter valid counted cash'); return false; }
                    return counted;
                }
            });

            if (confirmed !== undefined) {
                const closeRes = await fetch('../api/register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'close', counted: confirmed })
                });
                const closeData = await closeRes.json();
                
                if (closeData.success) {
                    let msg = `Expected: ₱${closeData.expected.toFixed(2)}<br>Counted: ₱${confirmed.toFixed(2)}<br><br>`;
                    let icon = 'success';
                    if (closeData.variance < 0) {
                        msg += `<strong style="color:red;">Short by: ₱${Math.abs(closeData.variance).toFixed(2)}</strong>`;
                        icon = 'warning';
                    } else if (closeData.variance > 0) {
                        msg += `<strong style="color:blue;">Over by: ₱${closeData.variance.toFixed(2)}</strong>`;
                        icon = 'info';
                    } else {
                        msg += `<strong style="color:green;">Drawer perfectly balanced!</strong>`;
                    }

                    Swal.fire({
                        title: 'Register Closed', html: msg, icon: icon,
                        showCancelButton: true,
                        confirmButtonText: '🖨️ Print Z-Report',
                        cancelButtonText: 'Done',
                        confirmButtonColor: '#2e7d32'
                    }).then(async (result) => {
                        // THIS ACTUALLY TRIGGERS THE PRINTER
                        if (result.isConfirmed) {
                            Swal.fire({title: 'Printing to Register...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                            try {
                                const pRes = await fetch('../api/print_zreport.php');
                                const pData = await pRes.json();
                                if (pData.success) {
                                    Swal.fire({icon: 'success', title: 'Z-Report Printed!', timer: 1500, showConfirmButton: false});
                                } else {
                                    Swal.fire('Print Failed', pData.error, 'error');
                                }
                            } catch(e) { 
                                Swal.fire('Error', 'Could not reach printer service.', 'error'); 
                            }
                        }
                    });
                } else Swal.fire('Error', closeData.error, 'error');
            }
        }
    } catch(e) { console.error(e); Swal.fire('Error', 'Connection failed', 'error'); }
}
</script>