<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tcloud - Acceso</title>
    <link id="faviconLink" rel="icon" href="/static/logo.png?v=2" type="image/png">
    <link rel="stylesheet" href="/static/css/tailwind-built.css">
    <link rel="stylesheet" href="/static/css/flowbite.min.css">
    <link rel="stylesheet" href="/static/css/font-awesome.min.css">
    <style>
        :root {
            --color-primary: #6366f1;
            --color-primary-dark: #4f46e5;
            --color-secondary: #818cf8;
            --color-background: #0a0a0f;
            --color-surface: rgba(15, 15, 25, 0.9);
            --color-surface-light: rgba(30, 30, 50, 0.6);
            --color-text: #ffffff;
            --color-text-muted: #a0a0b0;
            --color-border: rgba(100, 100, 150, 0.2);
            --color-orb-1: rgba(99, 102, 241, 0.8);
            --color-orb-2: rgba(139, 92, 246, 0.6);
            --color-orb-3: rgba(59, 130, 246, 0.5);
            --color-orb-4: rgba(236, 72, 153, 0.4);
            --color-orb-5: rgba(34, 211, 238, 0.35);
            --color-particle: rgba(99, 102, 241, 1);
            --color-grid: rgba(99, 102, 241, 0.03);
            --color-primary-rgb: 99, 102, 241;
            --color-secondary-rgb: 129, 140, 248;
            --color-accent-rgb: 139, 92, 246;
            --login-bg-opacity: 0.4;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--color-background);
            color: var(--color-text);
            min-height: 100vh;
            overflow: hidden;
            transition: background 0.3s ease, color 0.3s ease;
        }
        #particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; }
        .orb { position: fixed; border-radius: 50%; filter: blur(80px); opacity: var(--login-bg-opacity, 0.4); pointer-events: none; z-index: 0; transition: opacity 0.3s ease; }
        .orb-1 { width: 400px; height: 400px; background: radial-gradient(circle, var(--color-orb-1) 0%, transparent 70%); top: -100px; left: -100px; animation: orbFloat1 20s ease-in-out infinite; }
        .orb-2 { width: 350px; height: 350px; background: radial-gradient(circle, var(--color-orb-2) 0%, transparent 70%); bottom: -80px; right: -80px; animation: orbFloat2 18s ease-in-out infinite; animation-delay: -5s; }
        .orb-3 { width: 250px; height: 250px; background: radial-gradient(circle, var(--color-orb-3) 0%, transparent 70%); top: 50%; right: 20%; animation: orbFloat3 22s ease-in-out infinite; animation-delay: -10s; }
        .orb-4 { width: 200px; height: 200px; background: radial-gradient(circle, var(--color-orb-4) 0%, transparent 70%); bottom: 30%; left: 10%; animation: orbFloat4 16s ease-in-out infinite; animation-delay: -8s; }
        .orb-5 { width: 180px; height: 180px; background: radial-gradient(circle, var(--color-orb-5) 0%, transparent 70%); top: 20%; left: 30%; animation: orbFloat5 24s ease-in-out infinite; animation-delay: -12s; }
        @keyframes orbFloat1 { 0%, 100% { transform: translate(0, 0) scale(1); } 25% { transform: translate(40px, -40px) scale(1.08); } 50% { transform: translate(-30px, 30px) scale(0.95); } 75% { transform: translate(30px, 15px) scale(1.04); } }
        @keyframes orbFloat2 { 0%, 100% { transform: translate(0, 0) scale(1); } 25% { transform: translate(-35px, 25px) scale(1.06); } 50% { transform: translate(25px, -35px) scale(0.94); } 75% { transform: translate(-15px, 20px) scale(1.02); } }
        @keyframes orbFloat3 { 0%, 100% { transform: translate(0, 0) scale(1); } 33% { transform: translate(50px, -20px) scale(1.1); } 66% { transform: translate(-25px, 35px) scale(0.92); } }
        @keyframes orbFloat4 { 0%, 100% { transform: translate(0, 0) scale(1); } 50% { transform: translate(-45px, -30px) scale(1.07); } }
        @keyframes orbFloat5 { 0%, 100% { transform: translate(0, 0) scale(1); } 33% { transform: translate(35px, 40px) scale(0.96); } 66% { transform: translate(-40px, -20px) scale(1.05); } }
        .grid-pattern { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: linear-gradient(var(--color-grid) 1px, transparent 1px), linear-gradient(90deg, var(--color-grid) 1px, transparent 1px); background-size: 60px 60px; z-index: 1; transition: background 0.3s ease; }
        @keyframes cardAppear { from { opacity: 0; transform: translateY(40px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .login-card { animation: cardAppear 0.8s cubic-bezier(0.16, 1, 0.3, 1); background: var(--color-surface); border-color: var(--color-border); transition: background 0.3s ease, border-color 0.3s ease; }
        .logo:hover { transform: scale(1.08) rotate(3deg); filter: drop-shadow(0 0 30px var(--color-primary)); }
        .brand-name { background: linear-gradient(135deg, var(--color-text) 0%, var(--color-primary) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; transition: background 0.3s ease; }
        .form-input { background: color-mix(in srgb, var(--color-background) 55%, transparent); border-color: var(--color-border); color: var(--color-text); transition: all 0.2s ease; }
        .form-input:focus { border-color: var(--color-primary); box-shadow: 0 0 0 4px rgba(var(--color-primary-rgb), 0.2), 0 0 30px rgba(var(--color-primary-rgb), 0.12); background: color-mix(in srgb, var(--color-background) 70%, transparent); outline: none; }
        .form-input::placeholder { color: var(--color-text-muted); opacity: 0.5; }
        .form-label { color: var(--color-text-muted); }
        .btn-primary { background: linear-gradient(135deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 70%, var(--color-secondary) 30%)); box-shadow: 0 4px 12px rgba(var(--color-primary-rgb), 0.32); transition: all 0.2s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(var(--color-primary-rgb), 0.45); }
        .btn-primary::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: left 0.5s ease; }
        .btn-primary:hover::before { left: 100%; }
        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: var(--color-text-muted); border-color: var(--color-border); transition: all 0.2s ease; }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.15); color: var(--color-text); }
        .error-message { background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #fca5a5; }
        .error-message.success { background: rgba(34, 197, 94, 0.15); border-color: rgba(34, 197, 94, 0.3); color: #86efac; }
        .error-message.show { animation: shake 0.5s ease; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner { animation: spin 0.7s linear infinite; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }
        .feature-badge { background: rgba(var(--color-primary-rgb), 0.14); border: 1px solid rgba(var(--color-primary-rgb), 0.28); color: color-mix(in srgb, var(--color-text) 75%, var(--color-primary) 25%); transition: all 0.2s ease; }
        .feature-badge .badge-icon { color: var(--color-secondary); }
        .border-t-border { border-color: var(--color-border); transition: border-color 0.3s ease; }
        @media (max-width: 480px) { .orb { opacity: 0.25; } }
    </style>
</head>
<body class="flex items-center justify-center">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="orb orb-4"></div>
    <div class="orb orb-5"></div>
    <div class="grid-pattern"></div>
    <canvas id="particles"></canvas>

    <div class="login-wrapper relative z-10 w-full max-w-md p-5">
        <div id="loginCard" class="login-card backdrop-blur-xl border rounded-3xl p-10 shadow-2xl">
            <div class="text-center mb-7">
                <img src="/static/logo.png?v=2" alt="Tcloud" class="logo mx-auto rounded-2xl object-contain transition-all duration-300" style="width: 72px; height: 72px;" onerror="this.style.display='none'">
                <div class="brand mt-4">
                    <div class="brand-name text-2xl font-bold tracking-tight">Tcloud</div>
                    <div class="text-sm mt-1" style="color: var(--color-text-muted)">Plataforma de Gestión de Contenido</div>
                </div>
            </div>

            <div id="errorMessage" class="error-message hidden p-3 rounded-xl text-sm mb-4"></div>

            <form id="loginForm">
                <div class="mb-5 relative">
                    <label for="email" class="form-label block text-xs font-medium mb-2 uppercase tracking-wide">Correo Electrónico</label>
                    <input type="email" id="email" name="email" required class="form-input w-full px-4 py-3.5 border rounded-xl text-sm transition-all duration-200" placeholder="admin@tcloud.com" autocomplete="username">
                </div>
                <div class="mb-6 relative">
                    <label for="password" class="form-label block text-xs font-medium mb-2 uppercase tracking-wide">Contraseña</label>
                    <div class="relative flex items-center">
                        <input type="password" id="password" name="password" required class="form-input w-full px-4 py-3.5 border rounded-xl text-sm transition-all duration-200 pr-12" placeholder="••••••••" autocomplete="current-password">
                        <button type="button" id="togglePassword" class="absolute right-3.5 p-1 transition-colors" style="color: var(--color-text-muted)">
                            <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                            </svg>
                        </button>
                    </div>
                </div>
                <button type="submit" id="loginBtn" class="btn-primary relative flex items-center justify-center w-full py-3.5 text-white rounded-xl font-semibold text-sm shadow-lg transition-all duration-200 hover:-translate-y-0.5 hover:shadow-xl disabled:opacity-60 disabled:cursor-not-allowed disabled:transform-none overflow-hidden">
                    <span id="btnText">Iniciar Sesión</span>
                    <span id="btnLoading" class="hidden absolute items-center gap-2">
                        <svg class="spinner w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" fill="none" viewBox="0 0 24 24"></svg>
                        Iniciando...
                    </span>
                </button>
            </form>

            <div class="mt-6 pt-5 border-t border-t-border flex justify-center">
                <button id="clearCookiesBtn" class="btn-secondary flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    Limpiar Sesión
                </button>
            </div>
        </div>

        <div class="mt-7 flex justify-center gap-4 flex-wrap">
            <div class="feature-badge flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full border font-medium">
                <svg class="w-3 h-3 badge-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                Seguro
            </div>
            <div class="feature-badge flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full border font-medium">
                <svg class="w-3 h-3 badge-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                Rápido
            </div>
            <div class="feature-badge flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full border font-medium">
                <svg class="w-3 h-3 badge-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                </svg>
                Cloud
            </div>
        </div>
    </div>

    <script src="/static/js/flowbite.min.js"></script>
    <script>
        // Theme loader + Login logic + Particles (same as provided HTML)
    </script>
</body>
</html>