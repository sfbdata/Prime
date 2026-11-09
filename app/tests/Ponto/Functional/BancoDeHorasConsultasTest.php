<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\BancoDeHorasPorLotes;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\LimitesDoBancoDeHoras;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O banco por lotes atravessa os anos e os meses crescem sem teto; a /ponto abre várias vezes por dia. O número de
 * consultas do cálculo não pode crescer com os meses (E17): o período inteiro sai de uma vez (batidas, justificativas e
 * horas pagas agrupadas), e cada mês é só conta em memória.
 */
#[CoversClass(BancoDeHorasPorLotes::class)]
#[CoversClass(FolhaPontoBuilder::class)]
final class BancoDeHorasConsultasTest extends KernelTestCase
{
    private const TETO = 8;

    #[TestDox('24 meses de banco fazem as mesmas consultas que 1 mês, e no máximo 8')]
    public function testConsultasNaoCrescemComOsMeses(): void
    {
        self::bootKernel();
        $escritorio = $this->escritorio();
        $hoje = new \DateTimeImmutable('today');
        $umMes = $this->pessoaComJornada($escritorio, $hoje->modify('first day of this month'), 1);
        $vinteEQuatro = $this->pessoaComJornada($escritorio, $hoje->modify('first day of -23 months'), 24);

        $escritorioId = (int) $escritorio->getId();

        $consultasDeUmMes = $this->consultasDoCalculo($umMes, $escritorioId);
        $consultasDeVinteEQuatro = $this->consultasDoCalculo($vinteEQuatro, $escritorioId);

        self::assertSame($consultasDeUmMes, $consultasDeVinteEQuatro, sprintf('1 mês: %d consultas; 24 meses: %d', $consultasDeUmMes, $consultasDeVinteEQuatro));
        self::assertLessThanOrEqual(self::TETO, $consultasDeVinteEQuatro);
    }

    private function consultasDoCalculo(int $pessoa, int $escritorio): int
    {
        $em = $this->em();
        $em->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $user = $em->find(User::class, $pessoa);
        $tenant = $em->find(Tenant::class, $escritorio);
        self::assertNotNull($user);
        self::assertNotNull($tenant);
        // Cada conta é um pedido novo: a leitura dos limites guarda a configuração do escritório só durante um pedido.
        $limites = static::getContainer()->get(LimitesDoBancoDeHoras::class);
        if ($limites instanceof ResetInterface) {
            $limites->reset();
        }
        $holder->reset();

        $banco = static::getContainer()->get(BancoDeHorasPorLotes::class)->calcular($user, $tenant, new \DateTimeImmutable('today'));
        self::assertNotSame([], $banco->lotes, 'controle: o cenário tem lotes');

        return count($holder->getData()['default'] ?? []);
    }

    /** Jornada de segunda a sexta, a primeira batida no início do período e 1h paga em cada mês. */
    private function pessoaComJornada(Tenant $escritorio, \DateTimeImmutable $inicio, int $meses): int
    {
        $user = new User();
        $user->setEmail('consultas_' . uniqid() . '@test.com');
        $user->setFullName('Consultas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);

        $jornada = (new JornadaColaborador())->setUser($user)->setTenant($escritorio)->setCargaHorariaSemanal(2400);
        $jornada->setValeAPartirDe($inicio->modify('-1 month'));
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('17:00')->setMinutosBloco(480));
        $this->em()->persist($jornada);

        $batida = new RegistroPonto();
        $batida->setUser($user);
        $batida->setTenant($escritorio);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime($inicio->format('Y-m-d') . ' 08:00:00'));
        $batida->setSedeNomeSnapshot('Teste');
        $this->em()->persist($batida);

        for ($mes = 0; $mes < $meses; ++$mes) {
            $competencia = $inicio->modify(sprintf('+%d months', $mes));
            $lancamento = new LancamentoHorasPagas();
            $lancamento->setTenant($escritorio);
            $lancamento->setUser($user);
            $lancamento->setAno((int) $competencia->format('Y'));
            $lancamento->setMes((int) $competencia->format('n'));
            $lancamento->setMinutos(60);
            $lancamento->setMotivo('Lançamento de teste');
            $lancamento->setCriadoPor($user);
            $lancamento->setCriadoEm(new \DateTimeImmutable());
            $this->em()->persist($lancamento);
        }
        $this->em()->flush();

        return (int) $user->getId();
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório Consultas ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
