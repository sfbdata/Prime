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
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\UseCase\ListarPendenciasDeRegistroUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A lista de pendências da tela do ponto (spec `docs/specs/ponto-esquecimento-nao-trava.md` R5, risco ALTO), com o
 * EntityManager real: a pessoa está em X (escritório da sessão) e tem vínculo ativo, com o módulo de ponto, em Y.
 * Também o caso de R2 com o esquecimento de dia anterior, o nome de Y fora da tela do admin de X e o pedido de
 * esquecimento criado só no escritório da sessão.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(ListarPendenciasDeRegistroUseCase::class)]
#[CoversClass(BatidaJaRegistrada::class)]
final class PendenciasDeRegistroTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private Tenant $x;
    private Tenant $y;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->x = $this->tenant('Escritório X');
        $this->y = $this->tenant('Escritório Y');
        $this->user = $this->user(['ROLE_USER']);
        $this->vincular($this->user, $this->x, ['modules.ponto.view']);
        $this->vincular($this->user, $this->y, ['modules.ponto.view']);
    }

    #[TestDox('Mostra as pendências deste escritório (link que abre o modal de esquecimento na folha do mês) e as dos outros (com o nome e a orientação de trocar); hoje fica fora')]
    public function testMostraAsDesteEscritorioEAsDosOutros(): void
    {
        $diaX = $this->diaUtilAtras(3);
        $diaY = $this->diaUtilAtras(4);
        $this->batida($this->x, 'entrada', $diaX->setTime(8, 0));
        $this->batida($this->y, 'entrada', $diaY->setTime(9, 0));
        $this->batida($this->y, 'repouso', $diaY->setTime(12, 0));
        $this->batida($this->y, 'saida', $diaY->setTime(18, 0));
        $this->batida($this->x, 'entrada', new \DateTimeImmutable('today 00:00:01'));   // hoje: jornada em curso

        $lista = $this->pendencias();

        $deX = $lista->filter(sprintf('li[data-pendencia="%s:saida"][data-pendencia-escritorio="%d"]', $diaX->format('Y-m-d'), $this->x->getId()));
        self::assertCount(1, $deX);
        $href = (string) $deX->filter('a')->attr('href');
        self::assertStringContainsString('competencia=' . $diaX->format('Y-m'), $href);
        self::assertStringContainsString('esquecimento=' . $diaX->format('Y-m-d') . ':saida', $href);
        self::assertStringContainsString('Falta a saída', $deX->text());

        $deY = $lista->filter(sprintf('li[data-pendencia="%s:retorno"][data-pendencia-escritorio="%d"]', $diaY->format('Y-m-d'), $this->y->getId()));
        self::assertCount(1, $deY, 'metade do intervalo: falta o retorno');
        self::assertStringContainsString((string) $this->y->getName(), $deY->text());
        self::assertStringContainsString('Troque para esse escritório', $deY->text());
        self::assertSame('/escritorio/selecionar', $deY->filter('a')->attr('href'));
        self::assertStringNotContainsString('esquecimento=', (string) $deY->filter('a')->attr('href'), 'o pedido de Y não sai daqui');

        self::assertCount(2, $lista->filter('li'), 'nada de hoje, nada além das duas');
    }

    #[TestDox('Some quando há pedido pendente ou abonado da mesma batida no mesmo escritório; o pedido de outro escritório não esconde')]
    public function testSomeComPedidoPendenteOuAbonado(): void
    {
        $diaX = $this->diaUtilAtras(3);
        $diaY = $this->diaUtilAtras(5);
        $this->batida($this->x, 'entrada', $diaX->setTime(8, 0));
        $this->batida($this->y, 'entrada', $diaY->setTime(8, 0));
        $this->esquecimento($this->x, $diaX, 'saida', 'pendente');
        $this->esquecimento($this->y, $diaY, 'saida', 'abonado');

        self::assertCount(0, $this->crawlerDoPonto()->filter('#pendencias-ponto'), 'pendente em X e abonado em Y: lista vazia, nem o cartão aparece');

        $outroDia = $this->diaUtilAtras(8);
        $this->batida($this->y, 'entrada', $outroDia->setTime(8, 0));
        $this->esquecimento($this->x, $outroDia, 'saida', 'pendente');   // pedido feito no escritório errado

        $lista = $this->pendencias();
        self::assertCount(1, $lista->filter(sprintf('li[data-pendencia="%s:saida"][data-pendencia-escritorio="%d"]', $outroDia->format('Y-m-d'), $this->y->getId())));
        self::assertCount(1, $lista->filter('li'));
    }

    #[TestDox('Não mostra escritório sem vínculo ativo, nem escritório sem o módulo de ponto')]
    public function testSemVinculoAtivoNaoMostra(): void
    {
        $dia = $this->diaUtilAtras(3);
        $this->batida($this->y, 'entrada', $dia->setTime(8, 0));
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->y->getId()]);
        $z = $this->tenant('Escritório Z');
        $this->vincular($this->user, $z, ['modules.clientes.view']);
        $this->batida($z, 'entrada', $dia->setTime(8, 0));

        $html = $this->htmlDoPonto();

        self::assertStringNotContainsString('id="pendencias-ponto"', $html);
        self::assertStringNotContainsString((string) $this->y->getName(), $html);
        self::assertStringNotContainsString((string) $z->getName(), $html);
    }

    #[TestDox('Respeita a janela de 30 dias: 31 dias atrás fica fora; 28 dias atrás entra')]
    public function testJanelaDe30Dias(): void
    {
        $fora = $this->diaUtilAtras(31);
        $dentro = $this->diaUtilAtras(28, true);
        $this->batida($this->x, 'entrada', $fora->setTime(8, 0));
        $this->batida($this->x, 'entrada', $dentro->setTime(8, 0));

        $lista = $this->pendencias();

        self::assertCount(1, $lista->filter('li'));
        self::assertCount(1, $lista->filter(sprintf('li[data-pendencia="%s:saida"]', $dentro->format('Y-m-d'))));
    }

    #[TestDox('R2: com a entrada esquecida em Y num dia anterior, a entrada em X passa sem aviso de ponto aberto, e a pendência de Y aparece na lista')]
    public function testEsquecimentoDeDiaAnteriorEmYNaoTravaEApareceNaLista(): void
    {
        $this->homeOfficeTodoDia($this->x);
        $dia = $this->diaUtilAtras(1);
        $this->batida($this->y, 'entrada', $dia->setTime(8, 0));

        $this->logarComTenant($this->client, $this->user, $this->x);
        $this->client->request('POST', '/ponto/batida', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{"tipo":"entrada"}');
        $resposta = (array) json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame([], $resposta['avisos']);
        self::assertCount(1, $this->pendencias()->filter(sprintf('li[data-pendencia="%s:saida"][data-pendencia-escritorio="%d"]', $dia->format('Y-m-d'), $this->y->getId())));
    }

    #[TestDox('O admin de X, na ficha e na folha do colaborador, não vê o nome de Y nem a lista de pendências')]
    public function testAdminDeXNaoVeONomeDeY(): void
    {
        $dia = $this->diaUtilAtras(3);
        $this->batida($this->y, 'entrada', $dia->setTime(8, 0));
        $this->batida($this->x, 'entrada', $dia->setTime(8, 0));
        self::assertStringContainsString((string) $this->y->getName(), $this->htmlDoPonto(), 'controle: a própria pessoa vê');

        $admin = $this->user(['ROLE_USER']);
        $this->vincular($admin, $this->x, ['admin.users.manage']);
        $this->logarComTenant($this->client, $admin, $this->x);
        foreach (['ponto', 'justificativas'] as $aba) {
            $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?tab=%s&competencia=%s', $this->x->getId(), $this->user->getId(), $aba, $dia->format('Y-m')));
            self::assertResponseIsSuccessful();
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString((string) $this->y->getName(), $html, $aba);
            self::assertStringNotContainsString('id="pendencias-ponto"', $html, $aba);
        }
    }

    #[TestDox('Multi-tenant: o pedido de esquecimento enviado em X é criado só em X, mesmo para o dia da pendência de Y')]
    public function testPedidoCriadoSoNoEscritorioCorrente(): void
    {
        $dia = $this->diaUtilAtras(3);
        $this->batida($this->y, 'entrada', $dia->setTime(8, 0));
        $this->logarComTenant($this->client, $this->user, $this->x);

        $this->client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => [
                '_token' => 'TOKEN_justificativa_ponto',
                'datas' => $dia->format('Y-m-d'),
                'tipo' => TipoJustificativa::EsquecimentoRegistro->value,
                'tipoRegistroEsquecido' => 'saida',
                'horaRegistroEsquecido' => '18:00',
            ],
        ]);

        self::assertResponseRedirects();
        $porEscritorio = $this->em()->getConnection()->fetchAllKeyValue(
            'SELECT tenant_id, COUNT(*) FROM justificativa_ponto WHERE user_id = ? GROUP BY tenant_id',
            [$this->user->getId()],
        );
        self::assertSame([(int) $this->x->getId() => 1], array_map('intval', $porEscritorio));
        self::assertCount(1, $this->pendencias()->filter(sprintf('li[data-pendencia-escritorio="%d"]', $this->y->getId())), 'a pendência de Y continua');
    }

    #[TestDox('O pedido de esquecimento só vale para a batida que falta: com a saída batida no dia neste escritório, o pedido de saída é recusado; a saída batida em outro escritório não recusa')]
    public function testPedidoDeBatidaJaRegistradaERecusado(): void
    {
        $dia = $this->diaUtilAtras(3);
        $this->batida($this->x, 'entrada', $dia->setTime(8, 0));
        $this->batida($this->x, 'saida', $dia->setTime(22, 0));
        $outro = $this->diaUtilAtras(8);
        $this->batida($this->x, 'entrada', $outro->setTime(8, 0));
        $this->batida($this->y, 'saida', $outro->setTime(18, 0));   // a saída de Y não é deste escritório
        $this->logarComTenant($this->client, $this->user, $this->x);

        $this->pedirEsquecimento($outro, 'saida', '18:00');
        self::assertSame(1, $this->pedidosEm($this->x), 'controle: a saída de Y não recusa o pedido em X');

        $this->pedirEsquecimento($dia, 'saida', '22:05');

        self::assertResponseRedirects();
        self::assertSame(1, $this->pedidosEm($this->x), 'nada novo é criado');
        $this->client->followRedirect();
        self::assertStringContainsString(sprintf(BatidaJaRegistrada::MENSAGEM, 'saída'), (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('A edição do pedido não troca para uma batida que já existe no dia neste escritório')]
    public function testEdicaoNaoTrocaParaBatidaJaRegistrada(): void
    {
        $dia = $this->diaUtilAtras(3);
        $this->batida($this->x, 'saida', $dia->setTime(18, 0));
        $this->esquecimento($this->x, $dia, 'entrada', 'pendente');
        $id = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM justificativa_ponto WHERE user_id = ?', [$this->user->getId()]);
        $this->logarComTenant($this->client, $this->user, $this->x);

        $this->client->request('POST', sprintf('/ponto/justificativa/%d/editar', $id), [
            '_token' => 'TOKEN_editar_justificativa_' . $id,
            'tipo' => TipoJustificativa::EsquecimentoRegistro->value,
            'tipoRegistroEsquecido' => 'saida',
            'horaRegistroEsquecido' => '18:05',
        ]);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame('entrada', $this->em()->getConnection()->fetchOne('SELECT tipo_registro_esquecido FROM justificativa_ponto WHERE id = ?', [$id]));
        $this->client->followRedirect();
        self::assertStringContainsString(sprintf(BatidaJaRegistrada::MENSAGEM, 'saída'), (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('R7: sem a chave do ponto em X e sem batida em X nos últimos 30 dias, a pessoa não vê pendência nem alerta de horário em X; com uma batida em X, volta a ver')]
    public function testPublicoDosAvisos(): void
    {
        $dia = $this->diaUtilAtras(3);
        $this->batida($this->y, 'entrada', $dia->setTime(8, 0));
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET registra_ponto = false WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->x->getId()]);
        $this->em()->clear();

        self::assertStringNotContainsString('id="pendencias-ponto"', $this->htmlDoPonto(), 'fora do público: nada');
        $this->client->request('GET', '/ponto/alerta-horario');
        self::assertSame(['alertar' => false], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->user = $this->em()->find(User::class, $this->user->getId()) ?? $this->user;
        $this->x = $this->em()->find(Tenant::class, $this->x->getId()) ?? $this->x;
        $this->y = $this->em()->find(Tenant::class, $this->y->getId()) ?? $this->y;
        $this->batida($this->x, 'entrada', $this->diaUtilAtras(10)->setTime(8, 0));
        $this->batida($this->x, 'saida', $this->diaUtilAtras(10)->setTime(17, 0));

        self::assertCount(1, $this->pendencias()->filter(sprintf('li[data-pendencia-escritorio="%d"]', $this->y->getId())), 'bateu em X nos últimos 30 dias: vê');
    }

    #[TestDox('R8: na ficha do colaborador, o admin de X vê o sinal no dia em que houve batida em Y com o ponto de X aberto, sem o nome de Y; o dia com a batida de Y depois da saída de X não tem sinal')]
    public function testAdminDeXVeOSinalDeRegistroEmOutroEscritorio(): void
    {
        $comSinal = $this->diaUtilAtras(3);
        $semSinal = $this->diaUtilAtras(8);
        $this->batida($this->x, 'entrada', $comSinal->setTime(8, 0));
        $this->batida($this->y, 'entrada', $comSinal->setTime(10, 0));   // com o ponto de X aberto
        $this->batida($this->x, 'saida', $comSinal->setTime(17, 0));
        if ($semSinal->format('Y-m') === $comSinal->format('Y-m')) {   // o controle negativo, quando cabe na competência
            $this->batida($this->x, 'entrada', $semSinal->setTime(8, 0));
            $this->batida($this->x, 'saida', $semSinal->setTime(12, 0));
            $this->batida($this->y, 'entrada', $semSinal->setTime(13, 0));   // depois da saída de X
        }

        $admin = $this->user(['ROLE_USER']);
        $this->vincular($admin, $this->x, ['admin.users.manage']);
        $this->logarComTenant($this->client, $admin, $this->x);
        $crawler = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?tab=ponto&competencia=%s', $this->x->getId(), $this->user->getId(), $comSinal->format('Y-m')));

        self::assertResponseIsSuccessful();
        $sinal = $crawler->filter(sprintf('tr[data-chave-dia="%s"] [data-registro-em-outro-escritorio]', $comSinal->format('Y-m-d')));
        self::assertCount(1, $sinal);
        self::assertStringContainsString('Houve registro em outro escritório neste período', $sinal->text());
        self::assertCount(1, $crawler->filter('[data-registro-em-outro-escritorio]'), 'só o dia com o ponto de X aberto');
        self::assertStringNotContainsString((string) $this->y->getName(), (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('R9: o cartão diário sai para o público com pendência: a mais antiga deste escritório, com o link do modal; sem pendência aqui, a de outro escritório com a orientação de trocar')]
    public function testCartaoDiarioParaOPublico(): void
    {
        $antiga = $this->diaUtilAtras(8);
        $recente = $this->diaUtilAtras(3);
        $this->batida($this->y, 'entrada', $antiga->setTime(8, 0));
        $this->batida($this->x, 'entrada', $recente->setTime(8, 0));

        self::assertStringContainsString('id="bjPontoPendente"', $this->htmlDoPonto(), 'o parcial global entra para o público');
        $cartao = $this->cartao();
        self::assertSame('Registro de ponto pendente', $cartao['titulo']);
        self::assertSame('PONTO · 2 pendências', $cartao['rotulo']);
        self::assertSame($recente->format('d/m/Y') . ': Falta a saída', $cartao['linha']);
        self::assertSame('/ponto/?competencia=' . $recente->format('Y-m') . '&esquecimento=' . $recente->format('Y-m-d') . ':saida', $cartao['link']);
        self::assertNull($cartao['orientacao']);

        $this->em()->getConnection()->executeStatement('DELETE FROM registro_ponto WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->x->getId()]);
        $cartao = $this->cartao();
        self::assertSame('/escritorio/selecionar', $cartao['link']);
        self::assertStringContainsString((string) $this->y->getName(), (string) $cartao['orientacao'], 'só a própria pessoa vê o nome');
    }

    #[TestDox('R9: o cartão diário não sai para quem não bate ponto (sem a chave e sem batida aqui nos últimos 30 dias), nem o parcial entra na página')]
    public function testCartaoDiarioNaoSaiForaDoPublico(): void
    {
        $this->batida($this->y, 'entrada', $this->diaUtilAtras(3)->setTime(8, 0));
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET registra_ponto = false WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->x->getId()]);
        $this->em()->clear();

        self::assertStringNotContainsString('id="bjPontoPendente"', $this->htmlDoPonto());
        self::assertNull($this->cartao());
    }

    /** @return array<string, ?string>|null */
    private function cartao(): ?array
    {
        $this->logarComTenant($this->client, $this->user, $this->x);
        $this->client->request('GET', '/ponto/pendencia-do-dia');
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['cartao'];
    }

    // ----------------------------------------------------------------- apoio

    private function pedirEsquecimento(\DateTimeImmutable $dia, string $batida, string $hora): void
    {
        $this->client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => [
                '_token' => 'TOKEN_justificativa_ponto',
                'datas' => $dia->format('Y-m-d'),
                'tipo' => TipoJustificativa::EsquecimentoRegistro->value,
                'tipoRegistroEsquecido' => $batida,
                'horaRegistroEsquecido' => $hora,
            ],
        ]);
    }

    private function pedidosEm(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM justificativa_ponto WHERE user_id = ? AND tenant_id = ?',
            [$this->user->getId(), $tenant->getId()],
        );
    }

    private function pendencias(): Crawler
    {
        $lista = $this->crawlerDoPonto()->filter('#pendencias-ponto');
        self::assertCount(1, $lista, 'o cartão de pendências aparece');

        return $lista->filter('ul');
    }

    private function crawlerDoPonto(): Crawler
    {
        $this->logarComTenant($this->client, $this->user, $this->x);
        $crawler = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function htmlDoPonto(): string
    {
        $this->crawlerDoPonto();

        return (string) $this->client->getResponse()->getContent();
    }

    /** Um dia útil `$dias` atrás; caindo no fim de semana, recua (ou avança, com `$avancar`) até um dia útil. */
    private function diaUtilAtras(int $dias, bool $avancar = false): \DateTimeImmutable
    {
        $dia = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $dias));
        while ((int) $dia->format('N') >= 6) {
            $dia = $dia->modify($avancar ? '+1 day' : '-1 day');
        }

        return $dia;
    }

    private function batida(Tenant $tenant, string $tipo, \DateTimeImmutable $quando): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(\DateTime::createFromImmutable($quando));
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function esquecimento(Tenant $tenant, \DateTimeImmutable $dia, string $batida, string $status): void
    {
        $pedido = new JustificativaPonto();
        $pedido->setUser($this->user);
        $pedido->setTenant($tenant);
        $pedido->setData(new \DateTime($dia->format('Y-m-d')));
        $pedido->setStatus($status);
        $pedido->setTipo(TipoJustificativa::EsquecimentoRegistro->value);
        $pedido->setTipoRegistroEsquecido($batida);
        $pedido->setHoraRegistroEsquecido(new \DateTime('18:00:00'));
        $this->em()->persist($pedido);
        $this->em()->flush();
    }

    private function homeOfficeTodoDia(Tenant $tenant): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($this->user);
        $config->setTenant($tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    /** @param list<string> $codigos */
    private function vincular(User $user, Tenant $tenant, array $codigos): void
    {
        $em = $this->em();
        $papel = new TenantRole();
        $papel->setTenant($tenant);
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
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function user(array $papeis): User
    {
        $user = new User();
        $user->setEmail('pendencias_ponto_' . uniqid() . '@test.com');
        $user->setFullName('Pendências do ponto');
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
