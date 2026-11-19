FROM php:8.3-apache
# pdo_pgsql: conexão com o PostgreSQL (Neon). pdo_sqlite já vem na imagem (usado só no backup .db e como reserva sem DATABASE_URL)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
