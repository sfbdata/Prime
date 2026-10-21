<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Tenant\Tenant;
use App\Profile\Command\ResumoDiarioCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ResumoDiarioCommand::class)]
final class ResumoDiarioCommandTest extends KernelTestCase
{
    #[TestDox('app:perfil:resumo-diario: simulação de um escritório termina bem e mostra a tabela; escritório que não existe falha')]
    public function testSimulacaoEEscritorioInexistente(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Comando ' . uniqid());
        $em->persist($tenant);
        $em->flush();
        $tester = new CommandTester((new Application($kernel))->find('app:perfil:resumo-diario'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--tenant' => (string) $tenant->getId()]));
        self::assertStringContainsString('Modo simulação', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--tenant' => '999999999']));
        self::assertStringContainsString('não encontrado', $tester->getDisplay());
    }
}
