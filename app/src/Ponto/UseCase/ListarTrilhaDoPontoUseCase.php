<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\PaginaDaTrilhaDoPontoOutput;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;

/**
 * Uma página da aba Auditoria do Ponto (E9, P64): os eventos do escritório, o mais recente primeiro, em texto de tela
 * (rótulo da ação, detalhe legível, selo abreviado), e a conferência da cadeia para o cabeçalho.
 *
 * Quem: só lê; a permissão (`GestaoDoPonto::veAuditoria`) é de quem chama. Página fora do intervalo cai na última.
 */
final class ListarTrilhaDoPontoUseCase
{
    public const POR_PAGINA = 50;

    /** Caracteres do selo mostrados na coluna (o desenho mostra 8); o selo inteiro vai no `title`. */
    private const SELO_CURTO = 8;

    public function __construct(
        private readonly TrilhaDoPontoRepository $trilha,
        private readonly ConferirTrilhaDoPontoUseCase $conferir,
    ) {
    }

    public function executar(Tenant $escritorio, int $pagina): PaginaDaTrilhaDoPontoOutput
    {
        $tenantId = $escritorio->getId() ?? throw new \LogicException('Escritório sem id na aba Auditoria.');
        $total    = $this->trilha->contar($tenantId);
        $paginas  = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina   = min(max(1, $pagina), $paginas);

        $eventos = array_map(static fn (array $linha): array => [
            'sequencia' => $linha['sequencia'],
            'dataHora'  => (new \DateTimeImmutable($linha['em']))->format('d/m/Y, H:i:s'),
            'autor'     => $linha['autor_nome'] ?? 'Sistema',
            'acao'      => AcaoDaTrilhaDoPonto::rotuloDe($linha['acao']),
            'detalhe'   => DetalheDaTrilhaDoPonto::texto($linha['acao'], $linha['detalhe']),
            'selo'      => $linha['selo'],
            'seloCurto' => $linha['selo'] === null ? null : substr($linha['selo'], 0, self::SELO_CURTO),
        ], $this->trilha->pagina($tenantId, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA));

        return new PaginaDaTrilhaDoPontoOutput($eventos, $pagina, $paginas, $total, $this->conferir->executar($escritorio));
    }
}
