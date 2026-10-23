<?php

declare(strict_types=1);

namespace App\Shared\Scheduler\Twig;

use App\Shared\Scheduler\QuemVeAvisoDoAgendador;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `aviso_de_falha_agendada()`: as falhas pendentes das tarefas agendadas para o aviso da moldura
 * (`layout/_globais/aviso_falha_agendada.html.twig`), ou null quando não há aviso.
 *
 * Só consulta para quem vê o aviso ({@see QuemVeAvisoDoAgendador}); para os demais não toca no banco.
 * Uma consulta por requisição, barata: pendentes com LIMIT, no índice (dispensada_em, ocorrida_em).
 * Uma falha aqui some com o aviso, nunca com a página.
 */
final class AvisoDeFalhaAgendadaExtension extends AbstractExtension implements ResetInterface
{
    /** Quantas falhas o aviso lista; as mais antigas ficam na Central do Master. */
    public const LIMITE = 5;

    private bool $montado = false;

    /** @var array{itens: list<array{comando: string, hora: string, data: string}>, mais: bool}|null */
    private ?array $aviso = null;

    public function __construct(
        private readonly Security $security,
        private readonly QuemVeAvisoDoAgendador $quemVe,
        private readonly FalhaAgendadaRepository $falhas,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('aviso_de_falha_agendada', $this->avisoDeFalhaAgendada(...))];
    }

    /** @return array{itens: list<array{comando: string, hora: string, data: string}>, mais: bool}|null */
    public function avisoDeFalhaAgendada(): ?array
    {
        if (!$this->montado) {
            $this->aviso   = $this->quemVe->podeVer($this->security->getUser()) ? $this->montarSemDerrubar() : null;
            $this->montado = true;
        }

        return $this->aviso;
    }

    public function reset(): void
    {
        $this->montado = false;
        $this->aviso   = null;
    }

    /** @return array{itens: list<array{comando: string, hora: string, data: string}>, mais: bool}|null */
    private function montarSemDerrubar(): ?array
    {
        try {
            $falhas = $this->falhas->pendentesRecentes(self::LIMITE + 1);
        } catch (\Throwable $e) {
            $this->logger->error('Aviso de falha do agendador não montado: {erro}', ['erro' => $e->getMessage()]);

            return null;
        }
        if ($falhas === []) {
            return null;
        }

        $itens = [];
        foreach (array_slice($falhas, 0, self::LIMITE) as $falha) {
            $itens[] = [
                'comando' => $falha->getComando(),
                'hora'    => $falha->getOcorridaEm()->format('H:i'),
                'data'    => $falha->getOcorridaEm()->format('d/m'),
            ];
        }

        return ['itens' => $itens, 'mais' => count($falhas) > self::LIMITE];
    }
}
