#!/usr/bin/env bash
# Ajustes de OPcache para rodar a suíte, compartilhados por frente-testar.sh e testar-paralelo.sh.
#
# No container, opcache.enable_cli=0: o bytecode de cada classe carregada fica na memória do próprio
# processo do PHPUnit. A suíte serial carrega quase 7 mil classes (src, vendor, templates Twig
# compilados, proxies do Doctrine) e o pico chegava a ~400 MB só por isso. Com o OPcache ligado o
# código vai para a área compartilhada (que morre com o processo): pico ~208 MB e rodada mais rápida.
# Os tamanhos padrão (128M, 8M de strings) enchem no meio da suíte e o resto volta para a heap.
# revalidate_freq=0: arquivo PHP gerado durante a rodada (cache do Twig, proxies) nunca fica velho.
#
# Uso: source scripts/lib/opcache-de-teste.sh; php $OPCACHE_DE_TESTE bin/phpunit ...
OPCACHE_DE_TESTE="-d opcache.enable_cli=1 -d opcache.memory_consumption=512 -d opcache.interned_strings_buffer=32 -d opcache.max_accelerated_files=20000 -d opcache.revalidate_freq=0"
