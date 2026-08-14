<?php
// db.php مبسّط — فقط auth_user للـ API
function auth_user(): ?array {
    return $_SESSION['user'] ?? null;
}
