<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Entity\Apoio;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Service\ApoiadoresDoBlueJus;
use App\Contribua\Twig\SeloApoiadorExtension;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Apoiador = apoio confirmado pelo banco nos últimos 30 dias. Informado e não confirmado não conta; o filtro
 * de tenant da sessão não interfere (quem pergunta é o Trabalho Externo, sobre outros escritórios).
 */
#[CoversClass(ApoiadoresDoBlueJus::class)]
#[CoversClass(SeloApoiadorExtension::class)]
final class ApoiadoresDoBlueJusTest extends KernelTestCase
{
    #[TestDox('Só o apoio confirmado no último mês faz apoiador, em qualquer escritório da lista')]
    public function testApoiadores(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$confirmado, $antigo, $soInformado, $semApoio] = [$this->tenant($em), $this->tenant($em), $this->tenant($em), $this->tenant($em)];
        $this->apoio($em, $confirmado, '-3 days');
        $this->apoio($em, $antigo, '-40 days');
        $this->apoio($em, $soInformado, null);
        $em->getFilters()->enable('tenant')->setParameter('tenant', $semApoio->getId(), Types::INTEGER);

        $apoiadores = new ApoiadoresDoBlueJus(static::getContainer()->get(ApoioRepository::class));
        $agora = new \DateTimeImmutable();
        $ids = array_map(static fn (Tenant $t): int => (int) $t->getId(), [$confirmado, $antigo, $soInformado, $semApoio]);

        self::assertSame([(int) $confirmado->getId()], $apoiadores->apoiadores($ids, $agora));
        self::assertTrue($apoiadores->ehApoiador((int) $confirmado->getId(), $agora));
        self::assertFalse($apoiadores->ehApoiador((int) $antigo->getId(), $agora));
        self::assertSame([], $apoiadores->apoiadores([], $agora));
    }

    #[TestDox('Selo do Meu Perfil: aparece para o apoiador, some para quem não é, e o parcial renderiza')]
    public function testSeloDoPerfil(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$apoiador, $outro] = [$this->tenant($em), $this->tenant($em)];
        $this->apoio($em, $apoiador, '-1 day');
        $twig = static::getContainer()->get('twig');
        $selo = $twig->getExtension(SeloApoiadorExtension::class);

        self::assertSame(['dias' => 30], $selo->paraEscritorio((int) $apoiador->getId()));
        self::assertNull($selo->paraEscritorio((int) $outro->getId()));
        self::assertNull($selo->doEscritorioDaSessao(), 'sem sessão: nada, sem lançar');
        self::assertSame('', trim($twig->render('contribua/_selo_apoiador.html.twig')));
    }

    private function tenant(EntityManagerInterface $em): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório APOIADOR ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** A confirmação pelo banco é da C4; aqui a coluna é preenchida direto. */
    private function apoio(EntityManagerInterface $em, Tenant $tenant, ?string $confirmadoHa): void
    {
        $apoio = new Apoio($tenant, null, 2000);
        $em->persist($apoio);
        $em->flush();
        if ($confirmadoHa !== null) {
            static::getContainer()->get(Connection::class)->executeStatement('UPDATE contribua_apoio SET confirmado_em = ? WHERE id = ?', [(new \DateTimeImmutable($confirmadoHa))->format('Y-m-d H:i:s'), $apoio->getId()]);
        }
    }
}
