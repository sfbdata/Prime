<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\Repository\CodigoDoConectorRepository;
use App\Conector\Repository\ConcessaoDoConectorRepository;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Conector\Repository\TokenDoConectorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * A faxina diária das tabelas do conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 9.9 e 13.2), rodada pelo
 * Scheduler (`app:conector:faxinar`). Em todos os escritórios de uma vez: é manutenção da plataforma e não lê dado
 * nenhum, só apaga o que já não serve a ninguém.
 *
 * 1. concessões vencidas ou revogadas há mais de 30 dias (a cascata leva os códigos e os tokens delas);
 * 2. tokens (acesso e renovação) vencidos há mais de 1 dia;
 * 3. códigos de autorização vencidos há mais de 1 dia;
 * 4. propostas decididas há mais de 1 dia (folga para a `confirmar_acao` repetida, PR 5) e pendentes vencidas (os dados
 *    delas já foram zerados, 6.7, item 6).
 *
 * Nada em uso sai: token de acesso dentro da 1 hora, renovação de cadeia viva (vale até o fim da concessão), concessão
 * dentro dos 7 dias e não revogada, proposta pendente no prazo. Consentimento e cliente nunca saem daqui. Tudo numa
 * transação.
 */
final class FaxinarConectorUseCase
{
    public const FOLGA_DE_CODIGO_E_TOKEN = 'P1D';
    public const FOLGA_DE_CONCESSAO = 'P30D';

    public function __construct(
        private readonly ConcessaoDoConectorRepository $concessoes,
        private readonly TokenDoConectorRepository $tokens,
        private readonly CodigoDoConectorRepository $codigos,
        private readonly PropostaDoConectorRepository $propostas,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return array{concessoes: int, tokens: int, codigos: int, propostas: int} quantas linhas saíram de cada tabela */
    public function executar(): array
    {
        $agora = $this->relogio->now();
        $umDiaAtras = $agora->sub(new \DateInterval(self::FOLGA_DE_CODIGO_E_TOKEN));
        $trintaDiasAtras = $agora->sub(new \DateInterval(self::FOLGA_DE_CONCESSAO));

        return $this->em->wrapInTransaction(fn (): array => [
            'concessoes' => $this->concessoes->apagarEncerradasAntesDe($trintaDiasAtras),
            'tokens' => $this->tokens->apagarVencidosAntesDe($umDiaAtras),
            'codigos' => $this->codigos->apagarVencidosAntesDe($umDiaAtras),
            'propostas' => $this->propostas->apagarDecididasOuVencidas($agora, $umDiaAtras),
        ]);
    }
}
