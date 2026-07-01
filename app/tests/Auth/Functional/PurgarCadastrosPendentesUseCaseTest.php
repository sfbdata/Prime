<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Entity\CadastroPendente;
use App\Auth\UseCase\PurgarCadastrosPendentesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PurgarCadastrosPendentesUseCase::class)]
final class PurgarCadastrosPendentesUseCaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PurgarCadastrosPendentesUseCase $sut;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em  = static::getContainer()->get(EntityManagerInterface::class);
        $this->sut = static::getContainer()->get(PurgarCadastrosPendentesUseCase::class);
    }

    #[TestDox('Purga apaga confirmados e pendentes expirados, preserva pendentes vivos')]
    public function testPurgaApagaConfirmadosEExpiradosPreservaVivos(): void
    {
        $agora = new \DateTimeImmutable('2026-07-01 12:00:00');

        $vivo       = $this->criar('vivo@x.com', $agora->modify('+12 hours'));            // pending, no prazo
        $expirado   = $this->criar('exp@x.com', $agora->modify('-1 hour'));               // pending, vencido
        $confirmado = $this->criar('ok@x.com', $agora->modify('+12 hours'));
        $confirmado->confirmar();                                                          // confirmado (hash morto)
        $this->em->flush();

        $vivoId       = $vivo->getId();
        $expiradoId   = $expirado->getId();
        $confirmadoId = $confirmado->getId();

        $apagados = $this->sut->executar($agora, dryRun: false);

        self::assertSame(2, $apagados, 'deve apagar o expirado e o confirmado');

        $this->em->clear();
        self::assertNotNull($this->em->find(CadastroPendente::class, $vivoId), 'pendente vivo preservado');
        self::assertNull($this->em->find(CadastroPendente::class, $expiradoId), 'pendente expirado apagado');
        self::assertNull($this->em->find(CadastroPendente::class, $confirmadoId), 'confirmado apagado');
    }

    #[TestDox('Dry-run conta o que seria purgado sem apagar nada')]
    public function testDryRunContaSemApagar(): void
    {
        $agora = new \DateTimeImmutable('2026-07-01 12:00:00');
        $this->criar('exp@x.com', $agora->modify('-1 hour'));
        $this->em->flush();

        $contagem = $this->sut->executar($agora, dryRun: true);

        self::assertSame(1, $contagem);

        $this->em->clear();
        self::assertSame(1, (int) $this->em->getRepository(CadastroPendente::class)
            ->count([]), 'dry-run não pode apagar');
    }

    private function criar(string $email, \DateTimeImmutable $expiraEm): CadastroPendente
    {
        $cadastro = new CadastroPendente(
            email: $email,
            token: bin2hex(random_bytes(16)),
            nomeCompleto: 'Fulano de Tal',
            nomeEscritorio: 'Escritório Teste',
            oabNumero: '12345',
            oabUf: 'SP',
            senhaHash: 'hash-falso',
            ip: '127.0.0.1',
            expiresAt: $expiraEm,
        );
        $this->em->persist($cadastro);

        return $cadastro;
    }
}
