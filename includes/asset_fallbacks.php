<?php
/**
 * 轩宇-导航 · 前端依赖 CDN 兜底
 * 线上 assets/ 中若缺少 jQuery/Bootstrap/SweetAlert2，自动从多个 CDN 加载。
 */
?>
<script>
window.jQuery || document.write('<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"><\/script>');
</script>
<script>
window.jQuery || document.write('<script src="https://cdn.staticfile.org/jquery/3.7.1/jquery.min.js"><\/script>');
</script>
<script>
window.jQuery || document.write('<script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.7.1/jquery.min.js"><\/script>');
</script>
<script>
window.bootstrap || document.write('<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"><\/script>');
</script>
<script>
window.bootstrap || document.write('<script src="https://cdn.staticfile.org/twitter-bootstrap/5.3.0/js/bootstrap.bundle.min.js"><\/script>');
</script>
<script>
window.bootstrap || document.write('<script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/js/bootstrap.bundle.min.js"><\/script>');
</script>
<script>
window.Swal || document.write('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"><\/script>');
</script>
<script>
window.Swal || document.write('<script src="https://cdn.staticfile.org/sweetalert2/11.7.0/sweetalert2.all.min.js"><\/script>');
</script>
<script>
window.Swal || document.write('<script src="https://cdn.bootcdn.net/ajax/libs/sweetalert2/11.7.0/sweetalert2.all.min.js"><\/script>');
</script>
