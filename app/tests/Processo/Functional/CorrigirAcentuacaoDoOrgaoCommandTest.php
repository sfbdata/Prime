<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Tenant\Tenant;
use App\Processo\Command\CorrigirAcentuacaoDoOrgaoCommand;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Repository\MovimentacaoProcessoRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\UseCase\CorrigirAcentuacaoDoOrgaoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:processo:corrigir-acentuacao-do-orgao`: simula por padrão, grava só com `--aplicar` (processo e
 * movimentações, todos os escritórios), deixa intocado o que não reverte limpo e é idempotente. Os nomes são os
 * estragados em produção, reproduzidos pelo caminho do defeito.
 */
#[CoversClass(CorrigirAcentuacaoDoOrgaoCommand::class)]
#[CoversClass(CorrigirAcentuacaoDoOrgaoUseCase::class)]
#[CoversClass(ProcessoRepository::class)]
#[CoversClass(MovimentacaoProcessoRepository::class)]
final class CorrigirAcentuacaoDoOrgaoCommandTest extends KernelTestCase
{
    private const INTOCADO = "VARA \u{C3}\u{A9} \u{C3}O TESTE";

    /** @var array{int, int, int, int} processo estragado (escritório A), processo de B, movimentação, processo intocado */
    private array $ids;

    protected function setUp(): void
    {
        self::bootKernel();
        $a = $this->tenant('A');
        $b = $this->tenant('B');

        $sucessoes = $this->processo($a, self::estragar('Vara de Família, Sucessões e Infância e Juventude'));
        $brasilia  = $this->processo($b, self::estragar('6ª Vara do Trabalho de Brasília - DF'));
        $intocado  = $this->processo($a, self::INTOCADO);
        $legitimo  = $this->processo($a, 'VARA DE SÃO PAULO');
        $mov = (new MovimentacaoProcesso())->setDescricao('Distribuído')->setOrgao(self::estragar('Vara Única de Luziânia'))->setTenant($a);
        $sucessoes->addMovimentacao($mov);
        $this->em()->flush();

        $this->ids = [(int) $sucessoes->getId(), (int) $brasilia->getId(), (int) $mov->getId(), (int) $intocado->getId()];
        self::assertNotNull($legitimo->getId());
    }

    #[TestDox('sem --aplicar só simula: mostra antes e depois e não grava nada')]
    public function testSimulaPorPadrao(): void
    {
        $tester = $this->rodar([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $saida = $tester->getDisplay();
        self::assertStringContainsString('VARA DE FAMÍLIA, SUCESSÕES E INFÂNCIA E JUVENTUDE', $saida);
        self::assertStringContainsString('Simulação', $saida);
        self::assertSame(self::estragar('Vara de Família, Sucessões e Infância e Juventude'), $this->orgaoDoProcesso($this->ids[0]));
    }

    #[TestDox('--aplicar corrige processos de todos os escritórios e as movimentações; o que não reverte limpo e o "Ã" legítimo ficam como estão')]
    public function testAplicaECorrige(): void
    {
        $tester = $this->rodar(['--aplicar' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('VARA DE FAMÍLIA, SUCESSÕES E INFÂNCIA E JUVENTUDE', $this->orgaoDoProcesso($this->ids[0]));
        self::assertSame('6ª VARA DO TRABALHO DE BRASÍLIA - DF', $this->orgaoDoProcesso($this->ids[1]));
        self::assertSame('VARA ÚNICA DE LUZIÂNIA', $this->em()->getConnection()->fetchOne('SELECT orgao FROM movimentacao_processo WHERE id = ?', [$this->ids[2]]));
        self::assertSame(self::INTOCADO, $this->orgaoDoProcesso($this->ids[3]), 'não reverte limpo: fica intocado');
        self::assertStringContainsString('Não reverte limpo', $tester->getDisplay());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM processo WHERE orgao_julgador = 'VARA DE SÃO PAULO'"));
    }

    #[TestDox('idempotente: rodar --aplicar de novo não acha mais o que já foi corrigido')]
    public function testIdempotente(): void
    {
        $this->rodar(['--aplicar' => true]);
        $segunda = $this->rodar(['--aplicar' => true]);

        self::assertStringNotContainsString('SUCESSÕES', $segunda->getDisplay());
        self::assertSame('VARA DE FAMÍLIA, SUCESSÕES E INFÂNCIA E JUVENTUDE', $this->orgaoDoProcesso($this->ids[0]));
    }

    private static function estragar(string $original): string
    {
        return mb_strtoupper(mb_convert_encoding($original, 'UTF-8', 'ISO-8859-1'));
    }

    /** @param array<string, mixed> $opcoes */
    private function rodar(array $opcoes): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:processo:corrigir-acentuacao-do-orgao'));
        $tester->execute($opcoes);

        return $tester;
    }

    private function orgaoDoProcesso(int $id): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT orgao_julgador FROM processo WHERE id = ?', [$id]);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = (new Tenant())->setName('Acentuação ' . $nome . ' ' . uniqid());
        $this->em()->persist($tenant);

        return $tenant;
    }

    private function processo(Tenant $tenant, string $orgao): Processo
    {
        $processo = (new Processo())->setTenant($tenant)->setNumeroProcesso(sprintf('%020d', random_int(1, PHP_INT_MAX)))->setOrgaoJulgador($orgao);
        $this->em()->persist($processo);

        return $processo;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
