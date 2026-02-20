<nav>
    <div class="brand">
        <img src="../assets/img/logo.jpg" style="width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.2);">
        FogsTasa's Cafe
    </div>
    
    <div class="nav-links">
        <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager'])): ?>
            <a href="../admin/dashboard.php" style="color:white; text-decoration:none; margin-right:15px; font-weight:500;">📊 Dashboard</a>
            <a href="../admin/products.php" style="color:white; text-decoration:none; margin-right:15px; font-weight:500;">📦 Menu</a>
            <a href="../admin/settings.php" style="color:white; text-decoration:none; margin-right:15px; font-weight:500;">⚙️ Settings</a>
        <?php endif; ?>
        
        <a href="../pos/index.php" style="color:white; text-decoration:none; margin-right:20px; font-weight:500;">🖥️ POS</a>

        <button id="navTimeClockBtn" onclick="toggleTimeClock()" style="background:var(--text-muted); border:none; padding:6px 15px; border-radius:6px; color:white; font-weight:bold; cursor:pointer; margin-right:15px; transition:0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
            ⏳ Checking...
        </button>

        <span style="color: rgba(255,255,255,0.9); font-size: 0.9rem; padding-right: 15px;">
            👋 <?php echo htmlspecialchars($_SESSION['username'] ?? 'Staff'); ?>
        </span>
        <a href="../api/auth_logout.php" class="logout">Logout</a>
    </div>
</nav>

<script>
// Check status immediately when navbar loads
document.addEventListener('DOMContentLoaded', checkTimeClockStatus);

function checkTimeClockStatus() {
    fetch('../api/time_clock.php')
    .then(r => r.json())
    .then(d => { if(d.success) updateTimeClockUI(d.is_clocked_in); })
    .catch(e => console.error("Timeclock fetch error", e));
}

function updateTimeClockUI(isClockedIn) {
    const btn = document.getElementById('navTimeClockBtn');
    if (isClockedIn) {
        btn.style.background = 'var(--danger)'; 
        btn.innerHTML = '⏹️ Clock Out';
    } else {
        btn.style.background = 'var(--success)'; 
        btn.innerHTML = '▶️ Clock In';
    }
}

function toggleTimeClock() {
    const btn = document.getElementById('navTimeClockBtn');
    btn.disabled = true; // Prevent accidental double taps
    
    fetch('../api/time_clock.php', { method: 'POST' })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if(d.success) {
            updateTimeClockUI(d.action === 'clocked_in');
            Swal.fire({
                icon: 'success',
                title: d.action === 'clocked_in' ? 'Clocked In' : 'Clocked Out',
                text: d.message,
                timer: 3500,
                showConfirmButton: false
            });
        } else {
            Swal.fire('Error', d.error, 'error');
        }
    });
}
</script>