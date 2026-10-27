<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Repository\PainelMasterRepository;
use App\Master\UseCase\ConsultarAuditoriaUseCase;
use App\Master\UseCase\ConsultarContasUseCase;
use App\Master\UseCase\ConsultarUsuariosOabUseCase;
use App\Master\UseCase\ConsultarVisaoGeralUseCase;
use App\Profile\Entity\UserProfile;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraDocumentoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Consultas globais do Painel do Master contra o banco, num cenário de 2 escritórios + 1 na
 * quarentena. O banco de teste pode ter dados de outros testes: os KPIs são medidos como DIFERENÇA
 * antes/depois do cenário, e as contas pelo id dos escritórios criados aqui.
 */
#[CoversClass(PainelMasterRepository::class)]
#[CoversClass(ConsultarVisaoGeralUseCase::class)]
#[CoversClass(ConsultarContasUseCase::class)]
#[CoversClass(ConsultarUsuariosOabUseCase::class)]
#[CoversClass(ConsultarAuditoriaUseCase::class)]
final class ConsultasDoPainelTest extends KernelTestCase
{
    use Factories;

    private const GB = 1073741824;

    private EntityManagerInterface $em;

    /** @var array<string, mixed> */
    private array $cenario = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('KPIs: pessoa com 2 vínculos conta 1 vez; quarentena, vínculo inativo e Master sem escritório ficam fora; GB somado')]
    public function testKpis(): void
    {
        $antes = $this->visao()['kpis'];
        $this->montarCenario();
        $depois = $this->visao()['kpis'];

        $delta = static fn (string $k): int|float => $depois[$k] - $antes[$k];
        self::assertSame(2, $delta('escritorios'));
        self::assertSame(1, $delta('ativas'));
        self::assertSame(1, $delta('suspensas'));
        self::assertSame(1, $delta('primarios'), 'o titular de A, também secundário em B, conta uma vez como primário');
        self::assertSame(2, $delta('secundarios'));
        self::assertSame(2, $delta('oabRegistros'));
        self::assertSame(2, $delta('oabUnicas'));
        self::assertSame(2, $delta('pastas'), 'pasta na lixeira e pasta da quarentena ficam fora');
        self::assertSame(2, $delta('documentos'));
        self::assertSame(self::GB + self::GB / 2 + 1024, $delta('bytes'), 'bytes do documento da pasta em lápide contam');
        self::assertEqualsWithDelta(1.5, $delta('gb'), 0.001);
    }

    #[TestDox('Duplicidade: o mesmo sha256 em escritórios diferentes é cobrado duas vezes e ocupa uma; sem sha256 conta como único')]
    public function testDuplicidadeDeArquivos(): void
    {
        $antes = $this->visao()['duplicidade'];
        $this->montarCenario();
        $conexao = self::getContainer()->get(Connection::class);
        $hash    = str_repeat('ab', 32);
        foreach ([$this->cenario['a'], $this->cenario['b']] as $tenant) {
            $pasta = PastaFactory::createOne(['tenant' => $tenant]);
            $conexao->insert('pasta_documento', $this->linhaDeDocumentoDePasta($conexao, $pasta->_real()->getId(), $tenant->getId(), 5000) + ['sha256' => $hash]);
        }
        $depois = $this->visao()['duplicidade'];

        $delta = static fn (string $k): int => $depois[$k] - $antes[$k];
        $base  = self::GB + self::GB / 2 + 1024;
        self::assertSame($base + 10000, $delta('cobrado'), 'o cenário soma 1,5 GB + 1 KB (a quarentena fica fora) + 2 x 5000 bytes');
        self::assertSame($base + 5000, $delta('fisico'), 'o par idêntico ocupa 5000 uma vez só');
        self::assertSame(3 + 2, $delta('vinculos'));
        self::assertSame(3 + 1, $delta('arquivos'));
    }

    #[TestDox('Mapa e faixa etária: lotação Cidade/UF, OAB, sem localização; escritório sem estado à parte')]
    public function testMapaEIdades(): void
    {
        $antes = $this->visao();
        $this->montarCenario();
        $depois = $this->visao();

        $uf = static fn (array $v, string $modo, string $sigla): int => $v['mapa']['pessoas'][$modo]['A'][$sigla] ?? 0;
        self::assertSame(1, $uf($depois, 'geral', 'AL') - $uf($antes, 'geral', 'AL'), 'titular em Maceió/AL pela lotação');
        self::assertSame(1, $uf($depois, 'geral', 'DF') - $uf($antes, 'geral', 'DF'), 'secundário sem lotação conta na UF da OAB');
        self::assertSame(1, $depois['mapa']['pessoas']['geral']['semLoc'] - $antes['mapa']['pessoas']['geral']['semLoc']);
        self::assertSame(1, $uf($depois, 'prim', 'AL') - $uf($antes, 'prim', 'AL'));
        self::assertSame(0, $uf($depois, 'prim', 'DF') - $uf($antes, 'prim', 'DF'));
        $cidades = array_change_key_case($depois['mapa']['pessoas']['geral']['C']['AL'] ?? [], \CASE_LOWER);
        self::assertGreaterThanOrEqual(1, $cidades['maceió'] ?? 0);

        $esc = static fn (array $v): int => $v['mapa']['escritorios']['A']['GO'] ?? 0;
        self::assertSame(1, $esc($depois) - $esc($antes));
        self::assertSame(1, $depois['mapa']['escritorios']['semEnd'] - $antes['mapa']['escritorios']['semEnd']);

        $faixa = static fn (array $v, int $i): int => $v['idades']['faixas'][$i]['n'];
        self::assertSame(1, $faixa($depois, 2) - $faixa($antes, 2), '40 anos: 35 a 44');
        self::assertSame(1, $faixa($depois, 1) - $faixa($antes, 1), '26 anos: 25 a 34');
        self::assertSame(1, $depois['idades']['semData'] - $antes['idades']['semData']);
    }

    #[TestDox('O que sai para o mapa não identifica pessoa: sem nome, e-mail ou CPF')]
    public function testVisaoSemIdentificadores(): void
    {
        $this->montarCenario();
        $json = mb_strtolower(json_encode($this->visao(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        self::assertStringNotContainsString('@', $json, 'nenhum e-mail');
        foreach (['titular painel', 'secundario painel', '123.456.789-00', '12345678900'] as $proibido) {
            self::assertStringNotContainsString($proibido, $json);
        }
        self::assertSame(['kpis', 'crescimento', 'mapa', 'idades', 'armazenamento', 'duplicidade', 'registrosDeAuditoria', 'geradoEm'], array_keys($this->visao()));
        self::assertSame(['A', 'C', 'tend', 'semLoc', 'total'], array_keys($this->visao()['mapa']['pessoas']['geral']));
    }

    #[TestDox('Contas: uma linha por escritório, com titular, usuários, pastas e consumo; a quarentena só como "Em quarentena"')]
    public function testContas(): void
    {
        $this->montarCenario();
        $contas = array_column(self::getContainer()->get(ConsultarContasUseCase::class)->executar(), null, 'id');

        $a = $contas[$this->cenario['a']->getId()];
        self::assertSame('Titular Painel', mb_convert_case($a['titular'] ?? '', \MB_CASE_TITLE));
        self::assertSame(2, $a['usuarios']);
        self::assertSame(2, $a['pastas']);
        self::assertSame(2, $a['documentos']);
        self::assertSame(self::GB + self::GB / 2 + 1024, $a['bytes']);
        self::assertSame(self::GB + self::GB / 2 + 1024, $a['bytesRecentes']);
        self::assertSame('Ativa', $a['situacao']);

        $b = $contas[$this->cenario['b']->getId()];
        self::assertSame('Suspensa', $b['situacao']);
        self::assertSame(2, $b['usuarios']);
        self::assertSame(0, $b['bytes']);

        // C4b (spec perfis-1.2-contas): a quarentena entra à parte, só para a opção "Em quarentena" do filtro.
        $q = $contas[$this->cenario['q']->getId()];
        self::assertSame('Em quarentena', $q['situacao'], 'escritório na quarentena não conta como Ativa nem Suspensa');
        self::assertNotNull($q['purgaEm']);
        self::assertSame(1, $q['usuarios'], 'os vínculos da conta em quarentena continuam até a purga: a contagem é a real');
        self::assertSame(0, $q['pastas'], 'pastas e consumo continuam fora da quarentena');
        self::assertNull($a['purgaEm']);
    }

    #[TestDox('OABs: normaliza, conta não validadas e lista pendências por conta, sem nome de pessoa')]
    public function testOabs(): void
    {
        $antes = self::getContainer()->get(ConsultarUsuariosOabUseCase::class)->executar();
        $this->montarCenario();
        $depois = self::getContainer()->get(ConsultarUsuariosOabUseCase::class)->executar();

        self::assertSame(1, $depois['naoValidadas'] - $antes['naoValidadas']);
        self::assertSame(2, ($depois['ufs']['DF'] ?? 0) - ($antes['ufs']['DF'] ?? 0));

        $nomeA = $this->cenario['a']->getName();
        $minhas = array_values(array_filter($depois['pendencias'], static fn (array $p): bool => $p['conta'] === $nomeA));
        $tipos = array_column($minhas, 'tipo');
        sort($tipos);
        self::assertSame(['formatacao', 'nao_validada'], $tipos);
        self::assertSame('12044/DF', $minhas[0]['normalizada']);
        self::assertStringNotContainsString('secundario painel', mb_strtolower(json_encode($depois, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)));
    }

    #[TestDox('Auditoria: traduz, esconde o valor do campo, ignora carimbo de login e nomeia conta purgada')]
    public function testAuditoria(): void
    {
        $conexao = self::getContainer()->get(Connection::class);
        $agora = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $inserir = static fn (string $acao, string $entidade, string $mudancas, ?int $tenant, ?int $ator = null) => $conexao->insert('audit_log', [
            'action' => $acao, 'entity_class' => $entidade, 'entity_id' => '1', 'changes' => $mudancas, 'tenant_id' => $tenant,
            'actor_email' => 'quem@teste.com', 'actor_user_id' => $ator, 'created_at' => $agora,
        ]);
        $inserir('update', User::class, '{"diff":{"changes":{"password":{"from":"x","to":"$2y$segredo"}}}}', null, 999999999);
        $inserir('update', UserTenant::class, '{"diff":{"changes":{"lastLoginAt":{"from":"a","to":"b"}}}}', null);
        $inserir('purge', Tenant::class, '{"nome":"Escritório Purgado Teste","motivo":"quarentena_expirada"}', 999999999);
        $inserir('update', 'App\Pasta\Entity\Pasta', '{"diff":{"changes":{"nup":{"from":"1","to":"2"}}}}', null);

        $r = self::getContainer()->get(ConsultarAuditoriaUseCase::class)->executar();
        $json = json_encode($r, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        self::assertStringNotContainsString('$2y$segredo', $json);
        self::assertStringNotContainsString('quem@teste.com', $json, 'o e-mail do ator nunca aparece');
        $topo = array_slice($r['registros'], 0, 2);
        $acoes = array_column($topo, 'acao');
        sort($acoes);
        self::assertSame(['Conta excluída definitivamente', 'Usuário alterado'], $acoes, 'só entidades administrativas; carimbo de login e Pasta fora');
        $purga = array_values(array_filter($topo, static fn (array $l): bool => $l['acao'] === 'Conta excluída definitivamente'))[0];
        self::assertSame('Escritório Purgado Teste', $purga['conta']);
        self::assertSame('Sistema', $purga['quem'], 'sem ator: Sistema');
        $usuario = array_values(array_filter($topo, static fn (array $l): bool => $l['acao'] === 'Usuário alterado'))[0];
        self::assertSame('Usuário sem nome (nº 999999999)', $usuario['quem']);
    }

    /**
     * @return array<string, mixed>
     */
    private function visao(): array
    {
        return self::getContainer()->get(ConsultarVisaoGeralUseCase::class)->executar();
    }

    private function montarCenario(): void
    {
        if ($this->cenario !== []) {
            return;
        }

        $a = $this->tenant('Escritório A Painel', 'GO', 'Goiânia');
        $b = $this->tenant('Escritório B Painel', null, null);
        $b->setIsActive(false);
        $q = $this->tenant('Escritório Q Painel', 'SP', 'São Paulo');
        $q->setExcluidoEm(new \DateTimeImmutable('-1 day'));

        $sistemaA = $this->papel($a, true);
        $secA = $this->papel($a, false);
        $this->papel($b, true);
        $secB = $this->papel($b, false);
        $sistemaQ = $this->papel($q, true);

        $lotacao = (new Lotacao())->setNome('Maceió/AL')->setTenant($a);
        $this->em->persist($lotacao);

        $titular = $this->pessoa('titular.painel@teste.com', 'Titular Painel', '49613', 'DF', StatusOab::Confirmada, '1986-01-10');
        $a->setCriadoPor($titular);
        $this->vinculo($titular, $a, $sistemaA, $lotacao);
        $this->vinculo($titular, $b, $secB, null);

        $secundario = $this->pessoa('sec.painel@teste.com', 'Secundario Painel', '0012044', 'DF', StatusOab::NaoVerificada, (new \DateTimeImmutable('-26 years -10 days'))->format('Y-m-d'));
        $this->vinculo($secundario, $a, $secA, null);

        $semLocal = $this->pessoa('semlocal.painel@teste.com', 'Sem Local', null, null, null, null);
        $this->vinculo($semLocal, $b, $secB, null);

        $daQuarentena = $this->pessoa('q.painel@teste.com', 'Da Quarentena', '777', 'SP', StatusOab::Confirmada, '1990-01-01');
        $this->vinculo($daQuarentena, $q, $sistemaQ, null);

        $inativo = $this->pessoa('inativo.painel@teste.com', 'Vinculo Inativo', '888', 'RJ', StatusOab::Confirmada, '1990-01-01');
        $vInativo = $this->vinculo($inativo, $a, $secA, null);

        $master = $this->pessoa('master.painel@teste.com', 'Master Painel', null, null, null, '1980-01-01');
        $master->setRoles(['ROLE_SUPER_ADMIN']);

        $this->em->flush();
        // UserTenant não tem "desativar" no domínio; o estado inativo existe no banco e o painel o respeita.
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE user_tenant SET is_active = FALSE WHERE id = ?', [$vInativo->getId()]);

        PastaFactory::createMany(2, ['tenant' => $a]);
        PastaFactory::createOne(['tenant' => $q]);
        $lixeira = PastaFactory::createOne(['tenant' => $a]);
        $lixeira->_real()->marcarExcluida($titular, new \DateTimeImmutable());
        $lixeira->_save();
        // Documento de pasta em lápide: não conta como documento, mas os bytes contam (arquivo guardado).
        $conexao = self::getContainer()->get(Connection::class);
        $conexao->insert('pasta_documento', $this->linhaDeDocumentoDePasta($conexao, $lixeira->_real()->getId(), $a->getId(), 1024));

        $carteira = CarteiraFactory::createOne(['tenant' => $a, 'cliente' => ClientePFFactory::createOne(['tenant' => $a])]);
        CarteiraDocumentoFactory::createOne(['tenant' => $a, 'carteira' => $carteira, 'tamanhoBytes' => self::GB]);
        CarteiraDocumentoFactory::createOne(['tenant' => $a, 'carteira' => $carteira, 'tamanhoBytes' => self::GB / 2]);
        $carteiraQ = CarteiraFactory::createOne(['tenant' => $q, 'cliente' => ClientePFFactory::createOne(['tenant' => $q])]);
        CarteiraDocumentoFactory::createOne(['tenant' => $q, 'carteira' => $carteiraQ, 'tamanhoBytes' => self::GB]);

        $this->cenario = ['a' => $a, 'b' => $b, 'q' => $q];
    }

    /**
     * @return array<string, mixed>
     */
    private function linhaDeDocumentoDePasta(Connection $conexao, int $pastaId, int $tenantId, int $bytes): array
    {
        return [
            'id' => (int) $conexao->fetchOne("SELECT nextval('pasta_documento_id_seq')"),
            'titulo' => 'Documento da lápide', 'categoria' => 'outros', 'caminho_arquivo' => 'x/' . uniqid() . '.pdf',
            'nome_original' => 'x.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => $bytes,
            'uploaded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'ordem' => 0, 'pasta_id' => $pastaId, 'tenant_id' => $tenantId,
        ];
    }

    private function tenant(string $nome, ?string $estado, ?string $cidade): Tenant
    {
        $t = new Tenant();
        $t->setName($nome . ' ' . uniqid());
        $t->setEstado($estado);
        $t->setCidade($cidade);
        $this->em->persist($t);

        return $t;
    }

    private function papel(Tenant $tenant, bool $sistema): TenantRole
    {
        $r = new TenantRole();
        $r->setTenant($tenant);
        $r->setName($sistema ? 'Administrador do Escritório' : 'Colaborador ' . uniqid());
        $r->setIsSystem($sistema);
        $this->em->persist($r);

        return $r;
    }

    private function pessoa(string $email, string $nome, ?string $oab, ?string $uf, ?StatusOab $status, ?string $nascimento): User
    {
        $u = new User();
        $u->setEmail(str_replace('@', '.' . uniqid() . '@', $email));
        $u->setFullName($nome);
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setOabNumero($oab);
        $u->setOabUf($uf);
        $u->setOabStatus($status);
        $this->em->persist($u);

        $perfil = new UserProfile($u);
        $perfil->setCpf('123.456.789-00');
        if ($nascimento !== null) {
            $perfil->setDataNascimento(new \DateTime($nascimento));
        }
        $this->em->persist($perfil);

        return $u;
    }

    private function vinculo(User $u, Tenant $t, ?TenantRole $papel, ?Lotacao $lotacao): UserTenant
    {
        $v = new UserTenant($u, $t);
        $v->setTenantRole($papel);
        $v->setLotacao($lotacao);
        $this->em->persist($v);

        return $v;
    }
}
