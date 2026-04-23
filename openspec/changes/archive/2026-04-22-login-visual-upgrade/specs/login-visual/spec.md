## ADDED Requirements

### Requirement: Login page particle background
The login page SHALL display an animated particle background with 250 particles on a canvas element. Particles SHALL follow the mouse cursor with mouse-following force (strength 0.02, radius 150px). Particles SHALL connect with lines to nearby particles within 100px distance. Particles SHALL wrap around screen edges.

### Requirement: Login page animated gradient orbs
The login page SHALL display 5 animated gradient orbs using brand colors. Orb 1 (400x400px) uses brand-500 radial gradient, positioned top-left, animated with 20s float cycle. Orb 2 (350x350px) uses brand-400 radial gradient, positioned bottom-right, animated with 18s float cycle. Orb 3 (250x250px) uses brand-300 radial gradient, positioned center-right, animated with 22s float cycle. Orbs 4 and 5 use brand-500/400 at varying opacities.

### Requirement: Login page grid pattern
The login page SHALL display a subtle grid pattern overlay using brand-500 at 5% opacity. Grid uses 60x60px cells with linear gradients. Grid is positioned behind particles and orbs (z-index: 1).

### Requirement: Login page glassmorphism card
The login card SHALL use glassmorphism styling with background: rgba(8, 29, 74, 0.9), backdrop-filter: blur(24px), border: 1px solid rgba(100, 100, 150, 0.2), border-radius: 1.5rem, padding: 2.5rem. Card appears centered with z-index: 10.

### Requirement: Login page glass inputs
The email and password input fields SHALL use glass styling with background: rgba(3, 21, 60, 0.55), backdrop-blur: 8px, border: 1px solid rgba(100, 100, 150, 0.2). On focus, inputs SHALL have border-color: brand-500, box-shadow: 0 0 0 4px rgba(45, 90, 160, 0.2), 0 0 30px rgba(45, 90, 160, 0.12).

### Requirement: Login page feature badges
The login page SHALL display a strip of 3 feature badges below the card: "Seguro", "Rápido", "Cloud". Each badge SHALL have background: rgba(45, 90, 160, 0.14), border: 1px solid rgba(45, 90, 160, 0.28), and an icon from FontAwesome. Badges are centered in a flex container with gap-4.

### Requirement: Login page removes hardcoded credentials
The login page SHALL NOT display hardcoded test credentials. The paragraph element containing "Credenciales de prueba:" and the credential text SHALL be removed from the template.
