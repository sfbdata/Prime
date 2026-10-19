<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Consentimento\ConfirmacaoDeEnderecoInterface;
use App\Comercial\Consentimento\ResultadoDaConfirmacao;
use App\Comercial\DTO\ConfirmacaoRealizada;
use App\Comercial\Entity\FatoDeConsentimento;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Comercial\Repository\FatoDeConsentimentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * O double opt-in (spec `comercial-e-emails.md` §3.3).
 *
 * - `pedir`: sobre confirmação vigente não faz nada (N4, sem revelar). Senão aplica os limites (1 pedido por endereço
 *   e lista a cada 24 h, ou a cada 30 dias para quem saiu da lista ou está em `todos`; teto global de 200 por hora,
 *   contando os fatos `pedido`), grava o fato `pedido`, troca o token do estado e manda o e-mail pelo Carteiro,
 *   numa transação só da conexão (nada de `flush` nas entidades pendentes do consumidor). Limite estourado: não
 *   envia, sem exceção para o chamador (silencioso, log sem dado pessoal).
 * - `confirmar`: token pelo hash, uso único, 7 dias. Grava o fato `confirmado` com IP e agente e reativa a lista no
 *   descadastro da 0d (consentimento novo).
 * - `estaConfirmado`: `confirmado_em` preenchido e não descadastrado da lista (nem em `todos` sem exceção).
 */
final class ConfirmacaoDeEndereco implements ConfirmacaoDeEnderecoInterface
{
    public const ROTA = 'comercial_confirmar_endereco';
    public const ORIGEM_DA_REATIVACAO = 'nova_confirmacao';
    public const TETO_POR_HORA = 200;

    private const VALIDADE = '+7 days';
    private const INTERVALO = '-24 hours';
    private const INTERVALO_DE_QUEM_SAIU = '-30 days';
    private const TEMPLATE = 'email/comercial/confirmacao_endereco.html.twig';
    private const ASSUNTO = 'Confirme seu e-mail';

    public function __construct(
        private readonly ConfirmacaoDeEnderecoRepository $estados,
        private readonly FatoDeConsentimentoRepository $fatos,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly CarteiroInterface $carteiro,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function pedir(string $lista, string $email, string $origem, string $versaoDoConsentimento, string $textoDoConsentimento, ?Tenant $escopo = null): void
    {
        $email = MensagemDeEmail::normalizar($email);
        self::exigirPedidoValido($lista, $email, $origem, $versaoDoConsentimento, $textoDoConsentimento);
        $tenantId = self::idDoEscopo($escopo);
        $descadastrado = $this->descadastro->estaDescadastrado($escopo, $email, $lista);
        if (!$descadastrado && $this->jaConfirmado($tenantId, $lista, $email)) {
            return;
        }

        $agora = $this->relogio->now();
        $motivo = $this->limiteEstourado($tenantId, $lista, $email, $descadastrado, $agora);
        if ($motivo !== null) {
            $this->logger->info('Pedido de confirmação de endereço não enviado: limite atingido.', ['limite' => $motivo, 'lista' => $lista]);

            return;
        }

        $consentimento = ['origem' => $origem, 'versao' => $versaoDoConsentimento, 'texto' => $textoDoConsentimento];
        $this->estados->emTransacao(function () use ($tenantId, $lista, $email, $consentimento, $escopo, $agora): void {
            $token = bin2hex(random_bytes(32));
            $this->fatos->registrar($tenantId, FatoDeConsentimento::PEDIDO, $lista, $email, $consentimento, null, null, $agora);
            $this->estados->gravarPedido($tenantId, $lista, $email, hash('sha256', $token), $agora, $agora->modify(self::VALIDADE));
            $this->carteiro->enviar($this->mensagem($lista, $email, $consentimento['origem'], $token, $escopo));
        });
    }

    public function confirmar(string $token, string $ip, ?string $agente): ResultadoDaConfirmacao
    {
        return $this->confirmarComDetalhes($token, $ip, $agente) === null
            ? ResultadoDaConfirmacao::VencidoOuUsado
            : ResultadoDaConfirmacao::Confirmado;
    }

    /**
     * O mesmo que {@see confirmar}, devolvendo a lista, o endereço e o escopo confirmados (a página da rota precisa
     * do nome da lista e do link de descadastro), ou null se o link venceu ou já foi usado.
     */
    public function confirmarComDetalhes(string $token, string $ip, ?string $agente): ?ConfirmacaoRealizada
    {
        $hash = hash('sha256', $token);
        $estado = $this->estados->buscarPorTokenHash($hash);
        $agora = $this->relogio->now();
        if ($estado === null || $estado['expira_em'] === null || new \DateTimeImmutable($estado['expira_em']) < $agora) {
            return null;
        }
        $escopo = $estado['tenant_id'] === null ? null : $this->estados->escritorio($estado['tenant_id']);
        if ($estado['tenant_id'] !== null && $escopo === null) {
            return null;
        }

        $agente = $agente === null ? null : mb_substr($agente, 0, 255);
        $confirmou = $this->estados->emTransacao(fn (): bool => $this->gravarConfirmacao($estado, $hash, $escopo, mb_substr($ip, 0, 45), $agente, $agora));

        return $confirmou === true ? new ConfirmacaoRealizada($estado['lista'], $estado['email'], $escopo) : null;
    }

    public function estaConfirmado(string $lista, string $email, ?Tenant $escopo = null): bool
    {
        $email = MensagemDeEmail::normalizar($email);
        if (!$this->jaConfirmado(self::idDoEscopo($escopo), $lista, $email)) {
            return false;
        }

        return !$this->descadastro->estaDescadastrado($escopo, $email, $lista);
    }

    /**
     * Marca o estado (só se o token ainda vale), acrescenta o fato `confirmado` com a versão e o texto do último pedido
     * e reativa a lista no descadastro. Devolve se confirmou.
     *
     * @param array{id: int, tenant_id: ?int, lista: string, email: string} $estado
     */
    private function gravarConfirmacao(array $estado, string $hash, ?Tenant $escopo, string $ip, ?string $agente, \DateTimeImmutable $agora): bool
    {
        if (!$this->estados->marcarConfirmado($estado['id'], $hash, $agora)) {
            return false;
        }

        [$tenantId, $lista, $email] = [$estado['tenant_id'], $estado['lista'], $estado['email']];
        $pedido = $this->fatos->ultimoPedido($tenantId, $lista, $email) ?? ['origem' => 'desconhecida', 'versao' => '', 'texto' => ''];
        $this->fatos->registrar($tenantId, FatoDeConsentimento::CONFIRMADO, $lista, $email, $pedido, $ip, $agente, $agora);
        $this->descadastro->reativar($escopo, $email, $lista, self::ORIGEM_DA_REATIVACAO);

        return true;
    }

    private function jaConfirmado(?int $tenantId, string $lista, string $email): bool
    {
        $estado = $this->estados->buscar($tenantId, $lista, $email);

        return $estado !== null && $estado['confirmado_em'] !== null;
    }

    /** O limite estourado ('24h', '30d' ou 'global'), ou null se o pedido pode seguir. */
    private function limiteEstourado(?int $tenantId, string $lista, string $email, bool $descadastrado, \DateTimeImmutable $agora): ?string
    {
        $ultimo = $this->fatos->ultimoPedidoEm($tenantId, $lista, $email);
        if ($ultimo !== null && $ultimo > $agora->modify($descadastrado ? self::INTERVALO_DE_QUEM_SAIU : self::INTERVALO)) {
            return $descadastrado ? '30d' : '24h';
        }
        if ($this->fatos->contarPedidosDesde($agora->modify('-1 hour')) >= self::TETO_POR_HORA) {
            return 'global';
        }

        return null;
    }

    private function mensagem(string $lista, string $email, string $origem, string $token, ?Tenant $escopo): MensagemDeEmail
    {
        $corpo = $this->twig->render(self::TEMPLATE, [
            'nomeDaLista' => RotulosDoConsentimento::daLista($lista),
            'rotuloDaOrigem' => RotulosDoConsentimento::daOrigem($origem),
            'link' => $this->urls->generate(self::ROTA, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $motivo = sprintf('Este endereço foi informado %s. Se não foi você, ignore este e-mail.', RotulosDoConsentimento::daOrigem($origem));

        return new MensagemDeEmail($email, self::ASSUNTO, $corpo, TipoDeEnvio::Transacional, 'consentimento:' . $lista, escopo: $escopo, motivoDoEnvio: $motivo);
    }

    private static function exigirPedidoValido(string $lista, string $email, string $origem, string $versao, string $texto): void
    {
        if (preg_match(DescadastroDeEmailInterface::PADRAO_DO_ASSUNTO, $lista) !== 1 || $lista === DescadastroDeEmailInterface::ASSUNTO_TODOS) {
            throw new \InvalidArgumentException('Lista do consentimento inválida.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
            throw new \InvalidArgumentException('Endereço de e-mail inválido.');
        }
        if (trim($origem) === '' || mb_strlen($origem) > 60 || trim($versao) === '' || mb_strlen($versao) > 40 || trim($texto) === '') {
            throw new \InvalidArgumentException('Origem, versão e texto do consentimento são obrigatórios.');
        }
    }

    private static function idDoEscopo(?Tenant $escopo): ?int
    {
        return $escopo === null ? null : (int) $escopo->getId();
    }
}
