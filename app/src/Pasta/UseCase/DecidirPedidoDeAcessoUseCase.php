<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CadeadoDaPastaOutput;
use App\Pasta\Entity\EstadoDoPedidoDeAcesso;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use App\Pasta\Exception\CadeadoRecusadoException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Repository\PedidoDeAcessoAPastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\AvisosDoCadeado;
use App\Pasta\Service\EquipeDoCadeado;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Liberar para editar", "Liberar só visualizar" e "Recusar" do pedido de acesso (spec pasta-cadeado K5,
 * K6; `cadResolver` do protótipo).
 *
 * Quem: só quem configura o cadeado (K1). Liberar grava o nível da pessoa no cadeado; com o cadeado em
 * `Todos`, a pasta passa a `PorPessoa`, com os demais em `Oculto` e o solicitante no nível liberado. A
 * decisão fica no histórico da pasta e na auditoria da conta, e quem pediu é avisado. Pedido já decidido
 * é recusado com o texto do desenho ("Este pedido já foi atendido/recusado"). Se o cadeado foi removido
 * enquanto o pedido esperava, liberar só fecha o pedido (a pasta já está livre).
 */
final class DecidirPedidoDeAcessoUseCase
{
    public const LIBERAR_EDITAR     = 'editar';
    public const LIBERAR_VISUALIZAR = 'visualizar';
    public const RECUSAR            = 'recusar';

    public function __construct(
        private readonly PedidoDeAcessoAPastaRepository $pedidos,
        private readonly CadeadoDaPastaRepository $cadeados,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EquipeDoCadeado $equipe,
        private readonly AvisosDoCadeado $avisos,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $pedidoId, Pasta $pasta, User $ator, Tenant $tenant, string $decisao): CadeadoDaPastaOutput
    {
        $pedido = $this->carregar($pedidoId, $pasta, $ator, $tenant);
        $agora  = $this->relogio->now();
        $nome   = (string) ($pedido->getSolicitante()->getFullName() ?? $pedido->getSolicitante()->getEmail());

        $this->reservar($pedido, $decisao, $ator, $agora);
        if ($decisao === self::RECUSAR) {
            $pedido->recusar($ator, $agora);
            $this->registro->registrar($pasta, $ator, 'cadeado', sprintf('Pedido de acesso de %s recusado', $nome));
        } else {
            $nivel = self::nivelDaDecisao($decisao);
            $pedido->liberar($nivel, $ator, $agora);
            $this->liberarNoCadeado($pedido, $nivel, $ator, $agora);
            $this->registro->registrar($pasta, $ator, 'cadeado', sprintf('Acesso liberado para %s (%s)', $nome, $nivel->verbo()));
        }

        // `criarNotificacao` grava com flush: decisão, cadeado, histórico e aviso vão na mesma descarga.
        $this->avisos->avisarDecisao($pedido);
        $this->em->flush();
        $this->acessoAPasta->esquecer();

        return $this->estadoDoCadeado($pasta, $tenant);
    }

    private function carregar(int $pedidoId, Pasta $pasta, User $ator, Tenant $tenant): PedidoDeAcessoAPasta
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeConfigurar($ator, $tenant)) {
            throw new CadeadoRecusadoException('Só o usuário primário pode liberar a pasta.');
        }

        return $this->pedidos->daPasta($pedidoId, $pasta, $tenant)
            ?? throw new PastaDeOutroEscritorioException('Pedido não encontrado.');
    }

    /**
     * Concorrência: a decisão é reservada no banco (UPDATE condicional "ainda pendente") ANTES de mexer no
     * cadeado. A segunda decisão simultânea não muda nada e recebe o texto do desenho (422).
     */
    private function reservar(PedidoDeAcessoAPasta $pedido, string $decisao, User $ator, \DateTimeImmutable $agora): void
    {
        $estado = $decisao === self::RECUSAR ? EstadoDoPedidoDeAcesso::Recusado : EstadoDoPedidoDeAcesso::liberadoPara(self::nivelDaDecisao($decisao));
        if ($pedido->estaPendente() && $this->pedidos->reservarDecisao($pedido, $estado, $ator, $agora)) {
            return;
        }

        $this->em->refresh($pedido);
        throw new \DomainException($pedido->foiRecusado() ? 'Este pedido já foi recusado.' : 'Este pedido já foi atendido.');
    }

    private static function nivelDaDecisao(string $decisao): NivelDeAcesso
    {
        return match ($decisao) {
            self::LIBERAR_EDITAR     => NivelDeAcesso::Editar,
            self::LIBERAR_VISUALIZAR => NivelDeAcesso::Visualizar,
            default                  => throw new \DomainException('Decisão desconhecida.'),
        };
    }

    private function liberarNoCadeado(PedidoDeAcessoAPasta $pedido, NivelDeAcesso $nivel, User $ator, \DateTimeImmutable $agora): void
    {
        $cadeado = $this->cadeados->daPasta($pedido->getPasta());
        if ($cadeado !== null) {
            $cadeado->liberar($pedido->getSolicitante(), $nivel, $ator, $agora);
        }
    }

    private function estadoDoCadeado(Pasta $pasta, Tenant $tenant): CadeadoDaPastaOutput
    {
        $cadeado = $this->cadeados->daPasta($pasta);

        return $cadeado === null ? CadeadoDaPastaOutput::livre() : CadeadoDaPastaOutput::fromEntity($cadeado, count($this->equipe->doEscritorio($tenant)));
    }
}
