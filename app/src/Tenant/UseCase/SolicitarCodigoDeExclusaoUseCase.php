<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\PedidoDeExclusaoInput;
use App\Tenant\Entity\CodigoDeExclusaoDeConta;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Repository\CodigoDeExclusaoDeContaRepository;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\CodigoDeExclusaoMailerInterface;
use App\Tenant\Service\GuardaDaExclusaoPeloPrimario;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Etapa 1 do "Excluir conta" (PR 5, docs/specs/conta-pedido-exclusao.md E4; desenho 07 L148-151, L264):
 * confere o motivo, emite um código de 6 dígitos (o anterior do par deixa de valer) e o envia ao e-mail
 * do primário. Só o hash fica gravado. Se o e-mail não sai, o código é apagado e a etapa 2 não abre.
 *
 * Quem: um primário do escritório (guarda comum). Devolve o e-mail para o texto da etapa 2.
 *
 * @throws PedidoDeExclusaoRecusado
 */
final class SolicitarCodigoDeExclusaoUseCase
{
    public const MOTIVOS = ['Não uso mais', 'Achei caro', 'Faltou um recurso', 'Mudei de sistema', 'Encerrando o escritório', 'Outro motivo'];
    public const MAXIMO_OBSERVACAO = 300;
    public const VALIDADE = '+15 minutes';

    public function __construct(
        private readonly GuardaDaExclusaoPeloPrimario $guarda,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
        private readonly CodigoDeExclusaoDeContaRepository $codigos,
        private readonly CodigoDeExclusaoMailerInterface $mailer,
        private readonly ClockInterface $relogio,
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    public function executar(User $usuario, Tenant $tenant, PedidoDeExclusaoInput $input): string
    {
        $this->guarda->exigir($usuario, $tenant);
        self::exigirMotivo($input);
        if ($this->pedidos->pendenteDoEscritorio($tenant) !== null) {
            throw new PedidoDeExclusaoRecusado('Já existe um pedido de exclusão para esta conta.', 409);
        }

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $agora = $this->relogio->now();
        $this->codigos->apagarDoPar($tenant, $usuario);
        $registro = new CodigoDeExclusaoDeConta($tenant, $usuario, self::hash($this->segredo, $tenant, $usuario, $codigo), $agora, $agora->modify(self::VALIDADE));
        $this->codigos->salvar($registro);

        try {
            $this->mailer->enviar($usuario, (string) $tenant->getName(), $codigo);
        } catch (\Throwable) {
            $this->codigos->apagarDoPar($tenant, $usuario);
            throw new PedidoDeExclusaoRecusado('Não foi possível enviar o código. Tente de novo em instantes.', 503);
        }

        return (string) $usuario->getEmail();
    }

    /** "Escolha um motivo." (desenho) também para motivo fora da lista; observação até 300 caracteres. */
    public static function exigirMotivo(PedidoDeExclusaoInput $input): void
    {
        if (!in_array($input->motivo, self::MOTIVOS, true)) {
            throw new PedidoDeExclusaoRecusado('Escolha um motivo.');
        }
        if (mb_strlen(trim($input->observacao)) > self::MAXIMO_OBSERVACAO) {
            throw new PedidoDeExclusaoRecusado('Texto longo demais.');
        }
    }

    /** HMAC do par escritório + usuário + código: o mesmo código não serve para outra pessoa nem outra conta. */
    public static function hash(string $segredo, Tenant $tenant, User $usuario, string $codigo): string
    {
        return hash_hmac('sha256', $tenant->getId() . ':' . $usuario->getId() . ':' . $codigo, $segredo);
    }
}
