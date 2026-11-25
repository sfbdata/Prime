<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Integration;

use App\Comercial\Entity\ContatoComercial;
use App\Comercial\Exception\ContatoRecusado;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Comercial\UseCase\EditarNomeDoContatoComercialUseCase;
use App\Comercial\UseCase\ExcluirContatosDoComercialUseCase;
use App\Comercial\UseCase\ImportarContatosDoComercialUseCase;
use App\Comercial\UseCase\IncluirContatoDoComercialUseCase;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** As escritas da Base de contatos contra o banco de teste (DAMA desfaz cada teste). */
#[CoversClass(ImportarContatosDoComercialUseCase::class)]
#[CoversClass(IncluirContatoDoComercialUseCase::class)]
#[CoversClass(EditarNomeDoContatoComercialUseCase::class)]
#[CoversClass(ExcluirContatosDoComercialUseCase::class)]
#[CoversClass(ContatoComercialRepository::class)]
#[CoversClass(ContatoComercial::class)]
final class BaseDeContatosDoComercialTest extends KernelTestCase
{
    private User $master;
    private string $m;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->m = uniqid('bc');
        $this->master = (new User())->setEmail("master.{$this->m}@test.com")->setFullName('Master')->setRoles(['ROLE_SUPER_ADMIN'])->setIsActive(true)->setPassword('x');
        $em->persist($this->master);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function repo(): ContatoComercialRepository
    {
        return static::getContainer()->get(ContatoComercialRepository::class);
    }

    #[TestDox('Importar: conta novos, atualizados e inválidos; repetido não duplica; nome só muda se vier preenchido; normaliza e-mail')]
    public function testImportar(): void
    {
        $caso = static::getContainer()->get(ImportarContatosDoComercialUseCase::class);
        $a = "ana.{$this->m}@ex.com";
        $b = "bia.{$this->m}@ex.com";
        $c = "cris.{$this->m}@ex.com";
        $caso->executar("email,nome\n$a,Ana\n$b;Bia\nsem-arroba-valido@\nruim@x\n", 'lista.csv', $this->master);

        $csv = "email;nome\n" . strtoupper($a) . ";Ana Maria\n$b\n$c;Cris\n$c;\nruim@x\n";
        $r = $caso->executar($csv, 'outra.csv', $this->master);

        self::assertSame(1, $r->novos, 'só a Cris é nova (e repetida no arquivo conta uma vez)');
        self::assertSame(2, $r->atualizados);
        self::assertSame(1, $r->invalidos);
        $this->em()->clear();
        self::assertSame('Ana Maria', $this->repo()->porEmail($a)?->getNome(), 'nome preenchido atualiza');
        self::assertSame('Bia', $this->repo()->porEmail($b)?->getNome(), 'sem nome na linha, o nome fica');
        $cris = $this->repo()->porEmail($c);
        self::assertSame('Cris', $cris?->getNome());
        self::assertSame('importacao', $cris->getOrigem());
        self::assertSame('outra.csv', $cris->getOrigemDetalhe());
        self::assertCount(3, $this->repo()->porEmails([$a, $b, $c]));
    }

    #[TestDox('Importar: arquivo sem nenhuma linha com e-mail e arquivo acima de 5.000 linhas são recusados')]
    public function testImportarRecusas(): void
    {
        $caso = static::getContainer()->get(ImportarContatosDoComercialUseCase::class);
        try {
            $caso->executar("email,nome\nsó texto\n", null, $this->master);
            self::fail('deveria recusar');
        } catch (ContatoRecusado $e) {
            self::assertStringContainsString('nenhuma linha', $e->getMessage());
        }
        $this->expectException(ContatoRecusado::class);
        $caso->executar(str_repeat("a@b.com\n", 5001), null, $this->master);
    }

    #[TestDox('Incluir: normaliza, grava como manual, recusa inválido e repetido')]
    public function testIncluir(): void
    {
        $caso = static::getContainer()->get(IncluirContatoDoComercialUseCase::class);
        $contato = $caso->executar(strtoupper("  Novo.{$this->m}@Ex.com "), 'Nome (X)', $this->master);

        self::assertSame("novo.{$this->m}@ex.com", $contato->getEmail());
        self::assertSame('manual', $contato->getOrigem());
        self::assertSame('Nome X', $contato->getNome());
        foreach (['invalido', "novo.{$this->m}@ex.com"] as $email) {
            try {
                $caso->executar($email, null, $this->master);
                self::fail('deveria recusar ' . $email);
            } catch (ContatoRecusado) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Editar o nome e excluir (um ou vários); excluir sem escolha ou contato inexistente recusa')]
    public function testEditarEExcluir(): void
    {
        $incluir = static::getContainer()->get(IncluirContatoDoComercialUseCase::class);
        $x = $incluir->executar("x.{$this->m}@ex.com", 'X', $this->master);
        $y = $incluir->executar("y.{$this->m}@ex.com", null, $this->master);

        static::getContainer()->get(EditarNomeDoContatoComercialUseCase::class)->executar((int) $y->getId(), 'Yara', $this->master);
        self::assertSame('Yara', $y->getNome());
        static::getContainer()->get(EditarNomeDoContatoComercialUseCase::class)->executar((int) $y->getId(), '', $this->master);
        self::assertNull($y->getNome());

        $excluir = static::getContainer()->get(ExcluirContatosDoComercialUseCase::class);
        self::assertSame(2, $excluir->executar([(int) $x->getId(), (int) $y->getId(), (int) $y->getId()], $this->master));
        $this->em()->clear();
        self::assertSame([], $this->repo()->porEmails(["x.{$this->m}@ex.com", "y.{$this->m}@ex.com"]));

        try {
            $excluir->executar([], $this->master);
            self::fail('lista vazia');
        } catch (ContatoRecusado) {
            self::addToAssertionCount(1);
        }
        $this->expectException(ContatoRecusado::class);
        static::getContainer()->get(EditarNomeDoContatoComercialUseCase::class)->executar(999999999, 'Z', $this->master);
    }

    #[TestDox('A busca no servidor casa e-mail ou nome, sem acento e sem caixa, e trata % e _ como letra')]
    public function testBuscaSemAcento(): void
    {
        $incluir = static::getContainer()->get(IncluirContatoDoComercialUseCase::class);
        $incluir->executar("jose.{$this->m}@ex.com", 'José Conceição', $this->master);
        $incluir->executar("outro.{$this->m}@ex.com", 'Outro', $this->master);

        self::assertSame(1, $this->repo()->pagina('JOSE CONCEICAO', 1, 20)['total']);
        self::assertSame(1, $this->repo()->pagina("jose.{$this->m}", 1, 20)['total']);
        self::assertSame(0, $this->repo()->pagina('%', 1, 20)['total']);
        self::assertSame(2, $this->repo()->pagina($this->m, 1, 20)['total']);
        self::assertCount(1, $this->repo()->pagina($this->m, 2, 1)['itens']);
    }
}
