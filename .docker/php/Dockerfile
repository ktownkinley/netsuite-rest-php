FROM php:8.2-cli

RUN apt-get update \
	&& apt-get install -y git unzip libzip-dev libgmp-dev \
	&& docker-php-ext-install zip gmp \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

CMD ["bash"]
