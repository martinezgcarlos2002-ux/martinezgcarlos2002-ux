<?php
class AuthMiddleware {
    public static function verificar(){
        if(!isset($_SESSION['user'])){
            header("Location: ?url=login");
            exit;
        }
    }
}