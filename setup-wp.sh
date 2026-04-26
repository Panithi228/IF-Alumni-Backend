#!/bin/bash

# 1. รอ MySQL บูตให้เสร็จ (เปลี่ยนวิธีรอ)
echo "Waiting for database..."
until php -r "new mysqli('mysql', 'wordpress', 'wordpress', 'wordpress');" 2>/dev/null; do
  sleep 2
done
echo "Database is ready!"

# 2. รอให้ WordPress files พร้อมก่อน
until [ -f /var/www/html/wp-includes/version.php ]; do
  echo "Waiting for WordPress files..."
  sleep 2
done

# 3. สร้าง wp-config.php และใส่ค่า JWT ที่เตรียมไว้
if [ ! -f /var/www/html/wp-config.php ]; then
    echo "Creating wp-config.php..."
    wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=mysql --allow-root --path=/var/www/html
    
    wp config set JWT_AUTH_SECRET_KEY 'd|}43VK|5OveibEv-WwG)!QE GEh*rpS2d@m,ug)!NSzP.myT|`8CN,`Kx6P<Lxs' --allow-root --path=/var/www/html
    wp config set JWT_AUTH_CORS_ENABLE true --raw --allow-root --path=/var/www/html
fi

# 4. ติดตั้ง WordPress
if ! wp core is-installed --allow-root --path=/var/www/html; then
    echo "Installing WordPress core..."
    wp core install \
        --url="http://localhost:8041" \
        --title="IF Alumni" \
        --admin_user="admin" \
        --admin_password="admin" \
        --admin_email="test@test.com" \
        --skip-email \
        --allow-root \
        --path=/var/www/html
fi

# 5. Activate Plugins
echo "Activating plugins..."
wp plugin activate advanced-custom-fields --allow-root --path=/var/www/html
wp plugin activate custom-post-type-ui --allow-root --path=/var/www/html
wp plugin activate jwt-authentication-for-wp-rest-api --allow-root --path=/var/www/html
wp plugin activate alumni-api --allow-root --path=/var/www/html

# 6. ตั้งค่า Permalink
wp rewrite structure '/%postname%/' --hard --allow-root --path=/var/www/html

echo "WordPress is ready to go!"