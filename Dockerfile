FROM wordpress:5.8

ENV WOOCOMMERCE_VERSION 6.0.0

# Fetch WooCommerce.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip wget \
    && wget https://downloads.wordpress.org/plugin/woocommerce.$WOOCOMMERCE_VERSION.zip -O /tmp/wc.zip \
    && cd /usr/src/wordpress/wp-content/plugins \
    && unzip /tmp/wc.zip \
    && rm /tmp/wc.zip \
    && rm -rf /var/lib/apt/lists/*

# Install the gmp and soap extensions.
RUN apt-get update -y
RUN apt-get install -y libgmp-dev libxml2-dev
RUN ln -s /usr/include/x86_64-linux-gnu/gmp.h /usr/local/include/
RUN docker-php-ext-configure gmp
RUN docker-php-ext-install gmp
RUN docker-php-ext-install soap

# Download WordPress CLI.
RUN curl -L "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" > /usr/bin/wp && \
    chmod +x /usr/bin/wp

RUN { \
		echo 'file_uploads = On'; \
		echo 'post_max_size=100M'; \
		echo 'upload_max_filesize=100M'; \
	} > /usr/local/etc/php/conf.d/custom.ini
