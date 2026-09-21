<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\ConfirmarEdicaoDeJustificativaUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C2-01 (auditoria de 04/09, CRÍTICO) — docs/specs/ponto-edicao-justificativa-analisada.md.
 *
 * A rota de edição do colaborador conferia só o dono e o CSRF: tipo, abono parcial e horários de
 * uma justificativa já abonada (ou rejeitada) mudavam sem nova análise — e numa abonada isso muda o
 * saldo do dia, o banco de horas e a folha exportada. A regra da auditoria: o colaborador só edita
 * justificativa `pendente`; o caminho de volta de uma analisada é o "reverter" do administrador.
 *
 * Os testes fazem o POST cru, como faria quem manipula a requisição: a tela deixa de oferecer o
 * botão, mas quem garante é o servidor.
 */
#[CoversClass(PontoController::class)]
final class EdicaoDeJustificativaAnalisadaControllerTest extends JusPrimeWebTestCase
{
    /** @var list<string> o diretório de justificativas no início do teste */
    private ?array $linhaDeBase = null;

    protected function tearDown(): void
    {
        if ($this->linhaDeBase !== null) {
            $diretorio = $this->diretorioDeJustificativas();
            foreach (array_diff($this->arquivosEm($diretorio), $this->linhaDeBase) as $nome) {
                @unlink($diretorio . '/' . $nome);
            }
        }

        parent::tearDown();
    }

    #[TestDox('Pendente continua editável: tipo e horários do abono parcial são gravados')]
    public function testPendenteContinuaEditavel(): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, 'pendente', 'licenca');

        $this->editar($client, $j, [
            'tipo'            => 'atestado_medico',
            'abonoParcial'    => '1',
            'horaInicioAbono' => '14:00',
            'horaFimAbono'    => '16:00',
        ]);

        self::assertResponseRedirects('/ponto/');
        self::assertContains('Justificativa atualizada com sucesso.', $this->flashes($client)['success'] ?? []);
        $linha = $this->linha($j);
        self::assertSame('atestado_medico', $linha['tipo']);
        self::assertTrue((bool) $linha['abono_parcial']);
        self::assertSame('14:00', substr((string) $linha['hora_inicio_abono'], 0, 5));
        self::assertSame('16:00', substr((string) $linha['hora_fim_abono'], 0, 5));
        self::assertSame('pendente', $linha['status']);
    }

    /** @return iterable<string, array{string, string, array<string,string>}> */
    public static function edicoesDeAnalisada(): iterable
    {
        yield 'abonada: trocar o tipo' => ['abonado', 'esquecimento_registro', ['tipo' => 'atestado_medico']];
        yield 'abonada: limpar o tipo (null abona o dia inteiro)' => ['abonado', 'licenca', ['tipo' => '']];
        yield 'abonada: ligar abono parcial 00:00–23:59' => ['abonado', 'atestado_medico', [
            'tipo' => 'atestado_medico', 'abonoParcial' => '1', 'horaInicioAbono' => '00:00', 'horaFimAbono' => '23:59',
        ]];
        yield 'rejeitada: trocar o tipo' => ['rejeitado', 'licenca', ['tipo' => 'atestado_medico']];
        yield 'rejeitada: ligar abono parcial' => ['rejeitado', 'licenca', [
            'tipo' => 'licenca', 'abonoParcial' => '1', 'horaInicioAbono' => '08:00', 'horaFimAbono' => '12:00',
        ]];
        // O roteiro da auditoria: a falta não justificada nasce `abonado` sem análise nenhuma.
        yield 'falta não justificada (nasce abonada): virar ajuste de jornada 00:00–23:59' => ['abonado', 'falta_nao_justificada', [
            'tipo' => 'ajuste_jornada', 'abonoParcial' => '1', 'horaInicioAbono' => '00:00', 'horaFimAbono' => '23:59',
        ]];
    }

    /**
     * @param array<string,string> $campos
     */
    #[TestDox('Recusado pelo servidor — $_dataName: nada muda no banco, aviso sem sucesso')]
    #[DataProvider('edicoesDeAnalisada')]
    public function testAnalisadaNaoPodeSerEditada(string $status, string $tipo, array $campos): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, $status, $tipo);
        $antes        = $this->linha($j);

        $this->editar($client, $j, $campos);

        self::assertResponseRedirects('/ponto/');
        $flashes = $this->flashes($client);
        self::assertStringContainsString('já foi analisada', implode(' ', $flashes['warning'] ?? []));
        self::assertArrayNotHasKey('success', $flashes, 'recusa nunca vem com mensagem de sucesso');
        self::assertSame($antes, $this->linha($j), 'nenhum campo da justificativa analisada pode mudar');
    }

    #[TestDox('Esquecimento abonado: a hora e o tipo de registro não mudam depois da análise')]
    public function testEsquecimentoAbonadoNaoMudaAHora(): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, 'abonado', 'esquecimento_registro', esquecimento: ['entrada', '08:00']);
        $antes        = $this->linha($j);

        $this->editar($client, $j, [
            'tipo'                  => 'esquecimento_registro',
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => '07:00',
        ]);

        self::assertSame($antes, $this->linha($j));
    }

    /**
     * Requisição forjada: campos que o formulário nem tem (status, análise) e um tipo que abona. O
     * servidor recusa pela justificativa, não pelo que veio no corpo.
     */
    #[TestDox('Requisição forjada com status/análise no corpo é recusada numa abonada')]
    public function testRequisicaoForjadaEhRecusada(): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, 'abonado', 'esquecimento_registro');
        $antes        = $this->linha($j);

        $this->editar($client, $j, [
            'tipo'           => 'atestado_medico',
            'status'         => 'pendente',
            'analisadoPor'   => '',
            'dataAnalise'    => '',
            'justificativa'  => ['status' => 'pendente'],
        ]);

        self::assertSame($antes, $this->linha($j));
    }

    /**
     * Segunda metade da correção pedida na auditoria: editar uma pendente não pode fazê-la sair de
     * `pendente` sem o administrador — nem escolhendo o tipo que, na criação, nasce abonado.
     */
    #[TestDox('Pendente editada para "falta não justificada" continua pendente (o formulário não grava status)')]
    public function testPendenteNaoMudaDeStatusPelaEdicao(): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, 'pendente', 'licenca');

        $this->editar($client, $j, ['tipo' => 'falta_nao_justificada', 'status' => 'abonado']);

        $linha = $this->linha($j);
        self::assertSame('falta_nao_justificada', $linha['tipo']);
        self::assertSame('pendente', $linha['status']);
        self::assertNull($linha['analisado_por_id']);
    }

    /**
     * A edição de tipo/horário atinge só o registro da rota. Num lote misto, a recusa é pelo status
     * DESSE registro: o dia pendente continua editável, e os dias analisados nunca mudam.
     */
    #[TestDox('Lote misto: o dia analisado recusa; o pendente edita só a si mesmo')]
    public function testLoteMisto(): void
    {
        [$client, $c] = $this->cenario();
        $batch        = bin2hex(random_bytes(12));
        $abonado      = $this->justificativa($c, 'abonado', 'licenca', batchId: $batch, dia: 1);
        $pendente     = $this->justificativa($c, 'pendente', 'licenca', batchId: $batch, dia: 2);
        $antesAbonado = $this->linha($abonado);

        $this->editar($client, $abonado, ['tipo' => 'atestado_medico']);
        self::assertSame($antesAbonado, $this->linha($abonado), 'o dia abonado não muda pela própria rota');

        $this->editar($client, $pendente, ['tipo' => 'atestado_medico']);
        self::assertSame('atestado_medico', $this->linha($pendente)['tipo'], 'o dia pendente continua editável');
        self::assertSame($antesAbonado, $this->linha($abonado), 'nem editando o pendente o dia abonado muda');
    }

    /** @return iterable<string, array{string}> */
    public static function analisadas(): iterable
    {
        yield 'abonada' => ['abonado'];
        yield 'rejeitada' => ['rejeitado'];
    }

    /** A recusa acontece antes de qualquer gravação: nem o banco nem o diretório de atestados mudam. */
    #[TestDox('$_dataName com atestado novo no POST: recusa sem tocar banco nem storage')]
    #[DataProvider('analisadas')]
    public function testRecusaNaoTocaBancoNemStorage(string $status): void
    {
        [$client, $c] = $this->cenario();
        $anexo        = $this->semearAnexo($c['tenant']);
        $j            = $this->justificativa($c, $status, 'atestado_medico', anexo: $anexo);
        $antes        = $this->linha($j);
        $diretorio    = $this->diretorioDeJustificativas();
        $arquivos     = $this->arquivosEm($diretorio);

        $this->editar($client, $j, ['tipo' => 'licenca'], $this->upload());

        self::assertSame($antes, $this->linha($j), 'nem o tipo nem o anexo mudam');
        self::assertSame($arquivos, $this->arquivosEm($diretorio), 'nenhum arquivo entra nem sai');
        self::assertFileExists($diretorio . '/' . $anexo);
    }

    /**
     * A corrida pela porta HTTP: a justificativa chega pendente (passa a recusa rápida), e o admin
     * analisa antes da gravação — a leitura travada do UseCase vê. Aqui a análise concorrente é
     * simulada no repositório que o UseCase consulta; o que se prova é a rota transformar essa recusa
     * num aviso, sem sucesso e sem gravar.
     */
    #[TestDox('Análise concorrente vista na gravação: a rota avisa, não diz sucesso e não grava')]
    public function testAnaliseConcorrenteNaGravacaoViraAviso(): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, 'pendente', 'licenca');
        $antes        = $this->linha($j);

        $container   = static::getContainer();
        $repositorio = new class ($container->get('doctrine')) extends JustificativaPontoRepository {
            public function statusNoBancoTravadoPorId(int $id, Tenant $tenant): ?string
            {
                return 'abonado';
            }
        };
        $container->set(
            ConfirmarEdicaoDeJustificativaUseCase::class,
            new ConfirmarEdicaoDeJustificativaUseCase($container->get(EntityManagerInterface::class), $repositorio),
        );

        $this->editar($client, $j, ['tipo' => 'atestado_medico']);

        self::assertResponseRedirects('/ponto/');
        $flashes = $this->flashes($client);
        self::assertStringContainsString('já foi analisada', implode(' ', $flashes['warning'] ?? []));
        self::assertArrayNotHasKey('success', $flashes);
        self::assertSame($antes, $this->linha($j), 'nada do que a rota editou chega ao banco');
    }

    /** O fluxo legítimo do administrador não muda: ele continua analisando o que está pendente. */
    #[TestDox('Administrador (admin.users.manage, sem perfil de sistema) continua aprovando a pendente')]
    public function testAdministradorContinuaAprovando(): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, 'pendente', 'licenca');
        $admin        = $this->administrador($c['tenant']);
        $id           = (int) $j->getId();

        $this->logarComTenant($client, $admin, $c['tenant']);
        $this->requisicaoNova();
        $client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/aprovar', $c['tenant']->getId(), $c['user']->getId(), $id), [
            '_token' => 'TOKEN_justificativa_aprovar_' . $id,
        ]);

        self::assertResponseRedirects();
        $linha = $this->linha($j);
        self::assertSame('abonado', $linha['status']);
        self::assertSame($admin->getId(), $linha['analisado_por_id']);
    }

    /**
     * O caminho de volta que a regra aponta: o administrador reverte para pendente, e a edição volta
     * a ser aceita — e o que o colaborador mudar volta a depender de uma nova análise.
     */
    #[TestDox('Reverter para pendente (admin) libera de novo a edição pelo colaborador')]
    public function testReverterParaPendenteLiberaAEdicao(): void
    {
        [$client, $c] = $this->cenario();
        $j            = $this->justificativa($c, 'abonado', 'licenca');
        $admin        = $this->administrador($c['tenant']);
        $id           = (int) $j->getId();

        $this->editar($client, $j, ['tipo' => 'atestado_medico']);
        self::assertSame('licenca', $this->linha($j)['tipo'], 'antes de reverter, recusa');

        $this->logarComTenant($client, $admin, $c['tenant']);
        $this->requisicaoNova();
        $client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/reverter', $c['tenant']->getId(), $c['user']->getId(), $id), [
            '_token' => 'TOKEN_justificativa_reverter_' . $id,
        ]);
        self::assertSame('pendente', $this->linha($j)['status']);

        $this->logarComTenant($client, $c['user'], $c['tenant']);
        $this->editar($client, $j, ['tipo' => 'atestado_medico']);

        $linha = $this->linha($j);
        self::assertSame('atestado_medico', $linha['tipo'], 'depois de reverter, edita');
        self::assertSame('pendente', $linha['status'], 'e segue esperando uma nova análise');
    }

    /** Isolamento: o mesmo colaborador em dois escritórios não alcança a justificativa do outro. */
    #[TestDox('Justificativa de outro escritório: 404, e nada muda — nem a pendente')]
    public function testOutroEscritorioNaoAlcanca(): void
    {
        [$client, $c] = $this->cenario();
        $outro        = $this->tenant();
        $this->vincular($c['user'], $outro, sistema: true);
        $j     = $this->justificativa(['tenant' => $outro, 'user' => $c['user']], 'pendente', 'licenca');
        $antes = $this->linha($j);

        $this->editar($client, $j, ['tipo' => 'atestado_medico']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($antes, $this->linha($j));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function statusNaTela(): iterable
    {
        yield 'pendente: oferece editar'   => ['pendente', true];
        yield 'abonada: não oferece'       => ['abonado', false];
        yield 'rejeitada: não oferece'     => ['rejeitado', false];
    }

    /** A tela reflete a regra: sem botão de editar para o que já foi analisado. */
    #[TestDox('A tela só oferece "Editar justificativa" para pendente ($_dataName)')]
    #[DataProvider('statusNaTela')]
    public function testTelaOfereceEditarSoParaPendente(string $status, bool $oferece): void
    {
        [$client, $c] = $this->cenario();
        $this->justificativa($c, $status, 'licenca', mesCorrente: true);

        $this->requisicaoNova();
        $crawler = $client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        self::assertCount($oferece ? 1 : 0, $crawler->filter('button[title="Editar justificativa"]'));
        self::assertCount($oferece ? 0 : 1, $crawler->filter('[data-justificativa-bloqueada]'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string,mixed> $campos
     */
    private function editar(KernelBrowser $client, JustificativaPonto $j, array $campos, ?UploadedFile $anexo = null): void
    {
        $id = (int) $j->getId();

        $this->requisicaoNova();
        $client->request(
            'POST',
            '/ponto/justificativa/' . $id . '/editar',
            ['_token' => 'TOKEN_editar_justificativa_' . $id] + $campos,
            $anexo !== null ? ['anexo' => $anexo] : [],
        );
    }

    /**
     * Com `disableReboot()`, a primeira requisição reaproveitaria o EntityManager do preparo: o
     * `find()` do EntityValueResolver sairia do identity map, sem SQL — e sem o TenantFilter. Em
     * produção cada requisição nasce com o EntityManager vazio; aqui também.
     */
    private function requisicaoNova(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** @return array<string, list<string>> */
    private function flashes(KernelBrowser $client): array
    {
        return $client->getRequest()->getSession()->getFlashBag()->peekAll();
    }

    /**
     * A linha como está no BANCO, pela conexão: numa recusa dentro de transação o EntityManager
     * fecha, e a entidade em memória não diz o que foi gravado.
     *
     * @return array<string, mixed>
     */
    private function linha(JustificativaPonto $j): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT status, tipo, abono_parcial, hora_inicio_abono, hora_fim_abono, tipo_registro_esquecido,
                    hora_registro_esquecido, anexo_path, analisado_por_id, data_analise, observacao_analise
               FROM justificativa_ponto WHERE id = ?',
            [$j->getId()],
        ) ?: [];
    }

    /**
     * @param array{tenant: Tenant, user: User} $c
     * @param array{0: string, 1: string}|null  $esquecimento tipo de registro e hora
     */
    private function justificativa(
        array $c,
        string $status,
        ?string $tipo,
        ?string $batchId = null,
        int $dia = 1,
        ?string $anexo = null,
        ?array $esquecimento = null,
        bool $mesCorrente = false,
    ): JustificativaPonto {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $mes = $mesCorrente ? (new \DateTimeImmutable('first day of this month'))->format('Y-m') : '2026-04';

        $j = new JustificativaPonto();
        $j->setUser($c['user']);
        $j->setTenant($c['tenant']);
        $j->setData(new \DateTime($mes . '-' . str_pad((string) $dia, 2, '0', \STR_PAD_LEFT)));
        $j->setStatus($status);
        $j->setTipo($tipo);
        $j->setBatchId($batchId ?? bin2hex(random_bytes(12)));
        $j->setAnexoPath($anexo);

        if ($esquecimento !== null) {
            $j->setTipoRegistroEsquecido($esquecimento[0]);
            $j->setHoraRegistroEsquecido(\DateTime::createFromFormat('H:i', $esquecimento[1]) ?: null);
        }

        if ($status !== 'pendente' && $tipo !== 'falta_nao_justificada') {
            $j->setDataAnalise(new \DateTime('2026-04-20 10:00:00'));
        }

        $em->persist($j);
        $em->flush();

        return $j;
    }

    /** @return array{0: KernelBrowser, 1: array{tenant: Tenant, user: User}} */
    private function cenario(): array
    {
        $client = static::createClient();
        // O token CSRF falso precisa sobreviver às requisições do teste.
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        });

        $tenant = $this->tenant();
        $user   = $this->usuario('colaborador');
        $this->vincular($user, $tenant, sistema: true);
        $this->linhaDeBase = $this->arquivosEm($this->diretorioDeJustificativas());

        $this->logarComTenant($client, $user, $tenant);

        return [$client, ['tenant' => $tenant, 'user' => $user]];
    }

    private function tenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant C2-01 ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function usuario(string $prefixo): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($prefixo . '_c201_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . $prefixo);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function vincular(User $user, Tenant $tenant, bool $sistema): UserTenant
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Perfil ' . uniqid());
        $role->setIsSystem($sistema);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $vinculo;
    }

    /**
     * Administrador de verdade: perfil NÃO-sistema carregando só `admin.users.manage` — o fluxo do
     * admin é provado pela permissão que a rota exige, não por um bypass.
     */
    private function administrador(Tenant $tenant): User
    {
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => 'admin.users.manage']);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode('admin.users.manage');
            $permissao->setDescription('Gerenciar usuários');
            $permissao->setGroup('admin');
            $em->persist($permissao);
        }

        $admin   = $this->usuario('admin');
        $vinculo = $this->vincular($admin, $tenant, sistema: false);
        $role    = $vinculo->getTenantRole();

        $ligacao = new TenantRolePermission();
        $ligacao->setTenantRole($role);
        $ligacao->setPermission($permissao);
        $em->persist($ligacao);
        $role->getTenantRolePermissions()->add($ligacao);
        $em->flush();

        return $admin;
    }

    private function semearAnexo(Tenant $tenant): string
    {
        return static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePonto::novoAnexoDeLote($tenant, 'pdf'),
            FonteDeConteudo::deTexto('%PDF-1.4 atestado analisado'),
        )->chave->nome;
    }

    private function upload(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/c201-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($origem, "%PDF-1.4\n% novo\n");

        return new UploadedFile($origem, 'novo.pdf', 'application/pdf', null, true);
    }

    private function diretorioDeJustificativas(): string
    {
        return (string) static::getContainer()->getParameter('justificativas_uploads_dir');
    }

    /** @return list<string> */
    private function arquivosEm(string $diretorio): array
    {
        $nomes = array_values(array_diff(scandir($diretorio) ?: [], ['.', '..']));
        sort($nomes);

        return $nomes;
    }
}
