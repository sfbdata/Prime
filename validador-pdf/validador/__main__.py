"""Entrada do contêiner: `python -m validador`."""

from __future__ import annotations

import logging
import signal
import sys
import threading

from . import conferencia, execucao
from .ancoras import RepositorioDeAncoras
from .configuracao import Configuracao
from .servidor import ServidorDoValidador


def principal() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s %(levelname)s %(name)s %(message)s',
        stream=sys.stderr,
    )
    conferencia.silenciar_bibliotecas()
    log = logging.getLogger('validador')
    configuracao = Configuracao.do_ambiente()
    if not configuracao.token:
        log.warning('ASSINATURA_VALIDADOR_TOKEN vazio: toda chamada com token responde 401')
    if not configuracao.govbr_raizes_sha256:
        log.warning('nenhuma raiz gov.br fixada: nenhuma assinatura gov.br fica confiavel')

    # O forkserver nasce antes de qualquer fio, já com o pyHanko carregado.
    execucao.preparar()
    ancoras = RepositorioDeAncoras(configuracao)
    servidor = ServidorDoValidador(configuracao, ancoras)
    ancoras.iniciar()

    def encerrar(*_):
        ancoras.parar()
        threading.Thread(target=servidor.shutdown, daemon=True).start()

    # Como PID 1 no contêiner, sem tratador o SIGTERM seria ignorado e o `docker stop` esperaria o SIGKILL.
    signal.signal(signal.SIGTERM, encerrar)
    signal.signal(signal.SIGINT, encerrar)
    log.info('validador ouvindo em %s:%d', configuracao.endereco, configuracao.porta)
    try:
        servidor.serve_forever()
    finally:
        servidor.server_close()
    return 0


if __name__ == '__main__':
    sys.exit(principal())
