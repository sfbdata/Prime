<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\EscritorioNoConectorOutput;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\LiberacaoDoConector;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;

/**
 * O conector de I.A. do escritório da sessão, para o cartão da tela "BlueJus IA" do administrador
 * (`docs/specs/mcp-remoto-oauth.md`, 14.a): a chave geral, o "ligado" e o aceite. Sem linha, ligado e aceite falsos.
 *
 * As conexões da equipe ficam de fora (decisão 5 do plano do PR 8: o administrador não as vê no PR 8), então a
 * contagem sai sempre zero aqui; quem a vê é o Master ({@see ObterConectorNoPainelDoMasterUseCase}).
 *
 * Com a chave geral `conector_ia` desligada pelo Master (emergência), o cartão mostra o aviso de conector fora do ar em
 * vez de "ligado", porque o `/mcp` responde 403 (decisão 11 do PR 8).
 */
final class ObterConectorDoEscritorioUseCase
{
    public function __construct(
        private readonly EscritorioDoConectorRepository $escritorios,
        private readonly RecursosDoSistema $recursos,
    ) {
    }

    public function executar(Tenant $tenant): EscritorioNoConectorOutput
    {
        $escritorio = $this->escritorios->buscarDoEscritorio($tenant);
        $temLinha = $escritorio instanceof EscritorioDoConector;

        return new EscritorioNoConectorOutput(
            tenantId: (int) $tenant->getId(),
            nome: (string) $tenant->getName(),
            chaveGeralLigada: $this->recursos->estaAtivo(LiberacaoDoConector::CHAVE_GERAL),
            ligado: $temLinha && $escritorio->isLigado(),
            aceiteEm: $temLinha ? $escritorio->getAceiteEm() : null,
            aceiteVigente: $temLinha && $escritorio->aceiteCobreVersaoVigente(),
            conexoesAtivas: 0,
        );
    }
}
