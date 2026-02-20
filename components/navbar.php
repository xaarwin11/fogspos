<nav>
    <div class="brand">
        <img src="../assets/img/logo.jpg" style="width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.2);">
        FogsTasa's Cafe
    </div>
    
    <div class="nav-links">
        <a href="../admin/products.php" style="color:white; text-decoration:none; margin-right:15px; font-weight:500;">📦 Menu Manager</a>
        
        <a href="../pos/index.php" style="color:white; text-decoration:none; margin-right:15px; font-weight:500;">🖥️ POS</a>

        <span style="color: rgba(255,255,255,0.9); font-size: 0.9rem; padding-right: 15px;">
            👋 <?php echo htmlspecialchars($_SESSION['username'] ?? 'Staff'); ?>
        </span>
        <a href="../api/auth_logout.php" class="logout">Logout</a>
    </div>
</nav>