<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\DTO\CompromissoOutput as C;
use App\Agenda\Enum\TipoDeAjuste;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Entity\Tenant\Tenant;
use App\Profile\Repository\UserProfileRepository;

/**
 * Os aniversários da equipe que a Agenda mostra no período, com os ajustes do escritório (oculto não aparece;
 * renomeado vem com o nome novo). Regra única da grade e do envio ao Google (spec agenda-google §9).
 */
final class AniversariosDaEquipe
{
    public function __construct(
        private readonly UserProfileRepository $perfis,
        private readonly AjusteDeDataRepository $ajustes,
    ) {
    }

    /** @return list<C> */
    public function noPeriodo(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $ini = $de->format('Y-m-d');
        $fim = $ate->format('Y-m-d');
        [$ocultos, $nomes] = $this->ajustesDeAniversario($tenant);
        $saida = [];
        foreach ($this->perfis->aniversariantesDoEscritorio($tenant) as $p) {
            for ($ano = (int) $de->format('Y'); $ano <= (int) $ate->format('Y'); ++$ano) {
                $md = $p['nascimento']->format('m-d');
                $data = $ano . '-' . ('02-29' === $md && !checkdate(2, 29, $ano) ? '02-28' : $md);
                $id = 'aniv-' . $p['id'] . '-' . $data;
                if ($data >= $ini && $data <= $fim && !isset($ocultos[$id])) {
                    $curto = implode(' ', array_slice(explode(' ', trim($p['nome'])), 0, 2));
                    $saida[] = new C($id, $data, $nomes[$id] ?? '🎂 Aniversário de ' . $curto, C::CAL_ANIV, C::ORIGEM_ANIV, true, usuarioId: $p['id'], donoNome: $p['nome'], editado: isset($nomes[$id]));
                }
            }
        }

        return $saida;
    }

    /**
     * Aniversário também é data do sistema no botão direito (desenho: ORG inclui "aniv"): pode ser
     * ocultado ou renomeado pelo escritório.
     *
     * @return array{array<string, true>, array<string, string>}
     */
    private function ajustesDeAniversario(Tenant $tenant): array
    {
        $ocultos = [];
        $nomes = [];
        foreach ($this->ajustes->doEscritorio($tenant) as $a) {
            $id = (string) $a->getDataId();
            if (!str_starts_with($id, 'aniv-')) {
                continue;
            }
            if (TipoDeAjuste::Ocultar === $a->getTipo()) {
                $ocultos[$id] = true;
            }
            if (TipoDeAjuste::Renomear === $a->getTipo()) {
                $nomes[$id] = (string) $a->getTitulo();
            }
        }

        return [$ocultos, $nomes];
    }
}
