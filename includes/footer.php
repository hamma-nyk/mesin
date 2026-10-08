<footer class="main-footer" style="font-size: 14px;">
        <strong>Copyright &copy; IT NBI TERCINTA 2026.</strong>
        All rights reserved.
    </footer>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="assets/js/script.js"></script>
<?php if (isset($_SESSION['global_error'])): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                icon: 'error',
                title: 'Akses Terlarang!',
                text: <?= json_encode(html_entity_decode($_SESSION['global_error']), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                confirmButtonColor: '#dc3545'
            });
        });
    </script>
    <?php unset($_SESSION['global_error']); // Hapus session agar tidak muncul terus saat refresh ?>
<?php endif; ?>
</body>
</html>
