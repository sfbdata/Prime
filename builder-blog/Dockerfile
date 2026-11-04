# Builder dos blogs (spec docs/specs/painel-artigos-blogs.md, seção 6.1): gera o Blog BlueJus e o Blog BlueJus
# Grátis com os artigos que o BlueJus põe em /pedidos e publica em /sites-publicados/{blog,gratis}.
# Versão do node fixada pela tag (nunca latest): trocar de versão é um PR que muda esta linha.
FROM node:22.23.4-alpine3.24

RUN apk add --no-cache git rsync openssh-client

# Pontos de montagem com o dono 33:33 (o www-data do php, que o builder usa como user). Volume nomeado vazio
# herda o dono do ponto de montagem da imagem do PRIMEIRO contêiner que o monta já com o caminho existindo:
# é isto que deixa o uid 33 escrever em publicacao_blogs e em blog_builder_cache (detalhe no compose).
RUN mkdir -p /pedidos /cache \
    && chown 33:33 /pedidos /cache

# O ssh sai com "No user exists for uid 33" quando o uid não está no /etc/passwd: garante a entrada (o Alpine
# pode ou não ter o 33, conforme a versão).
RUN grep -q '^[^:]*:x:33:' /etc/group || echo 'builder:x:33:' >> /etc/group; \
    grep -q '^[^:]*:x:33:' /etc/passwd || echo 'builder:x:33:33:builder dos blogs:/cache:/sbin/nologin' >> /etc/passwd

COPY laco.sh /usr/local/bin/laco.sh
RUN chmod 755 /usr/local/bin/laco.sh

ENV HOME=/cache
USER 33:33
WORKDIR /cache

CMD ["/usr/local/bin/laco.sh"]
