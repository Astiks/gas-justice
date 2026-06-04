<?php
// includes/footer.php
// Подвал для всех страниц
?>
        </div> <!-- .container -->
    </main>
    
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-copyright">
                    &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>
                </div>
                <div class="footer-links">
                    <a href="about.php">О системе</a>
                    <a href="help.php">Помощь</a>
                    <a href="contacts.php">Контакты</a>
                </div>
            </div>
        </div>
    </footer>
</div> <!-- .app-wrapper -->

<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>
<script>
    // Автоматическое скрытие alert-сообщений
    document.querySelectorAll('.alert-dismissible .alert-close').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.alert').remove();
        });
    });
    
    // Автоматическое скрытие alert через 5 секунд
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
</script>
</body>
</html>