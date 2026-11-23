<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDeClientes;
use App\Backup\Coletor\ColetorDePastas;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Service\DadosDaPastaParaBackup;
use App\Backup\Service\ZipDoBackup;
use App\Backup\UseCase\MontarBackupUseCase;
use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * Isolamento do backup (spec backup, seção 8, item 2). Roda o UseCase SEM sessão e SEM `TenantContext`, como o
 * worker: escritório 1 com A (primário) e R (acesso restrito), escritório 2 com B. O ZIP é aberto de verdade e
 * lido entrada por entrada.
 */
#[CoversClass(MontarBackupUseCase::class)]
final class MontarBackupUseCaseTest extends KernelTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;
    use Factories;

    private const PERMISSOES = [
        'modules.pastas.view', 'modules.clientes.view', 'resources.pasta.view', 'resources.cliente.view',
    ];
    private const RAIZ = 'backup-bluejus-2026-11-23/';

    private ArmazenamentoEmMemoriaNoContainer $duble;
    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $r;
    private User $b;
    private Pasta $p1;
    private Pasta $p2;
    private Pasta $p3;
    private Pasta $p4;
    private Pasta $pb;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());

        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->r = $this->criarColaborador($this->t1, 'Rita Restrita', self::PERMISSOES);
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->r, 'tenant' => $this->t1]);
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');

        $this->semear();
    }

    private function semear(): void
    {
        $c1 = $this->cliente($this->t1, 'CLIENTE UM DA RITA', '52998224725');
        $c2 = $this->cliente($this->t1, 'CLIENTE DOIS DO PRIMARIO', '11144477735');
        $cb = $this->cliente($this->t2, 'CLIENTE DO ESCRITORIO B', '39053344705');

        $this->p1 = $this->pasta($this->t1, 'P1 DA RITA', $this->r, $c1);
        $this->p2 = $this->pasta($this->t1, 'P2 DO PRIMARIO', $this->a, $c2);
        $this->p3 = $this->pasta($this->t1, 'P3 COM CADEADO', $this->r, $c1);
        $this->p4 = $this->pasta($this->t1, 'P4 NA LIXEIRA', $this->r, $c1);
        $this->p4->marcarExcluida($this->a, new \DateTimeImmutable());
        $this->pb = $this->pasta($this->t2, 'PB DO ESCRITORIO B', $this->b, $cb);

        $this->trancar($this->p3, $this->a);
        $this->ocultarFinanceiroDeP1();
        $this->dadosDaP1();
        $this->documentos();
        $this->vinculadosEAbaDocumentos($c1);
        $this->documentoDeCliente($c1, 'rg-um.pdf', 'RG DO CLIENTE UM');
        $this->documentoDeCliente($c2, 'rg-dois.pdf', 'RG DO CLIENTE DOIS');
        $this->documentoDeCliente($cb, 'rg-b.pdf', 'RG DO CLIENTE B');
        $this->em()->flush();
    }

    /** Documentos de pasta vinculados ao cliente, a pasta com a aba Documentos oculta e as mensagens do sistema. */
    private function vinculadosEAbaDocumentos(Cliente $c1): void
    {
        $p5 = $this->pasta($this->t1, 'P5 SEM ABA DOCUMENTOS', $this->r, $c1);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->t1, $p5, AbaDaPasta::Documentos, $this->a, new \DateTimeImmutable()));

        $vivo      = $this->documento($this->p1, null, 'vinc-vivo.pdf', 'CONTEUDO VINCULADO VIVO');
        $cadeado   = $this->documento($this->p3, null, 'vinc-cadeado.pdf', 'CONTEUDO VINCULADO CADEADO');
        $lixeira   = $this->documento($this->p1, null, 'vinc-lixeira.pdf', 'CONTEUDO VINCULADO LIXEIRA');
        $pastaLixo = $this->documento($this->p4, null, 'vinc-pasta-lixeira.pdf', 'CONTEUDO VINCULADO PASTA LIXEIRA');
        $foraEscopo = $this->documento($this->p2, null, 'vinc-fora-escopo.pdf', 'CONTEUDO VINCULADO FORA ESCOPO');
        $semAba    = $this->documento($p5, null, 'vinc-sem-aba.pdf', 'CONTEUDO VINCULADO SEM ABA');
        $this->documento($p5, null, 'peca-p5.pdf', 'CONTEUDO PECA P5 SEM ABA');
        $lixeira->marcarExcluido($this->a, new \DateTimeImmutable());
        foreach ([$vivo, $cadeado, $lixeira, $pastaLixo, $foraEscopo, $semAba] as $origem) {
            $this->em()->persist(ClienteDocumento::vinculadoA($origem, $c1, $this->t1));
        }

        $this->em()->persist((new PastaMensagem())->setPasta($this->p1)->setTenant($this->t1)->setAutor($this->a)->setConteudo('MSG SISTEMA FINANCEIRO')->setSistema('probono'));
        $this->em()->persist((new PastaMensagem())->setPasta($this->p1)->setTenant($this->t1)->setAutor($this->a)->setConteudo('MSG SISTEMA RESPONSAVEL')->setSistema('resp'));
        $this->em()->flush();
    }

    private function cliente(Tenant $tenant, string $nome, string $cpf): Cliente
    {
        return ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => $nome, 'cpf' => $cpf])->_real();
    }

    private function pasta(Tenant $tenant, string $nome, User $responsavel, Cliente $cliente): Pasta
    {
        $pasta = $this->criarPasta($tenant, $nome);
        $pasta->setResponsavel($responsavel);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        return $pasta;
    }

    private function ocultarFinanceiroDeP1(): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->t1, $this->p1, AbaDaPasta::Financeiro, $this->a, new \DateTimeImmutable()));
    }

    private function dadosDaP1(): void
    {
        $em = $this->em();
        $em->persist(new PastaReu($this->t1, $this->p1, 'REU DA P1', '12345678901', 0));
        $prazo = new PastaPrazo($this->t1, $this->p1, $this->a);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, 'PRAZO DA P1', new \DateTimeImmutable('2026-12-01'), null, null);
        $em->persist($prazo);
        $em->persist((new PastaMensagem())->setPasta($this->p1)->setTenant($this->t1)->setAutor($this->a)->setConteudo('MENSAGEM DA P1'));
        $em->persist((new PastaObservacaoDetalhes())->setPasta($this->p1)->setTenant($this->t1)->setAutor($this->a)->setConteudo('DETALHE DA P1'));
        $em->persist((new PastaObservacaoFinanceira())->setPasta($this->p1)->setTenant($this->t1)->setAutor($this->a)->setConteudo('OBS FINANCEIRA DA P1'));
        $em->persist((new PastaPagamento())->setPasta($this->p1)->setTenant($this->t1)->setDescricao('PAGAMENTO DA P1')->setValor('150.00'));
        $em->flush();
    }

    private function documentos(): void
    {
        $secao = (new PastaSecao())->setPasta($this->p1)->setTenant($this->t1)->setNome('Peças');
        $lixo  = (new PastaSecao())->setPasta($this->p1)->setTenant($this->t1)->setNome('SecaoNaLixeira');
        $this->em()->persist($secao);
        $this->em()->persist($lixo);
        $this->em()->flush();

        $this->documento($this->p1, $secao, 'peca-p1.pdf', 'CONTEUDO PECA P1');
        $this->documento($this->p1, null, 'raiz-p1.pdf', 'CONTEUDO RAIZ P1');
        $this->documento($this->p1, $secao, 'contrato-p1.pdf', 'CONTEUDO CONTRATO P1', PastaDocumento::CATEGORIA_CONTRATO);
        $naLixeira = $this->documento($this->p1, $secao, 'lixeira-p1.pdf', 'CONTEUDO LIXEIRA P1');
        $naLixeira->marcarExcluido($this->a, new \DateTimeImmutable());
        $emSecaoLixo = $this->documento($this->p1, $lixo, 'dentro-da-secao-lixo.pdf', 'CONTEUDO SECAO LIXO');
        $lixo->marcarExcluido($this->a, new \DateTimeImmutable());
        unset($emSecaoLixo);
        $this->documento($this->p2, null, 'peca-p2.pdf', 'CONTEUDO PECA P2');
        $this->documento($this->p3, null, 'peca-p3.pdf', 'CONTEUDO PECA P3 COM CADEADO');
        $this->documento($this->p4, null, 'peca-p4.pdf', 'CONTEUDO PECA P4 LIXEIRA');
        $this->documento($this->pb, null, 'peca-b.pdf', 'CONTEUDO PECA DO ESCRITORIO B');
        $this->em()->flush();
    }

    private function documento(Pasta $pasta, ?PastaSecao $secao, string $nome, string $conteudo, string $categoria = PastaDocumento::CATEGORIA_PECA): PastaDocumento
    {
        $documento = (new PastaDocumento())
            ->setTitulo($nome)->setCategoria($categoria)->setCaminhoArquivo('doc-' . uniqid() . '.pdf')
            ->setNomeOriginal($nome)->setMimeType('application/pdf')->setTamanhoBytes(strlen($conteudo))
            ->setPasta($pasta)->setSecao($secao)->setTenant($pasta->getTenant());
        $this->em()->persist($documento);
        $this->duble->memoria->semear(ChavesDePasta::documento($documento), $conteudo);

        return $documento;
    }

    private function documentoDeCliente(Cliente $cliente, string $nome, string $conteudo): void
    {
        $documento = (new ClienteDocumento())
            ->setTitulo($nome)->setCategoria('identidade')->setCaminhoArquivo('cli-' . uniqid() . '.pdf')
            ->setNomeOriginal($nome)->setMimeType('application/pdf')->setTamanhoBytes(strlen($conteudo));
        $documento->setCliente($cliente);
        $documento->setTenant($cliente->getTenant());
        $this->em()->persist($documento);
        $this->duble->memoria->semear(ChavesDeCliente::documento($documento), $conteudo);
    }

    /**
     * Montado à mão com os serviços reais do container: enquanto nada o injeta (o handler é o PR 3), o container
     * de teste o remove por não usado. Os coletores seguem a ordem de prioridade da tag.
     */
    private function casoDeUso(int $lote = ColetorDePastas::PASTAS_POR_LOTE): MontarBackupUseCase
    {
        $c        = static::getContainer();
        $permissao = $c->get(PermissionChecker::class);
        $acesso   = $c->get(AcessoAPasta::class);
        $escopo   = $c->get(EscopoDePastas::class);

        return new MontarBackupUseCase(
            $this->duble->memoria,
            $this->duble->memoria,
            new MockClock('2026-11-23 10:00:00'),
            [
                new ColetorDePastas($permissao, $acesso, $escopo, $c->get(VisibilidadeDasAbasDaPasta::class), new DadosDaPastaParaBackup($this->em()), $this->em(), $lote),
                new ColetorDeClientes($permissao, $escopo, $acesso, $c->get(VisibilidadeDasAbasDaPasta::class), $this->em(), $lote),
            ],
            $this->em(),
        );
    }

    /**
     * Monta o backup SEM sessão e abre o ZIP gerado.
     *
     * @return array<string, string> caminho dentro da raiz => conteúdo
     */
    private function backupDe(User $usuario, Tenant $tenant, int $lote = ColetorDePastas::PASTAS_POR_LOTE): array
    {
        self::assertNull(static::getContainer()->get(TenantContext::class)->getCurrentTenant(), 'o worker não tem escritório na sessão');

        $pedido    = new PedidoDeBackup($tenant, $usuario, new \DateTimeImmutable('2026-11-23 10:00:00'));
        $resultado = $this->casoDeUso($lote)->executar($pedido);

        self::assertInstanceOf(ResultadoDoBackup::class, $resultado);
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant((int) $tenant->getId()), CategoriaDeArquivo::BACKUP_ZIP, $resultado->chaveArquivo);
        $bytes = $this->duble->memoria->ler($chave);
        self::assertSame(strlen($bytes), $resultado->tamanhoBytes);
        self::assertSame(hash('sha256', $bytes), $resultado->sha256);

        return $this->abrir($bytes);
    }

    /** @return array<string, string> */
    private function abrir(string $bytes): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'bkp');
        file_put_contents((string) $arquivo, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open((string) $arquivo));
        $entradas = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nome = (string) $zip->getNameIndex($i);
            self::assertStringStartsWith(self::RAIZ, $nome);
            $entradas[substr($nome, strlen(self::RAIZ))] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink((string) $arquivo);

        return $entradas;
    }

    /** @param array<string, string> $zip */
    private static function tudo(array $zip): string
    {
        return implode("\n", array_keys($zip)) . "\n" . implode("\n", $zip);
    }

    /** @param array<string, string> $zip */
    private function entradaCom(array $zip, string $trecho): ?string
    {
        foreach ($zip as $nome => $conteudo) {
            if (str_contains($nome, $trecho)) {
                return $conteudo;
            }
        }

        return null;
    }

    /** @param array<string, string> $zip */
    private function entradaDosDados(array $zip, string $marca): ?string
    {
        foreach ($zip as $nome => $conteudo) {
            if (str_ends_with($nome, '/dados.json') && str_contains($conteudo, $marca)) {
                return $conteudo;
            }
        }

        return null;
    }

    #[TestDox('A (primário do escritório 1) leva suas pastas, clientes e documentos, e nada do escritório 2')]
    public function testPrimarioLevaTudoDoSeuEscritorioENadaDoOutro(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);

        foreach (['peca-p1.pdf', 'raiz-p1.pdf', 'contrato-p1.pdf', 'peca-p2.pdf', 'peca-p3.pdf', 'rg-um.pdf', 'rg-dois.pdf'] as $esperado) {
            self::assertNotNull($this->entradaCom($zip, $esperado), $esperado);
        }
        self::assertSame('CONTEUDO PECA P1', $this->entradaCom($zip, 'PEÇAS/peca-p1.pdf'));
        self::assertStringContainsString('OBS FINANCEIRA DA P1', $tudo);
        self::assertStringContainsString('PAGAMENTO DA P1', $tudo);
        self::assertStringContainsString('REU DA P1', $tudo);
        self::assertStringContainsString('PRAZO DA P1', $tudo);
        self::assertStringContainsString('MENSAGEM DA P1', $tudo);
        self::assertStringContainsString('DETALHE DA P1', $tudo);

        foreach (['ESCRITORIO B', 'peca-b', 'rg-b', 'PB DO'] as $deB) {
            self::assertStringNotContainsString($deB, $tudo, $deB);
        }
        self::assertArrayHasKey('LEIA-ME.txt', $zip);
        self::assertArrayHasKey('manifesto.json', $zip);
    }

    #[TestDox('Lixeira fora: pasta, documento e seção excluídos não entram nem para o primário')]
    public function testLixeiraFicaFora(): void
    {
        $tudo = self::tudo($this->backupDe($this->a, $this->t1));

        foreach (['P4 NA LIXEIRA', 'peca-p4', 'lixeira-p1', 'CONTEUDO LIXEIRA', 'SecaoNaLixeira', 'dentro-da-secao-lixo', 'CONTEUDO SECAO LIXO'] as $lixo) {
            self::assertStringNotContainsString($lixo, $tudo, $lixo);
        }
    }

    #[TestDox('R (acesso restrito): só a pasta do escopo e o cliente dela; sem a alheia, sem a do cadeado, sem o Financeiro oculto')]
    public function testRestritoSoVeOEscopoEOQueAbreSemFinanceiroOculto(): void
    {
        $zip  = $this->backupDe($this->r, $this->t1);
        $tudo = self::tudo($zip);

        self::assertNotNull($this->entradaCom($zip, 'peca-p1.pdf'));
        self::assertNotNull($this->entradaCom($zip, 'raiz-p1.pdf'));
        self::assertNotNull($this->entradaCom($zip, 'rg-um.pdf'));
        self::assertStringContainsString('REU DA P1', $tudo);
        self::assertStringContainsString('DETALHE DA P1', $tudo);

        foreach (['P2 DO PRIMARIO', 'peca-p2', 'CLIENTE DOIS', 'rg-dois'] as $alheio) {
            self::assertStringNotContainsString($alheio, $tudo, 'fora do escopo: ' . $alheio);
        }
        foreach (['P3 COM CADEADO', 'peca-p3', 'CONTEUDO PECA P3'] as $cadeado) {
            self::assertStringNotContainsString($cadeado, $tudo, 'cadeado: ' . $cadeado);
        }
        foreach (['OBS FINANCEIRA', 'PAGAMENTO DA P1', 'contrato-p1', 'CONTEUDO CONTRATO'] as $financeiro) {
            self::assertStringNotContainsString($financeiro, $tudo, 'Financeiro oculto: ' . $financeiro);
        }
        $dadosDaP1 = (string) $this->entradaDosDados($zip, 'REU DA P1');
        self::assertStringNotContainsString('"financeiro"', $dadosDaP1);
        self::assertStringNotContainsString('valor_causa', $dadosDaP1);
        foreach (['ESCRITORIO B', 'peca-b', 'rg-b'] as $deB) {
            self::assertStringNotContainsString($deB, $tudo, $deB);
        }
    }

    #[TestDox('R: documento vinculado vivo entra; de pasta trancada, fora do escopo, na lixeira ou com a aba Documentos oculta não entra nem quebra')]
    public function testVinculadosDoRestrito(): void
    {
        $zip  = $this->backupDe($this->r, $this->t1);
        $tudo = self::tudo($zip);

        self::assertSame('CONTEUDO VINCULADO VIVO', $this->entradaCom($zip, 'clientes/CLIENTE UM DA RITA/documentos/vinc-vivo.pdf'));
        foreach (['vinc-cadeado', 'vinc-lixeira', 'vinc-pasta-lixeira', 'vinc-fora-escopo', 'vinc-sem-aba', 'peca-p5', 'CONTEUDO PECA P5'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
        self::assertStringNotContainsString(ZipDoBackup::MOTIVO_CAMINHO_INVALIDO, $zip['LEIA-ME.txt'], 'vinculado não vira "caminho inválido"');
        self::assertStringContainsString('P5 SEM ABA DOCUMENTOS', $tudo, 'a pasta entra, só os documentos ficam de fora');
    }

    #[TestDox('Mensagem do sistema de aba oculta fica fora; a das outras abas fica')]
    public function testMensagemDoSistemaSegueAAba(): void
    {
        $tudo = self::tudo($this->backupDe($this->r, $this->t1));

        self::assertStringNotContainsString('MSG SISTEMA FINANCEIRO', $tudo);
        self::assertStringContainsString('MSG SISTEMA RESPONSAVEL', $tudo);
    }

    #[TestDox('Lote de 1 (clear a cada pasta e cliente): o ZIP sai igual para A, R e B, sem entidade destacada')]
    public function testLoteDeUmGeraOMesmoZip(): void
    {
        foreach ([[$this->a, $this->t1], [$this->r, $this->t1], [$this->b, $this->t2]] as [$usuario, $tenant]) {
            $normal = $this->backupDe($usuario, $tenant);
            $lote   = $this->backupDe($usuario, $tenant, 1);

            self::assertSame(array_keys($normal), array_keys($lote));
            foreach ($normal as $nome => $conteudo) {
                if ($nome !== 'LEIA-ME.txt' && $nome !== 'manifesto.json') {
                    self::assertSame($conteudo, $lote[$nome], $nome);
                }
            }
        }
    }

    #[TestDox('B (escritório 2) leva só o que é do escritório 2')]
    public function testOutroEscritorioLevaSoOSeu(): void
    {
        $zip  = $this->backupDe($this->b, $this->t2);
        $tudo = self::tudo($zip);

        self::assertSame('CONTEUDO PECA DO ESCRITORIO B', $this->entradaCom($zip, 'peca-b.pdf'));
        self::assertNotNull($this->entradaCom($zip, 'rg-b.pdf'));
        foreach (['P1 DA RITA', 'P2 DO PRIMARIO', 'P3 COM', 'peca-p', 'CLIENTE UM', 'CLIENTE DOIS', 'rg-um', 'REU DA P1'] as $do1) {
            self::assertStringNotContainsString($do1, $tudo, $do1);
        }
    }

    #[TestDox('Sem o módulo, o coletor não roda: o ZIP traz só LEIA-ME e manifesto')]
    public function testSemModuloOColetorNaoRoda(): void
    {
        $semModulo = $this->criarColaborador($this->t1, 'Sem Modulo', ['resources.pasta.view', 'resources.cliente.view']);
        $soClientes = $this->criarColaborador($this->t1, 'So Clientes', ['modules.clientes.view', 'resources.cliente.view']);

        $zip = $this->backupDe($semModulo, $this->t1);
        self::assertSame(['LEIA-ME.txt', 'manifesto.json'], array_keys($zip));

        $zip = $this->backupDe($soClientes, $this->t1);
        self::assertNull($this->entradaCom($zip, 'pastas/'));
        self::assertNotNull($this->entradaCom($zip, 'rg-um.pdf'));
    }

    #[TestDox('Falha ao gravar o ZIP no armazenamento: a exceção sobe e o temporário some')]
    public function testFalhaNaGravacaoLimpaOTemporario(): void
    {
        $this->duble->memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');
        $pedido = new PedidoDeBackup($this->t1, $this->a, new \DateTimeImmutable('2026-11-23 10:00:00'));

        try {
            $this->casoDeUso()->executar($pedido);
            self::fail('A falha de gravação devia subir.');
        } catch (FalhaDeArmazenamento) {
        }

        $raiz = DiretorioTemporarioPrivado::doProcesso('backup')->caminho();
        self::assertSame([], array_values(array_diff(scandir($raiz) ?: [], ['.', '..'])), 'a área temporária deve sumir');
        self::assertSame([], $this->duble->memoria->gravadas);
    }

    #[TestDox('O LEIA-ME e o manifesto descrevem o pedido e conferem com o conteúdo')]
    public function testLeiaMeEManifesto(): void
    {
        $zip       = $this->backupDe($this->a, $this->t1);
        $manifesto = json_decode($zip['manifesto.json'], true, flags: JSON_THROW_ON_ERROR);

        self::assertStringContainsString('Paula Primária', $zip['LEIA-ME.txt']);
        self::assertStringContainsString('Pastas: 4', $zip['LEIA-ME.txt']);
        self::assertStringContainsString('Clientes: 2', $zip['LEIA-ME.txt']);
        foreach ($manifesto['arquivos'] as $linha) {
            self::assertSame(hash('sha256', $zip[$linha['caminho']]), $linha['sha256']);
            self::assertSame(strlen($zip[$linha['caminho']]), $linha['tamanho_bytes']);
        }
    }
}
