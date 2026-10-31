<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Command\RotinaDaControladoriaCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\UseCase\LancarAlertasDaControladoriaUseCase;
use App\Repository\TenantRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Rotina de segunda-feira da Controladoria (spec central-documentos-controladoria, "Rotina de segunda-feira"). */
#[CoversClass(LancarAlertasDaControladoriaUseCase::class)]
#[CoversClass(RotinaDaControladoriaCommand::class)]
#[Group('pasta')]
final class RotinaDaControladoriaTest extends KernelTestCase
{
    use CriaEscritorioPastasParadasTrait;

    private function rotina(string $agora = '2026-11-02 07:30:00'): LancarAlertasDaControladoriaUseCase
    {
        self::bootKernel();
        $c = static::getContainer();

        return new LancarAlertasDaControladoriaUseCase(
            $c->get(\App\Pasta\Repository\PastaRepository::class),
            $c->get(\App\Pasta\Repository\PastaMensagemRepository::class),
            $c->get(\App\Pasta\Service\AlertasDaPasta::class),
            $c->get(\App\Pasta\Service\RegistroDoSistemaNaPasta::class),
            $this->em(),
            new MockClock($agora),
        );
    }

    /** @return list<PastaMensagem> */
    private function cartoes(Pasta $pasta): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(PastaMensagem::class)->findBy(['pasta' => $pasta->getId(), 'sistema' => 'controladoria'], ['id' => 'ASC']);
    }

    private function comPrazoVencido(Pasta $pasta, string $titulo = 'Contestação'): void
    {
        $prazo = new PastaPrazo($pasta->getTenant(), $pasta, $this->primeiroUsuario($pasta->getTenant()));
        $prazo->definir(PastaPrazo::TIPO_PRAZO, $titulo, new \DateTimeImmutable('2026-10-20'), null, null);
        $this->em()->persist($prazo);
        $this->em()->flush();
    }

    private function escritorio(string $nome = 'Rotina'): Tenant
    {
        $tenant = $this->novoEscritorio($nome);
        $this->novaPessoa($tenant, 'Paula', $this->novoPerfil($tenant, true));

        return $tenant;
    }

    #[TestDox('alerta novo vira cartão do sistema no Registro; a rodada seguinte, com o mesmo alerta, não repete')]
    public function testNaoRepeteAlertaQueSegueValendo(): void
    {
        $tenant = $this->escritorio();
        $pasta  = $this->pastaAbertaEm($tenant, '1', '2026-10-30', $this->primeiroUsuario($tenant));
        $this->comPrazoVencido($pasta);

        $primeira = $this->rotina()->executar($tenant);
        $segunda  = $this->rotina()->executar($tenant);

        self::assertSame(1, $primeira->alertasNovos);
        self::assertSame(1, $primeira->pastasComAlertaNovo);
        self::assertSame(0, $segunda->alertasNovos);
        $cartoes = $this->cartoes($pasta);
        self::assertCount(1, $cartoes);
        self::assertStringContainsString('prazo(s) vencido(s)', $cartoes[0]->getConteudo());
        self::assertStringContainsString('<strong>Controladoria:</strong>', $cartoes[0]->getConteudo());
    }

    #[TestDox('um segundo alerta, com o primeiro ainda valendo, lança só o segundo')]
    public function testLancaSoOAlertaNovo(): void
    {
        $tenant = $this->escritorio();
        $pasta  = $this->pastaAbertaEm($tenant, '2', '2026-10-30', $this->primeiroUsuario($tenant));
        $this->comPrazoVencido($pasta);
        $this->rotina()->executar($tenant);
        $pasta = $this->em()->find(Pasta::class, $pasta->getId());
        $pasta->setDataAbertura(new \DateTimeImmutable('2026-06-01'));   // agora a pasta também está parada
        $this->em()->flush();

        $r = $this->rotina()->executar($tenant);

        self::assertSame(1, $r->alertasNovos);
        $cartoes = $this->cartoes($pasta);
        self::assertCount(2, $cartoes);
        self::assertStringContainsString('Pasta parada', $cartoes[1]->getConteudo());
        self::assertStringNotContainsString('prazo(s) vencido(s)', $cartoes[1]->getConteudo());
    }

    #[TestDox('alerta que some e volta é lançado de novo')]
    public function testAlertaQueVoltaEhNovo(): void
    {
        $tenant = $this->escritorio();
        $pasta  = $this->pastaAbertaEm($tenant, '3', '2026-10-30', $this->primeiroUsuario($tenant));
        $this->comPrazoVencido($pasta);
        $this->rotina()->executar($tenant);
        $this->em()->getConnection()->executeStatement("UPDATE pasta_prazo SET data = '2027-01-01' WHERE pasta_id = ?", [$pasta->getId()]);
        $sumiu = $this->rotina()->executar($tenant);
        $this->em()->getConnection()->executeStatement("UPDATE pasta_prazo SET data = '2026-10-20' WHERE pasta_id = ?", [$pasta->getId()]);

        $voltou = $this->rotina()->executar($tenant);

        self::assertSame(0, $sumiu->alertasNovos);
        self::assertSame(1, $voltou->alertasNovos);
        self::assertCount(2, $this->cartoes($pasta));
    }

    #[TestDox('pasta sem alerta não ganha cartão; --dry-run não grava; outro escritório não é tocado')]
    public function testSemAlertaSimulacaoEIsolamento(): void
    {
        $meu    = $this->escritorio('Meu');
        $outro  = $this->escritorio('Outro');
        $limpa  = $this->pastaAbertaEm($meu, '4', '2026-10-30', $this->primeiroUsuario($meu));
        $alheia = $this->pastaAbertaEm($outro, '5', '2026-10-30', $this->primeiroUsuario($outro));
        $this->comPrazoVencido($alheia);
        $this->comPrazoVencido($this->pastaAbertaEm($meu, '6', '2026-10-30', $this->primeiroUsuario($meu)));

        $simulado = $this->rotina()->executar($meu, true);
        $real     = $this->rotina()->executar($meu);

        self::assertSame(1, $simulado->alertasNovos);
        self::assertSame(2, $real->pastas);
        self::assertSame(1, $real->alertasNovos);
        self::assertSame([], $this->cartoes($limpa));
        self::assertSame([], $this->cartoes($alheia));
    }

    #[TestDox('o comando percorre os escritórios ativos e sai com sucesso; --tenant inexistente falha')]
    public function testComando(): void
    {
        $tenant = $this->escritorio('Comando');
        $this->comPrazoVencido($this->pastaAbertaEm($tenant, '7', '2026-10-30', $this->primeiroUsuario($tenant)));
        $rotina = $this->rotina();
        $c = static::getContainer();
        $tester = new CommandTester(new RotinaDaControladoriaCommand(
            $c->get(TenantRepository::class),
            $rotina,
            $this->em(),
            $c->get(ManagerRegistry::class),
        ));

        $ok    = $tester->execute(['--tenant' => (string) $tenant->getId()]);
        $falha = $tester->execute(['--tenant' => '99999999']);

        self::assertSame(Command::SUCCESS, $ok);
        self::assertSame(Command::FAILURE, $falha);
    }

    #[TestDox('alerta de nível baixo (pasta parada há 45 dias) não entra no Registro; o médio (70 dias) entra')]
    public function testIgnoraNivelBaixo(): void
    {
        $tenant = $this->escritorio('Baixo');
        $user   = $this->primeiroUsuario($tenant);
        $baixa  = $this->pastaAbertaEm($tenant, '8', '2026-09-18', $user);   // 45 dias em 02/11
        $media  = $this->pastaAbertaEm($tenant, '9', '2026-08-24', $user);    // 70 dias em 02/11

        $r = $this->rotina()->executar($tenant);

        self::assertSame(1, $r->alertasNovos);
        self::assertSame(['parada' => 1], $r->porTipo);
        self::assertSame([], $this->cartoes($baixa));
        self::assertCount(1, $this->cartoes($media));
    }

    #[TestDox('--simular lista escritório e tipo e não grava cartão')]
    public function testSimularListaPorTipoSemGravar(): void
    {
        $tenant = $this->escritorio('Simular');
        $pasta  = $this->pastaAbertaEm($tenant, '10', '2026-10-30', $this->primeiroUsuario($tenant));
        $this->comPrazoVencido($pasta);
        $rotina = $this->rotina();
        $c = static::getContainer();
        $tester = new CommandTester(new RotinaDaControladoriaCommand(
            $c->get(TenantRepository::class),
            $rotina,
            $this->em(),
            $c->get(ManagerRegistry::class),
        ));

        $codigo = $tester->execute(['--simular' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);
        self::assertMatchesRegularExpression('/Simular \S+\s+prazos\s+1\s/u', $tester->getDisplay());
        self::assertSame([], $this->cartoes($pasta));
    }
}
