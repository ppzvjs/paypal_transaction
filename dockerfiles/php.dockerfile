FROM --platform=linux/amd64 php:8.3-cli

RUN apt-get update \
    && apt-get install -y libaio-dev unzip libssl-dev gcc make autoconf wget


RUN mkdir -p /opt/oracle/instantclient && \
    cd /opt/oracle/instantclient && \
    wget https://download.oracle.com/otn_software/linux/instantclient/2326000/instantclient-basic-linux.x64-23.26.0.0.0.zip && \
    wget https://download.oracle.com/otn_software/linux/instantclient/2326000/instantclient-sdk-linux.x64-23.26.0.0.0.zip && \
    unzip instantclient-basic-linux.x64-23.26.0.0.0.zip && \
    rm -rf META-INF && \
    unzip instantclient-sdk-linux.x64-23.26.0.0.0.zip && \
    mv instantclient_23_26/* . && rmdir instantclient_23_26 && \
    rm -rf META-INF

RUN ln -sf /opt/oracle/instantclient/libclntsh.so.23.1 /opt/oracle/instantclient/libclntsh.so \
    && echo "/opt/oracle/instantclient" > /etc/ld.so.conf.d/oracle-instantclient.conf \
    && ldconfig

ENV LD_LIBRARY_PATH=/opt/oracle/instantclient
ENV ORACLE_HOME=/opt/oracle/instantclient
ENV PATH=$ORACLE_HOME:$PATH

RUN docker-php-ext-configure oci8 --with-oci8=instantclient,/opt/oracle/instantclient \
    && docker-php-ext-install oci8

RUN docker-php-ext-install pdo pdo_mysql mysqli
