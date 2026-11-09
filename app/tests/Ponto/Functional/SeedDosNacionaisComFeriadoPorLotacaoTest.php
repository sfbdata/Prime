<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Command\SeedFeriadosNacionaisCommand;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * O cadastro dos feriados nacionais (`app:seed-feriados-nacionais`) com o feriado por lotação da E6 (spec
 * `docs/specs/ponto-desenho-0811.md`): o feriado de uma lotação só não ocupa o lugar do nacional na mesma data, que
 * vale para todo o escritório; o de "Todas as sedes" continua ocupando.
 */
#[CoversClass(SeedFeriadosNacionaisCommand::class)]
#[Group('ponto')]
final class SeedDosNacionaisComFeriadoPorLotacaoTest extends KernelTestCase
{
    #[TestDox('O feriado de uma lotação em 07/09 não impede o nacional; o de todos em 25/12 impede')]
    public function testFeriadoDaLotacaoNaoOcupaOLugarDoNacional(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $escritorio = (new Tenant())->setName('Escritório dos nacionais ' . uniqid());
        $lotacao = (new Lotacao())->setNome('MACEIÓ/AL')->setTenant($escritorio);
        $daLotacao = (new Feriado())->setNome('Feriado só de Maceió')->setData(new \DateTime('2000-09-07'))->setTenant($escritorio)->setLotacao($lotacao);
        $natal = (new Feriado())->setNome('Natal')->setData(new \DateTime('2000-12-25'))->setTenant($escritorio);
        foreach ([$escritorio, $lotacao, $daLotacao, $natal] as $entidade) {
            $em->persist($entidade);
        }
        $em->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:seed-feriados-nacionais'));
        $tester->execute(['--dry-run' => true]);
        $saida = $tester->getDisplay();

        self::assertStringContainsString(sprintf('[%s] Adicionando: Independência do Brasil (07/09)', $escritorio->getName()), $saida);
        self::assertStringNotContainsString(sprintf('[%s] Adicionando: Natal', $escritorio->getName()), $saida);
    }
}
