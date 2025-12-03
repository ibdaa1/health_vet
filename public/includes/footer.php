<!-- الفوتر -->
<footer class="footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <h3 class="footer-title">عن المركز</h3>
                <p>نحن نقدم الرعاية للحيوانات الضالة والمتاحة للتبني، ونسعى لتوفير منازل دافئة لها مع عائلات محبة.</p>
            </div>
            
            <div class="footer-section">
                <h3 class="footer-title">معلومات الاتصال</h3>
                <div class="footer-contact">
                    <div class="contact-item">
                        <i class="fas fa-phone"></i>
                        <span>9715597403340</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <span>zedanmahmoud99@gmail.com</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>مركز الرعاية البيطرية</span>
                    </div>
                </div>
            </div>
            
            <div class="footer-section">
                <h3 class="footer-title">تابعنا</h3>
                <div class="social-icons">
                    <a href="#" class="social-icon">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="#" class="social-icon">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="#" class="social-icon">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <a href="#" class="social-icon">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</footer>

<style>
    .footer {
        background: var(--text-dark);
        color: white;
        padding: 40px 0;
        margin-top: 50px;
    }
    
    .footer-content {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .footer-section {
        flex: 1;
        min-width: 250px;
    }
    
    .footer-title {
        font-size: 1.3em;
        margin-bottom: 20px;
        color: var(--primary-green);
    }
    
    .footer-contact {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .footer-contact .contact-item {
        color: white;
    }
    
    .social-icons {
        display: flex;
        gap: 15px;
        margin-top: 20px;
    }
    
    .social-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--primary-green);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.2em;
        transition: background 0.3s ease;
        text-decoration: none;
    }
    
    .social-icon:hover {
        background: #1e7e34;
    }
    
    @media (max-width: 768px) {
        .footer-content {
            flex-direction: column;
            text-align: center;
        }
    }
</style>

<script>
    // دالة تبديل اللغة
    document.addEventListener('DOMContentLoaded', function() {
        const langToggle = document.getElementById('lang-toggle');
        if (langToggle) {
            let currentLang = localStorage.getItem('lang') || 'ar';
            
            langToggle.addEventListener('click', function() {
                currentLang = currentLang === 'ar' ? 'en' : 'ar';
                localStorage.setItem('lang', currentLang);
                document.documentElement.dir = currentLang === 'ar' ? 'rtl' : 'ltr';
                document.documentElement.lang = currentLang;
                this.textContent = currentLang === 'ar' ? 'EN' : 'عربي';
                location.reload();
            });

            // تطبيق اللغة الحالية
            document.documentElement.dir = currentLang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = currentLang;
            langToggle.textContent = currentLang === 'ar' ? 'EN' : 'عربي';
        }
    });
</script>
</body>
</html>