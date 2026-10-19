<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\EstadoDoPontoDoDiaOutput;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\UseCase\ObterEstadoDoPontoDoDiaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Os estados do atalho de ponto do cabeçalho (spec `presenca-e-ponto-no-cabecalho.md` §3), com os serviços reais: a
 * escolha da folha, o home office e as sedes. Integração (e não unidade) porque o `HomeOfficeConfigRepository` é final.
 */
#[CoversClass(ObterEstadoDoPontoDoDiaUseCase::class)]
final class ObterEstadoDoPontoDoDiaUseCaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return iterable<string, array{list<array{string, string}>, string, array<string, ?string>}> */
    public static function dias(): iterable
    {
        yield 'sem batida: bater a entrada' => [[], EstadoDoPontoDoDiaOutput::BATER_ENTRADA, ['entrada' => null, 'saida' => null]];
        yield 'entrada: em jornada' => [[['entrada', '08:00']], EstadoDoPontoDoDiaOutput::EM_JORNADA, ['entrada' => '08:00']];
        yield 'repouso sem retorno: em intervalo (sem ação)' => [[['entrada', '08:00'], ['repouso', '12:00']], EstadoDoPontoDoDiaOutput::EM_INTERVALO, ['repouso' => '12:00', 'retorno' => null]];
        yield 'voltou do intervalo: em jornada' => [[['entrada', '08:00'], ['repouso', '12:00'], ['retorno', '13:00']], EstadoDoPontoDoDiaOutput::EM_JORNADA, ['retorno' => '13:00']];
        yield 'entrada e saída: concluída' => [[['entrada', '08:00'], ['saida', '17:00']], EstadoDoPontoDoDiaOutput::JORNADA_CONCLUIDA, ['saida' => '17:00']];
        yield 'saída sem entrada: bater a entrada (o servidor decide)' => [[['saida', '00:30']], EstadoDoPontoDoDiaOutput::BATER_ENTRADA, ['entrada' => null, 'saida' => '00:30']];
        yield 'entrada repetida a 3 min: a escolha da folha fica com a primeira' => [[['entrada', '08:00'], ['entrada', '08:03']], EstadoDoPontoDoDiaOutput::EM_JORNADA, ['entrada' => '08:00']];
    }

    /**
     * @param list<array{string, string}> $batidas
     * @param array<string, ?string> $esperado
     */
    #[DataProvider('dias')]
    #[TestDox('Estado do dia: $_dataName')]
    public function testEstados(array $batidas, string $estado, array $esperado): void
    {
        [$user, $tenant] = $this->pessoa();
        foreach ($batidas as [$tipo, $hora]) {
            $this->batida($user, $tenant, $tipo, 'today ' . $hora);
        }
        $this->batida($user, $tenant, RegistroPonto::TIPO_ENTRADA, 'yesterday 08:00');   // outro dia não entra

        $saida = $this->casoDeUso()->executar($user, $tenant);

        self::assertSame($estado, $saida->estado);
        foreach ($esperado as $tipo => $hora) {
            self::assertSame($hora, $saida->hoje[$tipo], $tipo);
        }
        self::assertFalse($saida->homeOffice);
        self::assertSame((new \DateTimeImmutable())->format('Y-m-d'), $saida->dia);
    }

    #[TestDox('Home office liberado hoje: o estado diz, e a tela não pede posição nem avalia a cerca')]
    public function testHomeOffice(): void
    {
        [$user, $tenant] = $this->pessoa();
        $config = new \App\Ponto\Entity\HomeOfficeConfig();
        $config->setUser($user);
        $config->setTenant($tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em->persist($config);
        $this->em->flush();

        self::assertTrue($this->casoDeUso()->executar($user, $tenant)->homeOffice);
    }

    #[TestDox('Interjornada: o padrão do servidor (sem configuração vale; desligada no escritório, não)')]
    public function testInterjornada(): void
    {
        [$user, $tenant] = $this->pessoa();
        self::assertTrue($this->casoDeUso()->executar($user, $tenant)->interjornadaLigada);

        $jornada = new JornadaTenant();
        $jornada->setValidacaoInterjornadaHabilitada(false);
        $tenant->setJornadaTenant($jornada);
        $this->em->persist($jornada);
        $this->em->flush();
        self::assertFalse($this->casoDeUso()->executar($user, $tenant)->interjornadaLigada);
    }

    private function casoDeUso(): ObterEstadoDoPontoDoDiaUseCase
    {
        return static::getContainer()->get(ObterEstadoDoPontoDoDiaUseCase::class);
    }

    /** @return array{User, Tenant} */
    private function pessoa(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant estado do ponto ' . uniqid());
        $this->em->persist($tenant);
        $user = new User();
        $user->setEmail('estado_ponto_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa do ponto');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return [$user, $tenant];
    }

    private function batida(User $user, Tenant $tenant, string $tipo, string $quando): void
    {
        $r = new RegistroPonto();
        $r->setUser($user);
        $r->setTenant($tenant);
        $r->setTipo($tipo);
        $r->setDataHora(new \DateTime($quando));
        $this->em->persist($r);
        $this->em->flush();
    }
}
