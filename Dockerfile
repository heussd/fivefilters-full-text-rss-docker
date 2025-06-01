# Unofficial fivefilters Full-Text RSS service
# Enriches third-party RSS feeds with full text articles
# https://bitbucket.org/fivefilters/full-text-rss

FROM	alpine/git AS gitsrc
WORKDIR /ftr
RUN	git clone https://bitbucket.org/fivefilters/full-text-rss.git . && \
		git reset --hard 384d52fd83361ffd6e7f28bd39b322970a015a28


FROM	alpine/git AS gitconfig
WORKDIR	/ftr-site-config
RUN	git clone https://github.com/fivefilters/ftr-site-config . 


# Do not upgrade. More recent versions of PHP are seg faulting. 
FROM	php:5-apache

# https://unix.stackexchange.com/questions/371890/debian-the-repository-does-not-have-a-release-file#answer-743863
RUN 	echo "deb http://archive.debian.org/debian stretch main contrib non-free" > /etc/apt/sources.list

RUN   apt-get update && \
      apt-get install \
      	-y --allow-unauthenticated \
      	--no-install-recommends \
      libtidy-dev \
      && rm -rf /var/lib/apt/lists/*

RUN		docker-php-ext-install tidy


COPY php.ini /usr/local/etc/php/

COPY --from=gitsrc /ftr /var/www/html
COPY --from=gitconfig /ftr-site-config/.* /ftr-site-config/* /var/www/html/site_config/standard/

RUN		mkdir -p /var/www/html/cache/rss && \
			chmod -Rv 777 /var/www/html/cache && \
			chmod -Rv 777 /var/www/html/site_config

VOLUME	/var/www/html/cache

COPY	custom_config.php /var/www/html/

