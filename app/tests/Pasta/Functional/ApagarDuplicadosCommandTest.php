<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Command\ApagarDuplicadosCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaDocumentoFavorito;
use App\Pasta\Repository\ConsultaDeDuplicadosDaPasta;
use App\Pasta\UseCase\EnviarDuplicadosParaALixeiraUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:documentos:apagar-duplicados` com banco de verdade (DAMA): a consulta por sha256 na MESMA pasta,
 * o isolamento por escritório, a simulação, a lixeira sem autor, o vínculo que decide quem fica, o
 * cursor de fatias e o CSV sem nomes.
 */
#[CoversClass(ApagarDuplicadosCommand::class)]
#[CoversClass(EnviarDuplicadosParaALixeiraUseCase::class)]
#[CoversClass(ConsultaDeDuplicadosDaPasta::class)]
final class ApagarDuplicadosCommandTest extends KernelTestCase
{
    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Tenant $outro;
    private User $usuario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant  = $this->tenant('Dup A');
        $this->outro   = $this->tenant('Dup B');
        $this->usuario = (new User())->setEmail('dup_' . uniqid() . '@test.com')->setFullName('Fulano')
            ->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('dummy');
        $this->em->persist($this->usuario);
        $this->em->flush();
    }

    private function tenant(string $nome): Tenant
    {
        $t = new Tenant();
        $t->setName($nome . ' ' . uniqid());
        $this->em->persist($t);

        return $t;
    }

    private function pasta(Tenant $tenant): Pasta
    {
        $p = new Pasta();
        $p->setNup('DUP-' . uniqid());
        $p->setTenant($tenant);
        $this->em->persist($p);
        $this->em->flush();

        return $p;
    }

    private function doc(Pasta $pasta, string $hash, int $tamanho = 10): PastaDocumento
    {
        $d = (new PastaDocumento())->setTenant($pasta->getTenant());
        $d->setTitulo('t')->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)->setCaminhoArquivo('x-' . uniqid())
            ->setNomeOriginal('segredo-cliente.pdf')->setMimeType('application/pdf')->setTamanhoBytes($tamanho)
            ->setPasta($pasta)->setSha256($hash);
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    /** @param array<string, mixed> $opcoes */
    private function rodar(array $opcoes): CommandTester
    {
        $c = static::getContainer();
        $t = new CommandTester(new ApagarDuplicadosCommand($c->get(EnviarDuplicadosParaALixeiraUseCase::class)));
        $t->execute($opcoes);

        return $t;
    }

    /** Lê a lápide por SQL cru: o filtro da lixeira esconderia a linha. */
    private function naLixeira(PastaDocumento $d): bool
    {
        $conn = static::getContainer()->get(Connection::class);

        return (bool) $conn->fetchOne('SELECT excluido_em IS NOT NULL FROM pasta_documento WHERE id = :id', ['id' => $d->getId()]);
    }

    #[TestDox('simulação (padrão) conta e relata, mas não grava nada')]
    public function testSimulacaoNaoGrava(): void
    {
        $pasta = $this->pasta($this->tenant);
        $a = $this->doc($pasta, self::HASH_A);
        $b = $this->doc($pasta, self::HASH_A);

        $t = $this->rodar(['--tenant' => (string) $this->tenant->getId()]);

        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertStringContainsString('modo=simulacao grupos=1 copias_enviadas=1', $t->getDisplay());
        self::assertFalse($this->naLixeira($a));
        self::assertFalse($this->naLixeira($b));
    }

    #[TestDox('--confirmar: fica a mais antiga, a cópia vai para a lixeira SEM autor')]
    public function testConfirmarManda(): void
    {
        $pasta = $this->pasta($this->tenant);
        $a = $this->doc($pasta, self::HASH_A, 10);
        $b = $this->doc($pasta, self::HASH_A, 10);
        $c = $this->doc($pasta, self::HASH_A, 10);
        $docPdf = $this->doc($pasta, self::HASH_B, 10); // "DOC e PDF": hash diferente, não é cópia
        $ids = [$a->getId(), $b->getId(), $c->getId(), $docPdf->getId()];

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId()]);

        self::assertStringContainsString('modo=gravacao grupos=1 copias_enviadas=2 bytes_a_liberar=20', $t->getDisplay());
        $conn  = static::getContainer()->get(Connection::class);
        $rows  = $conn->fetchAllKeyValue('SELECT id, excluido_em IS NOT NULL FROM pasta_documento WHERE id IN (' . implode(',', $ids) . ')');
        self::assertFalse((bool) $rows[$ids[0]], 'a mais antiga fica');
        self::assertTrue((bool) $rows[$ids[1]]);
        self::assertTrue((bool) $rows[$ids[2]]);
        self::assertFalse((bool) $rows[$ids[3]], 'hash diferente não é tocado');
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id IN (' . implode(',', $ids) . ') AND excluido_em IS NOT NULL AND excluido_por_id IS NULL'));
    }

    #[TestDox('cópia com vínculo (favorito) fica, mesmo sendo a mais nova')]
    public function testVinculoDecideQuemFica(): void
    {
        $pasta = $this->pasta($this->tenant);
        $a = $this->doc($pasta, self::HASH_A);
        $b = $this->doc($pasta, self::HASH_A);
        $this->em->persist(PastaDocumentoFavorito::doDocumento($this->tenant, $this->usuario, $b));
        $this->em->flush();

        $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId()]);

        self::assertTrue($this->naLixeira($a));
        self::assertFalse($this->naLixeira($b));
    }

    #[TestDox('mesmo hash em pastas diferentes ou em escritório fora do filtro: nada é tocado')]
    public function testIsolamentoPorPastaEEscritorio(): void
    {
        $p1 = $this->pasta($this->tenant);
        $p2 = $this->pasta($this->tenant);
        $a  = $this->doc($p1, self::HASH_A);
        $b  = $this->doc($p2, self::HASH_A); // outra pasta: não é cópia "na mesma pasta"
        $po = $this->pasta($this->outro);
        $c  = $this->doc($po, self::HASH_A);
        $d  = $this->doc($po, self::HASH_A);

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId()]);

        self::assertStringContainsString('grupos=0', $t->getDisplay());
        foreach ([$a, $b, $c, $d] as $doc) {
            self::assertFalse($this->naLixeira($doc));
        }
    }

    #[TestDox('--limite corta por grupos com cursor por pasta, imprime a próxima fatia e o CSV leva só ids')]
    public function testFatiasECsv(): void
    {
        $p1 = $this->pasta($this->tenant);
        $p2 = $this->pasta($this->tenant);
        $this->doc($p1, self::HASH_A);
        $this->doc($p1, self::HASH_A);
        $this->doc($p2, self::HASH_A);
        $this->doc($p2, self::HASH_A);
        $csv = sys_get_temp_dir() . '/dup_' . uniqid() . '.csv';

        $t = $this->rodar(['--tenant' => (string) $this->tenant->getId(), '--limite' => '1', '--saida' => $csv]);

        self::assertStringContainsString('grupos=1 ', $t->getDisplay());
        self::assertStringContainsString('Próxima fatia: repita o comando com --a-partir-de=' . $p1->getId(), $t->getDisplay());
        $conteudo = (string) file_get_contents($csv);
        unlink($csv);
        self::assertStringContainsString('tenant_id,pasta_id,documento_mantido', $conteudo);
        self::assertStringNotContainsString('segredo-cliente', $conteudo);
        self::assertCount(2, array_filter(explode("\n", $conteudo)), 'cabeçalho + 1 grupo');

        $t2 = $this->rodar(['--tenant' => (string) $this->tenant->getId(), '--limite' => '1', '--a-partir-de' => (string) $p1->getId()]);
        self::assertStringContainsString('ultima_pasta=' . $p2->getId() . ' a_frente=0', $t2->getDisplay());
    }

    #[TestDox('opção inválida falha sem rodar')]
    public function testOpcaoInvalida(): void
    {
        $t = $this->rodar(['--limite' => 'abc']);

        self::assertSame(Command::FAILURE, $t->getStatusCode());
    }
}
