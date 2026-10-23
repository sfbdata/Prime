<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Repository\NotificacaoRepository;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O aviso "Nova mensagem na pasta N" para quem acompanha a pasta (tipo
 * {@see Notificacao::TIPO_PASTA_ACOMPANHADA}; aparece no sino e na Caixa da Central, o "Chat I.A"
 * do desenho). Quem recebe é decidido por {@see QuemAcompanhaAPasta}; os eventos que ainda não
 * avisam estão listados lá.
 *
 * Frequência: no máximo UM aviso NÃO LIDO deste tipo por pasta e por pessoa. Se ela ainda não leu
 * o anterior desta pasta, não ganha outro: o primeiro já diz "a pasta mudou" e o link leva a ela.
 * Lido o aviso, a próxima alteração volta a avisar. Por isso estes avisos não gastam a
 * {@see CotaDeNotificacoesDoRegistro}: a deduplicação já limita o volume.
 *
 * O link aponta para a mensagem (`pasta_show#pasta-msg-<id>`), como os outros avisos do Registro;
 * quando a mensagem é excluída, o aviso some com ela (`ExcluirMensagemPastaUseCase`).
 *
 * LIMITAÇÃO ACEITA (revisão de 23/10/2026, achado 4): a deduplicação e a exclusão não conversam.
 * O aviso não lido aponta para a PRIMEIRA mensagem desde a última leitura; as seguintes não criaram
 * aviso. Se essa primeira for excluída, o aviso some com ela e a pessoa fica sem aviso das outras
 * mensagens novas até a próxima chegar. O link continua levando à pasta certa enquanto existe.
 *
 * Gravação: os avisos são persistidos todos e gravados com UM flush, depois do commit da mensagem.
 * Quem chama ({@see \App\Pasta\UseCase\EnviarMensagemPastaUseCase}) envolve a chamada num
 * try/catch: falha aqui vai para o log e não derruba nem desfaz o envio.
 */
final class AvisoDePastaAcompanhada
{
    public function __construct(
        private readonly QuemAcompanhaAPasta $quemAcompanha,
        private readonly NotificacaoRepository $notificacoes,
        private readonly NotificacaoService $notificacaoService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $em,
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
        $pastaId = $pasta->getId();
        if ($pastaId === null || $mensagem->getId() === null) {
            return;
        }

        $destinatarios = $this->quemAcompanha->destinatarios($pasta, $tenant, $naoAvisar);
        if ($destinatarios === []) {
            return;
        }

        $caminho    = $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]);
        $jaAvisados = $this->notificacoes->idsDosUsuariosComNaoLidaNoLink($tenant, Notificacao::TIPO_PASTA_ACOMPANHADA, $caminho . '#');
        $titulo     = sprintf('Nova mensagem na pasta %s', self::numeroDa($pasta));

        $novos = 0;
        foreach ($destinatarios as $pessoa) {
            if (isset($jaAvisados[(int) $pessoa->getId()])) {
                continue;
            }
            $this->notificacaoService->prepararNotificacao($pessoa, $tenant, Notificacao::TIPO_PASTA_ACOMPANHADA, $titulo, $caminho . '#pasta-msg-' . $mensagem->getId());
            ++$novos;
        }

        if ($novos > 0) {
            $this->em->flush();
        }
    }

    /** O número do cabeçalho da pasta; sem ele, o id (como os outros avisos do Registro). */
    private static function numeroDa(Pasta $pasta): string
    {
        $nup = $pasta->getNup();

        return $nup !== null && $nup !== '' ? $nup : '#' . $pasta->getId();
    }
}
