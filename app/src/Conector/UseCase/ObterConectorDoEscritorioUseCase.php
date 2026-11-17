<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\EscritorioNoConectorOutput;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Entity\Tenant\Tenant;

/**
 * O conector de I.A. do escritório da sessão, para o cartão da tela "BlueJus IA" do administrador
 * (`docs/specs/mcp-remoto-oauth.md`, 14.a): liberado pelo Master, ligado e o aceite. Sem linha, tudo falso.
 *
 * As conexões da equipe ficam de fora (decisão 5 do plano do PR 8: o administrador não as vê no PR 8), então a
 * contagem sai sempre zero aqui; quem a vê é o Master ({@see ObterConectorNoPainelDoMasterUseCase}).
 */
final class ObterConectorDoEscritorioUseCase
{
    public function __construct(
        private readonly EscritorioDoConectorRepository $escritorios,
    ) {
    }

    public function executar(Tenant $tenant): EscritorioNoConectorOutput
    {
        $escritorio = $this->escritorios->buscarDoEscritorio($tenant);
        $temLinha = $escritorio instanceof EscritorioDoConector;

        return new EscritorioNoConectorOutput(
            tenantId: (int) $tenant->getId(),
            nome: (string) $tenant->getName(),
            liberado: $temLinha && $escritorio->isLiberadoPeloMaster(),
            ligado: $temLinha && $escritorio->isLigado(),
            aceiteEm: $temLinha ? $escritorio->getAceiteEm() : null,
            aceiteVigente: $temLinha && $escritorio->aceiteCobreVersaoVigente(),
            conexoesAtivas: 0,
        );
    }
}
