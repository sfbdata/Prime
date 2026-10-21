<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\SolicitarResumoDoPushInput;
use App\Inteligencia\UseCase\SolicitarResumoDoPushUseCase;
use App\Pasta\DTO\AvisoDaPastaOutput;
use App\Pasta\DTO\ChavesDoAvisoDoPush;
use App\Pasta\DTO\MovimentacaoDoAvisoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AvisoDoPushDaPastaRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AgrupadorDoAvisoDoPush;
use App\Pasta\Service\AvisoDoPushMailer;
use App\Pasta\Service\DestinosDoAvisoDoPush;
use App\Pasta\Service\DisponibilidadeDaIaNoAvisoDoPush;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Avisar quando chegar" do Push (spec push-avisar-quando-chegar §3), ao fim de cada captação do DJEN.
 *
 * História: o responsável pela pasta quer saber que saiu algo no diário sem abrir o sistema para
 * conferir; e, se o escritório quiser, a BlueJus IA já resume as movimentações. Quem dispara é a
 * captação (comando diário ou "Buscar agora"), sem sessão: o escritório é explícito em tudo.
 *
 *   1. as publicações NOVAS desta captação que estão vinculadas a um processo do escritório
 *      (inclusive as religadas pela reconciliação);
 *   2. as pastas VIVAS desses processos, uma entrada por pasta (agrupa: um aviso por pasta);
 *   3. por pasta, com as chaves dela (ou os padrões): sino ao responsável apto, e-mail se ligado e
 *      não descadastrado, e o pedido do resumo de IA se ligado e disponível;
 *   4. falha de e-mail ou de IA de uma pasta é registrada no log (só ids e a classe do erro) e não
 *      interrompe as outras pastas nem a captação.
 *
 * O aviso geral do módulo ("N novas publicações capturadas no DJEN") continua com o
 * NotificadorPublicacoesDjen. Pendência: respeitar a aba Push oculta por usuário (PR #68).
 */
final class AvisarQuandoChegarNoPushUseCase
{
    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaRepository $pastas,
        private readonly AvisoDoPushDaPastaRepository $avisos,
        private readonly UserTenantRepository $vinculos,
        private readonly PermissionChecker $permissoes,
        private readonly NotificacaoService $notificacoes,
        private readonly AvisoDoPushMailer $mailer,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly DisponibilidadeDaIaNoAvisoDoPush $ia,
        private readonly SolicitarResumoDoPushUseCase $solicitarResumo,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $djenIdsNovos as publicações que esta captação criou
     *
     * @return int quantas pastas receberam algum aviso
     */
    public function executar(Tenant $tenant, array $djenIdsNovos): int
    {
        $publicacoes = $this->publicacoes->listarVinculadasPorDjenIdsDoTenant($tenant, $djenIdsNovos);
        if ($publicacoes === []) {
            return 0;
        }

        $processoIds = array_map(static fn (PublicacaoDjen $p): int => (int) $p->getProcesso()?->getId(), $publicacoes);
        $grupos = AgrupadorDoAvisoDoPush::agrupar($this->pastas->listarVinculosDePastasVivasDosProcessos($tenant, $processoIds), $publicacoes);
        $chaves = $this->avisos->porPastasDoTenant($tenant, array_map(static fn (array $g): int => (int) $g['pasta']->getId(), $grupos));
        $iaDisponivel = $this->ia->doEscritorio($tenant)->estaDisponivel();

        $avisadas = 0;
        foreach ($grupos as $grupo) {
            $pasta = $grupo['pasta'];
            $avisadas += $this->avisarPasta($pasta, $tenant, $this->aviso($pasta, $grupo['publicacoes']), ChavesDoAvisoDoPush::da($chaves[(int) $pasta->getId()] ?? null), $iaDisponivel) ? 1 : 0;
        }

        return $avisadas;
    }

    private function avisarPasta(Pasta $pasta, Tenant $tenant, AvisoDaPastaOutput $aviso, ChavesDoAvisoDoPush $chaves, bool $iaDisponivel): bool
    {
        $responsavel = $this->responsavelApto($pasta, $tenant);
        $descadastrado = $responsavel !== null && $chaves->responsavel && $chaves->email
            && $this->descadastro->estaDescadastrado($tenant, (string) $responsavel->getEmail(), AvisoDoPushMailer::ASSUNTO_DO_DESCADASTRO);
        $destinos = DestinosDoAvisoDoPush::decidir($chaves, $responsavel !== null, $descadastrado, $iaDisponivel);

        if ($responsavel !== null && $destinos->sino) {
            $caminho = $this->urls->generate('pasta_show', ['id' => $aviso->pastaId]) . '#push';
            $this->notificacoes->criarNotificacao($responsavel, $tenant, Notificacao::TIPO_PUSH_PUBLICACAO_PASTA, mb_substr($aviso->mensagem(), 0, 255), $caminho);
        }
        if ($responsavel !== null && $destinos->email) {
            $this->enviarEmail($responsavel, $tenant, $aviso);
        }
        if ($responsavel !== null && $destinos->ia) {
            $this->solicitarAnalise($responsavel, $tenant, $aviso->pastaId);
        }

        return $destinos->algum();
    }

    /** O responsável da pasta, se ainda é usuário ativo, tem vínculo ativo com o escritório e vê a pasta. */
    private function responsavelApto(Pasta $pasta, Tenant $tenant): ?User
    {
        $responsavel = $pasta->getResponsavel();
        if ($responsavel === null || !$responsavel->isActive() || !$this->vinculos->existeVinculoAtivo($responsavel, $tenant)) {
            return null;
        }

        return $this->permissoes->canAccessResource($responsavel, $tenant, 'pasta', (int) $pasta->getId(), 'view') ? $responsavel : null;
    }

    /** @param non-empty-list<PublicacaoDjen> $publicacoes */
    private function aviso(Pasta $pasta, array $publicacoes): AvisoDaPastaOutput
    {
        return new AvisoDaPastaOutput(
            (int) $pasta->getId(),
            (string) $pasta->getNup(),
            array_map(static fn (PublicacaoDjen $p): MovimentacaoDoAvisoOutput => MovimentacaoDoAvisoOutput::dePublicacao($p), $publicacoes),
        );
    }

    private function enviarEmail(User $responsavel, Tenant $tenant, AvisoDaPastaOutput $aviso): void
    {
        try {
            $this->mailer->enviar($responsavel, $tenant, $aviso);
        } catch (\Throwable $e) {
            $this->logger->error('Aviso do Push: falha ao enviar o e-mail da pasta {pasta} do tenant {tenant} ({classe}).', [
                'pasta' => $aviso->pastaId, 'tenant' => $tenant->getId(), 'classe' => $e::class,
            ]);
        }
    }

    /** O mesmo pedido do botão "Resumir com IA", em nome do responsável; recusa (cota, contexto vazio, fila) só vai ao log. */
    private function solicitarAnalise(User $responsavel, Tenant $tenant, int $pastaId): void
    {
        try {
            $this->solicitarResumo->executar(new SolicitarResumoDoPushInput($pastaId), $responsavel, $tenant);
        } catch (\Throwable $e) {
            $this->logger->warning('Aviso do Push: análise de IA da pasta {pasta} do tenant {tenant} não solicitada ({classe}).', [
                'pasta' => $pastaId, 'tenant' => $tenant->getId(), 'classe' => $e::class,
            ]);
        }
    }
}
