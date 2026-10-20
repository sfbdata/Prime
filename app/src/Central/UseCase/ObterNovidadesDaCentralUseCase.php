<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\NovidadesOutput;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Central\Service\LeituraDasMensagens;
use App\Central\DTO\ContagemDeAvisos;
use App\Central\Service\ContadorDeAvisos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * O polling da Central (D2): as mensagens acima do cursor nas conversas de quem pede, mais o total de não
 * lidas. Só leitura — o cursor do polling é da tela, não é o cursor de leitura.
 *
 * `desde = null` é a primeira chamada da tela: devolve só o cursor atual, sem mensagens — o histórico vem pela
 * conversa aberta, e despejar as 200 mais antigas como "novidade" seria falso. `desde = 0` é um cursor como
 * outro qualquer (quem ainda não tinha mensagem nenhuma): devolve tudo acima de 0. Misturar os dois perdia a
 * primeira mensagem de quem começou sem nenhuma.
 *
 * O texto (das novas e da janela de 30 s) sai por {@see LeituraDasMensagens}: despacho de pasta que eu não vejo vem
 * como "Item de pasta sem acesso".
 */
final class ObterNovidadesDaCentralUseCase
{
    public const TETO = 200;

    /** Janela de sobreposição do polling (B, spec §6): repete as mensagens dos últimos 30 s; a tela descarta as vistas. */
    public const JANELA_SEGUNDOS = 30;

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagemRepository $mensagens,
        private readonly ClockInterface $clock,
        private readonly ParticipanteRepository $participantes,
        private readonly AvisoDeMencaoNaCentral $avisos,
        private readonly LeituraDasMensagens $leitura,
        // Opcional só para não mexer nos testes unitários antigos do polling; no container o autowiring sempre injeta
        // (provado pelo teste funcional das contagens).
        private readonly ?ContadorDeAvisos $contadorDeAvisos = null,
    ) {
    }

    /** @throws SemAcessoACentralException */
    public function executar(User $eu, ?Tenant $tenant, ?int $desde): NovidadesOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        // O total do cabeçalho deixa de fora as conversas silenciadas (B, spec §4); a conversa segue com as dela.
        $naoLidas = array_sum($this->mensagens->naoLidasPorConversa($eu, $tenant, semSilenciadas: true));
        // Lançador flutuante: em que conversas há menção por ver ("@n", marcador da linha, filtro "Menções").
        $comMencao = $this->avisos->conversasComMencaoNaoLida($eu, $tenant, $this->participantes->idsDasConversasAtivas($eu, $tenant));

        // F1: os avisos do sino na Caixa, só contados (com a rechecagem de acesso): o lançador anda sem a lista.
        $avisos = $this->contadorDeAvisos?->contar($eu, $tenant) ?? new ContagemDeAvisos(0, 0, 0);

        if ($desde === null) {
            return new NovidadesOutput([], $this->mensagens->ultimoIdVisivelPara($eu, $tenant), $naoLidas, true, $comMencao,
                $avisos->naoLidos, $avisos->urgentes, $avisos->ultimo, $avisos->importantes);
        }

        $novas = $this->mensagens->novidades($eu, $tenant, $desde, self::TETO);
        $janela = $this->clock->now()->modify(sprintf('-%d seconds', self::JANELA_SEGUNDOS));
        $repetidas = $this->mensagens->recentesAbaixoDoCursor($eu, $tenant, $desde, $janela, self::TETO);

        $cursor = $desde;
        foreach ($novas as $mensagem) {
            $cursor = max($cursor, (int) $mensagem->getId());
        }

        // `completo` conta só as NOVAS: a janela repete, não é motivo para a tela pedir de novo.
        return new NovidadesOutput(
            mensagens: $this->leitura->paraLeitor(array_merge($repetidas, $novas), $eu),
            cursor: $cursor,
            naoLidas: $naoLidas,
            completo: count($novas) < self::TETO,
            conversasComMencao: $comMencao,
            avisosNaoLidos: $avisos->naoLidos,
            avisosUrgentes: $avisos->urgentes,
            ultimoAviso: $avisos->ultimo,
            avisosImportantes: $avisos->importantes,
        );
    }
}
