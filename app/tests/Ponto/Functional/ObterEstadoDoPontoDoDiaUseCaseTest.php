<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\EstadoDoPontoDoDiaOutput;
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
        // Outro dia não entra. Ontem com entrada E saída: um ontem sem saída, num dia útil, poria "Regularizar ponto de
        // ontem" na frente do estado de hoje e o teste dependeria do dia da semana em que roda.
        $this->batida($user, $tenant, RegistroPonto::TIPO_ENTRADA, 'yesterday 08:00');
        $this->batida($user, $tenant, RegistroPonto::TIPO_SAIDA, 'yesterday 17:00');

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

    #[TestDox('Regularizar ontem: entrada sem saída num dia útil vem antes do estado de hoje, com o dia de ontem')]
    public function testRegularizarOntem(): void
    {
        [$user, $tenant] = $this->pessoa();
        $this->batida($user, $tenant, RegistroPonto::TIPO_ENTRADA, '2026-10-19 08:00');   // segunda, sem saída

        $saida = $this->casoDeUsoEm('2026-10-20 10:00')->executar($user, $tenant);   // terça

        self::assertSame(EstadoDoPontoDoDiaOutput::REGULARIZAR_ONTEM, $saida->estado);
        self::assertSame('2026-10-19', $saida->ontemSemSaida);
    }

    #[TestDox('Regularizar ontem some: com saída, num fim de semana, sem batida ou com o esquecimento da saída já pedido')]
    public function testSemRegularizarOntem(): void
    {
        [$user, $tenant] = $this->pessoa();
        $this->batida($user, $tenant, RegistroPonto::TIPO_ENTRADA, '2026-10-19 08:00');
        $this->batida($user, $tenant, RegistroPonto::TIPO_SAIDA, '2026-10-19 17:00');
        self::assertNull($this->casoDeUsoEm('2026-10-20 10:00')->executar($user, $tenant)->ontemSemSaida, 'com saída');

        [$user2, $tenant2] = $this->pessoa();
        $this->batida($user2, $tenant2, RegistroPonto::TIPO_ENTRADA, '2026-10-18 08:00');   // domingo
        self::assertNull($this->casoDeUsoEm('2026-10-19 10:00')->executar($user2, $tenant2)->ontemSemSaida, 'fim de semana (condição 4)');

        [$user3, $tenant3] = $this->pessoa();
        $saida3 = $this->casoDeUsoEm('2026-10-20 10:00')->executar($user3, $tenant3);
        self::assertNull($saida3->ontemSemSaida, 'sem batida ontem (condição 9)');
        self::assertSame(EstadoDoPontoDoDiaOutput::BATER_ENTRADA, $saida3->estado);

        [$user4, $tenant4] = $this->pessoa();
        $this->batida($user4, $tenant4, RegistroPonto::TIPO_ENTRADA, '2026-10-19 08:00');
        $pedido = new \App\Ponto\Entity\JustificativaPonto();
        $pedido->setUser($user4);
        $pedido->setTenant($tenant4);
        $pedido->setData(new \DateTime('2026-10-19'));
        $pedido->setTipo('esquecimento_registro');
        $pedido->setTipoRegistroEsquecido(RegistroPonto::TIPO_SAIDA);
        $pedido->setStatus('pendente');
        $this->em->persist($pedido);
        $this->em->flush();
        self::assertNull($this->casoDeUsoEm('2026-10-20 10:00')->executar($user4, $tenant4)->ontemSemSaida, 'esquecimento pendente (condição 8)');
    }

    #[TestDox('Descanso mínimo do aviso da saída: o do escritório, 660 sem configuração, null com a validação desligada')]
    public function testInterjornadaMinima(): void
    {
        [$user, $tenant] = $this->pessoa();
        self::assertSame(660, $this->casoDeUso()->executar($user, $tenant)->interjornadaMinimaMin);

        $jornada = new \App\Ponto\Entity\JornadaTenant();
        $jornada->setMinimoMinutosInterjornada(390);
        $tenant->setJornadaTenant($jornada);
        $this->em->persist($jornada);
        $this->em->flush();
        self::assertSame(390, $this->casoDeUso()->executar($user, $tenant)->interjornadaMinimaMin);

        $jornada->setValidacaoInterjornadaHabilitada(false);
        $this->em->flush();
        self::assertNull($this->casoDeUso()->executar($user, $tenant)->interjornadaMinimaMin);
    }

    #[TestDox('Regularizar ontem, as demais condições do "+": feriado e abonado bloqueiam; esquecimento de outra batida não; vem antes de hoje em jornada')]
    public function testCondicoesDoMais(): void
    {
        [$u1, $t1] = $this->pessoa();
        $this->batida($u1, $t1, RegistroPonto::TIPO_ENTRADA, '2026-10-19 08:00');
        $feriado = new \App\Ponto\Entity\Feriado();
        $feriado->setNome('Feriado de teste');
        $feriado->setData(new \DateTime('2026-10-19'));
        $feriado->setRecorrente(false);
        $feriado->setTenant($t1);
        $this->em->persist($feriado);
        $this->em->flush();
        self::assertNull($this->casoDeUsoEm('2026-10-20 10:00')->executar($u1, $t1)->ontemSemSaida, 'feriado (condição 5)');

        [$u2, $t2] = $this->pessoa();
        $this->batida($u2, $t2, RegistroPonto::TIPO_ENTRADA, '2026-10-19 08:00');
        $this->esquecimento($u2, $t2, RegistroPonto::TIPO_SAIDA, 'abonado');
        self::assertNull($this->casoDeUsoEm('2026-10-20 10:00')->executar($u2, $t2)->ontemSemSaida, 'abonado (condição 8)');

        [$u3, $t3] = $this->pessoa();
        $this->batida($u3, $t3, RegistroPonto::TIPO_ENTRADA, '2026-10-19 08:00');
        $this->esquecimento($u3, $t3, RegistroPonto::TIPO_RETORNO, 'pendente');
        $this->batida($u3, $t3, RegistroPonto::TIPO_ENTRADA, '2026-10-20 08:00');   // hoje em jornada
        $saida = $this->casoDeUsoEm('2026-10-20 10:00')->executar($u3, $t3);
        self::assertSame('2026-10-19', $saida->ontemSemSaida, 'o esquecimento de OUTRA batida não bloqueia a saída');
        self::assertSame(EstadoDoPontoDoDiaOutput::REGULARIZAR_ONTEM, $saida->estado, 'vem antes do estado de hoje, como no dc');
        self::assertSame('08:00', $saida->hoje['entrada']);
    }

    private function esquecimento(User $user, Tenant $tenant, string $tipo, string $status): void
    {
        $pedido = new \App\Ponto\Entity\JustificativaPonto();
        $pedido->setUser($user);
        $pedido->setTenant($tenant);
        $pedido->setData(new \DateTime('2026-10-19'));
        $pedido->setTipo('esquecimento_registro');
        $pedido->setTipoRegistroEsquecido($tipo);
        $pedido->setStatus($status);
        $this->em->persist($pedido);
        $this->em->flush();
    }

    private function casoDeUsoEm(string $agora): ObterEstadoDoPontoDoDiaUseCase
    {
        $c = static::getContainer();

        return new ObterEstadoDoPontoDoDiaUseCase(
            $c->get(\App\Ponto\Repository\RegistroPontoRepository::class),
            $c->get(\App\Ponto\Service\EscolhaDasBatidasDoDia::class),
            $c->get(\App\Ponto\Service\HomeOfficeResolver::class),
            $c->get(\App\Ponto\Service\SedesDaCercaDoPonto::class),
            $c->get(\App\Ponto\Service\FolhaPontoBuilder::class),
            $c->get(\App\Ponto\Repository\FeriadoRepository::class),
            $c->get(\App\Ponto\Repository\JustificativaPontoRepository::class),
            $c->get(\App\Ponto\Service\InicioContagemResolver::class),
            $c->get(\App\Ponto\Service\EsquecimentosRegistrados::class),
            new \Symfony\Component\Clock\MockClock($agora, date_default_timezone_get()),   // o fuso do sistema, como o relógio real
            $c->get(\App\Ponto\Repository\JornadaColaboradorRepository::class),
        );
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
