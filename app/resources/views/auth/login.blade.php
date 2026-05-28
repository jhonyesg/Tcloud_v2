@extends('layouts.app')

@section('title', 'Iniciar Sesión - Tcloud')

@section('content')
<style>
    :root {
        --color-brand-500: #2d5aa0;
        --color-brand-400: #4e75b6;
        --color-brand-300: #7a97c9;
        --color-brand-800: #081d4a;
        --color-brand-900: #03153C;
        --color-orb-1: rgba(45, 90, 160, 0.8);
        --color-orb-2: rgba(78, 117, 182, 0.6);
        --color-orb-3: rgba(122, 151, 201, 0.5);
        --color-orb-4: rgba(45, 90, 160, 0.4);
        --color-orb-5: rgba(78, 117, 182, 0.35);
        --color-particle: rgba(122, 151, 201, 1);
        --color-grid: rgba(45, 90, 160, 0.05);
        --color-primary-rgb: 45, 90, 160;
    }

    * { box-sizing: border-box; }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: var(--color-brand-900) !important;
        min-height: 100vh;
        overflow-y: auto;
    }

    .login-wrapper {
        position: relative;
        z-index: 10;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        width: 100%;
        padding: 1rem;
    }

    #particles {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 0;
        pointer-events: none;
    }

    .orb {
        position: fixed;
        border-radius: 50%;
        filter: blur(80px);
        opacity: 0.4;
        pointer-events: none;
        z-index: 0;
    }

    .orb-1 {
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, var(--color-orb-1) 0%, transparent 70%);
        top: -100px;
        left: -100px;
        animation: orbFloat1 20s ease-in-out infinite;
    }

    .orb-2 {
        width: 350px;
        height: 350px;
        background: radial-gradient(circle, var(--color-orb-2) 0%, transparent 70%);
        bottom: -80px;
        right: -80px;
        animation: orbFloat2 18s ease-in-out infinite;
        animation-delay: -5s;
    }

    .orb-3 {
        width: 250px;
        height: 250px;
        background: radial-gradient(circle, var(--color-orb-3) 0%, transparent 70%);
        top: 50%;
        right: 20%;
        animation: orbFloat3 22s ease-in-out infinite;
        animation-delay: -10s;
    }

    .orb-4 {
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, var(--color-orb-4) 0%, transparent 70%);
        bottom: 30%;
        left: 10%;
        animation: orbFloat4 16s ease-in-out infinite;
        animation-delay: -8s;
    }

    .orb-5 {
        width: 180px;
        height: 180px;
        background: radial-gradient(circle, var(--color-orb-5) 0%, transparent 70%);
        top: 20%;
        left: 30%;
        animation: orbFloat5 24s ease-in-out infinite;
        animation-delay: -12s;
    }

    @keyframes orbFloat1 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        25% { transform: translate(40px, -40px) scale(1.08); }
        50% { transform: translate(-30px, 30px) scale(0.95); }
        75% { transform: translate(30px, 15px) scale(1.04); }
    }

    @keyframes orbFloat2 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        25% { transform: translate(-35px, 25px) scale(1.06); }
        50% { transform: translate(25px, -35px) scale(0.94); }
        75% { transform: translate(-15px, 20px) scale(1.02); }
    }

    @keyframes orbFloat3 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(50px, -20px) scale(1.1); }
        66% { transform: translate(-25px, 35px) scale(0.92); }
    }

    @keyframes orbFloat4 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        50% { transform: translate(-45px, -30px) scale(1.07); }
    }

    @keyframes orbFloat5 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(35px, 40px) scale(0.96); }
        66% { transform: translate(-40px, -20px) scale(1.05); }
    }

    .grid-pattern {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-image: 
            linear-gradient(var(--color-grid) 1px, transparent 1px),
            linear-gradient(90deg, var(--color-grid) 1px, transparent 1px);
        background-size: 60px 60px;
        z-index: 1;
    }

    @keyframes cardAppear {
        from {
            opacity: 0;
            transform: translateY(40px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .glass-card {
        animation: cardAppear 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        background: rgba(8, 29, 74, 0.75);
        backdrop-filter: blur(32px);
        -webkit-backdrop-filter: blur(32px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 1.5rem;
        padding: 1.5rem;
        width: 100%;
        max-width: 28rem;
        box-shadow:
            0 0 60px rgba(45, 90, 160, 0.15),
            0 0 100px rgba(45, 90, 160, 0.1),
            inset 0 0 80px rgba(255, 255, 255, 0.02);
    }

    @media (min-width: 480px) {
        .glass-card { padding: 2.5rem; }
    }

    .glass-input {
        background: rgba(3, 21, 60, 0.4);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #ffffff;
        transition: all 0.3s ease;
        font-size: 0.95rem;
        padding: 1rem 1.25rem;
    }

    .glass-input:focus {
        border-color: rgba(255, 255, 255, 0.4);
        box-shadow: 
            0 0 0 3px rgba(45, 90, 160, 0.25),
            0 0 40px rgba(45, 90, 160, 0.15),
            inset 0 0 20px rgba(255, 255, 255, 0.05);
        background: rgba(3, 21, 60, 0.5);
        outline: none;
    }

    .glass-input:hover {
        border-color: rgba(255, 255, 255, 0.25);
    }

    .glass-input::placeholder {
        color: rgba(180, 180, 200, 0.6);
    }

    .feature-badge {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: rgba(255, 255, 255, 0.95);
        transition: all 0.2s ease;
        text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
    }

    .feature-badge .badge-icon {
        color: rgba(255, 255, 255, 0.9);
        filter: drop-shadow(0 0 5px rgba(255, 255, 255, 0.5));
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .spinner {
        animation: spin 0.7s linear infinite;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--color-brand-500), var(--color-brand-400));
        box-shadow: 
            0 4px 20px rgba(45, 90, 160, 0.4),
            inset 0 1px 0 rgba(255, 255, 255, 0.2);
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
    }

    .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 
            0 8px 30px rgba(45, 90, 160, 0.5),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
        background: linear-gradient(135deg, var(--color-brand-400), var(--color-brand-500));
    }

    .btn-primary:active {
        transform: translateY(-1px);
    }

    .btn-primary::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        transition: left 0.5s ease;
    }

    .btn-primary:hover::before {
        left: 100%;
    }

    .error-message {
        background: rgba(239, 68, 68, 0.15);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #fca5a5;
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-6px); }
        40%, 80% { transform: translateX(6px); }
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-6px); }
        40%, 80% { transform: translateX(6px); }
    }

    .logo {
        filter: drop-shadow(0 0 20px rgba(45, 90, 160, 0.5));
        transition: all 0.3s ease;
    }

    .logo:hover {
        transform: scale(1.1) rotate(3deg);
        filter: drop-shadow(0 0 40px rgba(45, 90, 160, 0.7));
    }

    .brand-name {
        background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.8) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        text-shadow: 0 0 30px rgba(255, 255, 255, 0.2);
    }

    @media (max-width: 480px) {
        .orb {
            opacity: 0.25;
        }
    }
</style>

<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>
<div class="orb orb-4"></div>
<div class="orb orb-5"></div>
<div class="grid-pattern"></div>
<canvas id="particles"></canvas>

<div class="login-wrapper">
    <div class="glass-card shadow-2xl">
        <div class="text-center mb-7">
            <img src="/logo.png" alt="Tcloud" class="logo mx-auto rounded-2xl object-contain transition-all duration-300" style="width: 72px; height: 72px;">
            <div class="brand mt-4">
                <div class="brand-name text-2xl font-bold tracking-tight">Tcloud</div>
                <div class="text-sm mt-1" style="color: var(--color-brand-300)">Plataforma de Gestión de Contenido</div>
            </div>
        </div>

        @if(session('error'))
        <div class="error-message p-3 rounded-xl text-sm mb-4 flex items-start gap-2">
            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ session('error') }}</span>
        </div>
        @endif

        @if($errors->any())
        <div class="error-message p-3 rounded-xl text-sm mb-4 flex items-start gap-2">
            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <ul class="list-none space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if(session('success'))
        <div class="p-3 rounded-xl text-sm mb-4 flex items-start gap-2" style="background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #86efac;">
            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ session('success') }}</span>
        </div>
        @endif

        <form id="loginForm" action="/login" method="POST" class="space-y-5">
            @csrf
            <div>
                <label for="login" class="block text-xs font-medium mb-2 uppercase tracking-widest" style="color: rgba(255, 255, 255, 0.6); font-size: 0.7rem;">
                    Email o nombre de usuario
                </label>
                <input type="text" id="login" name="login" required autocomplete="username"
                       class="glass-input block w-full border rounded-xl transition-all duration-200"
                       placeholder="tu@ejemplo.com o jsuarez"
                       value="{{ old('login') }}">
            </div>

            <div>
                <label for="password" class="block text-xs font-medium mb-2 uppercase tracking-widest" style="color: rgba(255, 255, 255, 0.6); font-size: 0.7rem;">
                    Contraseña
                </label>
                <div class="relative">
                    <input type="password" id="password" name="password" required
                           class="glass-input block w-full border rounded-xl transition-all duration-200 pr-12"
                           placeholder="••••••••">
                    <button type="button" id="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 p-2 transition-all duration-200 hover:scale-110" style="color: rgba(255, 255, 255, 0.5)">
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

            <button type="submit" id="submitBtn"
                    class="btn-primary relative flex items-center justify-center w-full py-3.5 text-white rounded-xl font-semibold text-sm shadow-lg transition-all duration-200 hover:-translate-y-0.5 hover:shadow-xl overflow-hidden">
                <span id="btnText">Iniciar Sesión</span>
                <span id="btnSpinner" class="hidden flex items-center gap-2">
                    <svg class="spinner w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Verificando...
                </span>
            </button>
        </form>
    </div>

    <div class="mt-7 flex justify-center gap-4 flex-wrap" style="margin-top: 1.75rem;">
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

<script>
(function() {
    const canvas = document.getElementById('particles');
    const ctx = canvas.getContext('2d');
    let particles = [];
    let mouseX = -1000;
    let mouseY = -1000;
    const PARTICLE_COUNT = 750;
    const FOLLOW_STRENGTH = 0.02;
    const MOUSE_RADIUS = 150;
    
    const particleColors = [
        'rgba(255, 255, 255,',    // white
        'rgba(255, 255, 255,',   // white
        'rgba(200, 200, 220,',   // light blue-white
        'rgba(255, 255, 255,',  // white
        'rgba(220, 220, 240,',   // light blue-white
    ];
    
    function resize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    
    function createParticle() {
        return {
            x: Math.random() * canvas.width,
            y: Math.random() * canvas.height,
            size: Math.random() * 2.5 + 0.8,
            baseSpeedX: (Math.random() - 0.5) * 0.4,
            baseSpeedY: (Math.random() - 0.5) * 0.4,
            speedX: 0,
            speedY: 0,
            opacity: Math.random() * 0.4 + 0.2,
            colorIndex: Math.floor(Math.random() * particleColors.length)
        };
    }
    
    function initParticles() {
        particles = [];
        for (let i = 0; i < PARTICLE_COUNT; i++) {
            particles.push(createParticle());
        }
    }
    
    function animate() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        particles.forEach((p, index) => {
            const dx = mouseX - p.x;
            const dy = mouseY - p.y;
            const distance = Math.sqrt(dx * dx + dy * dy);
            
            if (distance < MOUSE_RADIUS && distance > 0) {
                const force = (MOUSE_RADIUS - distance) / MOUSE_RADIUS;
                p.speedX += dx * force * FOLLOW_STRENGTH;
                p.speedY += dy * force * FOLLOW_STRENGTH;
            }
            
            p.speedX += p.baseSpeedX * 0.1;
            p.speedY += p.baseSpeedY * 0.1;
            
            p.speedX *= 0.95;
            p.speedY *= 0.95;
            
            p.x += p.speedX;
            p.y += p.speedY;
            
            if (p.x < 0) p.x = canvas.width;
            if (p.x > canvas.width) p.x = 0;
            if (p.y < 0) p.y = canvas.height;
            if (p.y > canvas.height) p.y = 0;
            
            let finalOpacity = p.opacity;
            if (distance < MOUSE_RADIUS) {
                finalOpacity = Math.min(1, p.opacity + (1 - distance / MOUSE_RADIUS) * 0.5);
            }
            
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fillStyle = particleColors[p.colorIndex] + finalOpacity + ')';
            ctx.fill();
            
            for (let j = index + 1; j < particles.length; j++) {
                const p2 = particles[j];
                const dx2 = p.x - p2.x;
                const dy2 = p.y - p2.y;
                const dist2 = Math.sqrt(dx2 * dx2 + dy2 * dy2);
                
                if (dist2 < 80) {
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                    ctx.lineTo(p2.x, p2.y);
                    ctx.strokeStyle = particleColors[p.colorIndex] + ((100 - dist2) / 100 * 0.3) + ')';
                    ctx.lineWidth = 0.5;
                    ctx.stroke();
                }
            }
        });
        
        requestAnimationFrame(animate);
    }
    
    document.addEventListener('mousemove', function(e) {
        mouseX = e.clientX;
        mouseY = e.clientY;
    });
    
    document.addEventListener('mouseleave', function() {
        mouseX = -1000;
        mouseY = -1000;
    });
    
    resize();
    initParticles();
    animate();
    
    window.addEventListener('resize', function() {
        resize();
        initParticles();
    });

    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    const eyeOffIcon = document.getElementById('eyeOffIcon');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('hidden');
            eyeOffIcon.classList.toggle('hidden');
        });
    }

    const loginForm = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const btnSpinner = document.getElementById('btnSpinner');

    if (loginForm && submitBtn) {
        loginForm.addEventListener('submit', function(e) {
            btnText.classList.add('hidden');
            btnSpinner.classList.remove('hidden');
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.8';
            submitBtn.style.cursor = 'not-allowed';
        });
    }
})();
</script>
@endsection
