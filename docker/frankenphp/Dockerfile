# La démo ERP servie par FrankenPHP en mode worker (phase 7, étape 9 : RG-07.5 sous charge)
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_mysql intl opcache

# un worker = un processus PHP qui sert des milliers de requêtes : la mémoire est mesurée
RUN { echo 'opcache.enable=1'; echo 'opcache.enable_cli=1'; echo 'memory_limit=256M'; } > /usr/local/etc/php/conf.d/prism-demo.ini
