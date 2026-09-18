<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\TrocaDeAnexoRecusadaException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\SubstituirAnexoDoLoteUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Shared\Doubles\ArmazenamentoEspiao;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Risco ALTO (ponto eletrônico) — docs/specs/ponto-troca-de-atestado-analisado.md.
 *
 * Trocar o atestado de um lote já analisado apagava o arquivo que o gestor viu, e o lote seguia
 * `abonado` apontando para um arquivo que ninguém analisou. A troca agora só acontece com o lote
 * TODO pendente; qualquer dia analisado recusa antes de gravar qualquer coisa.
 *
 * Tudo aqui roda contra o banco e o disco de teste reais: "banco e arquivo intactos" é conferido
 * pela conexão (o EntityManager fecha na recusa) e pelo diretório, não pela entidade em memória.
 */
#[CoversClass(SubstituirAnexoDoLoteUseCase::class)]
#[CoversClass(JustificativaPontoRepository::class)]
final class TrocaDeAnexoDeLoteAnalisadoTest extends KernelTestCase
{
    /** @var list<string>|null o diretório no início do teste: tudo o que surgir depois sai no fim */
    private ?array $linhaDeBase = null;

    protected function tearDown(): void
    {
        // Recolhido aqui, e não depois das asserções: se uma delas falhar, nada vaza.
        if ($this->linhaDeBase !== null) {
            foreach (array_diff($this->arquivosNoDiretorio(), $this->linhaDeBase) as $nome) {
                @unlink($this->caminho($nome));
            }
        }

        parent::tearDown();
    }

    #[TestDox('Lote de 3 dias TODO pendente: troca nos três, o antigo some, o status não muda')]
    public function testLotePendenteTrocaERemoveOAntigo(): void
    {
        $this->iniciar();
        [$tenant, $user] = $this->cenario();
        $antigo          = $this->semear('pendente');
        $lote            = $this->criarLote($tenant, $user, $antigo, ['pendente', 'pendente', 'pendente']);

        $atingidos = $this->useCase()->executar($lote[0], $this->upload(), $tenant);

        self::assertSame(3, $atingidos);
        $anexos = $this->anexosNoBanco($lote);
        self::assertCount(1, array_unique($anexos), 'o lote inteiro aponta para UM anexo');
        self::assertNotSame($antigo, $anexos[0]);
        self::assertFileExists($this->caminho((string) $anexos[0]), 'nenhuma resposta de sucesso aponta para o vazio');
        self::assertFileDoesNotExist($this->caminho($antigo), 'sem referência restante, o antigo sai depois do COMMIT');
        self::assertSame(['pendente', 'pendente', 'pendente'], $this->statusNoBanco($lote));
    }

    /** @return iterable<string, array{list<string>, int}> status de cada dia e o dia pelo qual se edita */
    public static function lotesAnalisados(): iterable
    {
        yield 'lote todo abonado'                          => [['abonado', 'abonado', 'abonado'], 0];
        yield 'lote todo rejeitado'                        => [['rejeitado', 'rejeitado'], 0];
        // Lote misto existe em produção (a análise é por dia). Editar pelo dia pendente não pode
        // trocar o atestado dos dias já analisados.
        yield 'misto: editado pelo dia pendente'           => [['pendente', 'abonado', 'abonado'], 0];
        yield 'misto: o analisado é o último dia'          => [['pendente', 'pendente', 'rejeitado'], 1];
        yield 'misto 26+1 de produção, em miniatura'       => [['abonado', 'abonado', 'rejeitado'], 2];
    }

    /**
     * @param list<string> $status
     */
    #[TestDox('Troca recusada em $_dataName: banco, arquivo antigo e diretório ficam intactos')]
    #[DataProvider('lotesAnalisados')]
    public function testLoteAnalisadoRecusaSemTocarBancoNemDisco(array $status, int $editadoPeloDia): void
    {
        $this->iniciar();
        [$tenant, $user] = $this->cenario();
        $antigo          = $this->semear('analisado');
        $lote            = $this->criarLote($tenant, $user, $antigo, $status);
        $antes           = $this->arquivosNoDiretorio();

        [$useCase, $espiao] = $this->useCaseEspiado();

        $capturada = null;
        try {
            $useCase->executar($lote[$editadoPeloDia], $this->upload(), $tenant);
        } catch (TrocaDeAnexoRecusadaException $e) {
            $capturada = $e;
        }

        self::assertNotNull($capturada, 'a troca num lote com dia analisado tem de ser recusada');
        // R3. O diretório sozinho não prova isto: sob o DAMA a transação da troca é a de fora
        // (nível 0 para o DBAL), e um arquivo gravado antes da recusa seria apagado na hora.
        self::assertSame([], $espiao->gravadas, 'a recusa vem ANTES de gravar qualquer arquivo');
        self::assertSame([], $espiao->excluidas, 'e nada é apagado');
        self::assertStringContainsString('não pode ser trocado', $capturada->getMessage());
        self::assertSame(array_fill(0, \count($lote), $antigo), $this->anexosNoBanco($lote), 'nenhum dia pode mudar de anexo');
        self::assertSame($status, $this->statusNoBanco($lote), 'a recusa não mexe em status');
        self::assertFileExists($this->caminho($antigo), 'o atestado analisado NUNCA pode sair do disco');
        self::assertSame($antes, $this->arquivosNoDiretorio(), 'o diretório termina como começou');
    }

    #[TestDox('Justificativa avulsa (sem lote) abonada também é recusada')]
    public function testAvulsaAbonadaEhRecusada(): void
    {
        $this->iniciar();
        [$tenant, $user] = $this->cenario();
        $antigo          = $this->semear('avulsa');
        $lote            = $this->criarLote($tenant, $user, $antigo, ['abonado'], batchId: null);

        $this->expectException(TrocaDeAnexoRecusadaException::class);

        try {
            $this->useCase()->executar($lote[0], $this->upload(), $tenant);
        } finally {
            self::assertSame([$antigo], $this->anexosNoBanco($lote));
            self::assertFileExists($this->caminho($antigo));
        }
    }

    /**
     * A corrida que importa: a entidade chega carregada como `pendente` (EntityValueResolver, antes
     * da trava) e o admin aprova um dia do lote antes de a troca rodar. O identity map não relê
     * campos — decidir pelo getter trocaria o atestado de um dia já abonado.
     */
    #[TestDox('Admin aprova depois de a entidade ser carregada: a decisão usa o banco, não o getter')]
    public function testAprovacaoDepoisDoCarregamentoEhVistaPelaDecisao(): void
    {
        $this->iniciar();
        [$tenant, $user] = $this->cenario();
        $antigo          = $this->semear('corrida');
        $lote            = $this->criarLote($tenant, $user, $antigo, ['pendente', 'pendente']);

        // Por fora do UnitOfWork, como faria outra transação: em memória, os dois seguem pendentes.
        $this->em()->getConnection()->executeStatement(
            "UPDATE justificativa_ponto SET status = 'abonado' WHERE id = ?",
            [$lote[1]->getId()],
        );
        self::assertSame('pendente', $lote[1]->getStatus(), 'premissa: a entidade em memória está velha');

        $this->expectException(TrocaDeAnexoRecusadaException::class);

        try {
            $this->useCase()->executar($lote[0], $this->upload(), $tenant);
        } finally {
            self::assertSame([$antigo, $antigo], $this->anexosNoBanco($lote));
            self::assertFileExists($this->caminho($antigo));
        }
    }

    /**
     * A análise do admin não pega a trava advisory do lote. O que a serializa com a troca é a trava
     * de LINHA da leitura do status: sem ela, uma aprovação que comitasse entre a leitura e o UPDATE
     * do anexo reabriria o defeito. Sob o DAMA não há segunda conexão que enxergue as linhas da
     * fixture, então a prova é a consulta que o banco recebeu.
     */
    #[TestDox('A leitura do status do lote trava as linhas (SELECT … FOR UPDATE)')]
    public function testLeituraDoStatusTravaAsLinhas(): void
    {
        $this->iniciar();
        [$tenant, $user] = $this->cenario();
        $lote            = $this->criarLote($tenant, $user, $this->semear('trava'), ['pendente', 'pendente']);

        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();

        $this->useCase()->executar($lote[0], $this->upload(), $tenant);

        $leituras = array_values(array_filter(
            array_column($coletor->getData()['default'] ?? [], 'sql'),
            static fn (string $sql): bool => (bool) preg_match('/^SELECT\s+\S+\.status\b.*\bFROM\s+justificativa_ponto\b/is', $sql),
        ));

        self::assertCount(1, $leituras, 'a decisão lê o status do lote uma vez, pelo banco');
        self::assertMatchesRegularExpression('/\bFOR\s+UPDATE\b/i', $leituras[0]);
    }

    /** Isolamento: o filtro de tenant da leitura é explícito, e o lote de outro escritório não conta. */
    #[TestDox('Registro de OUTRO escritório com o mesmo batchId não bloqueia nem é tocado')]
    public function testOutroEscritorioComMesmoLoteNaoInterfere(): void
    {
        $this->iniciar();
        [$tenantA, $user] = $this->cenario();
        [$tenantB]        = $this->cenario();
        // O MESMO colaborador nos dois escritórios: só o filtro de tenant separa os registros —
        // um filtro por usuário veria o dia abonado de B e recusaria a troca em A.
        $this->em()->persist(new UserTenant($user, $tenantB));
        $this->em()->flush();
        $batch             = bin2hex(random_bytes(12));
        $antigoA           = $this->semear('A');
        $antigoB           = $this->semear('B');
        $loteA             = $this->criarLote($tenantA, $user, $antigoA, ['pendente', 'pendente'], batchId: $batch);
        $loteB             = $this->criarLote($tenantB, $user, $antigoB, ['abonado'], batchId: $batch);

        $this->useCase()->executar($loteA[0], $this->upload(), $tenantA);

        self::assertNotSame($antigoA, $this->anexosNoBanco($loteA)[0], 'o lote de A troca normalmente');
        self::assertSame([$antigoB], $this->anexosNoBanco($loteB), 'o registro de B não é tocado');
        self::assertFileExists($this->caminho($antigoB));
    }

    // ------------------------------------------------------------------ helpers

    private function iniciar(): void
    {
        self::bootKernel();
        $this->linhaDeBase = $this->arquivosNoDiretorio();
    }

    private function useCase(): SubstituirAnexoDoLoteUseCase
    {
        return static::getContainer()->get(SubstituirAnexoDoLoteUseCase::class);
    }

    /**
     * O UseCase de verdade, com o storage REAL embrulhado num espião.
     *
     * @return array{0: SubstituirAnexoDoLoteUseCase, 1: ArmazenamentoEspiao}
     */
    private function useCaseEspiado(): array
    {
        $container = static::getContainer();
        $em        = $this->em();
        $espiao    = new ArmazenamentoEspiao($container->get(ArmazenamentoLocal::class));
        $logger    = new LoggerEmMemoria();
        $remocao   = new RemocaoAposTransacao($espiao, $logger);

        return [
            new SubstituirAnexoDoLoteUseCase(
                $em,
                $container->get(JustificativaPontoRepository::class),
                $espiao,
                new TransacaoComArquivoNovo($em, new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), $remocao, $logger),
                $remocao,
                $container->get(ValidatorInterface::class),
                $logger,
            ),
            $espiao,
        ];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Pela conexão: depois de uma recusa o EntityManager está fechado.
     *
     * @param JustificativaPonto[] $lote
     *
     * @return list<string|null>
     */
    private function anexosNoBanco(array $lote): array
    {
        return $this->colunaNoBanco($lote, 'anexo_path');
    }

    /**
     * @param JustificativaPonto[] $lote
     *
     * @return list<string|null>
     */
    private function statusNoBanco(array $lote): array
    {
        return $this->colunaNoBanco($lote, 'status');
    }

    /**
     * @param JustificativaPonto[] $lote
     *
     * @return list<string|null>
     */
    private function colunaNoBanco(array $lote, string $coluna): array
    {
        $conexao = $this->em()->getConnection();

        return array_map(
            static fn (JustificativaPonto $j): ?string => ($v = $conexao->fetchOne(
                "SELECT {$coluna} FROM justificativa_ponto WHERE id = ?",
                [$j->getId()],
            )) === false ? null : $v,
            $lote,
        );
    }

    private function diretorio(): string
    {
        return (string) static::getContainer()->getParameter('justificativas_uploads_dir');
    }

    private function caminho(string $nome): string
    {
        return $this->diretorio() . '/' . $nome;
    }

    /** @return list<string> */
    private function arquivosNoDiretorio(): array
    {
        $nomes = array_values(array_diff(scandir($this->diretorio()) ?: [], ['.', '..']));
        sort($nomes);

        return $nomes;
    }

    /** O anexo de justificativa mora num diretório plano: basta um escritório qualquer para a chave. */
    private function semear(string $conteudo): string
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 1);

        $nome = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePonto::novoAnexoDeLote($tenant, 'pdf'),
            FonteDeConteudo::deTexto('%PDF-1.4 ' . $conteudo),
        )->chave->nome;

        return $nome;
    }

    private function upload(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/anexo-analisado-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($origem, "%PDF-1.4\n% conteudo novo\n");

        return new UploadedFile($origem, 'novo.pdf', 'application/pdf', null, true);
    }

    /**
     * @param list<string> $status um por dia
     *
     * @return JustificativaPonto[]
     */
    private function criarLote(Tenant $tenant, User $user, string $anexo, array $status, ?string $batchId = 'auto'): array
    {
        $em    = $this->em();
        $batch = $batchId === 'auto' ? bin2hex(random_bytes(12)) : $batchId;
        $lote  = [];

        foreach ($status as $i => $statusDoDia) {
            $j = new JustificativaPonto();
            $j->setUser($user);
            $j->setTenant($tenant);
            $j->setData(new \DateTime('2026-03-' . str_pad((string) ($i + 1), 2, '0', \STR_PAD_LEFT)));
            $j->setAnexoPath($anexo);
            $j->setStatus($statusDoDia);
            $j->setBatchId($batch);
            $j->setTipo('atestado_medico');
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
        $tenant->setName('Tenant ATESTADO ANALISADO ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('atestado_analisado_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Atestado');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$tenant, $user];
    }
}
