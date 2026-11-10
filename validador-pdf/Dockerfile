# syntax=docker/dockerfile:1
# Validador de PDF assinado da Assinatura de documentos (docs/specs/assinatura-documentos-etapa-6.md, E6-1).
# Dois alvos: `producao` (o serviço validador_pdf) e `teste` (a mesma base com pytest e os testes, sem rede):
#   docker build --target teste -t bluejus-validador-pdf:teste validador-pdf
#   docker run --rm --network none bluejus-validador-pdf:teste
# Python fixado pela tag e pelo digest (nunca latest): trocar de versão é um PR que muda esta linha e refaz os
# hashes dos requirements.
FROM python:3.12.16-slim-trixie@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_ROOT_USER_ACTION=ignore

# Dependências num venv sem pip: só wheels (nada compila) e só com o hash conferido.
FROM base AS dependencias
COPY requirements.txt /tmp/requirements.txt
RUN python -m venv --without-pip /opt/venv \
 && pip --python /opt/venv/bin/python install --require-hashes --only-binary=:all: -r /tmp/requirements.txt

FROM dependencias AS dependencias-teste
COPY requirements-teste.txt /tmp/requirements-teste.txt
RUN pip --python /opt/venv/bin/python install --require-hashes --only-binary=:all: -r /tmp/requirements-teste.txt

# O que roda: sem pip, usuário sem root, /dados para o volume das âncoras (validador_ancoras).
# Em produção o compose monta a raiz só de leitura e /tmp em tmpfs (o forkserver usa um socket em /tmp).
FROM base AS execucao
RUN python -m pip uninstall --yes --quiet pip \
 && useradd --system --uid 10001 --user-group --no-create-home --home-dir /nonexistent \
        --shell /usr/sbin/nologin validador \
 && mkdir -p /dados \
 && chown 10001:10001 /dados
ENV PATH=/opt/venv/bin:$PATH
WORKDIR /app

FROM execucao AS teste
COPY --from=dependencias-teste /opt/venv /opt/venv
COPY validador /app/validador
COPY testes /app/testes
COPY pytest.ini /app/pytest.ini
USER 10001:10001
CMD ["python", "-m", "pytest", "-q"]

FROM execucao AS producao
COPY --from=dependencias /opt/venv /opt/venv
COPY validador /app/validador
RUN python -m compileall -q /app/validador
USER 10001:10001
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/saude', timeout=4)"]
CMD ["python", "-m", "validador"]
