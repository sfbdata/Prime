<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\PedidoDeZeramentoOutput;
use App\Dashboard\DTO\PedirZeramentoInput;
use App\Dashboard\Zeramento\ColaboradorDoEscritorio;
use App\Dashboard\Zeramento\ColunasZeraveis;
use App\Entity\Auth\User;
use App\Profile\Enum\Idioma;
use App\Profile\Repository\UserProfileRepository;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Pedir para zerar" (DASH-014; desenho "01 - Dashboard 1.2.2", `zerarCol` para quem não é primário,
 * e `pedirZerar` do bluejus-central.js): quem não pode zerar avisa, pela Central, cada primário ativo
 * do escritório (o pedido) e cada pessoa marcada como Controladoria (`UserTenant.controladoria`, a
 * ciência: "A decisão é do usuário primário; nada foi alterado"). Nada muda nos números.
 *
 * Teto: um pedido por pessoa, coluna e alvo por dia. Sem tabela nova: o próprio aviso guarda o pedido
 * (o link leva coluna, alvo e quem pediu), como no PedirAcessoAosPerfisUseCase. Pedido repetido não
 * avisa de novo e volta `repetido` (o desenho: "Você já tinha pedido isso hoje").
 *
 * Textos no idioma de quem RECEBE (spec idioma-do-sistema §4). A pessoa alvo tem de ser colaboradora
 * ativa do escritório da sessão (404).
 */
final class PedirZeramentoDoDashboardUseCase
{
    public const TIPO_NOTIFICACAO = 'dashboard_pedido_zerar';

    private const PRIMARIO      = 'primario';
    private const CONTROLADORIA = 'controladoria';

    public function __construct(
        private readonly ColaboradorDoEscritorio $colaboradores,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly NotificacaoService $notificacaoService,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $tradutor,
        private readonly UserProfileRepository $perfis,
    ) {
    }

    public function executar(PedirZeramentoInput $input): PedidoDeZeramentoOutput
    {
        ColunasZeraveis::exigir($input->coluna);
        $alvo = $this->colaboradores->buscar($input->tenant, $input->pessoaId);
        $url  = $this->linkDoPedido($input);
        $destinatarios    = $this->destinatarios($input);
        $comControladoria = in_array(self::CONTROLADORIA, array_column($destinatarios, 1), true);
        if ($this->jaPediuHoje($input, $url)) {
            return new PedidoDeZeramentoOutput(0, $comControladoria, true);
        }

        foreach ($destinatarios as [$pessoa, $papel]) {
            $this->avisar($pessoa, $papel, $input, $alvo, $url);
        }

        return new PedidoDeZeramentoOutput(count($destinatarios), $comControladoria, false);
    }

    private function jaPediuHoje(PedirZeramentoInput $input, string $url): bool
    {
        return $this->notificacaoRepository->existeDoTipoComUrlNoEscritorioDesde(
            $input->tenant,
            self::TIPO_NOTIFICACAO,
            $url,
            $input->agora->setTime(0, 0),
        );
    }

    /**
     * Primários ativos (conta ativa) e a Controladoria, sem quem pediu e sem repetir quem é as duas
     * coisas (vale o pedido, que é o aviso de primário).
     *
     * @return list<array{0: User, 1: string}>
     */
    private function destinatarios(PedirZeramentoInput $input): array
    {
        $lista = [];
        $vistos = [(int) $input->solicitante->getId() => true];
        foreach ($this->userTenantRepository->findUsuariosAdminsAtivos($input->tenant) as $primario) {
            if ($primario->isActive() && !isset($vistos[(int) $primario->getId()])) {
                $vistos[(int) $primario->getId()] = true;
                $lista[] = [$primario, self::PRIMARIO];
            }
        }
        foreach ($this->userTenantRepository->findUsuariosDaControladoria($input->tenant) as $controladoria) {
            if (!isset($vistos[(int) $controladoria->getId()])) {
                $vistos[(int) $controladoria->getId()] = true;
                $lista[] = [$controladoria, self::CONTROLADORIA];
            }
        }

        return $lista;
    }

    private function avisar(User $destinatario, string $papel, PedirZeramentoInput $input, ?User $alvo, string $url): void
    {
        $locale = $this->localeDe($destinatario);
        $traduzir = fn (string $chave, array $vars = []): string => $this->tradutor->trans($chave, $vars, null, $locale);
        $rotulo = ColunasZeraveis::rotulo($input->coluna, $traduzir);
        $vars = [
            'destinatario' => $this->primeiroNome($destinatario),
            'nome'         => $this->nomeOu($input->solicitante, $traduzir('dashboard.zerar.aviso.sem_nome')),
            'hora'         => $input->agora->format('H:i'),
            'rotulo'       => $rotulo,
            'alvo'         => $alvo === null
                ? $traduzir('dashboard.zerar.aviso.alvo_todos')
                : $traduzir('dashboard.zerar.aviso.alvo_pessoa', ['nome' => $this->nomeOu($alvo, '')]),
        ];
        $texto = $papel === self::PRIMARIO
            ? $traduzir('dashboard.zerar.aviso.texto_primario', $vars)
            : $traduzir('dashboard.zerar.aviso.texto_controladoria', $vars);
        $titulo = $traduzir('dashboard.zerar.aviso.titulo', ['rotulo' => $rotulo]);
        $this->notificacaoService->criarNotificacao($destinatario, $input->tenant, self::TIPO_NOTIFICACAO, $titulo, $url, $texto);
    }

    /** O idioma escolhido no Meu Perfil de quem recebe; sem perfil, o padrão (português). */
    private function localeDe(User $destinatario): string
    {
        return ($this->perfis->buscarPorUsuario($destinatario)?->getIdioma() ?? Idioma::PADRAO)->locale();
    }

    private function primeiroNome(User $pessoa): string
    {
        return explode(' ', trim((string) $pessoa->getFullName()))[0];
    }

    private function nomeOu(User $pessoa, string $padrao): string
    {
        $nome = trim((string) $pessoa->getFullName());

        return $nome !== '' ? $nome : $padrao;
    }

    /** O Dashboard com o pedido no link: identifica coluna, alvo e quem pediu (o teto diário). */
    private function linkDoPedido(PedirZeramentoInput $input): string
    {
        return $this->urls->generate('dashboard_index', [
            'pedido_zerar' => $input->coluna,
            'alvo'         => $input->pessoaId ?? 'todos',
            'de'           => $input->solicitante->getId(),
        ]);
    }
}
