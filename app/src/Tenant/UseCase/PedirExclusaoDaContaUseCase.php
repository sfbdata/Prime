<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\PedidoDeExclusaoInput;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Repository\CodigoDeExclusaoDeContaRepository;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\AvisoAoMasterDoPedidoDeExclusao;
use App\Tenant\Service\GuardaDaExclusaoPeloPrimario;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Etapa 2 do "Excluir conta" (PR 5; desenho 07 L152-156, L264-265): confere o código (5 erros queimam,
 * a tentativa é reservada antes de comparar) e a palavra EXCLUIR, consome o código e cria o pedido na
 * mesma transação. A conta fica "suspensa a pedido" (a barreira (b) segura a equipe) e os Masters são
 * avisados no sino. A contagem dos 365 dias só começa quando o Master decidir.
 *
 * Erros, na ordem do desenho: "Código inválido." e "Digite EXCLUIR para confirmar.".
 *
 * @throws PedidoDeExclusaoRecusado
 */
final class PedirExclusaoDaContaUseCase
{
    public const PALAVRA = 'EXCLUIR';
    private const CODIGO_INVALIDO = 'Código inválido.';

    public function __construct(
        private readonly GuardaDaExclusaoPeloPrimario $guarda,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
        private readonly CodigoDeExclusaoDeContaRepository $codigos,
        private readonly AvisoAoMasterDoPedidoDeExclusao $avisoAoMaster,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    public function executar(User $usuario, Tenant $tenant, PedidoDeExclusaoInput $input): PedidoDeExclusaoDeConta
    {
        $this->guarda->exigir($usuario, $tenant);
        SolicitarCodigoDeExclusaoUseCase::exigirMotivo($input);
        $agora = $this->relogio->now();

        $codigo = trim($input->codigo);
        $registro = preg_match('/^\d{6}$/', $codigo) === 1 ? $this->codigos->vivoDoPar($tenant, $usuario, $agora) : null;
        if (
            $registro === null
            || !$this->codigos->reservarTentativa($registro)
            || !hash_equals($registro->getTokenHash(), SolicitarCodigoDeExclusaoUseCase::hash($this->segredo, $tenant, $usuario, $codigo))
        ) {
            throw new PedidoDeExclusaoRecusado(self::CODIGO_INVALIDO);
        }
        if (mb_strtoupper(trim($input->palavra)) !== self::PALAVRA) {
            throw new PedidoDeExclusaoRecusado('Digite EXCLUIR para confirmar.');
        }

        $observacao = trim($input->observacao);
        $pedido = new PedidoDeExclusaoDeConta($tenant, $usuario, $input->motivo . ($observacao !== '' ? ' · ' . $observacao : ''), $agora);

        try {
            $this->em->wrapInTransaction(function () use ($registro, $agora, $pedido): void {
                if (!$this->codigos->consumir($registro, $agora)) {
                    throw new PedidoDeExclusaoRecusado(self::CODIGO_INVALIDO);
                }
                $this->pedidos->salvar($pedido, flush: true);
            });
        } catch (UniqueConstraintViolationException) {
            throw new PedidoDeExclusaoRecusado('Já existe um pedido de exclusão para esta conta.', 409);
        }

        $this->avisoAoMaster->avisar($pedido);

        return $pedido;
    }
}
