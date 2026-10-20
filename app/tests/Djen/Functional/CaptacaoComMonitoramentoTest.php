<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A captação diária com o monitoramento por processo (spec push-painel-monitoramento §1), contra o
 * banco: o processo pausado sai da lista de números e não é consultado; o consultado grava a última
 * verificação, inclusive com o número cadastrado COM máscara; outro escritório não é tocado.
 */
#[CoversClass(ProcessoRepository::class)]
#[CoversClass(SincronizarPublicacoesDjenUseCase::class)]
final class CaptacaoComMonitoramentoTest extends KernelTestCase
{
    private const ATIVO   = '07011345720258070007';
    private const PAUSADO = '07022222222222222222';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        if ($this->em->getFilters()->isEnabled('tenant')) {
            $this->em->getFilters()->disable('tenant');
        }
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Captação ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function processo(Tenant $tenant, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);
        $this->em->persist($processo);

        return $processo;
    }

    #[TestDox('Pula o pausado, grava a última verificação do consultado (cadastrado com máscara) e não toca outro escritório')]
    public function testCaptacaoPulaPausadoEGravaVerificacao(): void
    {
        $tenant = $this->tenant();
        $outro = $this->tenant();
        $ativo = $this->processo($tenant, '0701134-57.2025.8.07.0007');
        $pausado = $this->processo($tenant, self::PAUSADO);
        $pausado->pausarMonitoramento($this->usuario(), new \DateTimeImmutable());
        $homonimo = $this->processo($outro, self::ATIVO);
        $this->em->flush();

        $consultados = [];
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturnCallback(
            function (ConsultaComunicacoesQuery $q) use (&$consultados): RespostaComunicacoes {
                $consultados[] = $q->numeroProcesso;

                return new RespostaComunicacoes(0, []);
            },
        );
        static::getContainer()->set(DjenClient::class, $mock);

        self::assertSame(['0701134-57.2025.8.07.0007'], static::getContainer()->get(ProcessoRepository::class)->listarNumerosAcompanhadosDoTenant($tenant));
        self::assertSame(1, static::getContainer()->get(ProcessoRepository::class)->contarAcompanhadosDoTenant($tenant));

        static::getContainer()->get(SincronizarPublicacoesDjenUseCase::class)
            ->sincronizarProcessosCobrindoTodos($tenant, PeriodoConsulta::dia(new \DateTimeImmutable('today')));

        self::assertSame([self::ATIVO], $consultados, 'o pausado não é consultado');
        $this->em->clear();
        self::assertNotNull($this->em->find(Processo::class, $ativo->getId())?->getUltimaVerificacaoEm());
        self::assertNull($this->em->find(Processo::class, $pausado->getId())?->getUltimaVerificacaoEm());
        self::assertNull($this->em->find(Processo::class, $homonimo->getId())?->getUltimaVerificacaoEm(), 'mesmo número em outro escritório');
    }

    private function usuario(): User
    {
        $user = new User();
        $user->setEmail('captacao_' . uniqid() . '@test.com');
        $user->setFullName('Quem pausou');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }
}
