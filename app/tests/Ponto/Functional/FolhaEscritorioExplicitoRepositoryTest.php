<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As consultas que alimentam a folha, o espelho exportado e a ficha do funcionário, com o filtro `tenant` DESLIGADO
 * (spec `ponto-escritorio-explicito-na-folha.md`, auditoria 1.13). Sem o filtro (console, Messenger, Master sem
 * escritório), o escritório pedido no parâmetro é a única guarda: a mesma pessoa com ponto em dois escritórios não
 * pode ver no espelho de um as batidas e as justificativas do outro.
 */
#[CoversClass(RegistroPontoRepository::class)]
#[CoversClass(JustificativaPontoRepository::class)]
final class FolhaEscritorioExplicitoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RegistroPontoRepository $registros;
    private JustificativaPontoRepository $justificativas;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->registros = static::getContainer()->get(RegistroPontoRepository::class);
        $this->justificativas = static::getContainer()->get(JustificativaPontoRepository::class);
        $this->em->getFilters()->isEnabled('tenant') && $this->em->getFilters()->disable('tenant');
    }

    #[TestDox('findByUserAndCompetencia, filtro desligado: só as batidas do escritório pedido')]
    public function testBatidasDaCompetenciaSoDoEscritorio(): void
    {
        [$a, $b] = [$this->tenant('A'), $this->tenant('B')];
        $user = $this->user();
        $a1 = $this->batida($user, $a, '2026-03-10 08:00:00');
        $b1 = $this->batida($user, $b, '2026-03-10 19:00:00');
        $a2 = $this->batida($user, $a, '2026-03-20 08:00:00');
        $this->batida($user, $a, '2026-04-01 08:00:00');   // outra competência

        self::assertSame([$a1->getId(), $a2->getId()], self::ids($this->registros->findByUserAndCompetencia($user, $a, 2026, 3)));
        self::assertSame([$b1->getId()], self::ids($this->registros->findByUserAndCompetencia($user, $b, 2026, 3)));
    }

    #[TestDox('findBatidasDoDia, filtro desligado: só as batidas do dia no escritório pedido')]
    public function testBatidasDoDiaSoDoEscritorio(): void
    {
        [$a, $b] = [$this->tenant('A'), $this->tenant('B')];
        $user = $this->user();
        $deA = $this->batida($user, $a, '2026-03-10 08:00:00');
        $deB = $this->batida($user, $b, '2026-03-10 08:05:00');
        $this->batida($user, $a, '2026-03-11 08:00:00');   // outro dia

        $dia = new \DateTimeImmutable('2026-03-10 15:00:00');
        self::assertSame([$deA->getId()], self::ids($this->registros->findBatidasDoDia($user, $a, $dia)));
        self::assertSame([$deB->getId()], self::ids($this->registros->findBatidasDoDia($user, $b, $dia)));
    }

    #[TestDox('findByUserAndCompetencia das justificativas, filtro desligado: só as do escritório pedido')]
    public function testJustificativasDaCompetenciaSoDoEscritorio(): void
    {
        [$a, $b] = [$this->tenant('A'), $this->tenant('B')];
        $user = $this->user();
        $deA = $this->justificativa($user, $a, '2026-03-10', 'pendente');
        $deB = $this->justificativa($user, $b, '2026-03-12', 'abonado');

        self::assertSame([$deA->getId()], self::ids($this->justificativas->findByUserAndCompetencia($user, $a, 2026, 3)));
        self::assertSame([$deB->getId()], self::ids($this->justificativas->findByUserAndCompetencia($user, $b, 2026, 3)));
    }

    #[TestDox('findByUserAndCompetenciaIndexed, filtro desligado: o abono do outro escritório não vence o dia deste')]
    public function testJustificativasIndexadasSoDoEscritorio(): void
    {
        [$a, $b] = [$this->tenant('A'), $this->tenant('B')];
        $user = $this->user();
        // Mesmo dia nos dois escritórios. Misturadas, a abonada de B venceria a pendente de A pela precedência do dia
        // e a folha de A perdoaria o déficit com um abono que A nunca deu.
        $deA = $this->justificativa($user, $a, '2026-03-10', 'pendente');
        $deB = $this->justificativa($user, $b, '2026-03-10', 'abonado');

        $doA = $this->justificativas->findByUserAndCompetenciaIndexed($user, $a, 2026, 3);
        self::assertSame(['2026-03-10'], array_keys($doA));
        self::assertSame($deA->getId(), $doA['2026-03-10']->getId());

        $doB = $this->justificativas->findByUserAndCompetenciaIndexed($user, $b, 2026, 3);
        self::assertSame($deB->getId(), $doB['2026-03-10']->getId());
    }

    #[TestDox('findByTenantUser, filtro desligado: a ficha lista só as justificativas do escritório pedido')]
    public function testJustificativasDaFichaSoDoEscritorio(): void
    {
        [$a, $b] = [$this->tenant('A'), $this->tenant('B')];
        $user = $this->user();
        $a1 = $this->justificativa($user, $a, '2026-03-10', 'pendente');
        $b1 = $this->justificativa($user, $b, '2026-03-11', 'abonado');
        $a2 = $this->justificativa($user, $a, '2026-02-05', 'rejeitado');

        self::assertSame([$a1->getId(), $a2->getId()], self::ids($this->justificativas->findByTenantUser($user, $a)));
        self::assertSame([$b1->getId()], self::ids($this->justificativas->findByTenantUser($user, $b)));
    }

    /**
     * @param iterable<RegistroPonto|JustificativaPonto> $itens
     *
     * @return list<?int>
     */
    private static function ids(iterable $itens): array
    {
        $ids = [];
        foreach ($itens as $item) {
            $ids[] = $item->getId();
        }

        return $ids;
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant folha ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('folha_escritorio_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa em dois escritórios');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function batida(User $user, Tenant $tenant, string $dataHora): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $registro->setDataHora(new \DateTime($dataHora));
        $this->em->persist($registro);
        $this->em->flush();

        return $registro;
    }

    private function justificativa(User $user, Tenant $tenant, string $data, string $status): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime($data));
        $justificativa->setStatus($status);
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }
}
