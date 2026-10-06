<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Filter;

use Doctrine\ORM\EntityManagerInterface;

/**
 * A ÚNICA porta para enxergar o que está na lixeira: desliga o {@see LixeiraFilter} só durante o
 * callback e religa no `finally`, mesmo com exceção. Três consumidores, e só três: restaurar,
 * listar a lixeira e purgar.
 *
 * Por que uma classe e não `getFilters()->disable()` espalhado: o filtro desligado e esquecido
 * faria a próxima consulta do MESMO request devolver item excluído como se vivo — o modo de falha
 * silencioso que a lixeira existe para impedir. Aqui o escopo é mínimo por construção.
 *
 * Duas armadilhas de quem usa:
 *  - tudo que precisa da lixeira (consulta, travessia das coleções e o `flush`) acontece DENTRO do
 *    callback; um proxy de seção excluída iniciado depois de religar o filtro falha com
 *    `EntityNotFoundException`;
 *  - entidade já carregada com o filtro ligado tem as coleções inicializadas SEM os itens da
 *    lixeira, e desligar o filtro não as reabastece (o Doctrine não relê o que está no identity
 *    map). Carregue dentro do escopo.
 */
final class AcessoALixeira
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function comLixeiraVisivel(callable $trabalho): mixed
    {
        $filtros      = $this->em->getFilters();
        $estavaLigado = $filtros->isEnabled(LixeiraFilter::NOME);

        if ($estavaLigado) {
            $filtros->disable(LixeiraFilter::NOME);
        }

        try {
            return $trabalho();
        } finally {
            if ($estavaLigado) {
                $filtros->enable(LixeiraFilter::NOME);
            }
        }
    }
}
