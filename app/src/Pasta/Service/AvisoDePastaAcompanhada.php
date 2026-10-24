<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Repository\NotificacaoRepository;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O aviso de "Acompanhar alterações" (tipo {@see Notificacao::TIPO_PASTA_ACOMPANHADA}; aparece no
 * sino e na Caixa da Central, o "Chat I.A" do desenho) para quem acompanha a pasta. Quem recebe é
 * decidido por {@see QuemAcompanhaAPasta}. Três eventos avisam (decisão do dono, 23/10/2026):
 *
 *  - MENSAGEM NOVA no Registro: "Nova mensagem na pasta N", link `#pasta-msg-<id>` (aba Dados);
 *  - DOCUMENTO NOVO (upload e peça de texto): "Novo documento na pasta N", link `#documentos`
 *    (abre a aba Documentos; não há âncora por documento);
 *  - SITUAÇÃO alterada pela pílula: "Situação da pasta N alterada para X", link para o cartão do
 *    sistema que a mudança grava no Registro (`#pasta-msg-<id>`, aba Dados).
 *
 * Meta e Push ficam de fora: já têm aviso próprio. Cada evento confere a aba de onde vem: quem tem a
 * aba oculta não recebe aquele aviso (Dados para mensagem e situação, Documentos para documento).
 *
 * Frequência: no máximo UM aviso NÃO LIDO deste tipo por pasta e por pessoa, SOMANDO os três
 * eventos: todos os links começam com `/pasta/<id>#`, e é por esse começo que a deduplicação
 * procura. Se a pessoa ainda não leu o anterior desta pasta, não ganha outro: o primeiro já diz "a
 * pasta mudou" e o link leva a ela. Lido o aviso, a próxima alteração volta a avisar. Por isso
 * estes avisos não gastam a {@see CotaDeNotificacoesDoRegistro}: a deduplicação já limita o volume.
 *
 * LIMITAÇÕES ACEITAS:
 *  - (revisão de 23/10/2026, achado 4) a deduplicação e a exclusão não conversam. O aviso não lido
 *    aponta para o PRIMEIRO evento desde a última leitura; os seguintes não criaram aviso. Quando a
 *    mensagem desse primeiro aviso é excluída, o aviso some com ela (`ExcluirMensagemPastaUseCase`)
 *    e a pessoa fica sem aviso dos eventos seguintes até o próximo chegar.
 *  - o aviso de documento NÃO some quando o documento é excluído: o link é o da aba Documentos, não
 *    do documento, e a exclusão de documento não tem a limpeza de avisos que a de mensagem tem. O
 *    título não traz o nome do arquivo, então nada do documento apagado fica no sino; o link
 *    continua abrindo a aba certa.
 *
 * Gravação: os avisos são persistidos todos e gravados com UM flush, depois do commit da ação.
 * Para a mensagem, quem chama ({@see \App\Pasta\UseCase\EnviarMensagemPastaUseCase}) envolve a
 * chamada num try/catch. Os de documento e situação têm o try/catch aqui dentro: nunca lançam, a
 * falha vai para o log e a ação segue. Se o próprio flush falhar, o EntityManager fecha (a ação já
 * foi gravada antes).
 */
final class AvisoDePastaAcompanhada
{
    public function __construct(
        private readonly QuemAcompanhaAPasta $quemAcompanha,
        private readonly NotificacaoRepository $notificacoes,
        private readonly NotificacaoService $notificacaoService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Chamar DEPOIS do commit da mensagem (o link leva o id dela). Custo: o de
     * {@see QuemAcompanhaAPasta::destinatarios()}, mais uma consulta de deduplicação (com o índice
     * `idx_notificacao_tenant_tipo_lida`) e um flush com todos os avisos.
     *
     * @param list<User|null> $naoAvisar o autor e quem DE FATO recebeu aviso próprio pela mesma
     *                                   mensagem (o respondido avisado, os mencionados avisados)
     */
    public function aoRegistrarMensagem(PastaMensagem $mensagem, Pasta $pasta, Tenant $tenant, array $naoAvisar): void
    {
        if ($mensagem->getId() === null) {
            return;
        }

        $this->avisar($pasta, $tenant, $naoAvisar, AbaDaPasta::Dados, sprintf('Nova mensagem na pasta %s', self::numeroDa($pasta)), 'pasta-msg-' . $mensagem->getId());
    }

    /**
     * Documento novo na pasta (upload ou peça de texto), DEPOIS do commit dele. Ninguém recebe aviso
     * próprio por documento novo: sai só o autor. Nunca lança.
     */
    public function aoIncluirDocumento(PastaDocumento $documento, Pasta $pasta, Tenant $tenant, ?User $autor): void
    {
        $this->semDerrubarAAcao('documento', $pasta, $tenant, function () use ($documento, $pasta, $tenant, $autor): void {
            if ($documento->getId() === null) {
                return;
            }
            $this->avisar($pasta, $tenant, [$autor], AbaDaPasta::Documentos, sprintf('Novo documento na pasta %s', self::numeroDa($pasta)), 'documentos');
        });
    }

    /**
     * Situação da pasta alterada (a pílula e o Arquivar/Desarquivar do menu), DEPOIS do commit. O
     * link é o cartão do sistema que a mudança gravou no Registro. Ninguém recebe aviso próprio pela
     * troca de situação: sai só o autor. Nunca lança.
     */
    public function aoMudarSituacao(Pasta $pasta, Tenant $tenant, User $autor, PastaMensagem $cartao): void
    {
        $this->semDerrubarAAcao('situacao', $pasta, $tenant, function () use ($pasta, $tenant, $autor, $cartao): void {
            if ($cartao->getId() === null) {
                return;
            }
            $this->avisar($pasta, $tenant, [$autor], AbaDaPasta::Dados, sprintf('Situação da pasta %s alterada para %s', self::numeroDa($pasta), $pasta->getSituacaoRotulo()), 'pasta-msg-' . $cartao->getId());
        });
    }

    /**
     * O núcleo dos três eventos: quem acompanha e vê a aba do evento, menos `$naoAvisar` e quem já
     * tem um não lido desta pasta; link `/pasta/<id>#<ancora>`.
     *
     * @param list<User|null> $naoAvisar
     */
    private function avisar(Pasta $pasta, Tenant $tenant, array $naoAvisar, AbaDaPasta $aba, string $titulo, string $ancora): void
    {
        $pastaId = $pasta->getId();
        if ($pastaId === null) {
            return;
        }

        $destinatarios = $this->quemAcompanha->destinatarios($pasta, $tenant, $naoAvisar, $aba);
        if ($destinatarios === []) {
            return;
        }

        $caminho    = $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]);
        $jaAvisados = $this->notificacoes->idsDosUsuariosComNaoLidaNoLink($tenant, Notificacao::TIPO_PASTA_ACOMPANHADA, $caminho . '#');

        $novos = 0;
        foreach ($destinatarios as $pessoa) {
            if (isset($jaAvisados[(int) $pessoa->getId()])) {
                continue;
            }
            $this->notificacaoService->prepararNotificacao($pessoa, $tenant, Notificacao::TIPO_PASTA_ACOMPANHADA, $titulo, $caminho . '#' . $ancora);
            ++$novos;
        }

        if ($novos > 0) {
            $this->em->flush();
        }
    }

    /** @param \Closure(): void $aviso */
    private function semDerrubarAAcao(string $evento, Pasta $pasta, Tenant $tenant, \Closure $aviso): void
    {
        try {
            $aviso();
        } catch (\Throwable $falha) {
            $this->logger->error('Aviso de pasta acompanhada não saiu; a ação foi gravada.', [
                'evento'    => $evento,
                'pasta_id'  => $pasta->getId(),
                'tenant_id' => $tenant->getId(),
                'erro'      => $falha->getMessage(),
                'exception' => $falha,
            ]);
        }
    }

    /** O número do cabeçalho da pasta; sem ele, o id (como os outros avisos do Registro). */
    private static function numeroDa(Pasta $pasta): string
    {
        $nup = $pasta->getNup();

        return $nup !== null && $nup !== '' ? $nup : '#' . $pasta->getId();
    }
}
