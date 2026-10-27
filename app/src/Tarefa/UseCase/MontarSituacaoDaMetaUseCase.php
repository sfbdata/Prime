<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Tarefa\DTO\AguardandoNaMetaOutput;
use App\Tarefa\DTO\SituacaoDaMetaOutput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;

/**
 * Monta a situação da meta para a tela de detalhe (desenho 04B de 22/10, L50, L77, L85 e L131-164; spec
 * `minhas-metas-esteira-2210.md`, itens L2 a L4).
 *
 * Regras do desenho:
 * - atrasada = prazo vencido (hoje depois do prazo) em meta não concluída; os dias de atraso são corridos (L125);
 * - painel: Concluída, senão Aguardando revisão, senão Atrasada, senão Pendente (L136);
 * - faixa: some na concluída; em revisão aguarda o criador desde o envio para revisão, senão aguarda os
 *   responsáveis desde a criação (à 00:00); vermelha só se atrasada fora da revisão (L131-132). Os "N dias" da faixa
 *   são períodos de 24h a partir desse momento (`Math.floor((agora - base) / 86400s)`), não dias de calendário;
 * - "Etapa i/n · Nome" usa o nome que o escritório deu à etapa na caixa, como na lista (`MinhasMetasOutput`).
 */
final class MontarSituacaoDaMetaUseCase
{
    public function __construct(private readonly CaixaDaEsteiraRepository $caixas)
    {
    }

    public function executar(Tarefa $meta, Tenant $escritorio, \DateTimeImmutable $agora): SituacaoDaMetaOutput
    {
        $hoje         = $agora->setTime(0, 0);
        $concluida    = $meta->getStatus() === Tarefa::STATUS_CONCLUIDA;
        $emRevisao    = $meta->getStatus() === Tarefa::STATUS_EM_REVISAO;
        $prazo        = $meta->getPrazo();
        $diasDeAtraso = $prazo === null ? 0 : max(0, self::diasEntre($prazo, $hoje));
        $atrasada     = !$concluida && $diasDeAtraso > 0;
        $etapa        = $meta->getEtapa();
        $tipo         = $meta->getTipoDeDemanda();

        return new SituacaoDaMetaOutput(
            estado: match (true) {
                $concluida => SituacaoDaMetaOutput::CONCLUIDA,
                $emRevisao => SituacaoDaMetaOutput::REVISAO,
                $atrasada  => SituacaoDaMetaOutput::ATRASADA,
                default    => SituacaoDaMetaOutput::PENDENTE,
            },
            diasDeAtraso: $diasDeAtraso,
            atrasada: $atrasada,
            etapa: $etapa,
            posicao: $tipo->posicaoDe($etapa),
            total: count($tipo->sequencia()),
            nomeDaEtapa: $this->caixas->daEtapa($escritorio, $etapa)?->getNomeDaEtapa(),
            aguardando: $concluida ? null : self::aguardando($meta, $emRevisao, $atrasada && !$emRevisao, $agora),
            prazo: $prazo,
            criadaEm: $meta->getDataCriacao(),
            diasDesdeACriacao: max(0, self::diasEntre($meta->getDataCriacao(), $hoje)),
            despachante: $meta->getCriadoPor()?->getFullName() ?? $meta->getCriadoPor()?->getEmail(),
        );
    }

    private static function aguardando(Tarefa $meta, bool $emRevisao, bool $atrasada, \DateTimeImmutable $agora): ?AguardandoNaMetaOutput
    {
        $quem = self::quemAguarda($meta);
        if ($quem === []) {
            return null;
        }

        return new AguardandoNaMetaOutput(
            emRevisao: $emRevisao,
            pessoas: array_map(self::pessoa(...), $quem),
            dias: self::diasAguardando($meta, $agora),
            atrasada: $atrasada,
        );
    }

    /**
     * Com quem a meta está (L131): em revisão, o criador; senão, os responsáveis. É também a quem o Lembrar avisa.
     *
     * @return list<User>
     */
    public static function quemAguarda(Tarefa $meta): array
    {
        if ($meta->getStatus() !== Tarefa::STATUS_EM_REVISAO) {
            return array_values($meta->getResponsaveis()->toArray());
        }
        $criador = $meta->getCriadoPor();

        return $criador === null ? [] : [$criador];
    }

    /** Os "N dias" da faixa: períodos de 24h desde o envio para revisão, ou desde a criação (à 00:00). */
    public static function diasAguardando(Tarefa $meta, \DateTimeImmutable $agora): int
    {
        $desde = $meta->getStatus() === Tarefa::STATUS_EM_REVISAO ? self::envioParaRevisao($meta) : $meta->getDataCriacao()->setTime(0, 0);

        return max(0, intdiv($agora->getTimestamp() - $desde->getTimestamp(), 86400));
    }

    /** O envio para revisão mais recente (despacho com ação `envio`); sem ele, a última alteração da meta. */
    private static function envioParaRevisao(Tarefa $meta): \DateTimeImmutable
    {
        $envio = null;
        foreach ($meta->getMensagens() as $mensagem) {
            /** @var TarefaMensagem $mensagem */
            if ($mensagem->getAcao() === AcaoDoDespacho::Envio && ($envio === null || $mensagem->getCriadoEm() > $envio)) {
                $envio = $mensagem->getCriadoEm();
            }
        }

        return $envio ?? $meta->getDataAlteracao() ?? $meta->getDataCriacao();
    }

    /**
     * Nome curto e iniciais da faixa (L132): cada palavra com a inicial maiúscula; o nome é a primeira e a última
     * palavra, as iniciais são das duas primeiras.
     *
     * @return array{iniciais: string, nome: string}
     */
    private static function pessoa(User $pessoa): array
    {
        $palavras = preg_split('/\s+/u', mb_convert_case(trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail())), MB_CASE_TITLE), -1, PREG_SPLIT_NO_EMPTY) ?: [''];
        $nome     = count($palavras) > 1 ? $palavras[0] . ' ' . $palavras[count($palavras) - 1] : $palavras[0];
        $iniciais = implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice($palavras, 0, 2)));

        return ['iniciais' => $iniciais, 'nome' => $nome];
    }

    /** Dias corridos de `$de` até `$ate`, pelas datas (sem a hora); negativo se `$ate` vem antes. */
    private static function diasEntre(\DateTimeImmutable $de, \DateTimeImmutable $ate): int
    {
        return (int) $de->setTime(0, 0)->diff($ate->setTime(0, 0))->format('%r%a');
    }
}
