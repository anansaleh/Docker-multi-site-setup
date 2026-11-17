FROM nginx:1.27-alpine

# Remove default config; we supply our own
RUN rm -f /etc/nginx/conf.d/default.conf

# Our global config
COPY nginx.conf /etc/nginx/nginx.conf

# Vhost configs
COPY sites/ /etc/nginx/conf.d/

EXPOSE 80 443

CMD ["nginx", "-g", "daemon off;"]
