<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\NumeroSequencialDoRegistro;
use App\Ponto\Service\SeloDoComprovante;
use App\Ponto\UseCase\RegistrarBatidaDaPessoaUseCase;
use App\Tests\Ponto\Support\SeloDoComprovanteDeTeste;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O NSR da batida (E15, D2): sequencial por escritório, sem buraco e sem repetir; escritórios independentes; o
 * rollback da batida devolve o número. E a gravação da batida da pessoa: NSR sempre, comprovante só com o selo pronto.
 */
#[Group('ponto')]
#[CoversClass(NumeroSequencialDoRegistro::class)]
#[CoversClass(RegistrarBatidaDaPessoaUseCase::class)]
final class NumeroSequencialDoRegistroTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Sequencial e sem buraco por escritório; outro escritório tem a própria sequência, começando em 1')]
    public function testSequencialPorEscritorio(): void
    {
        [$a, $b] = [$this->escritorio(), $this->escritorio()];

        self::assertSame([1, 2, 3], [$this->proximo($a), $this->proximo($a), $this->proximo($a)]);
        self::assertSame([1, 2], [$this->proximo($b), $this->proximo($b)], 'o escritório B não continua a do A');
        self::assertSame(4, $this->proximo($a), 'o A segue de onde parou, sem pular o que o B usou');
    }

    #[TestDox('Rollback da transação da batida devolve o número: a próxima recebe o mesmo NSR, sem buraco')]
    public function testRollbackNaoDeixaBuraco(): void
    {
        $escritorio = $this->escritorio();
        self::assertSame(1, $this->proximo($escritorio));

        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();
        self::assertSame(2, $this->servico()->proximo($escritorio));
        $conexao->rollBack();

        self::assertSame(2, $this->proximo($escritorio), 'o 2 voltou com o rollback');
        self::assertSame(3, $this->proximo($escritorio));
    }

    #[TestDox('Selo indisponível (produção da fase A): a batida grava com NSR e nenhum comprovante é emitido')]
    public function testSeloIndisponivelGravaSoComNsr(): void
    {
        $escritorio = $this->escritorio();
        $registrar = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class);

        self::assertNull($registrar->executar($this->batida($escritorio)));
        $segunda = $this->batida($escritorio);
        self::assertNull($registrar->executar($segunda));

        self::assertSame(2, $segunda->getNsr());
        self::assertNotNull($segunda->getId(), 'a batida foi gravada');
        self::assertSame(0, $this->em->getRepository(ComprovanteDoPonto::class)->count([]), 'nada emitido');
    }

    #[TestDox('Selo pronto: na mesma transação, NSR, batida e comprovante com código, HMAC que confere e o NSR selado')]
    public function testSeloProntoEmiteComprovante(): void
    {
        static::getContainer()->set(SeloDoComprovante::class, new SeloDoComprovanteDeTeste());
        $escritorio = $this->escritorio();
        $batida = $this->batida($escritorio);

        $comprovante = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class)->executar($batida);

        self::assertNotNull($comprovante);
        self::assertNotNull($comprovante->getId());
        self::assertSame(1, $batida->getNsr());
        self::assertSame(1, $comprovante->getNsr());
        self::assertSame($batida, $comprovante->getRegistro());
        self::assertSame(SeloDoComprovanteDeTeste::VERSAO, $comprovante->getVersaoChave());
        self::assertTrue((new SeloDoComprovanteDeTeste())->conferir($comprovante->getDadosAssinados(), $comprovante->getHmac(), $comprovante->getVersaoChave()));
        self::assertStringContainsString('"nsr":1,"escritorio_id":' . $escritorio->getId() . ',"nome":"Pessoa do NSR"', $comprovante->getDadosAssinados());
        self::assertStringContainsString('"codigo":"' . $comprovante->getCodigo() . '"', $comprovante->getDadosAssinados());
    }

    private function proximo(Tenant $escritorio): int
    {
        return $this->em->wrapInTransaction(fn (): int => $this->servico()->proximo($escritorio));
    }

    private function servico(): NumeroSequencialDoRegistro
    {
        return static::getContainer()->get(NumeroSequencialDoRegistro::class);
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório NSR ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function batida(Tenant $escritorio): RegistroPonto
    {
        $pessoa = new User();
        $pessoa->setEmail('nsr_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa do NSR');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setIsActive(true);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);

        $batida = new RegistroPonto();
        $batida->setUser($pessoa);
        $batida->setTenant($escritorio);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime());

        return $batida;
    }
}
