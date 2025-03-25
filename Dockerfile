FROM php:8.1-apache

# Instalar dependencias necesarias
RUN apt-get update && apt-get install -y \
    libssl-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install \
    zip \
    pdo_mysql \
    && a2enmod rewrite

# Establecer el directorio de trabajo
WORKDIR /var/www/html

# Copiar composer.json y composer.lock
COPY composer.json composer.lock* ./

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instalar dependencias
RUN composer install --no-scripts --no-autoloader

# Copiar el resto de la aplicación
COPY . .

# Crear directorios necesarios
RUN mkdir -p /var/www/html/storage/folios \
    && mkdir -p /var/www/html/storage/logs \
    && mkdir -p /var/www/html/storage/debug \
    && chown -R www-data:www-data /var/www/html/storage

# Generar autoloader optimizado
RUN composer dump-autoload --optimize

# Configurar Apache para usar el directorio público
RUN sed -i 's/\/var\/www\/html/\/var\/www\/html\/public/g' /etc/apache2/sites-available/000-default.conf

# Exponer el puerto 80
EXPOSE 80

# Comando para iniciar Apache
CMD ["apache2-foreground"]