<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Unified session settings for all site paths
ini_set('session.cookie_path', '/');
ini_set('session.cookie_secure', true);
// ini_set('session.cookie_samesite', 'None'); // Note: 'None' requires 'Secure' to be true in modern browsers. Keeping it commented out or using 'Lax' might be safer if not strictly on HTTPS.
session_start();

// Update path to database connection file
include '../api/db.php'; 

// Set UAE timezone (+04:00)
if (isset($conn)) {
    $conn->query("SET time_zone = '+04:00'");
}

// Cache control headers to prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = "Please enter both username and password."; // English error
    } else {
        // Get user data
        if (!isset($conn)) {
            $error = "Database connection error."; 
        } else {
            // Use prepared statement to prevent SQL injection
            $stmt = $conn->prepare("
                SELECT u.*, s.SectorName
                FROM Users u
                LEFT JOIN tbl_Sectors s ON u.SectorID = s.SectorID
                WHERE u.Username = ?
            ");
            
            if ($stmt === false) {
                 $error = "Query preparation error: " . $conn->error;
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user && password_verify($password, $user['Password'])) {
    // Store data in session
    $_SESSION['user'] = [
        'EmpID' => $user['EmpID'],
        'username' => $user['Username'],
        'EmpName' => $user['EmpName'],
        'SectorName' => $user['SectorName'] ?? '',
        'CanAdd' => (int) $user['CanAdd'],
        'CanEdit' => (int) $user['CanEdit'],
        'CanDelete' => (int) $user['CanDelete'],
        'CanSendWhatsApp' => (int) $user['CanSendWhatsApp'],
        'IsLicenseManager' => (int) $user['IsLicenseManager'],
        'IsAdmin' => (int) $user['IsAdmin'],
        'Active' => (int) $user['Active'],
        'follow_up_complaints' => (int) $user['follow_up_complaints'],
        'complaints_manager_rights' => (int) $user['complaints_manager_rights'],
        'clinic_rights' => (int) $user['clinic_rights'],
        'warehouse_rights' => (int) $user['warehouse_rights'],
        'super_admin_rights' => (int) $user['super_admin_rights'],
        'LeaveApproval' => $user['LeaveApproval'] ?? 'No' // يمكن تحويله إلى int إذا لزم الأمر (مثل 1 لـ Yes، 0 لـ No)
    ];

                    // Redirect to main page
                    header("Location: index.php");
                    exit;
                } else {
                    $error = $user ? "Incorrect password." : "Username not found."; // English errors
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
<meta name="google-site-verification" content="jT-X8tTYvvZcs5zlVRlWYY9Siq0udJawCLNB8GlMGwU" />
<title>Login - Sharjah Municipality Shelter</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

    /* Define Gold/Dark Beige color variables for non-button elements */
    :root {
        --primary-gold: #C79A54; /* Main Gold/Beige */
        --darker-gold: #A37C42; /* Darker Gold for gradient start/hover */
        --text-color-dark: #333;
        /* Green colors re-added for the primary button only */
        --primary-green: #2E7D32;
        --light-green: #4CAF50;
        --darker-green: #1B5E20;
    }

    /* Reset */
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #FFFFFF;
        height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        direction: ltr; 
        overflow: auto;
        padding: 20px;
    }

    .login-card {
        background: rgba(255, 255, 255, 1);
        border-radius: 20px;
        box-shadow:
            0 10px 30px rgba(0, 0, 0, 0.05),
            0 5px 15px rgba(0, 0, 0, 0.05);
        width: 420px;
        max-width: 100%;
        padding: 35px 30px;
        position: relative;
        /* Updated: Outer border to green like button */
        border: 2px solid var(--primary-green);
        transition: all 0.3s ease-in-out;
        min-height: auto;
    }

    .header-section {
        text-align: center;
        margin-bottom: 30px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .logo-container {
        width: 100%;
        display: flex;
        justify-content: flex-start;
        margin-bottom: 15px;
    }

    .municipality-logo {
        width: 160px;
        height: auto;
        margin: 0; 
        display: flex;
        justify-content: flex-start;
        align-items: center;
    }

    .municipality-logo img {
        width: 100%;
        height: auto;
        max-height: 70px;
        object-fit: contain;
    }

    .icons-row {
        display: flex;
        justify-content: center;
        gap: 15px;
        margin-top: 15px;
        margin-bottom: 20px;
        width: 100%;
    }

    .animal-icon {
        /* Icon Background: Gold Gradient */
        width: 45px;
        height: 45px;
        background: linear-gradient(135deg, var(--darker-gold), var(--primary-gold)); 
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        box-shadow: 0 4px 12px rgba(199, 154, 84, 0.2);
    }

    .animal-icon i {
        font-size: 24px;
        color: white;
    }

    h2 {
        /* H2 text color: Use Green */
        color: var(--primary-green); 
        font-weight: 700;
        /* Updated: Smaller font size */
        font-size: 1.1rem; 
        margin-bottom: 6px;
        text-align: center;
        letter-spacing: -0.3px;
    }

    .subtitle {
        /* Updated: Subtitle color to green */
        color: var(--primary-green);
        font-weight: 500;
        font-size: 0.9rem;
        text-align: center;
        margin-bottom: 4px;
        line-height: 1.3;
    }

    .municipality-name {
        /* Updated: Municipality name color: Use Green */
        color: var(--primary-green);
        font-weight: 600;
        font-size: 0.85rem;
        text-align: center;
        margin-bottom: 20px;
        padding-top: 8px;
        /* Updated: Border top line to green */
        border-top: 1px solid var(--primary-green);
    }

    label {
        display: block;
        /* Updated: Label text color: Use Green */
        font-weight: 600;
        color: var(--primary-green);
        margin-bottom: 6px;
        font-size: 0.85rem;
        text-align: left;
    }

    .input-group {
        position: relative;
        width: 100%;
        margin-bottom: 20px;
    }

    input[type="text"],
    input[type="password"] {
        width: 100%;
        padding: 14px 16px;
        border: 2px solid #E0E0E0;
        border-radius: 10px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        outline: none;
        color: var(--text-color-dark);
        background-color: #FFFFFF;
        font-family: 'Inter', sans-serif;
        text-align: left;
    }

    input[type="text"]:focus,
    input[type="password"]:focus {
        /* Input focus border color: Use Green */
        border-color: var(--primary-green);
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15);
        background-color: #FFFFFF;
    }

    .toggle-password {
        position: absolute;
        top: 50%;
        right: 15px; 
        left: auto;
        transform: translateY(-50%);
        cursor: pointer;
        width: 20px;
        height: 20px;
        color: #718096;
        transition: color 0.3s ease;
    }

    .toggle-password:hover {
        /* Toggle icon hover color: Use Green */
        color: var(--primary-green);
    }

    .toggle-password i {
        font-size: 18px;
    }

    .btn-primary {
        /* BUTTON GRADIENT: REVERTED TO GREEN */
        background: linear-gradient(135deg, var(--primary-green), var(--light-green));
        color: #FFFFFF;
        border: none;
        font-weight: 600;
        font-size: 0.95rem;
        padding: 14px;
        border-radius: 10px;
        width: 100%;
        cursor: pointer;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        /* BUTTON SHADOW: REVERTED TO GREEN */
        box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3); 
        margin-top: 8px;
        font-family: 'Inter', sans-serif;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        /* BUTTON HOVER: REVERTED TO DARKER GREEN */
        box-shadow: 0 6px 16px rgba(46, 125, 50, 0.4);
        background: linear-gradient(135deg, var(--darker-green), var(--primary-green));
    }

    .btn-primary:active {
        transform: translateY(0);
        /* BUTTON SHADOW: REVERTED TO GREEN */
        box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
    }

    .btn-primary i {
        font-size: 18px;
    }

    .error-message {
        background: #FFF3F3; 
        color: #C62828;
        border: 1px solid #FFCDD2;
        border-radius: 8px;
        padding: 12px 14px;
        margin-bottom: 20px;
        font-weight: 500;
        text-align: center;
        font-size: 0.85rem;
        box-shadow: 0 2px 6px rgba(198, 40, 40, 0.04);
    }

    /* Responsive Design adjustments */
    @media (max-width: 768px) {
        .login-card {
            width: 100%;
            max-width: 380px;
            padding: 30px 25px;
            border-radius: 18px;
        }

        .municipality-logo {
            width: 140px;
            margin-bottom: 12px;
        }

        .municipality-logo img {
            max-height: 60px;
        }

        .animal-icon {
            width: 40px;
            height: 40px;
        }

        .animal-icon i {
            font-size: 20px;
        }

        h2 {
            /* Adjusted responsive size */
            font-size: 1rem;
        }

        .subtitle {
            font-size: 0.85rem;
        }

        input[type="text"],
        input[type="password"] {
            padding: 12px 14px;
            font-size: 0.85rem;
        }

        .btn-primary {
            padding: 12px;
            font-size: 0.9rem;
        }

        .btn-primary i {
            font-size: 16px;
        }
    }

    @media (max-width: 480px) {
        .login-card {
            padding: 25px 20px;
            border-radius: 16px;
        }

        .municipality-logo {
            width: 120px;
            margin-bottom: 10px;
        }

        .municipality-logo img {
            max-height: 50px;
        }

        .animal-icon {
            width: 35px;
            height: 35px;
        }

        .animal-icon i {
            font-size: 18px;
        }

        h2 {
            /* Adjusted responsive size */
            font-size: 0.95rem;
        }

        .subtitle {
            font-size: 0.8rem;
        }

        label {
            font-size: 0.8rem;
        }

        input[type="text"],
        input[type="password"] {
            padding: 11px 13px;
            font-size: 0.8rem;
        }

        .btn-primary {
            padding: 11px;
            font-size: 0.85rem;
        }

        .btn-primary i {
            font-size: 14px;
        }

        .toggle-password i {
            font-size: 16px;
        }

        .error-message {
            padding: 10px 12px;
            font-size: 0.8rem;
            margin-bottom: 15px;
        }
    }

    @media (max-width: 320px) {
        .login-card {
            padding: 20px 15px;
        }
         
        .municipality-logo {
            width: 100px;
        }

        .municipality-logo img {
            max-height: 45px;
        }

        .animal-icon {
            width: 30px;
            height: 30px;
        }

        .animal-icon i {
            font-size: 16px;
        }

        h2 {
            /* Adjusted responsive size */
            font-size: 0.9rem;
        }
    }
</style>
</head>
<body>
    <div class="login-card">
        <div class="header-section">
            <div class="logo-container">
                <div class="municipality-logo">
                    <img src="shjmunlogo.png?v=<?= time() ?>" alt="Sharjah Municipality Logo" />
                </div>
            </div>
            
            <div class="icons-row">
                <div class="animal-icon">
                    <i class="fas fa-dog"></i>
                </div>
                <div class="animal-icon">
                    <i class="fas fa-cat"></i>
                </div>
            </div>

            <h2>Sharjah Cats and Dogs Shelter</h2>
            <p class="municipality-name">Sharjah City Municipality</p>
        </div>

        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <label for="username">Username</label>
            <div class="input-group">
                <input id="username" type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Enter your username" />
            </div>

            <label for="password">Password</label>
            <div class="input-group">
                <input id="password" type="password" name="password" required placeholder="Enter your password" />
                <span class="toggle-password" onclick="togglePassword()">
                    <i class="fas fa-eye"></i>
                </span>
            </div>

            <button type="submit" class="btn-primary">
                <i class="fas fa-sign-in-alt"></i>
                Sign In
            </button>
        </form>
    </div>

    <script>
        function togglePassword() {
            var passwordField = document.getElementById("password");
            var toggleIcon = document.querySelector(".toggle-password i");
            
            if (passwordField.type === "password") {
                passwordField.type = "text";
                toggleIcon.className = "fas fa-eye-slash";
            } else {
                passwordField.type = "password";
                toggleIcon.className = "fas fa-eye";
            }
        }

        // Focus on username field when page loads
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });
    </script>
</body>
</html>