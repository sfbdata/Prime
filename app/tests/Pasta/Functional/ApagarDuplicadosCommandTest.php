<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Command\ApagarDuplicadosCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaDocumentoFavorito;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\ConsultaDeDuplicadosDaPasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\UseCase\DecisaoSobreGrupoDeDuplicados;
use App\Pasta\UseCase\EnviarDuplicadosParaALixeiraUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:documentos:apagar-duplicados` com banco de verdade (DAMA): o grupo por (pasta, seção, sha256), o
 * isolamento por escritório, a simulação, a lixeira sem autor, CADA vínculo (SQL real) decidindo quem
 * fica, a releitura dentro da transação, o cursor de fatias e o CSV incremental sem nomes.
 */
#[CoversClass(ApagarDuplicadosCommand::class)]
#[CoversClass(EnviarDuplicadosParaALixeiraUseCase::class)]
#[CoversClass(ConsultaDeDuplicadosDaPasta::class)]
final class ApagarDuplicadosCommandTest extends KernelTestCase
{
    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EntityManagerInterface $em;
    private Connection $conn;
    private Tenant $tenant;
    private Tenant $outro;
    private User $usuario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);

        $this->tenant  = $this->novoTenant('Dup A');
        $this->outro   = $this->novoTenant('Dup B');
        $this->usuario = (new User())->setEmail('dup_' . uniqid() . '@test.com')->setFullName('Fulano')
            ->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('dummy');
        $this->em->persist($this->usuario);
        $this->em->flush();
    }

    private function novoTenant(string $nome): Tenant
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

    private function secao(Pasta $pasta, string $nome): PastaSecao
    {
        $s = (new PastaSecao())->setNome($nome);
        $s->setPasta($pasta);
        $s->setTenant($pasta->getTenant());
        $this->em->persist($s);
        $this->em->flush();

        return $s;
    }

    private function doc(Pasta $pasta, string $hash, int $tamanho = 10, ?PastaSecao $secao = null): PastaDocumento
    {
        $d = (new PastaDocumento())->setTenant($pasta->getTenant());
        $d->setTitulo('t')->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)->setCaminhoArquivo('x-' . uniqid())
            ->setNomeOriginal('segredo-cliente.pdf')->setMimeType('application/pdf')->setTamanhoBytes($tamanho)
            ->setPasta($pasta)->setSecao($secao)->setSha256($hash);
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    /** @param array<string, mixed> $opcoes */
    private function rodar(array $opcoes): CommandTester
    {
        $t = new CommandTester(new ApagarDuplicadosCommand(static::getContainer()->get(EnviarDuplicadosParaALixeiraUseCase::class)));
        $t->execute($opcoes);

        return $t;
    }

    private function csvNovo(): string
    {
        return sys_get_temp_dir() . '/dup_' . uniqid() . '.csv';
    }

    private function naLixeira(PastaDocumento $d): bool
    {
        return (bool) $this->conn->fetchOne('SELECT excluido_em IS NOT NULL FROM pasta_documento WHERE id = :id', ['id' => $d->getId()]);
    }

    /** Os vínculos apontam para ids que não existem em outras tabelas (cliente, modelo, tarefa): FKs desligadas só nesta transação. */
    private function semFks(): void
    {
        $this->conn->executeStatement('SET LOCAL session_replication_role = replica');
    }

    #[TestDox('simulação (padrão) conta e relata, mas não grava nada')]
    public function testSimulacaoNaoGrava(): void
    {
        $pasta = $this->pasta($this->tenant);
        $a = $this->doc($pasta, self::HASH_A);
        $b = $this->doc($pasta, self::HASH_A);
        $csv = $this->csvNovo();

        $t = $this->rodar(['--tenant' => (string) $this->tenant->getId(), '--saida' => $csv]);

        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertStringContainsString('modo=simulacao grupos=1 copias_enviadas=1', $t->getDisplay());
        self::assertFalse($this->naLixeira($a));
        self::assertFalse($this->naLixeira($b));
        unlink($csv);
    }

    #[TestDox('--confirmar sem --saida é recusado e nada é gravado')]
    public function testConfirmarExigeSaida(): void
    {
        $pasta = $this->pasta($this->tenant);
        $this->doc($pasta, self::HASH_A);
        $b = $this->doc($pasta, self::HASH_A);

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId()]);

        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('--confirmar exige --saida', $t->getDisplay());
        self::assertFalse($this->naLixeira($b));
    }

    #[TestDox('--saida que já existe é recusada (não sobrescreve) e nada é gravado')]
    public function testSaidaExistenteNaoSobrescreve(): void
    {
        $pasta = $this->pasta($this->tenant);
        $this->doc($pasta, self::HASH_A);
        $b = $this->doc($pasta, self::HASH_A);
        $csv = $this->csvNovo();
        file_put_contents($csv, 'antigo');

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId(), '--saida' => $csv]);

        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('Não consegui criar o CSV', $t->getDisplay());
        self::assertSame('antigo', file_get_contents($csv));
        self::assertFalse($this->naLixeira($b));
        unlink($csv);
    }

    #[TestDox('--confirmar: fica a mais antiga, a cópia vai para a lixeira SEM autor, hash diferente não é tocado e o CSV tem a linha')]
    public function testConfirmarManda(): void
    {
        $pasta = $this->pasta($this->tenant);
        $a = $this->doc($pasta, self::HASH_A, 10);
        $b = $this->doc($pasta, self::HASH_A, 10);
        $c = $this->doc($pasta, self::HASH_A, 10);
        $docPdf = $this->doc($pasta, self::HASH_B, 10); // "DOC e PDF": hash diferente, não é cópia
        $csv = $this->csvNovo();

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId(), '--saida' => $csv]);

        self::assertStringContainsString('modo=gravacao grupos=1 copias_enviadas=2 bytes_a_liberar=20', $t->getDisplay());
        self::assertFalse($this->naLixeira($a), 'a mais antiga fica');
        self::assertTrue($this->naLixeira($b));
        self::assertTrue($this->naLixeira($c));
        self::assertFalse($this->naLixeira($docPdf));
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id IN (:b, :c) AND excluido_por_id IS NULL AND excluido_em IS NOT NULL', ['b' => $b->getId(), 'c' => $c->getId()]));

        $linhas = array_values(array_filter(explode("\n", (string) file_get_contents($csv))));
        unlink($csv);
        self::assertCount(2, $linhas, 'cabeçalho + 1 grupo');
        self::assertStringContainsString(',,' . $a->getId() . ',"' . $b->getId() . ' ' . $c->getId() . '",20,sem_vinculo_fica_a_mais_antiga,gravacao', $linhas[1]);
        self::assertStringNotContainsString('segredo-cliente', implode("\n", $linhas));
    }

    #[TestDox('mesma pasta, seções diferentes (e raiz x seção): nada sai; na mesma seção, sai')]
    public function testSecoesDiferentesNaoSaoDuplicado(): void
    {
        $pasta = $this->pasta($this->tenant);
        $s1 = $this->secao($pasta, 'S1');
        $s2 = $this->secao($pasta, 'S2');
        $naRaiz = $this->doc($pasta, self::HASH_A);
        $emS1   = $this->doc($pasta, self::HASH_A, 10, $s1);
        $emS2   = $this->doc($pasta, self::HASH_A, 10, $s2);
        $csv    = $this->csvNovo();

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId(), '--saida' => $csv]);

        self::assertStringContainsString('grupos=0', $t->getDisplay());
        foreach ([$naRaiz, $emS1, $emS2] as $d) {
            self::assertFalse($this->naLixeira($d));
        }

        $outraEmS1 = $this->doc($pasta, self::HASH_A, 10, $s1);
        $csv2      = $this->csvNovo();
        $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId(), '--saida' => $csv2]);
        self::assertTrue($this->naLixeira($outraEmS1));
        self::assertFalse($this->naLixeira($emS1));
        unlink($csv);
        unlink($csv2);
    }

    /** @return iterable<string, array{string}> */
    public static function vinculos(): iterable
    {
        yield 'assinatura eletrônica' => ['assinatura_eletronica_documento'];
        yield 'documento do cliente' => ['cliente_documento'];
        yield 'favorito' => ['pasta_documento_favorito'];
        yield 'uso em modelo de peça' => ['modelo_de_peca_uso'];
        yield 'anexo de mensagem de tarefa' => ['tarefa_mensagem'];
        yield 'selo assinado' => ['assinado_em'];
    }

    private function criarVinculo(string $tipo, PastaDocumento $doc): void
    {
        $id = (int) $doc->getId();
        $t  = (int) $this->tenant->getId();
        $ag = '2026-11-27 10:00:00';
        $this->semFks();

        match ($tipo) {
            'assinatura_eletronica_documento' => $this->conn->executeStatement(
                "INSERT INTO assinatura_eletronica_documento (nome, categoria, blocos, layout, folha_branca, logo_tipo, em_ordem, mensagem, situacao, criado_em, alterado_em, tenant_id, pasta_documento_id) VALUES ('x', 'x', '[]', 'x', false, 'x', true, '', 'x', :ag, :ag, :t, :id)",
                ['ag' => $ag, 't' => $t, 'id' => $id],
            ),
            'cliente_documento' => $this->conn->executeStatement(
                "INSERT INTO cliente_documento (titulo, categoria, caminho_arquivo, nome_original, mime_type, tamanho_bytes, uploaded_at, cliente_id, tenant_id, pasta_documento_id) VALUES ('x', 'x', 'x', 'x', 'x', 1, :ag, 999999, :t, :id)",
                ['ag' => $ag, 't' => $t, 'id' => $id],
            ),
            'pasta_documento_favorito' => $this->em->persist(PastaDocumentoFavorito::doDocumento($this->tenant, $this->usuario, $this->em->getReference(PastaDocumento::class, $id))),
            'modelo_de_peca_uso' => $this->conn->executeStatement(
                'INSERT INTO modelo_de_peca_uso (tenant_id, modelo_id, pasta_id, criado_em, documento_id) VALUES (:t, 999999, :p, :ag, :id)',
                ['ag' => $ag, 't' => $t, 'p' => (int) $doc->getPasta()->getId(), 'id' => $id],
            ),
            'tarefa_mensagem' => $this->conn->executeStatement(
                "INSERT INTO tarefa_mensagem (tarefa_id, usuario_id, mensagem, criado_em, tenant_id, arquivo_anexo) VALUES (999999, :u, 'x', :ag, :t, :anexo)",
                ['ag' => $ag, 't' => $t, 'u' => (int) $this->usuario->getId(), 'anexo' => 'pasta_documento:' . $id],
            ),
            'assinado_em' => $this->conn->executeStatement('UPDATE pasta_documento SET assinado_em = :ag WHERE id = :id', ['ag' => $ag, 'id' => $id]),
        };
        $this->em->flush();
    }

    #[DataProvider('vinculos')]
    #[TestDox('cópia com vínculo "$tipo" fica, mesmo sendo a mais nova: a antiga vai para a lixeira')]
    public function testCadaVinculoDecideQuemFica(string $tipo): void
    {
        $pasta = $this->pasta($this->tenant);
        $antiga = $this->doc($pasta, self::HASH_A);
        $nova   = $this->doc($pasta, self::HASH_A);
        $this->criarVinculo($tipo, $nova);
        $csv = $this->csvNovo();

        $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId(), '--saida' => $csv]);

        self::assertTrue($this->naLixeira($antiga), $tipo);
        self::assertFalse($this->naLixeira($nova), $tipo);
        self::assertStringContainsString('fica_a_copia_com_vinculo', (string) file_get_contents($csv));
        unlink($csv);
    }

    #[TestDox('duas cópias com vínculo: grupo pulado e relatado; nada vai para a lixeira')]
    public function testDoisVinculosPulam(): void
    {
        $pasta = $this->pasta($this->tenant);
        $a = $this->doc($pasta, self::HASH_A);
        $b = $this->doc($pasta, self::HASH_A);
        $c = $this->doc($pasta, self::HASH_A);
        $this->criarVinculo('assinado_em', $a);
        $this->criarVinculo('tarefa_mensagem', $c);
        $csv = $this->csvNovo();

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId(), '--saida' => $csv]);

        self::assertStringContainsString('copias_enviadas=0', $t->getDisplay());
        self::assertStringContainsString('pulado: pasta ' . $pasta->getId() . ', motivo pulado_mais_de_uma_copia_com_vinculo', $t->getDisplay());
        foreach ([$a, $b, $c] as $d) {
            self::assertFalse($this->naLixeira($d));
        }
        unlink($csv);
    }

    #[TestDox('vínculo que aparece DEPOIS da consulta e antes do COMMIT da pasta: o grupo é pulado')]
    public function testVinculoNovoNaTransacaoPulaOGrupo(): void
    {
        $pasta = $this->pasta($this->tenant);
        $a = $this->doc($pasta, self::HASH_A);
        $b = $this->doc($pasta, self::HASH_A);
        $conn = $this->conn;
        $consulta = new class ($conn, (int) $b->getId()) extends ConsultaDeDuplicadosDaPasta {
            private int $chamadas = 0;

            public function __construct(Connection $conexao, private readonly int $idQueGanhaVinculo)
            {
                parent::__construct($conexao);
            }

            public function vinculos(array $ids): array
            {
                // 1ª chamada (planejamento): nada. 2ª (dentro da transação): alguém vinculou a cópia nova.
                return ++$this->chamadas === 1 ? [] : [$this->idQueGanhaVinculo => ['favorito']];
            }
        };
        $useCase = new EnviarDuplicadosParaALixeiraUseCase($this->em, $consulta, static::getContainer()->get(PastaDocumentoRepository::class), new Clock());

        $r = $useCase->executar(true, (int) $this->tenant->getId());

        self::assertSame(0, $r->copiasEnviadas());
        self::assertSame(DecisaoSobreGrupoDeDuplicados::VINCULO_NOVO, $r->decisoes[0]->motivo);
        self::assertFalse($this->naLixeira($a));
        self::assertFalse($this->naLixeira($b));
    }

    #[TestDox('mesmo hash em pastas diferentes ou em escritório fora do filtro: nada é tocado')]
    public function testIsolamentoPorPastaEEscritorio(): void
    {
        $p1 = $this->pasta($this->tenant);
        $p2 = $this->pasta($this->tenant);
        $a  = $this->doc($p1, self::HASH_A);
        $b  = $this->doc($p2, self::HASH_A);
        $po = $this->pasta($this->outro);
        $c  = $this->doc($po, self::HASH_A);
        $d  = $this->doc($po, self::HASH_A);
        $csv = $this->csvNovo();

        $t = $this->rodar(['--confirmar' => true, '--tenant' => (string) $this->tenant->getId(), '--saida' => $csv]);

        self::assertStringContainsString('grupos=0', $t->getDisplay());
        foreach ([$a, $b, $c, $d] as $doc) {
            self::assertFalse($this->naLixeira($doc));
        }
        unlink($csv);
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
        $csv = $this->csvNovo();

        $t = $this->rodar(['--tenant' => (string) $this->tenant->getId(), '--limite' => '1', '--saida' => $csv]);

        self::assertStringContainsString('grupos=1 ', $t->getDisplay());
        self::assertStringContainsString('Próxima fatia: repita o comando com --a-partir-de=' . $p1->getId(), $t->getDisplay());
        $conteudo = (string) file_get_contents($csv);
        unlink($csv);
        self::assertStringContainsString('tenant_id,pasta_id,secao_id,documento_mantido', $conteudo);
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
