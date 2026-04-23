FROM nginx:1.26-alpine

# Directorios para logs
RUN mkdir -p /var/log/nginx

# Copiar configuración de Nginx
COPY nginx.conf /etc/nginx/nginx.conf

# Copiar configuración del sitio
COPY sites-available/default.conf /etc/nginx/sites-available/default.conf
RUN mkdir -p /etc/nginx/sites-enabled \
    && ln -s /etc/nginx/sites-available/default.conf /etc/nginx/sites-enabled/default.conf

# Limpiar configuración default de nginx
RUN rm -f /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]