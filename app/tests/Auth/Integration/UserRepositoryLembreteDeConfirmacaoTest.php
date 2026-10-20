<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\Entity\ConfirmacaoEmail;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A fila do lembrete único de "e-mail não confirmado" (varredura da 5d). As datas ficam em 2001 para nenhuma conta
 * de outro teste ou do banco de teste cair na janela; mesmo assim o resultado é filtrado pelos ids criados aqui.
 */
#[CoversClass(UserRepository::class)]
final class UserRepositoryLembreteDeConfirmacaoTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(UserRepository::class);
    }

    #[TestDox('Ativas, pendentes até a data e sem lembrete; a mais antiga primeiro')]
    public function testFiltraEOrdena(): void
    {
        $maisNova = $this->conta('2001-01-05');
        $maisAntiga = $this->conta('2001-01-02');
        $comLembrete = $this->conta('2001-01-01');
        $this->em->persist(new ConfirmacaoEmail($comLembrete, hash('sha256', uniqid('l', true)), new \DateTimeImmutable('2001-01-09'), lembrete: true));
        $comLinkComum = $this->conta('2001-01-03');
        $this->em->persist(new ConfirmacaoEmail($comLinkComum, hash('sha256', uniqid('c', true)), new \DateTimeImmutable('2001-01-10')));
        $inativa = $this->conta('2001-01-02', ativa: false);
        $depoisDoCorte = $this->conta('2001-01-20');
        $confirmada = $this->conta(null);
        $this->em->flush();

        $ids = $this->idsDe(
            $this->repository->semConfirmacaoDeEmailParaLembrete(new \DateTimeImmutable('2001-01-10'), 1000),
            [$maisNova, $maisAntiga, $comLembrete, $comLinkComum, $inativa, $depoisDoCorte, $confirmada],
        );

        self::assertSame([$maisAntiga->getId(), $comLinkComum->getId(), $maisNova->getId()], $ids);
    }

    #[TestDox('Respeita o limite, ficando com as mais antigas')]
    public function testRespeitaOLimite(): void
    {
        $primeira = $this->conta('1990-01-01');
        $this->conta('1990-01-02');
        $this->conta('1990-01-03');
        $this->em->flush();

        $resultado = $this->repository->semConfirmacaoDeEmailParaLembrete(new \DateTimeImmutable('1990-01-10'), 1);

        self::assertCount(1, $resultado);
        self::assertSame($primeira->getId(), $resultado[0]->getId());
    }

    private function conta(?string $pendenteDesde, bool $ativa = true): User
    {
        $user = UserFactory::createOne(['isActive' => $ativa])->_real();

        if ($pendenteDesde !== null) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable($pendenteDesde));
        }

        return $user;
    }

    /**
     * @param list<User> $resultado
     * @param list<User> $criadas
     *
     * @return list<int|null>
     */
    private function idsDe(array $resultado, array $criadas): array
    {
        $nossos = array_map(static fn (User $u): ?int => $u->getId(), $criadas);

        return array_values(array_filter(
            array_map(static fn (User $u): ?int => $u->getId(), $resultado),
            static fn (?int $id): bool => in_array($id, $nossos, true),
        ));
    }
}
