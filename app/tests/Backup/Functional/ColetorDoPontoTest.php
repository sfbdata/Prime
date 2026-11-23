<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\Coletor\ColetorDoPonto;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ponto no backup (RISCO ALTO): só o ponto da PRÓPRIA pessoa, nunca o de outra, nem para o primário (gestor).
 * Escritório 1: A (primário, também bate ponto), C (colaborador com o módulo), S (sem módulo). Escritório 2: B.
 */
#[CoversClass(ColetorDoPonto::class)]
final class ColetorDoPontoTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $b;
    private User $c;
    private User $s;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos', ['modules.ponto.view']);
        $this->s = $this->criarColaborador($this->t1, 'Sem Modulo', []);

        $this->bater($this->t1, $this->a, '2026-09-10 08:00', 'OBS-BATIDA-DO-A', 1, '-23.5500000');
        $this->bater($this->t1, $this->a, '2026-11-02 12:00', 'OBS-BATIDA-DO-A-2', 2, '-23.5500000');
        $this->bater($this->t1, $this->c, '2026-10-05 09:00', 'OBS-BATIDA-DO-C', 3, '-22.9000000');
        $this->bater($this->t2, $this->b, '2026-10-06 09:00', 'OBS-BATIDA-DO-B', 1, '-10.1234567');
        $this->justificar($this->t1, $this->a, 'MOTIVO-DO-A');
        $this->justificar($this->t1, $this->c, 'MOTIVO-DO-C');
        $this->em()->flush();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        $c = static::getContainer();

        return [new ColetorDoPonto($c->get(PermissionChecker::class), $c->get(RegistroPontoRepository::class), $c->get(EntityManagerInterface::class), $lote)];
    }

    private function bater(Tenant $tenant, User $user, string $quando, string $obs, int $nsr, string $latitude): void
    {
        $registro = (new RegistroPonto())->setUser($user)->setTenant($tenant)->setDataHora(new \DateTime($quando))->setTipo('entrada')
            ->setObservacao($obs)->setNsr($nsr)->setLatitude($latitude)->setLongitude('-46.6000000')->setPrecisaoGps('12.5');
        $this->em()->persist($registro);
    }

    private function justificar(Tenant $tenant, User $user, string $motivo): void
    {
        $this->em()->persist((new JustificativaPonto())->setUser($user)->setTenant($tenant)->setData(new \DateTime('2026-10-20'))->setStatus('pendente')->setTipo('falta')->setMotivo($motivo));
    }

    #[TestDox('O primário (gestor) leva só as batidas e justificativas DELE: nada do colega C nem do B')]
    public function testPrimarioNaoLevaPontoDeOutro(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);

        self::assertStringContainsString('OBS-BATIDA-DO-A', $tudo);
        self::assertStringContainsString('OBS-BATIDA-DO-A-2', $tudo);
        self::assertStringContainsString('MOTIVO-DO-A', $tudo);
        self::assertCount(2, self::json($zip, 'dados/ponto.json')['batidas']);
        foreach (['OBS-BATIDA-DO-C', 'MOTIVO-DO-C', 'OBS-BATIDA-DO-B'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
    }

    #[TestDox('Nenhuma coordenada do GPS entra no ZIP')]
    public function testSemGps(): void
    {
        $tudo = self::tudo($this->backupDe($this->a, $this->t1));

        foreach (['-23.55', '-46.6', '12.5', 'latitude', 'longitude', 'hmac'] as $gps) {
            self::assertStringNotContainsString($gps, $tudo, $gps);
        }
    }

    #[TestDox('O colega C leva só o dele; B (escritório 2) leva só o dele')]
    public function testColegaEOutroEscritorio(): void
    {
        $c = self::tudo($this->backupDe($this->c, $this->t1));
        self::assertStringContainsString('OBS-BATIDA-DO-C', $c);
        self::assertStringNotContainsString('OBS-BATIDA-DO-A', $c);

        $b = self::tudo($this->backupDe($this->b, $this->t2));
        self::assertStringContainsString('OBS-BATIDA-DO-B', $b);
        self::assertStringNotContainsString('OBS-BATIDA-DO-C', $b);
        self::assertStringNotContainsString('OBS-BATIDA-DO-A', $b);
    }

    #[TestDox('Sem o módulo ponto não leva nada')]
    public function testSemModulo(): void
    {
        self::assertArrayNotHasKey('dados/ponto.json', $this->backupDe($this->s, $this->t1));
    }

    #[TestDox('Lote de 1 mês gera o mesmo conteúdo')]
    public function testLoteDeUm(): void
    {
        $this->assertLoteDeUmIgual($this->a, $this->t1);
    }
}
