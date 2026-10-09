<?php
if (!empty($_SESSION['user_id'])) {
    audit_log('auth', 'logout', ['usuario_id' => $_SESSION['user_id']]);
    audit_session_end();
}
session_destroy();
header('Location: ?p=login');
exit;
