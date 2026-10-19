<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PedidoDeEnvioAutomatico;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClienteInterface;
use App\Pasta\Service\EnvioAoCliente\MensagemAoCliente;
use App\Pasta\Service\EnvioAoCliente\ResultadoDoEnvioAoCliente;
use App\Pasta\Service\EnvioAoCliente\TipoDeEmailAoCliente;
use App\Pasta\UseCase\EnviarAnaliseAoClienteUseCase;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tenant\Service\RegraDosPrimarios;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O envio de UM e-mail automático ao cliente de uma pasta (spec push-envio-automatico §3, D2 e D3),
 * comum às novas movimentações (captação) e ao resumo semanal (comando):
 *
 *   - destinatários: os e-mails do cliente que recebem avisos e não pediram para sair (`push_cliente`);
 *   - uma mensagem por destinatário (contrato do Carteiro), tipo LISTA, lista `push_cliente`, escopo o
 *     escritório, origem `pasta:push_cliente_auto`, remetente "{Escritório} via BlueJus", Responder
 *     para = o responsável ativo da pasta (sem ele, um primário do escritório; nunca o endereço da
 *     plataforma). A cópia da Controladoria vai só na primeira mensagem que sair;
 *   - falha de um destinatário (qualquer erro do envio) vai ao log e não impede os outros;
 *   - grava no histórico da pasta (`envio_ao_cliente`, tipo `auto`) uma linha por registro do pedido,
 *     com os destinatários que receberam, cada um gravado (flush) logo depois que o e-mail dele sai.
 *
 * O aviso aos primários sem Controladoria é de quem chama (uma vez por execução, não por e-mail).
 */
final class EnvioAutomaticoAoCliente
{
    public const ORIGEM = 'pasta:push_cliente_auto';

    /** @var array<int, ?string> o e-mail de um primário por escritório (Responder para sem responsável) */
    private array $primarioPorEscritorio = [];

    public function __construct(
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly ControladoriaDoEscritorioInterface $controladoria,
        private readonly EnvioDeEmailAoClienteInterface $envio,
        private readonly EnvioAoClienteRepository $envios,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $primarios,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> os e-mails da Controladoria do escritório (a cópia obrigatória) */
    public function copiaDaControladoria(Tenant $tenant): array
    {
        return array_map(static fn (User $u): string => (string) $u->getEmail(), $this->controladoria->pessoas($tenant));
    }

    /** @return list<string> os e-mails do cliente da pasta que recebem avisos e não se descadastraram */
    public function destinatarios(Pasta $pasta, Tenant $tenant): array
    {
        $destinatarios = [];
        foreach ($this->emails->caderno($pasta, $tenant)?->itens() ?? [] as $item) {
            if ($item->recebeAvisos() && !$this->descadastro->estaDescadastrado($tenant, $item->getEmail(), EnvioDeEmailAoClienteInterface::ASSUNTO_DO_DESCADASTRO)) {
                $destinatarios[] = $item->getEmail();
            }
        }

        return $destinatarios;
    }

    /**
     * Manda o pedido a cada destinatário e grava o histórico. Nunca lança por falha de envio.
     *
     * @param list<string> $destinatarios
     *
     * @return list<string> os que receberam
     */
    public function enviar(PedidoDeEnvioAutomatico $pedido, array $destinatarios): array
    {
        $enviados = [];
        $linhas = [];
        foreach ($destinatarios as $email) {
            $resultado = $this->enviarUm($pedido, $email, $linhas === [] ? $pedido->cc : []);
            if ($resultado !== null && $resultado->saiu()) {
                $enviados[] = $email;
                $linhas = $this->registrar($pedido, $linhas, $email, $resultado->envioId);
            }
        }

        return $enviados;
    }

    /** Sem Controladoria o envio sai e os primários ficam sabendo, uma vez por execução (D2). */
    public function avisarPrimariosSemControladoria(Tenant $tenant, Pasta $primeira, int $pastas): void
    {
        $mensagem = $pastas === 1
            ? 'Envio automático ao cliente da Pasta ' . $primeira->getNup() . ' feito sem cópia para a Controladoria: nenhum usuário com perfil de Controladoria cadastrado.'
            : 'Envios automáticos ao cliente em ' . $pastas . ' pastas feitos sem cópia para a Controladoria: nenhum usuário com perfil de Controladoria cadastrado.';
        $caminho = $this->urls->generate('pasta_show', ['id' => $primeira->getId()]) . '#push';
        foreach ($this->vinculos->findAtivosComPerfilPorTenant($tenant) as $vinculo) {
            if ($this->primarios->ehPrimario($vinculo)) {
                $this->notificacoes->criarNotificacao($vinculo->getUser(), $tenant, EnviarAnaliseAoClienteUseCase::TIPO_NOTIFICACAO_SEM_CONTROLADORIA, mb_substr($mensagem, 0, 255), $caminho);
            }
        }
    }

    /** @param list<string> $cc */
    private function enviarUm(PedidoDeEnvioAutomatico $pedido, string $email, array $cc): ?ResultadoDoEnvioAoCliente
    {
        try {
            return $this->envio->enviar($this->mensagem($pedido, $email, $cc));
        } catch (\Throwable $e) {
            $this->logger->error('Envio automático ao cliente: falha na pasta {pasta} do tenant {tenant} ({classe}).', [
                'pasta' => $pedido->pasta->getId(), 'tenant' => $pedido->tenant->getId(), 'classe' => $e::class,
            ]);

            return null;
        }
    }

    /** @param list<string> $cc */
    private function mensagem(PedidoDeEnvioAutomatico $pedido, string $para, array $cc): MensagemAoCliente
    {
        return new MensagemAoCliente(
            para: $para,
            assunto: $pedido->assunto,
            corpoHtml: $pedido->corpoHtml,
            tipo: TipoDeEmailAoCliente::Lista,
            origem: self::ORIGEM,
            lista: EnvioDeEmailAoClienteInterface::ASSUNTO_DO_DESCADASTRO,
            escopo: $pedido->tenant,
            nome: $pedido->cliente->getNomeExibicao(),
            titulo: $pedido->assunto,
            replyTo: $this->responderPara($pedido->pasta, $pedido->tenant),
            cc: $cc,
            nomeDoRemetente: trim((string) $pedido->tenant->getName()) . ' via BlueJus',
        );
    }

    /** O responsável ativo da pasta; sem ele, um primário do escritório. O cliente responde ao escritório. */
    private function responderPara(Pasta $pasta, Tenant $tenant): ?string
    {
        $responsavel = $pasta->getResponsavel();
        if ($responsavel !== null && $responsavel->isActive() && filter_var($responsavel->getEmail(), FILTER_VALIDATE_EMAIL) !== false) {
            return (string) $responsavel->getEmail();
        }

        return $this->primarioPorEscritorio[(int) $tenant->getId()] ??= $this->emailDeUmPrimario($tenant);
    }

    private function emailDeUmPrimario(Tenant $tenant): ?string
    {
        foreach ($this->vinculos->findAtivosComPerfilPorTenant($tenant) as $vinculo) {
            $email = $vinculo->getUser()->getEmail();
            if ($vinculo->getUser()->isActive() && $this->primarios->ehPrimario($vinculo) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return (string) $email;
            }
        }

        return null;
    }

    /**
     * Grava o destinatário que acabou de receber, antes do próximo e-mail sair (flush por
     * destinatário): no primeiro, cria as linhas do histórico (com a cópia, que foi nele); nos
     * seguintes, acrescenta o endereço nelas.
     *
     * @param list<EnvioAoCliente> $linhas
     *
     * @return non-empty-list<EnvioAoCliente>
     */
    private function registrar(PedidoDeEnvioAutomatico $pedido, array $linhas, string $email, ?int $envioId): array
    {
        if ($linhas === []) {
            $agora = $this->relogio->now();
            $linhas = array_map(static fn (array $registro): EnvioAoCliente => new EnvioAoCliente(
                $pedido->tenant,
                $pedido->pasta,
                $pedido->cliente,
                TipoDeEnvioAoCliente::Auto,
                [$email],
                null,
                $agora,
                cc: $pedido->cc,
                assunto: mb_substr($pedido->assunto, 0, 255),
                descricao: mb_substr($registro['descricao'], 0, 255),
                referencia: $registro['referencia'],
                envioIds: [$envioId],
            ), $pedido->registros);
        } else {
            array_walk($linhas, static fn (EnvioAoCliente $linha) => $linha->acrescentarDestinatario($email, $envioId));
        }
        $ultima = \count($linhas) - 1;
        foreach ($linhas as $i => $linha) {
            $this->envios->salvar($linha, $i === $ultima);
        }

        return $linhas;
    }
}
