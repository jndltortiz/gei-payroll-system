function togglePassword() {
    const pw  = document.getElementById('password');
    const ico = document.getElementById('eyeIcon');

    if (pw.type === 'password') {
        pw.type = 'text';
        ico.className = 'bi bi-eye-slash';
    } else {
        pw.type = 'password';
        ico.className = 'bi bi-eye';
    }
}

