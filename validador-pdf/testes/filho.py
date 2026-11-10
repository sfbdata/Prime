"""Funções que os testes do protocolo põem no lugar da conferência para provocar falhas no processo filho.

O filho nasce do forkserver e recebe a função por referência (módulo e nome): ela tem de morar num módulo
importável, como este.
"""

from __future__ import annotations

import os
import resource
import signal


def falhar(*_argumentos):
    raise RuntimeError('falha de teste no filho')


def morrer(*_argumentos):
    os._exit(3)  # o filho cai sem responder pelo pipe


def morrer_por_sinal(*_argumentos):
    os.kill(os.getpid(), signal.SIGKILL)  # como o OOM killer


def estourar_memoria(*_argumentos):
    teto, _ = resource.getrlimit(resource.RLIMIT_AS)
    if teto == resource.RLIM_INFINITY:
        # Sem o teto do filho, alocar de verdade poderia derrubar a máquina: falha de outro jeito.
        raise RuntimeError('filho sem teto de memoria')
    return bytearray(teto)  # o espaço já usado mais o teto passa do teto: MemoryError
