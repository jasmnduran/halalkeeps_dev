<?php if (isset($is_dashboard) && $is_dashboard): ?>
        </div><!-- /.dashboard-content -->
    </div><!-- /.flex-1 main column -->
</div><!-- /.tw-flex outer layout -->
<?php endif; ?>

<!-- Inject server-side BASE_URL so JS never has a hardcoded path -->
<script>
    window.BASE_URL = "<?= rtrim(BASE_URL, '/') . '/' ?>";
</script>
<!-- JavaScript -->
<script src="<?= BASE_URL ?>assets/js/main.js"></script>

<?php if (isset($extra_js)): ?>
    <?= $extra_js ?>
<?php endif; ?>

</body>
</html>
