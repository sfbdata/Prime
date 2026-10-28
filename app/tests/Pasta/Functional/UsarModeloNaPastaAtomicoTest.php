<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\UsarModeloNaPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/** "Usar na pasta" é atômico: documento, contagem e registro do uso confirmam juntos. */
#[CoversClass(UsarModeloNaPastaUseCase::class)]
#[Group('pasta')]
final class UsarModeloNaPastaAtomicoTest extends JusPrimeWebTestCase
{
    #[TestDox('Se o registro do uso falha, o documento não fica na pasta e a contagem não sobe')]
    public function testTudoOuNada(): void
    {
        static::createClient();
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Atomico ' . uniqid());
        $em->persist($tenant);
        $pasta = new Pasta();
        $pasta->setNup((string) random_int(10000, 99999));
        $pasta->setNomeCliente('CLIENTE');
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $modelo = new ModeloDePeca($tenant);
        $modelo->atualizar('Atômico', \App\Pasta\Enum\MateriaDeModelo::Civel, \App\Pasta\Enum\TipoDePeca::PeticaoInicial, '<p>{cliente}</p>');
        $em->persist($modelo);
        $em->flush();
        $pastaId  = (int) $pasta->getId();
        $modeloId = (int) $modelo->getId();

        // Usuário que nunca foi persistido: o INSERT do uso falha no flush único.
        $semId = new User();
        $semId->setEmail('fantasma_' . uniqid() . '@test.com');

        try {
            $container->get(UsarModeloNaPastaUseCase::class)->executar($modelo, $pasta, $tenant, $semId, []);
            self::fail('Era esperada uma exceção do flush.');
        } catch (\Throwable) {
            // esperado
        }

        $conn = $container->get('doctrine')->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = ?', [$pastaId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM modelo_de_peca_uso WHERE modelo_id = ?', [$modeloId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT usos FROM modelo_de_peca WHERE id = ?', [$modeloId]));
    }
}
