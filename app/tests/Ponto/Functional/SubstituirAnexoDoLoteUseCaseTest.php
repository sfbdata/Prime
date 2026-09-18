<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\SubstituirAnexoDoLoteUseCase;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Shared\Doubles\ArmazenamentoEspiao;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\FalhaDeCommitArmavel;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Bloco A da E1 — risco ALTO (ponto eletrônico).
 *
 * O lote de abono nasce como unidade: um upload, N registros (um por dia), mesmo `batchId`. Em
 * produção há um lote de 27 dias sobre um único atestado. A edição antiga trocava o anexo de UM
 * registro, deixando o resto do lote apontando para o arquivo velho.
 */
#[CoversClass(SubstituirAnexoDoLoteUseCase::class)]
final class SubstituirAnexoDoLoteUseCaseTest extends KernelTestCase
{
    /** @var list<string> arquivos que os testes da E2.5 criaram e apagam no fim */
    private array $criados = [];

    /** @var list<string>|null o diretório antes da troca: o que surgir depois também sai no fim */
    private ?array $antesDaTroca = null;

    protected function tearDown(): void
    {
        FalhaDeCommitArmavel::desarmar();

        // Recolhido aqui, e não depois das asserções: se uma delas falhar, nada vaza.
        if ($this->antesDaTroca !== null) {
            $this->criados = array_merge($this->criados, $this->novosDesde($this->antesDaTroca));
        }

        foreach ($this->criados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        parent::tearDown();
    }

    /**
     * E2.5: o COMMIT chega ao banco e a resposta se perde. O lote inteiro passa a apontar para o
     * arquivo novo — e sem prova do destino (sob o DAMA a consulta real diz "em andamento") ele
     * tem de FICAR. Antes da E2.5 ele era apagado aqui, e o lote ficava apontando para o vazio.
     */
    #[TestDox('COMMIT com resposta perdida: o lote aponta para o novo, e NENHUM arquivo sai do disco')]
    public function testCommitComRespostaPerdidaNaoApagaNada(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        $antigo          = $this->gravarAnexo('antigo');
        $this->criados[] = $this->caminho($antigo);
        $lote            = $this->criarLote($tenant, $user, $antigo, 3);
        $this->antesDaTroca = $this->arquivosNoDiretorio();
        FalhaDeCommitArmavel::perderRespostaDoProximoCommit();

        $falhou = false;
        try {
            $this->useCase()->executar($lote[0], $this->upload(), $tenant);
        } catch (\Doctrine\DBAL\Exception) {
            $falhou = true;
        }

        self::assertTrue($falhou, 'a falha do COMMIT sobe para quem chamou');
        self::assertSame(1, FalhaDeCommitArmavel::$disparos);

        $anexos = $this->anexosNoBanco($lote);
        self::assertCount(1, array_unique($anexos));
        self::assertNotSame($antigo, $anexos[0], 'o banco confirmou a troca');
        self::assertFileExists($this->caminho($anexos[0]), 'o lote aponta para ele: não pode ter saído (INV-6)');
        self::assertFileExists($this->caminho($antigo), 'a fase 2 não roda quando a fase 1 falha');
    }

    #[TestDox('COMMIT recusado e o banco PROVA aborted: o lote fica no antigo e só o arquivo novo sai')]
    public function testCommitRecusadoComProvaRemoveSoONovo(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        $antigo          = $this->gravarAnexo('antigo');
        $this->criados[] = $this->caminho($antigo);
        $lote            = $this->criarLote($tenant, $user, $antigo, 2);
        $this->antesDaTroca = $this->arquivosNoDiretorio();
        $this->bancoResponde(DestinoDaTransacao::NaoConfirmada);
        FalhaDeCommitArmavel::recusarProximoCommit();

        try {
            $this->useCase()->executar($lote[0], $this->upload(), $tenant);
            self::fail('a falha do COMMIT devia subir');
        } catch (\Doctrine\DBAL\Exception) {
        }

        self::assertSame([$antigo, $antigo], $this->anexosNoBanco($lote));
        self::assertSame([], $this->novosDesde($this->antesDaTroca), 'o arquivo novo sai quando o banco prova que nada foi confirmado');
        self::assertFileExists($this->caminho($antigo));
    }

    #[TestDox('Disco falha ao apagar o antigo na fase 2: a troca vale, e o antigo fica registrado como órfão')]
    public function testFalhaDeDiscoNaFase2NaoDesfazATroca(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        $antigo          = $this->gravarAnexo('antigo');
        $this->criados[] = $this->caminho($antigo);
        $lote            = $this->criarLote($tenant, $user, $antigo, 2);
        $this->antesDaTroca = $this->arquivosNoDiretorio();

        $container = static::getContainer();
        $espiao    = new ArmazenamentoEspiao($container->get(ArmazenamentoLocal::class));
        $espiao->falhaAoExcluir = static fn (ChaveDeArquivo $c): ?\Throwable => $c->nome === $antigo
            ? new FalhaDeArmazenamento('disco ilegível')
            : null;
        $logger = new LoggerEmMemoria();
        $this->montarUseCase($espiao, $logger, $container->get(\App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao::class));

        $atingidos = $this->useCase()->executar($lote[0], $this->upload(), $tenant);

        self::assertSame(2, $atingidos);
        $anexos = $this->anexosNoBanco($lote);
        self::assertNotSame($antigo, $anexos[0]);
        self::assertFileExists($this->caminho($antigo), 'a falha de disco deixou o antigo — órfão recuperável');
        self::assertSame($antigo, basename((string) $logger->doNivel('error')[0]['contexto']['chave']));
    }

    #[TestDox('Trocar o anexo de um lote de 3 dias atinge os TRÊS registros')]
    public function testTrocaAtingeOLoteInteiro(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();

        $anexoAntigo = $this->gravarAnexo('antigo');
        $lote        = $this->criarLote($tenant, $user, $anexoAntigo, 3);

        $atingidos = $this->useCase()->executar($lote[0], $this->upload(), $tenant);

        self::assertSame(3, $atingidos);

        $this->em()->clear();
        $novos = [];
        foreach ($lote as $registro) {
            $recarregado = $this->em()->find(JustificativaPonto::class, $registro->getId());
            $novos[]     = $recarregado->getAnexoPath();
        }

        self::assertCount(1, array_unique($novos), 'o lote inteiro deve apontar para UM anexo só');
        self::assertNotSame($anexoAntigo, $novos[0], 'o anexo deveria ter mudado');
    }

    #[TestDox('O arquivo antigo é removido do disco quando ninguém mais o referencia')]
    public function testArquivoAntigoSomeQuandoOrfao(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();

        $anexoAntigo = $this->gravarAnexo('vai sumir');
        $lote        = $this->criarLote($tenant, $user, $anexoAntigo, 2);
        self::assertFileExists($this->caminho($anexoAntigo));

        $this->useCase()->executar($lote[0], $this->upload(), $tenant);

        self::assertFileDoesNotExist(
            $this->caminho($anexoAntigo),
            'sem nenhuma referência restante, o arquivo antigo deveria ter sido apagado',
        );
    }

    #[TestDox('O arquivo antigo PERMANECE quando outro registro ainda o referencia')]
    public function testArquivoAntigoPermaneceSeAindaReferenciado(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();

        $anexoCompartilhado = $this->gravarAnexo('compartilhado');
        $lote               = $this->criarLote($tenant, $user, $anexoCompartilhado, 2);

        // Um registro FORA do lote apontando para o mesmo arquivo. É a situação que a contagem de
        // referências existe para proteger: apagar aqui destruiria o anexo de outro registro.
        $forasteiro = $this->criarLote($tenant, $user, $anexoCompartilhado, 1, 'outro-batch')[0];

        $this->useCase()->executar($lote[0], $this->upload(), $tenant);

        self::assertFileExists(
            $this->caminho($anexoCompartilhado),
            'o arquivo ainda é referenciado por outro registro — não podia ser apagado',
        );

        $this->em()->clear();
        $recarregado = $this->em()->find(JustificativaPonto::class, $forasteiro->getId());
        self::assertSame($anexoCompartilhado, $recarregado->getAnexoPath());
    }

    #[TestDox('Justificativa sem batchId troca apenas o próprio registro')]
    public function testSemBatchIdAtingeSomenteOProprioRegistro(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();

        $anexo  = $this->gravarAnexo('avulso');
        $avulsa = $this->criarLote($tenant, $user, $anexo, 1, null)[0];
        $outra  = $this->criarLote($tenant, $user, $this->gravarAnexo('intocada'), 1, null)[0];
        $anexoDaOutra = $outra->getAnexoPath();

        self::assertSame(1, $this->useCase()->executar($avulsa, $this->upload(), $tenant));

        $this->em()->clear();
        $recarregada = $this->em()->find(JustificativaPonto::class, $avulsa->getId());
        self::assertNotSame($anexo, $recarregada->getAnexoPath(), 'o registro deveria ter mudado');

        $intocada = $this->em()->find(JustificativaPonto::class, $outra->getId());
        self::assertSame($anexoDaOutra, $intocada->getAnexoPath(), 'nenhum outro registro podia ser tocado');
    }

    #[TestDox('Arquivo acima de 10 MB é recusado — a edição passou a validar como a criação')]
    public function testArquivoGrandeDemaisERecusado(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();

        $anexoAntigo = $this->gravarAnexo('x');
        $lote        = $this->criarLote($tenant, $user, $anexoAntigo, 1);
        $antesDoTeste = $this->quantosArquivos();

        try {
            $this->useCase()->executar($lote[0], $this->uploadGrande(), $tenant);
            self::fail('arquivo acima de 10 MB deveria ter sido recusado');
        } catch (\InvalidArgumentException) {
            // esperado
        }

        self::assertFileExists($this->caminho($anexoAntigo), 'o anexo antigo tinha de sobreviver');
        self::assertSame($antesDoTeste, $this->quantosArquivos(), 'nada podia ter sido gravado em disco');
    }

    #[TestDox('MIME fora de PDF/JPEG/PNG é recusado')]
    public function testMimeNaoPermitidoERecusado(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();

        $anexoAntigo  = $this->gravarAnexo('x');
        $lote         = $this->criarLote($tenant, $user, $anexoAntigo, 1);
        $antesDoTeste = $this->quantosArquivos();

        try {
            $this->useCase()->executar($lote[0], $this->uploadTexto(), $tenant);
            self::fail('MIME fora da lista deveria ter sido recusado');
        } catch (\InvalidArgumentException) {
            // esperado
        }

        self::assertFileExists($this->caminho($anexoAntigo), 'o anexo antigo tinha de sobreviver');
        self::assertSame($antesDoTeste, $this->quantosArquivos(), 'nada podia ter sido gravado em disco');
    }

    #[TestDox('Invariante: depois da troca, nenhum batchId tem dois anexos distintos')]
    public function testInvarianteUmBatchUmAnexo(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();

        $lote = $this->criarLote($tenant, $user, $this->gravarAnexo('antes'), 4);
        $this->useCase()->executar($lote[2], $this->upload(), $tenant);

        $this->em()->clear();
        $distintos = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (
                SELECT batch_id FROM justificativa_ponto
                WHERE tenant_id = ? AND anexo_path IS NOT NULL AND batch_id IS NOT NULL
                GROUP BY batch_id HAVING COUNT(DISTINCT anexo_path) > 1
             ) x',
            [$tenant->getId()],
        );

        self::assertSame(0, (int) $distintos, 'um batchId não pode terminar com dois anexos');
    }

    /**
     * O que este teste prova é ESTREITO, e vale dizer com todas as letras: a contagem é escopada
     * por escritório. Num diretório plano, esse escopo só é seguro porque o nome do arquivo tem
     * 128 bits aleatórios — dois escritórios não têm como referenciar o mesmo arquivo físico. O
     * cenário abaixo é artificial (força o mesmo nome nos dois) e existe para fixar o
     * comportamento da query, NÃO para sugerir que compartilhar arquivo entre tenants é suportado.
     */
    #[TestDox('contarReferenciasAoAnexo conta apenas o próprio escritório')]
    public function testContagemEEscopadaPorTenant(): void
    {
        self::bootKernel();
        [$tenantA, $userA] = $this->cenario();
        [$tenantB, $userB] = $this->cenario();

        $mesmoNome = $this->gravarAnexo('colisao');
        $this->criarLote($tenantA, $userA, $mesmoNome, 2);
        $this->criarLote($tenantB, $userB, $mesmoNome, 3);

        $repo = static::getContainer()->get(JustificativaPontoRepository::class);

        self::assertSame(2, $repo->contarReferenciasAoAnexo($mesmoNome, $tenantA));
        self::assertSame(3, $repo->contarReferenciasAoAnexo($mesmoNome, $tenantB));
    }

    #[TestDox('Justificativa de OUTRO escritório é recusada antes de tocar em qualquer coisa')]
    public function testRecusaJustificativaDeOutroTenant(): void
    {
        self::bootKernel();
        [$tenantA, $userA] = $this->cenario();
        [$tenantB]         = $this->cenario();

        $anexo = $this->gravarAnexo('do tenant A');
        $lote  = $this->criarLote($tenantA, $userA, $anexo, 3);

        try {
            $this->useCase()->executar($lote[0], $this->upload(), $tenantB);
            self::fail('substituir com o tenant errado deveria ser recusado');
        } catch (\LogicException) {
            // esperado
        }

        // O cenário destrutivo que a pré-condição impede: sem ela, o lote seria buscado no tenant
        // B, voltaria vazio, só 1 registro mudaria e a contagem daria zero — apagando o arquivo
        // que os outros 2 ainda referenciam.
        self::assertFileExists($this->caminho($anexo), 'o arquivo do outro escritório não podia ser apagado');

        $this->em()->clear();
        foreach ($lote as $registro) {
            $recarregado = $this->em()->find(JustificativaPonto::class, $registro->getId());
            self::assertSame($anexo, $recarregado->getAnexoPath(), 'nenhum registro podia ter mudado');
        }
    }

    // ------------------------------------------------------------------ helpers

    /** O banco "responde" o destino escolhido — a transação é trocada inteira no container. */
    private function bancoResponde(DestinoDaTransacao $destino): void
    {
        $container = static::getContainer();
        $this->montarUseCase(
            $container->get(ArmazenamentoLocal::class),
            new LoggerEmMemoria(),
            new ConsultaDeDestinoFixa($destino),
        );
    }

    private function montarUseCase(
        \App\Shared\Armazenamento\ArmazenamentoDeArquivos $armazenamento,
        LoggerEmMemoria $logger,
        \App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao $consulta,
    ): void {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $remocao   = new RemocaoAposTransacao($armazenamento, $logger);

        $container->set(SubstituirAnexoDoLoteUseCase::class, new SubstituirAnexoDoLoteUseCase(
            $em,
            $container->get(JustificativaPontoRepository::class),
            $armazenamento,
            new TransacaoComArquivoNovo($em, $consulta, $remocao, $logger),
            $remocao,
            $container->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class),
            $logger,
        ));
    }

    /**
     * Pelo banco, e não pela entidade: depois de uma falha o EntityManager está fechado.
     *
     * @param JustificativaPonto[] $lote
     *
     * @return list<string|null>
     */
    private function anexosNoBanco(array $lote): array
    {
        return array_map(
            fn (JustificativaPonto $j): ?string => $this->em()->getConnection()->fetchOne(
                'SELECT anexo_path FROM justificativa_ponto WHERE id = ?',
                [$j->getId()],
            ) ?: null,
            $lote,
        );
    }

    /** @return list<string> */
    private function arquivosNoDiretorio(): array
    {
        return array_values(array_diff(scandir($this->diretorio()) ?: [], ['.', '..']));
    }

    /**
     * @param list<string> $antes
     *
     * @return list<string> caminhos completos
     */
    private function novosDesde(array $antes): array
    {
        return array_values(array_map(
            fn (string $nome): string => $this->caminho($nome),
            array_diff($this->arquivosNoDiretorio(), $antes),
        ));
    }

    private function useCase(): SubstituirAnexoDoLoteUseCase
    {
        return static::getContainer()->get(SubstituirAnexoDoLoteUseCase::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * O anexo de justificativa mora num diretório PLANO (o escopo não aparece no caminho), então
     * para semear basta um escritório qualquer — o que importa é o nome cunhado.
     */
    private function tenantQualquer(): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 1);

        return $tenant;
    }

    private function diretorio(): string
    {
        return (string) static::getContainer()->getParameter('justificativas_uploads_dir');
    }

    private function caminho(string $nome): string
    {
        return $this->diretorio() . '/' . $nome;
    }

    private function quantosArquivos(): int
    {
        return \count(glob($this->diretorio() . '/*') ?: []);
    }

    private function gravarAnexo(string $conteudo, ?Tenant $tenant = null): string
    {
        // E2.6C: por chave — o shim saiu do container com o último consumidor de produção.
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $tenant        = $tenant ?? $this->tenantQualquer();

        return $armazenamento->gravar(
            ChavesDePonto::novoAnexoDeLote($tenant, 'pdf'),
            FonteDeConteudo::deTexto('%PDF-1.4 ' . $conteudo),
        )->chave->nome;
    }

    private function upload(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/anexo-novo-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($origem, "%PDF-1.4\n% conteudo novo\n");

        return new UploadedFile($origem, 'novo.pdf', 'application/pdf', null, true);
    }

    private function uploadGrande(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/anexo-grande-' . bin2hex(random_bytes(6)) . '.pdf';
        $fp     = fopen($origem, 'wb');
        fwrite($fp, "%PDF-1.4\n");
        fseek($fp, 11 * 1024 * 1024);
        fwrite($fp, '.');
        fclose($fp);

        return new UploadedFile($origem, 'grande.pdf', 'application/pdf', null, true);
    }

    private function uploadTexto(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/anexo-texto-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($origem, 'isto nao e pdf nem imagem');

        return new UploadedFile($origem, 'nota.txt', 'text/plain', null, true);
    }

    /** @return JustificativaPonto[] */
    private function criarLote(
        Tenant $tenant,
        User $user,
        string $anexo,
        int $dias,
        ?string $batchId = 'auto',
    ): array {
        $em    = $this->em();
        $batch = $batchId === 'auto' ? bin2hex(random_bytes(12)) : $batchId;
        $lote  = [];

        for ($i = 0; $i < $dias; $i++) {
            $j = new JustificativaPonto();
            $j->setUser($user);
            $j->setTenant($tenant);
            $j->setData(new \DateTime('2026-03-' . str_pad((string) ($i + 1), 2, '0', \STR_PAD_LEFT)));
            $j->setAnexoPath($anexo);
            $j->setStatus('pendente');
            $j->setBatchId($batch);
            $em->persist($j);
            $lote[] = $j;
        }

        $em->flush();

        return $lote;
    }

    /** @return array{0: Tenant, 1: User} */
    private function cenario(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant PONTO ANEXO ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('ponto_anexo_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Ponto');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$tenant, $user];
    }
}
