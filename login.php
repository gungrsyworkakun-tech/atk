<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password'])) {
        $_SESSION['user'] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'role' => $row['role'],
            'permissions' => decode_permissions($row['permissions']),
            'bidang_nama' => $row['bidang_nama'] ?? null,
        ];
        redirect('index.php');
    } else {
        $error = 'Nama pengguna atau kata sandi salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — Pendataan ATK</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    
    <!-- Stylesheet Main -->
    <link rel="stylesheet" href="assets/style.css">

    <!-- CSS Tambahan Khusus Elemen Interaktif Login (Tampilan Box/Soft) -->
    <style>
        /* Background utama (Biru dongker sangat gelap) */
        body, .login-wrap {
            background: #0B0F19 !important; 
            min-height: 100vh;
        }

        /* Mengembalikan desain Box/Card yang elegan */
        .login-card {
            background: #131A2B !important; /* Warna box sedikit lebih terang dari background */
            border: 1px solid #1F293D !important; /* Border tipis membatasi box */
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.5) !important; /* Bayangan soft */
            border-radius: 16px !important;
            width: 100%;
            max-width: 400px;
            padding: 40px 32px !important; /* Memberikan ruang napas di dalam box */
        }

        /* Sembunyikan header bawaan style.css */
        .login-tag {
            display: none !important;
        }

        /* Header Kustom */
        .auth-header {
            text-align: center;
            margin-bottom: 35px;
        }
        .auth-header h1 {
            font-family: 'Inter', sans-serif;
            font-size: 24px;
            font-weight: 600;
            color: #ffffff;
            margin: 16px 0 10px;
            letter-spacing: normal;
        }
        .auth-header p {
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: #8B93AC;
            margin: 0;
        }

        /* Penyesuaian Form & Label */
        .login-body {
            padding: 0 !important;
        }
        .field {
            margin-bottom: 22px;
        }
        .field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .field-header label {
            color: #10B981 !important; /* Warna label hijau */
            font-size: 13px !important;
            font-weight: 500 !important;
            text-transform: none !important;
            letter-spacing: normal !important;
            margin: 0 !important;
        }

        /* Input Styles (Dibuat agak masuk / sunken) */
        .field input {
            background: #0B0F19 !important; /* Menyamai warna background luar agar kontras dengan box */
            border: 1px solid #1F293D !important;
            color: #ffffff !important;
            border-radius: 6px !important;
            padding: 13px 14px !important;
            font-size: 14.5px !important;
            transition: all 0.2s ease !important;
        }
        .field input:focus {
            border-color: #10B981 !important; /* Hijau saat fokus */
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1) !important;
            outline: none;
        }
        .field input::placeholder {
            color: #5B6178 !important;
        }

        /* Checkbox Remember Me */
        .checkbox-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
        }
        .checkbox-wrap input[type="checkbox"] {
            appearance: none;
            width: 16px;
            height: 16px;
            border: 1px solid #1F293D;
            background: #0B0F19;
            border-radius: 3px;
            cursor: pointer;
            position: relative;
            outline: none;
            transition: all 0.2s ease;
        }
        .checkbox-wrap input[type="checkbox"]:checked {
            background: transparent;
            border-color: #10B981;
        }
        .checkbox-wrap input[type="checkbox"]:checked::after {
            content: '';
            position: absolute;
            left: 4px;
            top: 1px;
            width: 4px;
            height: 8px;
            border: solid #10B981;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .checkbox-wrap label {
            color: #10B981;
            font-size: 13.5px;
            cursor: pointer;
            user-select: none;
        }

        /* Tombol Utama (Biru Terang) */
        .btn-primary {
            background: #2563EB !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 6px !important;
            padding: 13px !important;
            font-size: 15px !important;
            font-weight: 500 !important;
            box-shadow: none !important;
            justify-content: center;
            transition: background 0.2s ease !important;
        }
        .btn-primary:hover {
            background: #1D4ED8 !important;
        }

        /* Error box */
        .err-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #F87171;
            padding: 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Login Hint */
        .login-hint {
            display: block !important;
            margin-top: 20px;
            font-size: 12.5px;
            color: #8B93AC;
            text-align: center;
            line-height: 1.5;
        }
        .login-hint .mono {
            font-family: 'IBM Plex Mono', monospace;
            color: #10B981;
        }

        /* Sembunyikan elemen bawaan yang tidak diperlukan lagi */
        .theme-toggle-login, .input-icon, .toggle-password {
            display: none !important;
        }
        
        /* Logo Sebelumnya */
        .brand-icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent, #10B981), var(--teal, #14B8A6));
            margin-bottom: 0px;
        }
    </style>
</head>
<body>

<div class="login-wrap">
    <div class="login-card">
        
        <!-- Header Baru Menyerupai Referensi -->
        <div class="auth-header">
            <!-- Logo dari sebelumnya -->
            <div class="brand-icon-box">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
            <h1>Masuk ke Akun Anda</h1>
            <p>Pendataan ATK & Sistem Inventaris</p>
        </div>

        <!-- Form Body -->
        <div class="login-body">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                <!-- Pesan Error jika Login Gagal -->
                <?php if ($error): ?>
                    <div class="err-box">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Field Username -->
                <div class="field">
                    <div class="field-header">
                        <label for="username">Nama pengguna</label>
                    </div>
                    <input id="username" name="username" placeholder="Masukkan nama pengguna" required autofocus>
                </div>

                <!-- Field Password -->
                <div class="field">
                    <div class="field-header">
                        <label for="password">Kata sandi</label>
                    </div>
                    <input type="password" id="password" name="password" placeholder="Masukkan kata sandi" required>
                </div>

                <!-- Checkbox Remember Me -->
                <div class="checkbox-wrap">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Ingat saya</label>
                </div>

                <!-- Tombol Submit -->
                <button class="btn btn-primary btn-block" type="submit">Masuk</button>

                <!-- Hint Info Akun Bawaan dari Kode Baru -->
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>