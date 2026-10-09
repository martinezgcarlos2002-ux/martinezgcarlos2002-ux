document.addEventListener('DOMContentLoaded', () => {
    // Animación login
    const loginBox = document.querySelector('.login-box');
    if(loginBox){
        loginBox.style.transform = 'translateY(-20px)';
        loginBox.style.opacity = '0';
        setTimeout(() => {
            loginBox.style.transition = 'all 0.5s ease';
            loginBox.style.transform = 'translateY(0)';
            loginBox.style.opacity = '1';
        }, 100);
    }

    // Resaltar filas de tabla
    document.querySelectorAll('table tr').forEach(row => {
        row.addEventListener('mouseenter', () => {row.style.backgroundColor='#003300';});
        row.addEventListener('mouseleave', () => {row.style.backgroundColor='';});
    });

    // Confirmación de acciones
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', e => {
            if(!confirm('¿Seguro que deseas continuar con esta acción?')){
                e.preventDefault();
            }
        });
    });
});