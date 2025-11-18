FROM --platform=linux/amd64 php:8.3-fpm

# --- Dependencies for Oracle Instant Client on Debian 12 ---
RUN apt-get update && apt-get install -y \
        libaio-dev \
        libnsl-dev \
        libnsl2 \
        unzip \
        wget \
        libssl-dev \
        build-essential \
        autoconf \
    && rm -rf /var/lib/apt/lists/*

# --- Install Oracle Instant Client ---
RUN mkdir -p /opt/oracle && \
    cd /opt/oracle && \
    wget https://download.oracle.com/otn_software/linux/instantclient/instantclient-basic-linuxx64.zip && \
    wget https://download.oracle.com/otn_software/linux/instantclient/instantclient-sdk-linuxx64.zip && \
    unzip -oq instantclient-basic-linuxx64.zip -d /opt/oracle/instantclient && \
    unzip -oq instantclient-sdk-linuxx64.zip -d /opt/oracle/instantclient

# --- Register Oracle libs ---
RUN echo "/opt/oracle/instantclient/instantclient_23_26" > /etc/ld.so.conf.d/oracle-instantclient.conf \
    && ldconfig

# --- Oracle needs libnsl ---
RUN ln -s /usr/lib/x86_64-linux-gnu/libnsl.so.2 /usr/lib/libnsl.so.1 || true

# (rare case fix) Oracle sometimes expects libaio.so.1 outside
RUN ln -s /usr/lib/x86_64-linux-gnu/libaio.so /usr/lib/x86_64-linux-gnu/libaio.so.1 || true


ENV LD_LIBRARY_PATH=/opt/oracle/instantclient/instantclient_23_26

# --- Build OCI extensions ---
RUN docker-php-ext-configure oci8 --with-oci8=instantclient,/opt/oracle/instantclient/instantclient_23_26 && \
    docker-php-ext-install oci8

RUN docker-php-ext-configure pdo_oci --with-pdo-oci=instantclient,/opt/oracle/instantclient/instantclient_23_26 && \
    docker-php-ext-install pdo_oci

RUN docker-php-ext-install pdo pdo_mysql mysqli
