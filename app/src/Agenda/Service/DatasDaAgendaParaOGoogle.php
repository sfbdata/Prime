<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Contract\DatasParaOGoogle;
use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\UseCase\ListarDatasDoSistemaUseCase;
use App\Repository\UserTenantRepository;

/**
 * TUDO o que a Agenda mostra sem ninguém cadastrar (decisão do dono, spec agenda-google §9): feriados, recesso,
 * tribunais e eleições, comemorativas, datas do escritório e aniversários da equipe, com os ajustes do escritório e na
 * UF da lotação da pessoa, como na grade dela. As mesmas regras da grade, sem cópia.
 */
final class DatasDaAgendaParaOGoogle implements DatasParaOGoogle
{
    public function __construct(
        private readonly ListarDatasDoSistemaUseCase $datas,
        private readonly AniversariosDaEquipe $aniversarios,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    public function daConexao(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $tenant = $conexao->getTenant();
        $lotacao = $this->vinculos->findPorUserETenant($conexao->getUsuario(), $tenant)?->getLotacao()?->getNome();
        $saida = [];
        foreach ($this->datas->executar($tenant, $lotacao, $de, $ate)['datas'] as $d) {
            $saida[$d->id] ??= ['data' => $d->data, 'titulo' => $d->titulo, 'observacao' => $d->observacao];
        }
        foreach ($this->aniversarios->noPeriodo($tenant, $de, $ate) as $a) {
            $saida[$a->id] ??= ['data' => $a->data, 'titulo' => $a->titulo, 'observacao' => null];
        }

        return $saida;
    }
}
