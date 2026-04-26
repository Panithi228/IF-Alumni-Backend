FROM wordpress:latest

COPY ./wordpress /var/www/html

RUN chown -R www-data:www-data /var/www/html