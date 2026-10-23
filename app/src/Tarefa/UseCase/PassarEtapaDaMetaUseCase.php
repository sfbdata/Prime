<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\PassagemFeitaOutput;
use App\Tarefa\DTO\PassarEtapaInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\Exception\PassagemRecusadaException;
use App\Tarefa\Service\CodigoDoRegistro;
use App\Tarefa\Service\NomesDasEtapas;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\Service\RotaDaPassagem;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\Service\TransacaoDaPassagem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Concluir etapa e passar adiante (desenhos 04 L131, L174-182 e 04B L88-101, L177-179; spec
 * `minhas-metas-esteira-2210.md`, Fase 3, D3, L11 a L14).
 *
 * Quem pode: o responsável pela etapa ou quem criou a meta (D3). Numa transação só ({@see TransacaoDaPassagem}):
 * conclui a meta atual; cria a meta da etapa destino ("Etapa: primeira linha das pendências", até 60 caracteres),
 * numerada no escritório, com a prioridade, a pasta, o tipo de demanda e as etapas saltadas; grava no histórico da
 * meta atual "X concluída → Y (Resp)" com "Pendências: ..." como ato registrado (código D1, linha "Etapa concluída");
 * avisa o responsável novo e quem a caixa destino avisa, sem repetir para quem já é o responsável.
 *
 * Sem etapa depois da atual, só conclui ("X concluída · pasta encerrada"), sem meta nova nem aviso. Voltar de etapa
 * exige o motivo nas pendências (spec 2.2). Os avisos usam o tipo "tarefa criada" da Central (spec 2.2: os tipos do
 * protótipo não existem na Central); ninguém é avisado do que ele mesmo fez, nem quem saiu do escritório ou não vê a
 * pasta.
 */
final class PassarEtapaDaMetaUseCase
{
    public const PRAZO_PADRAO_EM_DIAS_UTEIS = 2;

    /** Quantos caracteres da primeira linha das pendências entram no título da meta nova (04 L175). */
    public const LIMITE_DA_LINHA_NO_TITULO = 60;

    public const LIMITE_DO_TEXTO = 2000;

    public function __construct(
        private readonly TransacaoDaPassagem $transacao,
        private readonly TarefaMensagemRepository $mensagens,
        private readonly CodigoDoRegistro $codigo,
        private readonly NomesDasEtapas $nomes,
        private readonly NotificacaoService $notificacoes,
        private readonly UserTenantRepository $vinculos,
        private readonly DiasUteisDoEscritorio $diasUteis,
        private readonly TextoDoDespacho $texto,
        private readonly UrlGeneratorInterface $urls,
        private readonly QuemCuidaDaMeta $quemCuida,
    ) {
    }

    public function executar(Tarefa $meta, User $quem, Tenant $escritorio, PassarEtapaInput $input, ?User $responsavel, \DateTimeImmutable $agora): PassagemFeitaOutput
    {
        $this->exigirQuePodePassar($meta, $quem, $escritorio);
        $tipo = TipoDeDemanda::tryFrom($input->tipoDeDemanda) ?? throw PassagemRecusadaException::tipoInvalido();
        if (mb_strlen($input->pendencias) > self::LIMITE_DO_TEXTO || mb_strlen($input->feito) > self::LIMITE_DO_TEXTO) {
            throw PassagemRecusadaException::textoLongo();
        }
        $atual = $meta->getEtapa();
        $destino = $input->destino === '' ? RotaDaPassagem::proximaPadrao($tipo, $atual) : RotaDaPassagem::destinoEscolhido($tipo, $atual, $input->destino);
        if ($destino !== null && RotaDaPassagem::ehVolta($atual, $destino) && trim($input->pendencias) === '') {
            throw PassagemRecusadaException::voltaSemMotivo();
        }
        $prazo = $destino === null ? null : $this->prazo($input->prazo, $escritorio, $agora);

        $nova = $this->transacao->emTransacao(function () use ($meta, $quem, $escritorio, $input, $responsavel, $agora, $tipo, $destino, $prazo): ?Tarefa {
            // A conferência de fora só poupa trabalho; a que vale é esta, com a meta travada e relida do banco.
            $this->transacao->travarERecarregar($meta);
            $this->exigirQuePodePassar($meta, $quem, $escritorio);
            $nova = $destino === null ? null : $this->criarMetaNova($meta, $quem, $input, $responsavel, $tipo, $destino, $prazo);
            $meta->setStatus(Tarefa::STATUS_CONCLUIDA)->setDataConclusao($agora);
            if ($nova !== null) {
                $this->transacao->gravarMetaNova($nova);
            }
            $this->registrar($meta, $quem, $escritorio, $input, $responsavel, $destino, $agora);
            if ($nova !== null) {
                $this->avisar($meta, $nova, $quem, $escritorio, $input, $responsavel);
            }

            return $nova;
        });

        return new PassagemFeitaOutput(
            $destino === null ? null : $this->nomes->nome($escritorio, $destino),
            self::pasta($meta),
            $nova?->getId(),
            $destino,
        );
    }

    /** D3: o responsável pela etapa ou quem criou a meta; meta de outro escritório ou já concluída, nunca. */
    private function exigirQuePodePassar(Tarefa $meta, User $quem, Tenant $escritorio): void
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw PassagemRecusadaException::foraDoEscritorio();
        }
        if (!$this->quemCuida->ehResponsavelOuCriador($meta, $quem)) {
            throw PassagemRecusadaException::semPermissaoParaPassar();
        }
        if ($meta->getStatus() === Tarefa::STATUS_CONCLUIDA) {
            throw PassagemRecusadaException::jaConcluida();
        }
    }

    /** O prazo escolhido (não antes de hoje) ou o padrão de 2 dias úteis pela regra do escritório (C1). */
    private function prazo(?string $escolhido, Tenant $escritorio, \DateTimeImmutable $agora): \DateTimeImmutable
    {
        $hoje = $agora->setTime(0, 0);
        if ($escolhido === null || trim($escolhido) === '') {
            return $this->diasUteis->somarUteis($hoje, self::PRAZO_PADRAO_EM_DIAS_UTEIS, $escritorio->getEstado());
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($escolhido), $agora->getTimezone());
        if ($data === false || $data->format('Y-m-d') !== trim($escolhido)) {
            throw PassagemRecusadaException::prazoIlegivel();
        }
        if ($data < $hoje) {
            throw PassagemRecusadaException::prazoInvalido();
        }

        return $data;
    }

    private function criarMetaNova(Tarefa $meta, User $quem, PassarEtapaInput $input, ?User $responsavel, TipoDeDemanda $tipo, EtapaDaPasta $destino, \DateTimeImmutable $prazo): Tarefa
    {
        $escritorio = $meta->getTenant() ?? throw new \LogicException('Meta sem escritório.');
        $linha = trim(explode("\n", TextoDoDespacho::normalizar($input->pendencias))[0]);
        $nova = (new Tarefa())
            ->setTitulo($this->nomes->nome($escritorio, $destino) . ($linha === '' ? '' : ': ' . mb_substr($linha, 0, self::LIMITE_DA_LINHA_NO_TITULO)))
            ->setDescricao(self::descricaoDaNova($input))
            ->setStatus(Tarefa::STATUS_PENDENTE)
            ->setPrioridade($meta->getPrioridade())
            ->setPasta($meta->getPasta())
            ->setTenant($escritorio)
            ->setCriadoPor($quem)
            ->setEtapa($destino)
            ->setTipoDeDemanda($tipo)
            ->setEtapasSaltadas(RotaDaPassagem::saltadas($meta->getEtapasSaltadas(), $tipo, $meta->getEtapa(), $destino))
            ->setPrazo($prazo);
        if ($responsavel !== null) {
            $nova->addResponsavel($responsavel);
        }

        return $nova;
    }

    /**
     * A descrição da meta nova como o desenho a mostra (04 L178 grava `obs` e `pend`; 04 L218 abre com
     * `m.obs || 'Pendências:\n' + m.pend`): o que foi feito, quando escrito, e as pendências para o próximo setor
     * logo abaixo, para não se perderem (a meta só tem um campo de descrição).
     */
    private static function descricaoDaNova(PassarEtapaInput $input): string
    {
        $feito = TextoDoDespacho::normalizar($input->feito);
        $pendencias = TextoDoDespacho::normalizar($input->pendencias);
        $blocoDasPendencias = $pendencias === '' ? '' : "Pendências:\n" . $pendencias;
        if ($feito === '') {
            return $blocoDasPendencias;
        }

        return $blocoDasPendencias === '' ? $feito : $feito . "\n\n" . $blocoDasPendencias;
    }

    /** O ato no histórico da meta concluída (04B L178), registrado com autoria e código (D1). */
    private function registrar(Tarefa $meta, User $quem, Tenant $escritorio, PassarEtapaInput $input, ?User $responsavel, ?EtapaDaPasta $destino, \DateTimeImmutable $agora): void
    {
        $ato = (new TarefaMensagem())
            ->setUsuario($quem)
            ->setTenant($escritorio)
            ->setMensagem($this->texto->paraHtml($this->textoDoAto($meta, $escritorio, $input, $responsavel, $destino)))
            ->setAcao(AcaoDoDespacho::Etapa)
            ->setDestinatario($responsavel)
            ->setCriadoEm($agora);
        $ato->registrarAutoria(
            (string) ($quem->getFullName() ?? $quem->getEmail()),
            $this->vinculos->findPorUserETenant($quem, $escritorio)?->getCargo()?->getNome(),
        );
        $meta->addMensagem($ato);
        $this->mensagens->salvar($ato);
        $this->mensagens->confirmar();
        $this->codigo->gravar($ato);
    }

    private function textoDoAto(Tarefa $meta, Tenant $escritorio, PassarEtapaInput $input, ?User $responsavel, ?EtapaDaPasta $destino): string
    {
        $texto = $this->nomes->nome($escritorio, $meta->getEtapa()) . ' concluída' . ($destino === null
            ? ' · pasta encerrada'
            : ' → ' . $this->nomes->nome($escritorio, $destino) . ' (' . ($responsavel === null ? $this->nomes->setor($destino) : self::nome($responsavel)) . ')');
        // Sem responsável, quem cuida é o setor da etapa (04 L178, `dest: Fh.resp || Hp[3]`).
        $pendencias = TextoDoDespacho::normalizar($input->pendencias);
        if ($pendencias !== '') {
            $texto .= "\nPendências: " . $pendencias;
        }
        // O que foi feito vai na meta nova; sem ela (pasta encerrada), fica aqui para não se perder.
        $feito = $destino === null ? TextoDoDespacho::normalizar($input->feito) : '';

        return $feito === '' ? $texto : $texto . "\nO que foi feito: " . $feito;
    }

    /** O responsável novo (04 L179) e quem a caixa destino avisa (04 L181), uma vez cada, nunca quem passou. */
    private function avisar(Tarefa $meta, Tarefa $nova, User $quem, Tenant $escritorio, PassarEtapaInput $input, ?User $responsavel): void
    {
        $destino = $nova->getEtapa();
        $nomeDestino = $this->nomes->nome($escritorio, $destino);
        $url = $this->urls->generate('tarefa_show', ['id' => $nova->getId()]);
        $avisados = [(int) $quem->getId() => true];
        if ($responsavel !== null && $this->podeReceber($nova, $responsavel, $escritorio, $avisados)) {
            $pendencias = TextoDoDespacho::normalizar($input->pendencias);
            $this->notificacoes->criar(
                $responsavel,
                $escritorio,
                Notificacao::TIPO_TAREFA_CRIADA,
                sprintf('Nova etapa: %s · Pasta %s', $nomeDestino, self::pasta($meta)),
                sprintf('%s concluída. Próximo passo: %s%s.', $this->nomes->nome($escritorio, $meta->getEtapa()), $this->nomes->descricao($destino), $pendencias === '' ? '' : '. Pendências: ' . $pendencias),
                $nova,
            )->setUrl($url);
        }
        $titulo = sprintf('Nova demanda na caixa %s · Pasta %s', $this->nomes->nomeDaCaixa($escritorio, $destino), self::pasta($meta));
        $texto = sprintf('Chegou uma nova demanda em %s%s.', $nomeDestino, $responsavel === null ? '' : ' (responsável: ' . self::nome($responsavel) . ')');
        foreach ($this->nomes->avisados($escritorio, $destino) as $pessoa) {
            if ($this->podeReceber($nova, $pessoa, $escritorio, $avisados)) {
                $this->notificacoes->criar($pessoa, $escritorio, Notificacao::TIPO_TAREFA_CRIADA, $titulo, $texto, $nova)->setUrl($url);
            }
        }
    }

    /**
     * Ainda não avisado nesta passagem, com vínculo ativo e vendo a pasta (cadeado); marca como avisado.
     *
     * @param array<int, true> $avisados
     */
    private function podeReceber(Tarefa $nova, User $pessoa, Tenant $escritorio, array &$avisados): bool
    {
        $id = (int) $pessoa->getId();
        if (isset($avisados[$id])) {
            return false;
        }
        $avisados[$id] = true;

        return $this->vinculos->existeVinculoAtivo($pessoa, $escritorio) && $this->notificacoes->veAPastaDaMeta($nova, $pessoa);
    }

    private static function pasta(Tarefa $meta): string
    {
        return $meta->getPasta()->getNup() ?? '#' . $meta->getPasta()->getId();
    }

    /** O nome com as iniciais maiúsculas, como no seletor de responsável (04 L195). */
    private static function nome(User $pessoa): string
    {
        return mb_convert_case(trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail())), MB_CASE_TITLE);
    }
}
