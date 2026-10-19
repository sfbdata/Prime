<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Entity\ConfiguracaoDePagamento;
use App\Contribua\Entity\Plano;
use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\VisaoDoMaster;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Os dados do cartão na página Contribua (checkout e modal "Pagamento por cartão · só Master") e no bloco
 * "Assinatura" do Meu Perfil. Só leitura.
 */
final class MontarPagamentoDoContribuaUseCase
{
    /** Os quatro do seletor do desenho 08, na ordem dele. */
    public const GATEWAYS_DO_DESENHO = [
        'mercado_pago' => 'Mercado Pago · Assinaturas',
        'stripe' => 'Stripe · Payment Links',
        'asaas' => 'Asaas · Link de pagamento recorrente',
        'pagarme' => 'Pagar.me · Link de pagamento',
    ];

    private const NOMES = ['mercado_pago' => 'Mercado Pago', 'stripe' => 'Stripe', 'asaas' => 'Asaas', 'pagarme' => 'Pagar.me', 'falso' => 'Gateway de teste'];

    /**
     * Gateways de página hospedada, que criam o checkout pela API: não usam o "Link do pacote" (desvio
     * aprovado, 19/10) nem o formulário de cartão na tela.
     */
    private const SEM_LINK_DO_PACOTE = ['asaas', 'falso'];

    private const PEDIDOS_NO_MODAL = 8;

    public function __construct(
        private readonly GatewaysDeAssinatura $gateways,
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly PlanoRepository $planos,
        private readonly VisaoDoMaster $visaoDoMaster,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Solicitante $quem): array
    {
        $gateway = $this->gateways->configurado();

        return [
            'liberado' => $gateway !== null,
            'gateway' => $gateway?->codigo() ?? '',
            'gatewayNome' => $gateway === null ? '' : self::nome($gateway->codigo()),
            // Página hospedada (o cartão é digitado no gateway) ou o formulário do desenho com token no navegador.
            'modo' => $gateway === null || \in_array($gateway->codigo(), self::SEM_LINK_DO_PACOTE, true) ? InicioDoCheckout::REDIRECIONAR : InicioDoCheckout::FORMULARIO,
            'documento' => preg_replace('/\D/', '', (string) $quem->escritorio->getCnpj()) ?? '',
            // O formulário de cartão do gateway (Stripe, Mercado Pago, Pagar.me) é montado ao abrir o checkout
            // com a chave pública que o Master cadastrou; nome e e-mail vão ao campo seguro do gateway.
            'chavePublica' => $this->configuracao->atual()->getChavePublica() ?? '',
            'pagadorNome' => $quem->escritorio->getName() ?? $quem->nome(),
            'email' => (string) $quem->usuario->getEmail(),
            'script' => $gateway === null ? null : 'js/contribua-' . str_replace('_', '-', $gateway->codigo()) . '.js',
            'assinatura' => $this->assinaturaDoEscritorio($quem->tenantId()),
            'master' => $quem->ehMaster ? $this->doMaster() : null,
        ];
    }

    /** O bloco "Assinatura" do Meu Perfil: o plano pago, até quando vale e se dá para cancelar. Null sem assinatura. */
    public function assinaturaDoEscritorio(int $tenantId): ?array
    {
        $assinaturas = $this->assinaturas->doTenant($tenantId);
        $viva = self::primeira($assinaturas, [SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente])
            ?? self::primeira($assinaturas, [SituacaoDaAssinatura::Cancelada], new \DateTimeImmutable())
            ?? self::primeira($assinaturas, [SituacaoDaAssinatura::Aguardando]);
        if ($viva === null) {
            return null;
        }

        return [
            'plano' => $viva->getPlano()->getNome(),
            'gb' => $viva->getPlano()->getCotaGb(),
            'valor' => RegistrarApoioUseCase::reais($viva->getValorCentavos()),
            'situacao' => self::rotuloDaSituacao($viva),
            'cor' => self::corDaSituacao($viva->getSituacao()),
            'pagaAte' => $viva->getPagaAte()?->format('d/m/Y'),
            'cancelavel' => \in_array($viva->getSituacao(), [SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente], true),
            'gateway' => self::nome($viva->getGateway()),
        ];
    }

    /** @return array<string, mixed> */
    private function doMaster(): array
    {
        $config = $this->configuracao->atual();
        $instalados = $this->gateways->codigosInstalados();
        $opcoes = self::GATEWAYS_DO_DESENHO + array_fill_keys(array_diff($instalados, array_keys(self::GATEWAYS_DO_DESENHO)), '');

        return [
            'estado' => $this->estado($config),
            'gateway' => $config->getGateway(),
            'chavePublica' => $config->getChavePublica() ?? '',
            'carencia' => $config->getDiasDeCarencia(),
            'semLinkDoPacote' => self::SEM_LINK_DO_PACOTE,
            'retorno' => $this->urls->generate('contribua_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?assinatura=ok',
            'opcoes' => array_map(
                static fn (string $codigo, string $rotulo): array => ['codigo' => $codigo, 'rotulo' => $rotulo !== '' ? $rotulo : self::nome($codigo), 'instalado' => \in_array($codigo, $instalados, true)],
                array_keys($opcoes),
                $opcoes,
            ),
            'links' => array_map(
                static fn (Plano $p): array => ['codigo' => $p->getCodigo(), 'rotulo' => mb_strtoupper($p->getNome()), 'preco' => RegistrarApoioUseCase::reais($p->getPrecoCentavos()) . '/mês', 'valor' => $config->linkDoPacote($p->getCodigo()) ?? ''],
                array_values(array_filter($this->planos->ativosEmOrdem(), static fn (Plano $p): bool => !$p->ehGratuito())),
            ),
            'pedidos' => array_map(self::pedido(...), $this->visaoDoMaster->deTodosOsEscritorios(fn (): array => $this->assinaturas->ultimos(self::PEDIDOS_NO_MODAL))),
        ];
    }

    /**
     * O "· {gwSt}" do botão do desenho: "chave cadastrada", "N/3 pacotes ativos" ou "não configurado". Com
     * gateway hospedado (sem chave nem links, convenção) mostra o gateway escolhido.
     */
    private function estado(ConfiguracaoDePagamento $config): string
    {
        $gateway = $config->getGateway();
        if ($gateway !== null && \in_array($gateway, self::SEM_LINK_DO_PACOTE, true)) {
            return self::nome($gateway);
        }
        if ($config->getChavePublica() !== null) {
            return 'chave cadastrada';
        }
        $pagos = \count(array_filter($this->planos->ativosEmOrdem(), static fn (Plano $p): bool => !$p->ehGratuito()));
        $links = \count($config->getLinksDosPacotes());

        return $links > 0 ? sprintf('%d/%d pacotes ativos', $links, $pagos) : 'não configurado';
    }

    /** @return array<string, mixed> */
    private static function pedido(AssinaturaDeEspaco $a): array
    {
        return [
            'id' => $a->getId(),
            'conta' => sprintf('%s (Nº %06d)', (string) $a->getTenant()->getName(), (int) $a->getTenant()->getId()),
            'plano' => $a->getPlano()->getNome(),
            'em' => $a->getCriadaEm()->format('d/m/Y H:i'),
            'situacao' => self::rotuloDaSituacao($a),
            'cor' => self::corDaSituacao($a->getSituacao()),
            'pendente' => $a->getSituacao() === SituacaoDaAssinatura::Aguardando,
        ];
    }

    private static function rotuloDaSituacao(AssinaturaDeEspaco $a): string
    {
        return match ($a->getSituacao()) {
            // "Voltou do gateway" é só o pagador ter passado pela URL de retorno: não prova pagamento.
            SituacaoDaAssinatura::Aguardando => $a->getRetornouEm() !== null ? 'voltou do gateway, não confirmado' : 'aguardando confirmação',
            SituacaoDaAssinatura::Ativa => $a->getConfirmadaPor() !== null ? 'pago · ativo (confirmado pelo Master)' : 'pago · ativo',
            SituacaoDaAssinatura::Inadimplente => 'cobrança recusada · em carência',
            SituacaoDaAssinatura::Cancelada => 'cancelada',
        };
    }

    private static function corDaSituacao(SituacaoDaAssinatura $s): string
    {
        return match ($s) {
            SituacaoDaAssinatura::Ativa => '#1e7b45',
            SituacaoDaAssinatura::Inadimplente => '#b02a37',
            SituacaoDaAssinatura::Cancelada => '#5b7383',
            SituacaoDaAssinatura::Aguardando => '#9a5b00',
        };
    }

    /**
     * @param list<AssinaturaDeEspaco>   $assinaturas
     * @param list<SituacaoDaAssinatura> $situacoes
     */
    private static function primeira(array $assinaturas, array $situacoes, ?\DateTimeImmutable $pagaDepoisDe = null): ?AssinaturaDeEspaco
    {
        foreach ($assinaturas as $a) {
            if (\in_array($a->getSituacao(), $situacoes, true) && ($pagaDepoisDe === null || ($a->getPagaAte() !== null && $a->getPagaAte() >= $pagaDepoisDe))) {
                return $a;
            }
        }

        return null;
    }

    public static function nome(string $codigo): string
    {
        return self::NOMES[$codigo] ?? $codigo;
    }
}
