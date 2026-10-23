<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A interjornada não recusa por saída vinda de esquecimento (spec `docs/specs/ponto-esquecimento-nao-trava.md` R4,
 * risco ALTO), com o EntityManager real e a aprovação de verdade pela rota do admin. A saída batida continua recusando.
 * O escritório tem home office liberado todo dia para a pessoa (a batida passa sem GPS).
 */
#[CoversClass(PontoController::class)]
#[CoversClass(SaidaDeEsquecimento::class)]
final class InterjornadaDeEsquecimentoTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 310) {
            self::markTestSkipped('Antes das 03:10 a saída de 3 horas atrás cairia em ontem.');
        }
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->tenant = $this->tenant();
        $this->user = $this->user(['ROLE_USER']);
        $this->vincular($this->user, ['modules.ponto.view']);
        $config = new HomeOfficeConfig();
        $config->setUser($this->user);
        $config->setTenant($this->tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    #[TestDox('R4: a saída aprovada por esquecimento há 3 horas não recusa a entrada; a batida grava e a resposta traz o aviso da interjornada')]
    public function testSaidaAprovadaPorEsquecimentoViraAviso(): void
    {
        $saida = new \DateTimeImmutable('-3 hours');
        $this->batida('entrada', $saida->modify('-1 minute'));
        $this->aprovarPeloAdmin($this->esquecimentoDaSaida($saida));

        $resposta = $this->bater('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame([[
            'texto' => sprintf(SaidaDeEsquecimento::AVISO_INTERJORNADA, $saida->format('d/m'), $saida->format('H:i'), 11),
            'link' => null,
            'rotulo' => null,
        ]], $resposta['avisos']);
        self::assertSame(2, $this->batidasDoTipo('entrada'));
    }

    /**
     * O lote do admin trata a data de HOJE como futura (compara a data com a hora atual com o começo do dia, defeito
     * anterior a esta frente), então a saída vai para ontem ao meio-dia e a interjornada do escritório sobe para 48 h:
     * a entrada de agora fica dentro dela em qualquer hora do dia.
     */
    #[TestDox('R4: o lançamento em lote do admin (esquecimento já abonado, com a observação do lançamento) seguido de entrada: a interjornada não recusa e a resposta traz o aviso')]
    public function testLancamentoEmLoteDoAdminViraAviso(): void
    {
        $jornada = (new JornadaTenant())->setTenant($this->tenant)->setMinimoMinutosInterjornada(2880);
        $this->tenant->setJornadaTenant($jornada);
        $this->em()->persist($jornada);
        $this->em()->flush();
        $saida = new \DateTimeImmutable('yesterday 12:00');
        $this->batida('entrada', $saida->modify('-4 hours'));
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $this->em()->persist(new UserTenant($admin, $this->tenant));
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $this->tenant);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/nova', $this->tenant->getId(), $this->user->getId()), [
            '_token' => 'TOKEN_admin_nova_justificativa_' . $this->user->getId(),
            'datas' => $saida->format('Y-m-d'),
            'tipo' => TipoJustificativa::EsquecimentoRegistro->value,
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => $saida->format('H:i'),
        ]);
        self::assertResponseRedirects();
        $observacao = $this->em()->getConnection()->fetchOne(
            'SELECT observacao FROM registro_ponto WHERE user_id = ? AND tenant_id = ? AND tipo = ?',
            [$this->user->getId(), $this->tenant->getId(), 'saida'],
        );
        self::assertSame(SaidaDeEsquecimento::OBSERVACAO_LANCAMENTO_DO_ADMIN, $observacao, 'o lote gravou a saída com a observação do lançamento');

        $resposta = $this->bater('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame([[
            'texto' => sprintf(SaidaDeEsquecimento::AVISO_INTERJORNADA, $saida->format('d/m'), '12:00', 48),
            'link' => null,
            'rotulo' => null,
        ]], $resposta['avisos']);
        self::assertSame(2, $this->batidasDoTipo('entrada'));
    }

    #[TestDox('R4: a saída batida há 3 horas continua recusando a entrada pela interjornada, sem gravar')]
    public function testSaidaBatidaContinuaRecusando(): void
    {
        $this->batida('entrada', new \DateTimeImmutable('-3 hours -1 minute'));
        $this->batida('saida', new \DateTimeImmutable('-3 hours'));   // fecha a entrada: conta para a interjornada

        $resposta = $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Interjornada mínima é de 11h.', (string) $resposta['message']);
        self::assertSame(1, $this->batidasDoTipo('entrada'), 'só a de antes');
    }

    #[TestDox('R4: a observação fixa sem o esquecimento abonado do mesmo dia, batida e hora não basta: continua recusando')]
    public function testObservacaoSemJustificativaNaoBasta(): void
    {
        $saida = new \DateTimeImmutable('-3 hours');
        $this->batida('entrada', $saida->modify('-1 minute'));
        $this->batida('saida', $saida, RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO);
        $outraHora = $this->esquecimentoDaSaida($saida->modify('-5 minutes'));
        $outraHora->setStatus('abonado');
        $this->em()->flush();

        $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->batidasDoTipo('entrada'), 'só a de antes');
    }

    #[TestDox('R4: o esquecimento abonado sem a observação fixa na saída (lançamento manual) não basta: continua recusando')]
    public function testJustificativaSemObservacaoNaoBasta(): void
    {
        $saida = new \DateTimeImmutable('-3 hours');
        $this->batida('entrada', $saida->modify('-1 minute'));
        $this->batida('saida', $saida, 'Lançado à mão pelo admin');
        $this->esquecimentoDaSaida($saida)->setStatus('abonado');
        $this->em()->flush();

        $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->batidasDoTipo('entrada'), 'só a de antes');
    }

    #[TestDox('A aprovação confere: pedido de saída de uma hora antes da saída batida no mesmo dia é recusado na aprovação, fica pendente, nenhuma saída nova entra e a interjornada segue recusando')]
    public function testAprovacaoRecusadaComSaidaJaBatida(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 510) {
            self::markTestSkipped('Antes das 05:10 a entrada de 5 horas atrás cairia em ontem.');
        }
        $batida = new \DateTimeImmutable('-3 hours');
        $this->batida('entrada', $batida->modify('-2 hours'));
        $this->batida('saida', $batida);
        $pedido = $this->esquecimentoDaSaida($batida->modify('-1 hour'));

        $this->aprovar($pedido);

        self::assertResponseRedirects();
        self::assertSame('pendente', $this->em()->getConnection()->fetchOne('SELECT status FROM justificativa_ponto WHERE id = ?', [$pedido->getId()]), 'o admin decide');
        self::assertSame(1, $this->batidasDoTipo('saida'), 'nenhuma saída nova');
        $this->client->followRedirect();
        self::assertStringContainsString(sprintf(BatidaJaRegistrada::MENSAGEM, 'saída'), (string) $this->client->getResponse()->getContent());

        $this->bater('entrada');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->batidasDoTipo('entrada'), 'interjornada intacta: só a de antes');
    }

    #[TestDox('O "aprovar todos" também confere (RegistrarBatidaDoEsquecimentoUseCase): com a saída já batida no dia, o pedido de saída do lote fica pendente e nenhuma batida é gravada; o outro pedido do lote é abonado')]
    public function testAprovarTodosPulaOPedidoComBatidaJaRegistrada(): void
    {
        $ontem = new \DateTimeImmutable('yesterday 18:00');
        $this->batida('entrada', $ontem->setTime(8, 0));
        $this->batida('saida', $ontem);
        $comBatida = $this->esquecimentoDaSaida($ontem->setTime(17, 0));
        $semBatida = $this->esquecimentoDaSaida($ontem->modify('-1 day')->setTime(17, 0));
        foreach ([$comBatida, $semBatida] as $pedido) {
            $pedido->setBatchId('lote-teste');
        }
        $this->em()->flush();
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $this->em()->persist(new UserTenant($admin, $this->tenant));
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $this->tenant);

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/batch/lote-teste/aprovar-todos', $this->tenant->getId(), $this->user->getId()), [
            '_token' => 'TOKEN_justificativa_aprovar_todos_lote-teste',
        ]);

        self::assertResponseRedirects();
        $status = fn (JustificativaPonto $p): string => (string) $this->em()->getConnection()->fetchOne('SELECT status FROM justificativa_ponto WHERE id = ?', [$p->getId()]);
        self::assertSame('pendente', $status($comBatida), 'o admin decide');
        self::assertSame('abonado', $status($semBatida));
        self::assertSame(2, $this->batidasDoTipo('saida'), 'só a do pedido sem batida entrou');
        $this->client->followRedirect();
        self::assertStringContainsString(sprintf(BatidaJaRegistrada::MENSAGEM, 'saída'), (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('O lançamento em lote do admin também confere: esquecimento de saída num dia que já tem saída batida é recusado, sem justificativa nem batida nova')]
    public function testLoteRecusadoComSaidaJaBatida(): void
    {
        $ontem = new \DateTimeImmutable('yesterday 12:00');
        $this->batida('saida', $ontem);
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $this->em()->persist(new UserTenant($admin, $this->tenant));
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $this->tenant);

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/nova', $this->tenant->getId(), $this->user->getId()), [
            '_token' => 'TOKEN_admin_nova_justificativa_' . $this->user->getId(),
            'datas' => $ontem->format('Y-m-d'),
            'tipo' => TipoJustificativa::EsquecimentoRegistro->value,
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => '11:00',
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM justificativa_ponto WHERE user_id = ?', [$this->user->getId()]));
        self::assertSame(1, $this->batidasDoTipo('saida'));
    }

    #[TestDox('R4: só uma saída solta (sem entrada ou retorno antes dela aqui), há 3 horas: ela não conta para a interjornada e a entrada passa')]
    public function testSaidaSoltaNaoContaParaAInterjornada(): void
    {
        $this->batida('saida', new \DateTimeImmutable('-3 hours'));   // solta

        $resposta = $this->bater('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame([], $resposta['avisos']);
    }

    #[TestDox('Saída normal: entrada, saída às 22:00 de ontem e nova entrada às 07:00 de hoje (9 h depois) continua recusada pela interjornada')]
    public function testSaidaNormalContinuaRecusando(): void
    {
        $saida = new \DateTimeImmutable('-9 hours');
        $this->batida('entrada', $saida->modify('-8 hours'));
        $this->batida('saida', $saida);

        $resposta = $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Interjornada mínima é de 11h.', (string) $resposta['message']);
    }

    #[TestDox('R4, caso central: entrada, saída real há 9 h e uma saída solta mais nova; a entrada é recusada, com a interjornada medida da saída real (faltam 2 h, não 10 h)')]
    public function testInterjornadaMedeDaSaidaRealMesmoComSaidaSoltaMaisNova(): void
    {
        $real = new \DateTimeImmutable('-9 hours');
        $this->batida('entrada', $real->modify('-8 hours'));
        $this->batida('saida', $real);
        $this->batida('saida', new \DateTimeImmutable('-1 hour'));   // solta: a última batida antes dela é a saída real

        $resposta = $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Interjornada mínima é de 11h. Aguarde mais 2h00m.', $resposta['message']);
    }

    #[TestDox('R4, sem limite: entrada, saída real há 9 h e 11 saídas soltas depois dela; a interjornada ainda mede da saída real')]
    public function testOnzeSaidasSoltasNaoEscondemASaidaReal(): void
    {
        $real = new \DateTimeImmutable('-9 hours');
        $this->batida('entrada', $real->modify('-8 hours'));
        $this->batida('saida', $real);
        for ($i = 1; $i <= 11; ++$i) {
            $this->batida('saida', $real->modify(sprintf('+%d minutes', 30 * $i)));
        }

        $resposta = $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Interjornada mínima é de 11h. Aguarde mais 2h00m.', $resposta['message']);
    }

    // ----------------------------------------------------------------- apoio

    private function aprovar(JustificativaPonto $pedido): void
    {
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $this->em()->persist(new UserTenant($admin, $this->tenant));
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $this->tenant);
        $id = (int) $pedido->getId();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/aprovar', $this->tenant->getId(), $this->user->getId(), $id), [
            '_token' => 'TOKEN_justificativa_aprovar_' . $id,
        ]);
    }

    private function esquecimentoDaSaida(\DateTimeImmutable $quando): JustificativaPonto
    {
        $pedido = new JustificativaPonto();
        $pedido->setUser($this->user);
        $pedido->setTenant($this->tenant);
        $pedido->setData(new \DateTime($quando->format('Y-m-d')));
        $pedido->setStatus('pendente');
        $pedido->setTipo(TipoJustificativa::EsquecimentoRegistro->value);
        $pedido->setTipoRegistroEsquecido('saida');
        $pedido->setHoraRegistroEsquecido(new \DateTime($quando->format('H:i:s')));
        $this->em()->persist($pedido);
        $this->em()->flush();

        return $pedido;
    }

    /** A aprovação pela rota do admin, que grava a batida de saída com a observação fixa. */
    private function aprovarPeloAdmin(JustificativaPonto $pedido): void
    {
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $vinculo = new UserTenant($admin, $this->tenant);
        $this->em()->persist($vinculo);
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $this->tenant);
        $id = (int) $pedido->getId();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/aprovar', $this->tenant->getId(), $this->user->getId(), $id), [
            '_token' => 'TOKEN_justificativa_aprovar_' . $id,
        ]);
        self::assertResponseRedirects();
        self::assertSame(1, $this->batidasDoTipo('saida'), 'a aprovação gravou a saída');
    }

    /** @return array<string, mixed> */
    private function bater(string $tipo): array
    {
        $this->logarComTenant($this->client, $this->user, $this->tenant);
        $this->client->request(
            'POST',
            '/ponto/batida',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode(['tipo' => $tipo]),
        );

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function batida(string $tipo, \DateTimeImmutable $quando, ?string $observacao = null): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($this->tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(\DateTime::createFromImmutable($quando));
        $registro->setObservacao($observacao);
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function batidasDoTipo(string $tipo): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ? AND tipo = ?',
            [$this->user->getId(), $this->tenant->getId(), $tipo],
        );
    }

    /** @param list<string> $codigos */
    private function vincular(User $user, array $codigos): void
    {
        $em = $this->em();
        $papel = new TenantRole();
        $papel->setTenant($this->tenant);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem(false);
        $em->persist($papel);
        foreach ($codigos as $codigo) {
            $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($permissao === null) {
                $permissao = new Permission();
                $permissao->setCode($codigo);
                $permissao->setDescription($codigo);
                $permissao->setGroup(explode('.', $codigo)[0]);
                $em->persist($permissao);
            }
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($papel);
            $ligacao->setPermission($permissao);
            $em->persist($ligacao);
            $papel->getTenantRolePermissions()->add($ligacao);
        }
        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório interjornada ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function user(array $papeis): User
    {
        $user = new User();
        $user->setEmail('interjornada_esquecimento_' . uniqid() . '@test.com');
        $user->setFullName('Interjornada de esquecimento');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
