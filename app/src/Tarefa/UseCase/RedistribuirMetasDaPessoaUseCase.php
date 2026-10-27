<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\RedistribuirMetasInput;
use App\Tarefa\DTO\ResultadoDaRedistribuicaoOutput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\RedistribuicaoForaDoEscritorioException;
use App\Tarefa\Exception\RedistribuicaoNaoPermitidaException;
use App\Tarefa\Exception\RedistribuicaoRecusadaException;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\Service\AuditoriaDaRedistribuicao;
use App\Tarefa\Service\GuardaDaRedistribuicao;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\Service\TransacaoDaPassagem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Confirmação do "Redistribuir" do perfil da pessoa (spec painel-acompanhamento-redistribuir, fatia B). Só o Master ou
 * um primário ativo; nunca por regra, agendamento ou sozinho (LGPD art. 20): o gestor escolheu cada meta e cada
 * destino e confirmou. Sem migration: reaproveita `tarefa_responsaveis`.
 *
 * Valida o lote INTEIRO antes de gravar (no máximo 50 metas; meta de outro escritório ou de pasta oculta é 404; destino
 * que não pode receber, repetida ou lote vazio é 400). Depois grava meta a meta, cada uma numa transação com a trava
 * da linha ({@see TransacaoDaPassagem}): relê, e se a meta foi concluída ou a origem deixou de ser responsável, PULA.
 * Remove a origem e põe o destino (se já era responsável, só sai a origem): a meta nunca fica sem responsável. Não mexe
 * em prazo, status, etapa, pasta, criador nem acompanhantes. Por meta: histórico "Responsável alterado de A para B por
 * C." ({@see AcaoDoDespacho::Redistribuicao}) e auditoria `meta_redis`. No fim: auditoria `ia_redis` e UM aviso por
 * destinatário e UM à origem, em texto neutro (sem desempenho, carga nem acompanhamento).
 */
final class RedistribuirMetasDaPessoaUseCase
{
    public const LIMITE_DE_METAS = 50;

    /** Tipo do aviso (string livre em `notificacao.tipo`), mapeado na Central em `TiposDeAvisoDaCentral`. */
    public const TIPO_NOTIFICACAO = 'tarefa_redistribuida';

    public function __construct(
        private readonly GuardaDaRedistribuicao $guarda,
        private readonly TarefaRepository $tarefas,
        private readonly AcessoAMeta $acessoAMeta,
        private readonly TransacaoDaPassagem $transacao,
        private readonly TarefaMensagemRepository $mensagens,
        private readonly TextoDoDespacho $texto,
        private readonly NotificacaoService $notificacoes,
        private readonly AuditoriaDaRedistribuicao $auditoria,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @throws RedistribuicaoNaoPermitidaException
     * @throws RedistribuicaoForaDoEscritorioException
     * @throws RedistribuicaoRecusadaException
     */
    public function executar(RedistribuirMetasInput $input): ResultadoDaRedistribuicaoOutput
    {
        $origem = $this->guarda->exigirOrigem($input->tenant, $input->ator, $input->origemId);
        $plano  = $this->validar($input, $origem);

        $feitas = [];
        foreach ($plano as $passo) {
            if ($this->passar($passo['meta'], $origem, $passo['destino'], $input)) {
                $feitas[] = $passo;
            }
        }
        $this->finalizar($input, $origem, $feitas);

        return new ResultadoDaRedistribuicaoOutput(count($feitas), count($plano) - count($feitas));
    }

    /**
     * @return list<array{meta: Tarefa, destino: User}>
     */
    private function validar(RedistribuirMetasInput $input, User $origem): array
    {
        $ids = array_column($input->movimentos, 'meta_id');
        if ($ids === [] || count($ids) > self::LIMITE_DE_METAS || count(array_unique($ids)) !== count($ids)) {
            throw new RedistribuicaoRecusadaException(sprintf('Escolha de 1 a %d metas, sem repetir.', self::LIMITE_DE_METAS));
        }

        $metas     = $this->tarefas->findPorIdsDoEscritorio($ids, $input->tenant);
        $elegiveis = $this->guarda->elegiveis($input->tenant, $origem);
        $plano     = [];
        foreach ($input->movimentos as $mov) {
            $meta = $metas[$mov['meta_id']] ?? throw new RedistribuicaoForaDoEscritorioException('Meta não encontrada.');
            $this->acessoAMeta->exigir($meta, $input->tenant, $input->ator);
            $destino = $elegiveis[$mov['destino_id']] ?? null;
            if ($destino === null || !$this->guarda->veAPasta($meta, $destino)) {
                throw new RedistribuicaoRecusadaException('Um dos destinos não pode receber a meta escolhida.');
            }
            $plano[] = ['meta' => $meta, 'destino' => $destino];
        }

        return $plano;
    }

    /** @return bool falso quando a meta mudou antes da confirmação e foi pulada */
    private function passar(Tarefa $meta, User $origem, User $destino, RedistribuirMetasInput $input): bool
    {
        return $this->transacao->emTransacao(function () use ($meta, $origem, $destino, $input): bool {
            $this->transacao->travarERecarregar($meta);
            if ($meta->getStatus() === Tarefa::STATUS_CONCLUIDA || !$meta->getResponsaveis()->contains($origem)) {
                return false;
            }
            $meta->addResponsavel($destino);
            $meta->removeResponsavel($origem);

            $texto = sprintf('Responsável alterado de %s para %s por %s.', self::nome($origem), self::nome($destino), self::nome($input->ator));
            $historico = (new TarefaMensagem())
                ->setUsuario($input->ator)
                ->setTenant($input->tenant)
                ->setMensagem($this->texto->paraHtml($texto))
                ->setAcao(AcaoDoDespacho::Redistribuicao)
                ->setCriadoEm($input->agora);
            $meta->addMensagem($historico);
            $this->mensagens->salvar($historico);
            $this->auditoria->meta($input->tenant, $input->ator, $meta, (int) $origem->getId(), (int) $destino->getId());

            return true;
        });
    }

    /** @param list<array{meta: Tarefa, destino: User}> $feitas */
    private function finalizar(RedistribuirMetasInput $input, User $origem, array $feitas): void
    {
        if ($feitas === []) {
            return;
        }
        $this->transacao->emTransacao(function () use ($input, $origem, $feitas): void {
            $this->auditoria->operacao($input->tenant, $input->ator, (int) $origem->getId(), count($feitas));
            $this->avisarDestinos($input->tenant, $feitas);
            $visiveis = array_values(array_filter($feitas, fn (array $f): bool => $this->guarda->veAPasta($f['meta'], $origem)));
            if ($visiveis !== []) {
                $this->avisar($origem, $input->tenant, array_column($visiveis, 'meta'), count($visiveis) === 1 ? '1 meta sua passou para outra pessoa.' : sprintf('%d metas suas passaram para outras pessoas.', count($visiveis)));
            }
        });
    }

    /** UM aviso por destinatário, com o total que ele recebeu. @param list<array{meta: Tarefa, destino: User}> $feitas */
    private function avisarDestinos(Tenant $tenant, array $feitas): void
    {
        $porDestino = [];
        foreach ($feitas as $f) {
            $porDestino[(int) $f['destino']->getId()]['destino'] = $f['destino'];
            $porDestino[(int) $f['destino']->getId()]['metas'][] = $f['meta'];
        }
        foreach ($porDestino as $grupo) {
            $n = count($grupo['metas']);
            $this->avisar($grupo['destino'], $tenant, $grupo['metas'], $n === 1 ? '1 meta passou para você.' : sprintf('%d metas passaram para você.', $n));
        }
    }

    /** @param list<Tarefa> $metas com uma só, o aviso leva à meta; com várias, a "Minhas metas" */
    private function avisar(User $quem, Tenant $tenant, array $metas, string $texto): void
    {
        $uma = count($metas) === 1;
        $url = $uma ? $this->urls->generate('tarefa_show', ['id' => $metas[0]->getId()]) : $this->urls->generate('tarefa_minhas');
        $this->notificacoes->criar($quem, $tenant, self::TIPO_NOTIFICACAO, $uma ? 'Meta redistribuída' : 'Metas redistribuídas', $texto, $uma ? $metas[0] : null)->setUrl($url);
    }

    private static function nome(User $pessoa): string
    {
        return trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail()));
    }
}
