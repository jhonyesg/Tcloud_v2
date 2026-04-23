## 1. Configurar restart policy

- [x] 1.1 Agregar `restart: unless-stopped` al servicio nginx
- [x] 1.2 Agregar `restart: unless-stopped` al servicio php
- [x] 1.3 Agregar `restart: unless-stopped` al servicio postgres
- [x] 1.4 Agregar `restart: unless-stopped` al servicio redis

## 2. Aplicar cambios

- [x] 2.1 Ejecutar `docker-compose down` para detener contenedores actuales
- [x] 2.2 Ejecutar `docker-compose up -d` para aplicar nueva configuración
- [x] 2.3 Verificar que todos los contenedores estén corriendo con `docker ps`
