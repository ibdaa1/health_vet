// session_check.js
function checkSession() {
    fetch('check_session.php')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                window.location.href = 'login.php';
            }
        })
        .catch(error => {
            console.error('Error checking session:', error);
            window.location.href = 'login.php';
        });
}

// استدعاء الدالة تلقائيًا عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', checkSession);