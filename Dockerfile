FROM php:8.2-apache

# تفعيل mod_rewrite
RUN a2enmod rewrite headers

# نسخ الملفات
COPY . /var/www/html/

# صلاحيات مجلد data
RUN mkdir -p /var/www/html/data && \
    chmod -R 777 /var/www/html/data

# إعدادات Apache
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

EXPOSE 80
