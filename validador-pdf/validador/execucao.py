"""Roda a conferência (e o selo) num processo filho, com tempo máximo de verdade.

Um fio não pode ser interrompido; um processo pode. O filho nasce do forkserver (pré-carregado com o pyHanko,
sem os fios do servidor), recebe os bytes pelo pipe e devolve ('ok', valor) ou ('erro', código). Estourou o
tempo: o filho é morto. Um PDF hostil que trava, estoura memória ou derruba o interpretador só leva o filho.
"""

from __future__ import annotations

import logging
import multiprocessing
from multiprocessing import forkserver
import os
import resource
from collections.abc import Callable
from typing import Any

log = logging.getLogger('validador.execucao')

_contexto = multiprocessing.get_context('forkserver')
_MODULOS_PRE_CARREGADOS = ['validador.conferencia']

# Teto de memória de cada filho (espaço de endereçamento). Configurável porque depende da máquina.
MEMORIA_DO_FILHO = int(os.environ.get('ASSINATURA_VALIDADOR_MEMORIA_MB', '1536')) * 1024 * 1024


class TempoEsgotado(Exception):
    pass


class FalhaNoFilho(Exception):
    pass


class ErroConhecido(Exception):
    """Erro que vira código no JSON (por exemplo `pdf_ilegivel`). A mensagem É o código."""


def preparar(modulos_extras: list[str] | None = None) -> None:
    """Chame uma vez no processo principal: sobe o forkserver já com o pyHanko carregado."""
    _contexto.set_forkserver_preload(_MODULOS_PRE_CARREGADOS + list(modulos_extras or []))
    forkserver.ensure_running()


def _alvo(escritor, funcao: Callable[..., Any], argumentos: tuple) -> None:
    try:
        resource.setrlimit(resource.RLIMIT_AS, (MEMORIA_DO_FILHO, MEMORIA_DO_FILHO))
    except (ValueError, OSError):
        pass
    try:
        resultado = ('ok', funcao(*argumentos))
    except ErroConhecido as erro:
        resultado = ('erro', str(erro))
    except MemoryError:
        resultado = ('erro', 'memoria_esgotada')
    except BaseException as erro:  # noqa: BLE001 (o filho nunca deixa escapar o PDF no traceback)
        resultado = ('falha', type(erro).__name__)
    try:
        escritor.send(resultado)
    finally:
        escritor.close()


def executar(funcao: Callable[..., Any], argumentos: tuple, tempo_maximo: float) -> Any:
    """Devolve o valor do filho ou levanta ErroConhecido, TempoEsgotado ou FalhaNoFilho."""
    leitor, escritor = _contexto.Pipe(duplex=False)
    processo = _contexto.Process(target=_alvo, args=(escritor, funcao, argumentos), daemon=True)
    processo.start()
    escritor.close()
    try:
        if not leitor.poll(tempo_maximo):
            raise TempoEsgotado()
        try:
            tipo, valor = leitor.recv()
        except (EOFError, OSError):
            raise FalhaNoFilho('sem_resposta') from None
    finally:
        leitor.close()
        if processo.is_alive():
            processo.kill()
        processo.join(timeout=5)
    if tipo == 'ok':
        return valor
    if tipo == 'erro':
        raise ErroConhecido(valor)
    log.error('falha no processo filho: %s', valor)
    raise FalhaNoFilho(valor)
