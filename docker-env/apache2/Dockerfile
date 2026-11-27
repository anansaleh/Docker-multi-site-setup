FROM httpd:2.4-alpine

# Enable required modules
RUN sed -i \
    -e 's/^#\(LoadModule ssl_module\)/\1/' \
    -e 's/^#\(LoadModule socache_shmcb_module\)/\1/' \
    -e 's/^#\(LoadModule rewrite_module\)/\1/' \
    -e 's/^#\(LoadModule proxy_module\)/\1/' \
    -e 's/^#\(LoadModule proxy_fcgi_module\)/\1/' \
    -e 's/^#\(LoadModule headers_module\)/\1/' \
    /usr/local/apache2/conf/httpd.conf

# Replace default httpd.conf with our own base config
COPY vhost.conf /usr/local/apache2/conf/httpd.conf

# Copy vhost configs
COPY sites/ /usr/local/apache2/conf/sites/

EXPOSE 8080 8443

CMD ["httpd-foreground"]
