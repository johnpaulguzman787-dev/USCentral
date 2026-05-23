<?php
session_start(); // 🔥 REQUIRED — THIS WAS MISSING
include 'config/db.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = "Please enter your email and password.";
    } else {

        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                // ✅ LOGIN SUCCESS
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['student_id'] = $user['student_id'];
                $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['position'] = $user['position'];
                $_SESSION['org_id'] = $user['org_id'];
                $_SESSION['academic_year_id'] = $user['academic_year_id'];

                // 🔀 REDIRECT BASED ON ROLE
                if ($user['role'] === 'Admin') {
                    header("Location: dashboard.php");
                } elseif ($user['role'] === 'Sub-Admin') {
                    header("Location: subadmin/dashboard.php");
                } else {
                    header("Location: user/dashboard.php");
                }
                exit;

            } else {
                $error = "Invalid email or password.";
            }

        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>USCentral | Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/login.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">

        <!-- Logo -->
        <div class="text-center mb-3">
            <img src="images/university-logo.png" alt="USCentral Logo" class="logo">
        </div>

        <h3 class="text-center fw-semibold">USCENTRAL</h3>
        <p class="text-center text-muted mb-4">Empowering Campus Organizations</p>

        <!-- 🔔 ERROR ALERT -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show text-center" role="alert">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group mb-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input 
                        type="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="Email" 
                        required
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    >
                </div>
            </div>

            <div class="form-group mb-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input 
                        type="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Password" 
                        required
                    >
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
                <a href="#" class="forgot-link">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary w-100 login-btn">
                LOGIN
            </button>
        </form>

        <p class="text-center mt-4">
            Don’t have an account yet?
            <a href="#" class="signup-link">Sign up here</a>
        </p>

    </div>
</div>

<!-- Bootstrap JS (for alert close button) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
