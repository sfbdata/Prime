<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Auth\Repository\CadastroPendenteRepository;
use App\Auth\UseCase\ReemitirConfirmacaoDeCadastroUseCase;
use App\Comercial\Enum\Automacao;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use App\Comercial\Repository\CicloDaContaRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * As automações por tempo (spec §3.5): `lemb` (cadastro sem confirmação há 24/48/72 h, link novo, uma vez) e `dica`
 * (conta confirmada há 3/7/15 dias, uma vez). Só fatos depois do `ligada_em` de cada uma: nada para o passado. Lotes
 * de 50 por passo; o que sobra vai no próximo passo da cadeia.
 */
final class VarreduraDeAutomacoes
{
    public const LOTE = 50;

    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly CadastroPendenteRepository $pendentes,
        private readonly ReemitirConfirmacaoDeCadastroUseCase $reemitir,
        private readonly CicloDaContaRepository $ciclos,
        private readonly UserRepository $usuarios,
        private readonly DisparadorDeAutomacao $disparador,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function lembrarCadastrosNaoConfirmados(\DateTimeImmutable $agora): void
    {
        $estado = $this->automacoes->estado(Automacao::Lemb);
        if (!$estado->ligada || $estado->ligadaEm === null) {
            return;
        }
        $horas = (int) rtrim((string) $estado->atraso, 'h');
        foreach ($this->pendentes->semConfirmacaoParaLembrete($estado->ligadaEm, $agora->modify(sprintf('-%d hours', $horas)), self::LOTE) as $pendente) {
            $this->lembrar((int) $pendente->getId());
        }
    }

    public function mandarDicas(\DateTimeImmutable $agora): void
    {
        $estado = $this->automacoes->estado(Automacao::Dica);
        if (!$estado->ligada || $estado->ligadaEm === null) {
            return;
        }
        $dias = (int) rtrim((string) $estado->atraso, 'd');
        foreach ($this->ciclos->paraDica($estado->ligadaEm, $agora->modify(sprintf('-%d days', $dias)), self::LOTE) as $conta) {
            if (!$this->ciclos->marcarDica($conta['id'], $agora)) {
                continue;
            }
            $user = $this->usuarios->find($conta['user_id']);
            if ($user !== null && $user->getEmail() !== null) {
                $this->disparador->disparar(Automacao::Dica, (string) $user->getEmail(), $user->getFullName());
            }
        }
    }

    private function lembrar(int $pendenteId): void
    {
        try {
            $reemitido = $this->reemitir->executar($pendenteId);
        } catch (\DomainException) {
            return;   // confirmado ou lembrado entre a busca e agora
        }
        $link = $this->urls->generate('auth_cadastro_confirmar', ['token' => $reemitido->token], UrlGeneratorInterface::ABSOLUTE_URL);
        try {
            $this->disparador->disparar(Automacao::Lemb, $reemitido->email, $reemitido->nomeCompleto, ['urlDeConfirmacao' => $link]);
        } catch (\Throwable $erro) {
            $this->logger->error('Comercial: lembrete de confirmação não enfileirado.', ['pendenteId' => $pendenteId, 'erro' => $erro::class]);
        }
    }
}
