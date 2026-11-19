<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\NumeroDePastaEmUsoException;
use App\Pasta\Service\NumeracaoDePasta;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Pasta\UseCase\EditarPastaUseCase;
use App\Pasta\UseCase\GerarNumeroDePasta;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * O número digitado à mão (Editar dados e a proposta de pasta do Chat I.A) passa pela mesma trava da numeração
 * automática e da restauração (18/11/2026): duas pastas vivas do escritório não terminam com o mesmo número.
 *
 * Sob o DAMA não há segunda conexão que enxergue a fixture, então a corrida é provada em duas partes, como no
 * `CorrigirValorDoPagamentoTravaTest`:
 *
 * 1. a SEQUÊNCIA que o banco recebeu: a trava do escritório vem antes da conferência, e a conferência antes da gravação;
 * 2. a RELEITURA: o número muda por fora do UnitOfWork (como faria o commit de quem segurava a trava antes) e a
 *    decisão segue o banco, não a entidade em memória.
 */
#[CoversClass(EditarPastaUseCase::class)]
#[CoversClass(CriarPastaUseCase::class)]
#[CoversClass(GerarNumeroDePasta::class)]
#[CoversClass(NumeracaoDePasta::class)]
final class NumeroDigitadoNaTravaTest extends KernelTestCase
{
    use Factories;

    private const TRAVA = '/pg_advisory_xact_lock/i';
    private const CONFERENCIA = '/^SELECT 1 FROM pasta WHERE tenant_id = \? AND nup = \?/i';

    #[TestDox('Editar o número: trava do escritório, depois a conferência no banco, depois o UPDATE')]
    public function testSequenciaAoEditar(): void
    {
        self::bootKernel();
        $tenant = $this->tenant();
        $pasta = $this->pasta($tenant, '1239');

        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();
        $this->editar($pasta, '1250');

        $sql = array_column($coletor->getData()['default'] ?? [], 'sql');
        $trava = $this->primeiro($sql, self::TRAVA);
        $conferencia = $this->primeiro($sql, self::CONFERENCIA);
        $gravacao = $this->primeiro($sql, '/^UPDATE pasta\b/i');
        self::assertNotNull($trava, 'o número digitado pega a trava da numeração');
        self::assertNotNull($conferencia);
        self::assertNotNull($gravacao);
        self::assertLessThan($conferencia, $trava, 'a trava vem antes da conferência');
        self::assertLessThan($gravacao, $conferencia, 'a conferência vem antes da gravação');
        self::assertSame('1250', $this->nupGravado($pasta));
    }

    /**
     * A outra pasta foi carregada com 1300; quem segurava a trava antes já gravou 1250 nela. A conferência lê o banco:
     * se lesse a memória, os dois números se cruzariam.
     */
    #[TestDox('Número gravado por fora numa pasta viva (o commit de quem veio antes): a edição é recusada e nada muda')]
    public function testReleituraRecusaOEditar(): void
    {
        self::bootKernel();
        $tenant = $this->tenant();
        $pasta = $this->pasta($tenant, '1239');
        $outra = $this->pasta($tenant, '1300');
        $this->trocarPorFora($outra, '1250');
        self::assertSame('1300', $outra->getNup(), 'premissa: a memória está velha');

        $recusa = null;
        try {
            $this->editar($pasta, '1250');
        } catch (NumeroDePastaEmUsoException $e) {
            $recusa = $e;
        }

        self::assertNotNull($recusa, 'o número já é de outra pasta viva');
        self::assertSame('O número 1250 já é de outra pasta do escritório. Escolha outro número.', $recusa->getMessage());
        self::assertSame('1239', $this->nupGravado($pasta));
        self::assertTrue($this->em()->isOpen(), 'a tela ainda responde com o aviso');
    }

    #[TestDox('O número de uma pasta na lixeira ou de outro escritório não impede a edição')]
    public function testLixeiraEOutroEscritorioNaoOcupam(): void
    {
        self::bootKernel();
        $tenant = $this->tenant();
        $pasta = $this->pasta($tenant, '1239');
        $this->pasta($tenant, '1250')->marcarExcluida($this->usuario(), new \DateTimeImmutable());
        $this->pasta($this->tenant(), '1251');
        $this->em()->flush();

        $this->editar($pasta, '1250');
        $this->editar($pasta, '1251');

        self::assertSame('1251', $this->nupGravado($pasta));
    }

    #[TestDox('Pasta do legado que já divide o número com outra: mexer só no cliente não confere nem recusa')]
    public function testLegadoRepetidoSegueEditavel(): void
    {
        self::bootKernel();
        $tenant = $this->tenant();
        $pasta = $this->pasta($tenant, '10A');
        $this->pasta($tenant, '10A');

        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();
        $this->editar($pasta, '10a', 'Cliente novo');

        self::assertNull($this->primeiro(array_column($coletor->getData()['default'] ?? [], 'sql'), self::CONFERENCIA));
        self::assertSame('10A', $this->nupGravado($pasta));
    }

    #[TestDox('Criar com o número digitado (Chat I.A): trava antes do INSERT; número gravado por fora numa pasta viva é recusado')]
    public function testCriarComNumeroDigitado(): void
    {
        self::bootKernel();
        $tenant = $this->tenant();
        $outra = $this->pasta($tenant, '1300');

        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();
        $criada = $this->criar($tenant, '1250', true);
        $sql = array_column($coletor->getData()['default'] ?? [], 'sql');
        self::assertLessThan($this->primeiro($sql, '/^INSERT INTO pasta\b/i'), $this->primeiro($sql, self::CONFERENCIA));
        self::assertLessThan($this->primeiro($sql, self::CONFERENCIA), $this->primeiro($sql, self::TRAVA));
        self::assertSame('1250', $criada->getNup());

        $this->trocarPorFora($outra, '1260');
        $antes = $this->contar($tenant);
        try {
            $this->criar($tenant, '1260', true);
            self::fail('Deveria recusar o número.');
        } catch (NumeroDePastaEmUsoException $e) {
            self::assertSame('1260', $e->nup);
        }
        self::assertSame($antes, $this->contar($tenant), 'nada foi gravado');
        self::assertTrue($this->em()->isOpen());
    }

    #[TestDox('Acervo e Drive (sem a recusa) continuam aceitando o número repetido da origem, sem conferir')]
    public function testAcervoSegueAceitandoRepetido(): void
    {
        self::bootKernel();
        $tenant = $this->tenant();
        $this->pasta($tenant, '10');

        $criada = $this->criar($tenant, '10', false);

        self::assertSame('10', $criada->getNup());
        self::assertSame(2, $this->contar($tenant));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function usuario(): User
    {
        return UserFactory::createOne()->_real();
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Numero digitado ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function pasta(Tenant $tenant, string $nup): Pasta
    {
        $pasta = (new Pasta())->setNup($nup)->setNomeCliente('Cliente')->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function editar(Pasta $pasta, string $nup, string $cliente = 'Cliente'): void
    {
        static::getContainer()->get(EditarPastaUseCase::class)
            ->executar(new EditarPastaDTO(nup: $nup, nomeCliente: $cliente, situacao: $pasta->getSituacao()), $pasta);
    }

    private function criar(Tenant $tenant, string $nup, bool $recusar): Pasta
    {
        return static::getContainer()->get(CriarPastaUseCase::class)
            ->executar(new CriarPastaDTO(nup: $nup, nomeCliente: 'Nova', recusarNumeroEmUso: $recusar), $this->usuario(), $tenant);
    }

    private function trocarPorFora(Pasta $pasta, string $nup): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET nup = ? WHERE id = ?', [$nup, $pasta->getId()]);
    }

    private function nupGravado(Pasta $pasta): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT nup FROM pasta WHERE id = ?', [$pasta->getId()]);
    }

    private function contar(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta WHERE tenant_id = ?', [$tenant->getId()]);
    }

    /** @param list<string> $consultas */
    private function primeiro(array $consultas, string $padrao): ?int
    {
        foreach ($consultas as $i => $sql) {
            if (preg_match($padrao, trim($sql)) === 1) {
                return $i;
            }
        }

        return null;
    }
}
