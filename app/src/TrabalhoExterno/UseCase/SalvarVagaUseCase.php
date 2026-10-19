<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * "Nova vaga" e "Divulgar uma vaga" (spec VS 18.10.26 §4.1).
 * Quem: a Central (Master, ou Equipe com `talentos`) publica na hora; o escritório (Solicitante)
 * envia para a Central aprovar, com o período cortado na divulgação máxima da configuração.
 * Alternativos: obrigatório faltando → DadosInvalidos (422); perfil sem a ação ou prévia → 403.
 */
final class SalvarVagaUseCase
{
    public function __construct(
        private readonly PoliticaDaVaga $politica,
        private readonly VagaRepository $vagas,
    ) {
    }

    public function executar(Contexto $ctx, DadosDaVaga $dados): Vaga
    {
        $dados->exigirCompleta();
        $agora = new \DateTimeImmutable();
        $vaga = match (true) {
            $this->politica->podeCriar($ctx) => Vaga::daCentral($ctx->usuario, $dados, $agora),
            $this->politica->podeDivulgar($ctx) && $ctx->tenant !== null => $this->doEscritorio($ctx, $dados, $agora),
            default => throw new AcaoNaoPermitida('Você não pode publicar vagas.'),
        };
        $this->vagas->salvar($vaga, flush: true);

        return $vaga;
    }

    private function doEscritorio(Contexto $ctx, DadosDaVaga $dados, \DateTimeImmutable $agora): Vaga
    {
        \assert($ctx->tenant !== null);
        $vaga = Vaga::doEscritorio($ctx->tenant, $ctx->usuario, $dados, $agora);
        $vaga->limitarPeriodo($ctx->configuracao->getVagas()['maxDias']);

        return $vaga;
    }
}
