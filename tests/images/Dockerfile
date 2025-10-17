# Testing image used for GitLab CI
# cspell:ignore REQS pycache xvfz ldconfig
FROM registry.gitlab.com/drupal-infrastructure/drupalci/drupalci-environments/php-8.3-apache:production

RUN sudo apt-get update -y && \
    sudo apt-get remove sqlite3 -y && \
    sudo apt-get install build-essential wget -y

# Update SQLite as the default image's version is below Drupal's requirements.
RUN cd /tmp && \
    wget https://www.sqlite.org/2025/sqlite-autoconf-3500400.tar.gz && \
    tar xvfz sqlite-autoconf-3500400.tar.gz &&\
    cd sqlite-autoconf-3500400 && \
    ./configure --prefix=/usr/local && \
    make && \
    sudo make install && \
    sudo ldconfig && \
    sudo ln -sf /usr/local/bin/sqlite3 /usr/bin/sqlite3
RUN sqlite3 --version

RUN rm -f /usr/bin/node /usr/bin/npm /usr/bin/npx
ENV NVM_DIR=/root/.nvm
ENV NODE_VERSION=24.10.0
RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
RUN . /root/.nvm/nvm.sh && nvm install $NODE_VERSION && nvm use $NODE_VERSION && nvm alias default $NODE_VERSION
ENV PATH=$NVM_DIR/versions/node/v$NODE_VERSION/bin:$PATH
ENV COMPOSER_IGNORE_PLATFORM_REQS=ext-sodium

RUN npx playwright install-deps

# Install projects so we can cache dependencies
# This can be removed when https://www.drupal.org/project/infrastructure/issues/3387117
# is implemented
COPY package.json package.json
COPY package-lock.json package-lock.json
RUN npm ci

RUN npx playwright install

COPY ui/package.json ui/package.json
COPY ui/package-lock.json ui/package-lock.json
COPY  ui/lib/astro-hydration/package.json  ui/lib/astro-hydration/package.json
RUN cd ui && npm install && cd ../

RUN composer create-project drupal/recommended-project _drupal
RUN cd _drupal && \
    composer config minimum-stability dev && \
    composer require drupal/core-dev "drupal/canvas @dev" drush/drush --with-all-dependencies && \
    cd web/modules/contrib/canvas && \
    composer run install-dev-deps && \
    cd ../../../../../
