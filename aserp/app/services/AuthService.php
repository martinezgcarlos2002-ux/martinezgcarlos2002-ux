<?php

require_once __DIR__ . '/../models/Usuario.php';

class AuthService
{
    private $usuarioModel;

    public function __construct($db)
    {
        $this->usuarioModel = new Usuario($db);
    }

    public function login($email, $password)
    {
        $usuario = $this->usuarioModel->buscarPorEmail($email);

        if (!$usuario) {
            return ['error' => 'Usuario no encontrado'];
        }

        if (!password_verify($password, $usuario['password'])) {
            return ['error' => 'Contraseña incorrecta'];
        }

        // 🔹 Crear sesión
        $_SESSION['usuario'] = [
            'id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'email' => $usuario['email'],
            'rol' => $usuario['rol']
        ];

        return ['success' => true];
    }
}