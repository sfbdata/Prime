<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * Os botões de status do candidato no detalhe da vaga (`aStatus`, desenho 06 l.1047): qualquer status
 * para qualquer outro, só a Central com `talentos` (decisão 3 da §4.4). A data da etapa é a de agora.
 * O dono externo não muda nada ("O BLUEJUS ATUALIZA O ANDAMENTO"). O candidato é avisado da mudança
 * no escritório gravado na candidatura (spec §4.7); o mesmo status de novo não avisa.
 */
final class MudarStatusDaCandidaturaUseCase
{
    public function __construct(
        private readonly PoliticaDaVaga $politica,
        private readonly CandidaturaRepository $candidaturas,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    public function executar(Contexto $ctx, int $id, StatusCandidatura $status): Candidatura
    {
        if (!$this->politica->podeGerirCandidatos($ctx)) {
            throw new AcaoNaoPermitida('Só a Central com acesso ao banco de talentos muda o andamento dos candidatos.');
        }
        $candidatura = $this->candidaturas->daCentralPorId($id) ?? throw new DemandaNaoEncontrada('Candidatura não encontrada.');

        $mudou = $candidatura->getStatus() !== $status;
        $candidatura->mudarStatus($status, new \DateTimeImmutable());
        if ($mudou) {
            $this->avisos->andamentoDaCandidatura($ctx, $candidatura);
        }
        $this->candidaturas->salvar($candidatura, flush: true);

        return $candidatura;
    }
}
