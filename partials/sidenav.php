<!-- partials/sidenav.php -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
    /* Mobile toggle button */
    .mobile-toggle {
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        background: linear-gradient(180deg, #3479DB 0%, #2A5FB0 100%);
        color: white;
        border: none;
        border-radius: 8px;
        padding: 10px 15px;
        display: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .mobile-toggle:hover {
        background: linear-gradient(180deg, #2A5FB0 0%, #1E4A8E 100%);
    }

    /* Overlay for mobile */
    .sidenav-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 998;
    }

    .sidenav {
        width: 280px;
        height: 100vh;
        background: linear-gradient(180deg, #3479DB 0%, #2A5FB0 50%, #1E4A8E 100%);
        color: #ffffff;
        position: fixed;
        top: 0;
        left: 0;
        font-family: 'Poppins', sans-serif;
        display: flex;
        flex-direction: column;
        box-shadow: 5px 0 25px rgba(0, 0, 0, 0.1);
        z-index: 999;
        transition: transform 0.3s ease;
        overflow: hidden;
    }

    /* Mobile styles */
    @media (max-width: 768px) {
        .mobile-toggle {
            display: block;
        }
        
        .sidenav {
            transform: translateX(-100%);
        }
        
        .sidenav.active {
            transform: translateX(0);
        }
        
        .sidenav-overlay.active {
            display: block;
        }
        
        /* Adjust content when sidenav is open */
        .content {
            margin-left: 0 !important;
            padding-top: 70px;
        }
    }

    /* Desktop styles */
    @media (min-width: 769px) {
        .sidenav {
            transform: translateX(0) !important;
        }
        
        .content {
            margin-left: 280px;
        }
    }

    .sidenav-header {
        text-align: center;
        padding: 30px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(5px);
        position: relative;
        overflow: hidden;
    }

    .sidenav-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transform: translateX(-100%);
        transition: transform 0.6s ease;
    }

    .sidenav-header:hover::before {
        transform: translateX(100%);
    }

    .sidenav-logo {
        width: 70px;
        height: 70px;
        margin-bottom: 15px;
        border-radius: 50%;
        padding: 5px;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border: 2px solid rgba(255, 255, 255, 0.3);
        transition: transform 0.4s ease, box-shadow 0.4s ease;
    }

    .sidenav-logo:hover {
        transform: scale(1.05) rotate(5deg);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }

    .sidenav-header h4 {
        margin: 0;
        font-weight: 700;
        letter-spacing: 1.5px;
        font-size: 1.4rem;
        background: linear-gradient(90deg, #FFFFFF, #E6F7FF);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .sidenav-header small {
        font-size: 0.75rem;
        opacity: 0.85;
        font-weight: 300;
        letter-spacing: 0.5px;
        margin-top: 5px;
        display: block;
    }

    .sidenav-menu {
        list-style: none;
        padding: 20px 0;
        margin: 0;
        flex-grow: 1;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
    }

    .sidenav-menu::-webkit-scrollbar {
        width: 4px;
    }

    .sidenav-menu::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
    }

    .sidenav-menu::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 10px;
    }

    .sidenav-menu li {
        width: 100%;
        margin: 5px 0;
    }

    .sidenav-menu a {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px 25px;
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        font-size: 0.95rem;
        font-weight: 500;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        border-left: 3px solid transparent;
    }

    .sidenav-menu a::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: left 0.6s ease;
    }

    .sidenav-menu a:hover::before {
        left: 100%;
    }

    .sidenav-menu a:hover {
        background: rgba(255, 255, 255, 0.12);
        border-left: 3px solid #4CAF50;
        color: #ffffff;
        padding-left: 30px;
    }

    .sidenav-menu a i {
        font-size: 1.2rem;
        width: 24px;
        text-align: center;
        transition: transform 0.3s ease;
    }

    .sidenav-menu a:hover i {
        transform: scale(1.2);
    }

    .sidenav-menu .active {
        background: rgba(255, 255, 255, 0.15);
        border-left: 3px solid #FFD700;
        color: #ffffff;
        font-weight: 600;
    }

    .divider {
        margin: 15px 25px;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        position: relative;
    }

    .divider::after {
        content: '';
        position: absolute;
        top: -2px;
        left: 50%;
        transform: translateX(-50%);
        width: 30px;
        height: 5px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 3px;
    }

    /* Add a subtle animation for the entire sidenav */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateX(-20px); }
        to { opacity: 1; transform: translateX(0); }
    }

    .sidenav {
        animation: fadeIn 0.5s ease-out;
    }

    /* Responsive adjustments */
    @media (max-height: 700px) {
        .sidenav-header {
            padding: 20px 20px;
        }
        
        .sidenav-menu a {
            padding: 12px 25px;
        }
    }

    /* Close button for mobile */
    .sidenav-close {
        display: none;
        position: absolute;
        top: 15px;
        right: 15px;
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        align-items: center;
        justify-content: center;
        z-index: 1001;
    }

    @media (max-width: 768px) {
        .sidenav-close {
            display: flex;
        }
    }
</style>

<!-- Mobile Toggle Button -->
<button class="mobile-toggle" id="mobileToggle">
    <i class="bi bi-list"></i>
</button>

<!-- Overlay for mobile -->
<div class="sidenav-overlay" id="sidenavOverlay"></div>

<!-- Side Navigation -->
<div class="sidenav" id="sidenav">
    <button class="sidenav-close" id="sidenavClose">
        <i class="bi bi-x-lg"></i>
    </button>
    
    <div class="sidenav-header">
        <img src="images/university-logo.png" alt="USCentral Logo" class="sidenav-logo">
        <h4>USCENTRAL</h4>
        <small>Empowering Student Leadership</small>
    </div>

    <ul class="sidenav-menu">
        <li>
            <a href="dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </li>
        <li>
            <a href="usc-management.php">
                <i class="bi bi-kanban"></i> USC Management
            </a>
        </li>
        <li>
            <a href="org-oversight.php">
                <i class="bi bi-diagram-3"></i> Organization Oversight
            </a>
        </li>
        <li>
            <a href="reports.php">
                <i class="bi bi-file-earmark-text"></i> Reports
            </a>
        </li>
        <li>
            <a href="event-calendar.php">
                <i class="bi bi-calendar-event"></i> Event Calendar
            </a>
        </li>
        <li>
            <a href="announcement.php">
                <i class="bi bi-megaphone"></i> Announcements
            </a>
        </li>

        <li class="divider"></li>

        <li>
            <a href="system-admin.php">
                <i class="bi bi-gear"></i> System Administration
            </a>
        </li>
    </ul>
    
    <!-- Version badge -->
    <div style="padding: 15px; text-align: center; font-size: 0.7rem; color: rgba(255,255,255,0.5); border-top: 1px solid rgba(255,255,255,0.1);">
        v1.0.0 | © 2025 USCentral
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidenav = document.getElementById('sidenav');
    const mobileToggle = document.getElementById('mobileToggle');
    const sidenavOverlay = document.getElementById('sidenavOverlay');
    const sidenavClose = document.getElementById('sidenavClose');
    const menuItems = document.querySelectorAll('.sidenav-menu a');

    function toggleSidenav() {
        sidenav.classList.toggle('active');
        sidenavOverlay.classList.toggle('active');
    }

    function closeSidenav() {
        sidenav.classList.remove('active');
        sidenavOverlay.classList.remove('active');
    }

    mobileToggle.addEventListener('click', toggleSidenav);
    sidenavOverlay.addEventListener('click', closeSidenav);
    sidenavClose.addEventListener('click', closeSidenav);

    /* ==========================
       ACTIVE MENU (ONE ONLY)
    ========================== */
    const currentPage = window.location.pathname.split('/').pop();

    menuItems.forEach(item => {
        const href = item.getAttribute('href');

        if (href === currentPage) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }

        // Close on mobile click
        item.addEventListener('click', () => {
            if (window.innerWidth <= 768) closeSidenav();
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeSidenav();
    });

    function checkScreenSize() {
        if (window.innerWidth > 768) {
            sidenav.classList.add('active');
        } else {
            sidenav.classList.remove('active');
            sidenavOverlay.classList.remove('active');
        }
    }

    checkScreenSize();
    window.addEventListener('resize', checkScreenSize);
});
</script>
