<?php
class AuthController {
    private $db;

    public function __construct($db){
        $this->db = $db;
    }

    public function login(){
        if($_SERVER['REQUEST_METHOD'] === 'POST'){
            // Verificar CSRF
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $_SESSION['error'] = "Token de seguridad inválido";
                header("Location: ?url=login");
                exit;
            }

            $email = trim($_POST['correo']);
            $contrasena = $_POST['contrasena'];

            // Validar email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['error'] = "Correo electrónico inválido";
                header("Location: ?url=login");
                exit;
            }

            // 🔹 Cambiamos la columna 'password' según tu tabla
            $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if($user && password_verify($contrasena, $user['password'])){
                $_SESSION['user'] = $user;
                header("Location: ?url=dashboard");
                exit;
            } else {
                $_SESSION['error'] = "Correo o contraseña incorrectos";
                header("Location: ?url=login");
                exit;
            }
        }

        require_once __DIR__ . '/../../admin/auth/login.php';
    }

    public function logout(){
        session_destroy();
        header("Location: ?url=login");
        exit;
    }
}