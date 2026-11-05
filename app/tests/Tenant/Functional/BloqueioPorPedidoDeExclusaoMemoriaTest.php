<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec memoria-do-layout: o pedido de exclusão criado ou decidido na mesma requisição chega à memória do bloqueio
 * pelo ouvinte de entidade (postPersist, postUpdate, postRemove), sem cada UseCase lembrar de avisar.
 */
#[CoversClass(BloqueioPorPedidoDeExclusao::class)]
final class BloqueioPorPedidoDeExclusaoMemoriaTest extends KernelTestCase
{
    #[TestDox('Pedir, desistir e apagar o pedido na mesma requisição: a resposta acompanha o banco')]
    public function testGravacaoNaMesmaRequisicao(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $bloqueio = static::getContainer()->get(BloqueioPorPedidoDeExclusao::class);

        $tenant = (new Tenant())->setName('Conta memória ' . uniqid());
        $em->persist($tenant);
        $primario = (new User())->setEmail('mem_' . uniqid() . '@test.com')->setFullName('Primário')->setIsActive(true);
        $primario->setRoles(['ROLE_USER']);
        $primario->setPassword('x');
        $em->persist($primario);
        $em->flush();
        $id = (int) $tenant->getId();

        self::assertFalse($bloqueio->suspensaAPedido($id));
        self::assertFalse($bloqueio->suspensaAPedido($id));

        $pedido = new PedidoDeExclusaoDeConta($tenant, $primario, 'Teste', new \DateTimeImmutable());
        $em->persist($pedido);
        $em->flush();
        self::assertTrue($bloqueio->suspensaAPedido($id), 'pedido criado');

        $pedido->desistir($primario);
        $em->flush();
        self::assertFalse($bloqueio->suspensaAPedido($id), 'pedido desistido');

        $outro = new PedidoDeExclusaoDeConta($tenant, $primario, 'De novo', new \DateTimeImmutable());
        $em->persist($outro);
        $em->flush();
        self::assertTrue($bloqueio->suspensaAPedido($id), 'segundo pedido');

        $em->remove($outro);
        $em->flush();
        self::assertFalse($bloqueio->suspensaAPedido($id), 'pedido apagado');
    }

    #[TestDox('Decisão pelo caminho real (UPDATE condicional e flush na transação): a resposta acompanha o banco')]
    public function testDecisaoPeloGravarDecisaoSePendente(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $bloqueio = static::getContainer()->get(BloqueioPorPedidoDeExclusao::class);
        $pedidos = static::getContainer()->get(PedidoDeExclusaoDeContaRepository::class);

        $tenant = (new Tenant())->setName('Conta decisão ' . uniqid());
        $em->persist($tenant);
        $primario = (new User())->setEmail('dec_' . uniqid() . '@test.com')->setFullName('Primário')->setIsActive(true);
        $primario->setRoles(['ROLE_USER']);
        $primario->setPassword('x');
        $em->persist($primario);
        $pedido = new PedidoDeExclusaoDeConta($tenant, $primario, 'Teste', new \DateTimeImmutable());
        $em->persist($pedido);
        $em->flush();
        $id = (int) $tenant->getId();

        self::assertTrue($bloqueio->suspensaAPedido($id), 'pedido pendente');

        $pedido->desistir($primario);
        $pedidos->gravarDecisaoSePendente($pedido, static function () use ($em): void {
            $em->flush();
        });

        self::assertFalse($bloqueio->suspensaAPedido($id), 'pedido decidido pelo caminho dos UseCases');
    }
}
