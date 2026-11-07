<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\JornadaColaboradorRepository;

/**
 * "Limpar escala": tira a jornada personalizada e todo o histórico dela (spec `docs/specs/ponto-jornada-vigencia.md`
 * §8.3 e §10.2, regra 5). A pessoa passa a seguir a jornada padrão do escritório em todos os dias, inclusive os
 * passados.
 *
 * Quem já tinha jornada antes das versões (alguma versão cobre os dias anteriores) fica com UMA versão sem blocos e
 * sem carga, cobrindo todos os dias: é o que o botão sempre fez, e o saldo segue sendo calculado pela padrão. Quem
 * só tem versões criadas pela tela volta a não ter linha nenhuma, como era antes de a jornada existir.
 */
final class LimparJornadaPessoalUseCase
{
    public function __construct(
        private readonly JornadaColaboradorRepository $jornadas,
    ) {
    }

    public function executar(User $pessoa, Tenant $escritorio): void
    {
        $versoes = $this->jornadas->historicoDe($pessoa, $escritorio)?->versoes() ?? [];
        if ($versoes === []) {
            return;
        }

        $cobria = false;
        foreach ($versoes as $versao) {
            $cobria = $cobria || $versao->cobreDiasAnteriores();
        }

        $fica = $cobria ? $versoes[0] : null;
        foreach ($versoes as $versao) {
            if ($versao !== $fica) {
                $this->jornadas->remover($versao);
            }
        }
        if ($fica !== null) {
            foreach ($fica->getBlocos()->toArray() as $bloco) {
                $fica->removeBloco($bloco);
            }
            $fica->setCargaHorariaSemanal(null);
            $fica->setCobreDiasAnteriores(true);
        }

        $this->jornadas->gravar();
    }
}
