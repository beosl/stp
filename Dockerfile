FROM php:8.1-apache

# cURL ve diğer gerekli PHP kütüphanelerini kur
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Dosyaları sunucuya kopyala
COPY . /var/www/html/

# Render'ın port değişkenini Apache'ye tanıt
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Dosya yazma izinlerini ayarla (/tmp/ çerezler için kullanılacak)
RUN chmod -R 777 /var/www/html
