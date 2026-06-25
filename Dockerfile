# 1. 使用官方 PHP 8.2 Apache 版本作為基礎鏡像
FROM php:8.2-apache

# 2. 更新系統軟體源並安裝必要套件
RUN apt-get update && apt-get install -y \
    libmariadb-dev \
    && rm -rf /var/lib/apt/lists/*


# 3. 安裝 PHP 擴展 (連線資料庫必備)
# mysqli 是傳統寫法，pdo_mysql 是現代物件導向寫法，建議兩者都裝
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 4. 啟用 Apache 的 Rewrite 模組 (如果你有使用 .htaccess 或路由框架時需要)
RUN a2enmod rewrite

# 5. 設定工作目錄與 DocumentRoot
# ENV: 設定環境變數，此處定義 Apache 的文件根目錄路徑
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# 使用 sed 指令自動化替換 Apache 設定檔：
# -r: 使用延伸正規表示法 (Extended Regular Expressions)
# -i: 直接修改檔案內容 (In-place edit)
# -e: 執行的編輯腳本，'s!原路徑!新路徑!g' (s 為替換，! 為分隔符，g 為全域替換)
# 1. 替換虛擬主機設定檔中的 DocumentRoot
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
# 2. 替換全域 Apache 設定檔中的目錄權限設定
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# 6. 修改權限，確保 Apache 有權限讀取掛載的檔案
RUN chown -R www-data:www-data /var/www/html

# 設定照片上傳限制
RUN printf "file_uploads=On\nupload_max_filesize=10M\npost_max_size=12M\nmax_file_uploads=20\n" \
    > /usr/local/etc/php/conf.d/uploads.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/src/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

CMD ["sh", "-c", "mkdir -p /var/www/html/src/public/uploads/inspection && chown -R www-data:www-data /var/www/html/src/public/uploads && chmod -R 775 /var/www/html/src/public/uploads && apache2-foreground"]

