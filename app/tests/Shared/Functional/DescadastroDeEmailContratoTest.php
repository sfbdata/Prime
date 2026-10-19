<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmail;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\LinkDeDescadastro;
use App\Shared\Email\Descadastro\ListaDeDescadastros;
use App\Shared\Email\Descadastro\Repository\EmailDescadastroRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * O CONTRATO do descadastro de e-mail que as outras frentes reaproveitam (campanha de vagas, e-mails
 * comerciais): as três operações do {@see DescadastroDeEmailInterface}, contra o banco, pelo serviço
 * que o container entrega para a interface.
 */
#[CoversClass(DescadastroDeEmail::class)]
#[CoversClass(LinkDeDescadastro::class)]
#[CoversClass(EmailDescadastroRepository::class)]
#[CoversClass(ListaDeDescadastros::class)]
final class DescadastroDeEmailContratoTest extends KernelTestCase
{
    private DescadastroDeEmailInterface $descadastro;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->descadastro = static::getContainer()->get(DescadastroDeEmailInterface::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Descadastro ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    #[TestDox('O container entrega a implementação para a interface')]
    public function testContainer(): void
    {
        self::assertInstanceOf(DescadastroDeEmail::class, $this->descadastro);
    }

    #[TestDox('estaDescadastrado/descadastrar: por escritório e por assunto, e-mail normalizado, idempotente')]
    public function testVerificarERegistrar(): void
    {
        $tenant = $this->tenant();
        $outro = $this->tenant();

        self::assertFalse($this->descadastro->estaDescadastrado($tenant, 'ana@exemplo.com', 'push_aviso_equipe'));

        $this->descadastro->descadastrar($tenant, '  Ana@Exemplo.COM ', 'push_aviso_equipe', 'link_do_email');
        $this->descadastro->descadastrar($tenant, 'ana@exemplo.com', 'push_aviso_equipe', 'one_click');

        self::assertTrue($this->descadastro->estaDescadastrado($tenant, 'ANA@exemplo.com', 'push_aviso_equipe'));
        self::assertFalse($this->descadastro->estaDescadastrado($tenant, 'ana@exemplo.com', 'campanha_vagas'), 'outro assunto');
        self::assertFalse($this->descadastro->estaDescadastrado($outro, 'ana@exemplo.com', 'push_aviso_equipe'), 'outro escritório');
        self::assertFalse($this->descadastro->estaDescadastrado($tenant, '', 'push_aviso_equipe'));
        $linhas = $this->em->getConnection()->fetchAllAssociative('SELECT email, origem FROM email_descadastro WHERE tenant_id = ?', [$tenant->getId()]);
        self::assertSame([['email' => 'ana@exemplo.com', 'origem' => 'link_do_email']], $linhas, 'uma linha só, a do primeiro pedido');
    }

    #[TestDox('Escopos isolados nas duas direções: escritório A não vale para B nem para a plataforma; plataforma não vale para escritório')]
    public function testEscoposIsolados(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->descadastro->descadastrar($a, 'ana@exemplo.com', 'campanha_vagas', 'link_do_email');
        self::assertTrue($this->descadastro->estaDescadastrado($a, 'ana@exemplo.com', 'campanha_vagas'));
        self::assertFalse($this->descadastro->estaDescadastrado($b, 'ana@exemplo.com', 'campanha_vagas'), 'A não vale para B');
        self::assertFalse($this->descadastro->estaDescadastrado(null, 'ana@exemplo.com', 'campanha_vagas'), 'A não vale para a plataforma');

        $this->descadastro->descadastrar(null, 'bia@exemplo.com', 'campanha_vagas', 'link_do_email');
        $this->descadastro->descadastrar(null, 'BIA@exemplo.com', 'campanha_vagas', 'one_click');
        self::assertTrue($this->descadastro->estaDescadastrado(null, 'bia@exemplo.com', 'campanha_vagas'));
        self::assertFalse($this->descadastro->estaDescadastrado($a, 'bia@exemplo.com', 'campanha_vagas'), 'a plataforma não vale para escritório');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM email_descadastro WHERE tenant_id IS NULL AND email = 'bia@exemplo.com'"), 'plataforma idempotente');
    }

    #[TestDox('Opt-out total: "todos" vale para qualquer assunto, só no mesmo escopo')]
    public function testTodos(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->descadastro->descadastrar($a, 'caio@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');
        $this->descadastro->descadastrar(null, 'dora@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');

        self::assertTrue($this->descadastro->estaDescadastrado($a, 'caio@exemplo.com', 'push_aviso_equipe'));
        self::assertTrue($this->descadastro->estaDescadastrado($a, 'caio@exemplo.com', 'qualquer_outro'));
        self::assertFalse($this->descadastro->estaDescadastrado($b, 'caio@exemplo.com', 'push_aviso_equipe'));
        self::assertFalse($this->descadastro->estaDescadastrado(null, 'caio@exemplo.com', 'push_aviso_equipe'));
        self::assertTrue($this->descadastro->estaDescadastrado(null, 'dora@exemplo.com', 'campanha_vagas'));
        self::assertFalse($this->descadastro->estaDescadastrado($a, 'dora@exemplo.com', 'campanha_vagas'));
    }

    /** @return list<array<string, mixed>> todas as linhas (histórico) do endereço no escopo, na ordem de inserção */
    private function historico(?Tenant $tenant, string $email): array
    {
        $escopo = $tenant === null ? 'tenant_id IS NULL' : 'tenant_id = ' . (int) $tenant->getId();

        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT assunto, origem, motivo, reativado_em, reativado_origem, reativado_por_id FROM email_descadastro WHERE ' . $escopo . ' AND email = ? ORDER BY id',
            [$email],
        );
    }

    private function usuario(): User
    {
        $user = new User();
        $user->setEmail('master_' . uniqid() . '@test.com');
        $user->setFullName('Master');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    #[TestDox('Motivo e origem manual_master: gravados e devolvidos na lista; vazio vira null; mais de 280 caracteres recusa')]
    public function testMotivo(): void
    {
        $tenant = $this->tenant();

        $this->descadastro->descadastrar($tenant, 'gil@exemplo.com', 'campanha_vagas', DescadastroDeEmailInterface::ORIGEM_MANUAL_MASTER, '  Pediu por telefone  ');
        $this->descadastro->descadastrar($tenant, 'hugo@exemplo.com', 'campanha_vagas', 'link_do_email', '   ');

        $historico = $this->historico($tenant, 'gil@exemplo.com');
        self::assertSame(['manual_master', 'Pediu por telefone'], [$historico[0]['origem'], $historico[0]['motivo']]);
        self::assertNull($this->historico($tenant, 'hugo@exemplo.com')[0]['motivo']);
        $this->expectException(\InvalidArgumentException::class);
        $this->descadastro->descadastrar($tenant, 'ivo@exemplo.com', 'campanha_vagas', 'link_do_email', str_repeat('a', 281));
    }

    #[TestDox('(a) reativar(x) encerra a linha ativa de x (quando, origem, quem), idempotente; descadastrar de novo cria outra linha')]
    public function testReativarAssunto(): void
    {
        $tenant = $this->tenant();
        $quem = $this->usuario();
        $this->descadastro->descadastrar($tenant, 'ana@exemplo.com', 'campanha_vagas', 'link_do_email');

        $this->descadastro->reativar($tenant, 'Ana@Exemplo.com', 'campanha_vagas', 'painel', $quem);
        $this->descadastro->reativar($tenant, 'ana@exemplo.com', 'campanha_vagas', 'painel', $quem);

        self::assertFalse($this->descadastro->estaDescadastrado($tenant, 'ana@exemplo.com', 'campanha_vagas'));
        $historico = $this->historico($tenant, 'ana@exemplo.com');
        self::assertCount(1, $historico, 'idempotente: nada a mais');
        self::assertNotNull($historico[0]['reativado_em']);
        self::assertSame(['painel', $quem->getId()], [$historico[0]['reativado_origem'], $historico[0]['reativado_por_id']]);

        $this->descadastro->descadastrar($tenant, 'ana@exemplo.com', 'campanha_vagas', 'one_click');
        self::assertTrue($this->descadastro->estaDescadastrado($tenant, 'ana@exemplo.com', 'campanha_vagas'));
        self::assertCount(2, $this->historico($tenant, 'ana@exemplo.com'), 'o histórico fica inteiro');
    }

    #[TestDox('(b) reativar("todos") encerra todas as linhas ativas do escopo, de qualquer assunto')]
    public function testReativarTodos(): void
    {
        $tenant = $this->tenant();
        foreach (['campanha_vagas', 'push_aviso_equipe', DescadastroDeEmailInterface::ASSUNTO_TODOS] as $assunto) {
            $this->descadastro->descadastrar($tenant, 'bia@exemplo.com', $assunto, 'link_do_email');
        }

        $this->descadastro->reativar($tenant, 'bia@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'painel');

        foreach (['campanha_vagas', 'push_aviso_equipe', 'outro_assunto'] as $assunto) {
            self::assertFalse($this->descadastro->estaDescadastrado($tenant, 'bia@exemplo.com', $assunto), $assunto);
        }
        self::assertSame([], array_filter($this->historico($tenant, 'bia@exemplo.com'), static fn (array $l): bool => $l['reativado_em'] === null));
        self::assertSame(0, $this->descadastro->listar($tenant, null, 1, 10)->total);
    }

    #[TestDox('(c) Com "todos" ativo, reativar(x) libera só x (exceção já reativada), idempotente; um "todos" novo volta a bloquear x')]
    public function testExcecaoSobTodos(): void
    {
        $tenant = $this->tenant();
        $this->descadastro->descadastrar($tenant, 'caio@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');

        $this->descadastro->reativar($tenant, 'caio@exemplo.com', 'campanha_vagas', 'double_opt_in');
        $this->descadastro->reativar($tenant, 'caio@exemplo.com', 'campanha_vagas', 'double_opt_in');

        self::assertFalse($this->descadastro->estaDescadastrado($tenant, 'caio@exemplo.com', 'campanha_vagas'));
        self::assertTrue($this->descadastro->estaDescadastrado($tenant, 'caio@exemplo.com', 'push_aviso_equipe'), 'o todos segue para os demais');
        $historico = $this->historico($tenant, 'caio@exemplo.com');
        self::assertCount(2, $historico, 'o todos e UMA exceção');
        self::assertSame(['campanha_vagas', 'double_opt_in', 'double_opt_in'], [$historico[1]['assunto'], $historico[1]['origem'], $historico[1]['reativado_origem']]);
        self::assertNotNull($historico[1]['reativado_em']);
        self::assertNull($historico[0]['reativado_em'], 'o todos continua ativo');

        $this->descadastro->reativar($tenant, 'caio@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'painel');
        $this->descadastro->descadastrar($tenant, 'caio@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');
        self::assertTrue($this->descadastro->estaDescadastrado($tenant, 'caio@exemplo.com', 'campanha_vagas'), 'a exceção antiga não vale para o todos novo');
    }

    #[TestDox('(d) Reativar é isolado por escopo: reativar no escritório A não libera B nem a plataforma, e vice-versa')]
    public function testReativarIsolado(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        foreach ([$a, $b, null] as $escopo) {
            $this->descadastro->descadastrar($escopo, 'dora@exemplo.com', 'campanha_vagas', 'link_do_email');
            $this->descadastro->descadastrar($escopo, 'dora@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');
        }

        $this->descadastro->reativar($a, 'dora@exemplo.com', DescadastroDeEmailInterface::ASSUNTO_TODOS, 'painel');
        $this->descadastro->reativar(null, 'dora@exemplo.com', 'campanha_vagas', 'painel');

        self::assertFalse($this->descadastro->estaDescadastrado($a, 'dora@exemplo.com', 'campanha_vagas'));
        self::assertTrue($this->descadastro->estaDescadastrado($b, 'dora@exemplo.com', 'campanha_vagas'), 'B intacto');
        self::assertTrue($this->descadastro->estaDescadastrado($b, 'dora@exemplo.com', 'outro_assunto'), 'B intacto');
        self::assertFalse($this->descadastro->estaDescadastrado(null, 'dora@exemplo.com', 'campanha_vagas'), 'exceção da plataforma');
        self::assertTrue($this->descadastro->estaDescadastrado(null, 'dora@exemplo.com', 'outro_assunto'), 'todos da plataforma intacto');
    }

    #[TestDox('Double opt-in (5d): quem se descadastrou pelo link confirma que quer voltar; reativa com a origem do fluxo e quem confirmou')]
    public function testDoubleOptIn(): void
    {
        $quem = $this->usuario();
        $this->descadastro->descadastrar(null, 'eva@exemplo.com', 'alerta_vagas', 'one_click');
        self::assertTrue($this->descadastro->estaDescadastrado(null, 'eva@exemplo.com', 'alerta_vagas'));

        $this->descadastro->reativar(null, 'eva@exemplo.com', 'alerta_vagas', 'double_opt_in', $quem);

        self::assertFalse($this->descadastro->estaDescadastrado(null, 'eva@exemplo.com', 'alerta_vagas'));
        $historico = $this->historico(null, 'eva@exemplo.com');
        self::assertSame(['one_click', 'double_opt_in', $quem->getId()], [$historico[0]['origem'], $historico[0]['reativado_origem'], $historico[0]['reativado_por_id']]);
    }

    #[TestDox('listar: só os ativos do escopo, do mais recente para o mais antigo, busca no e-mail, paginação e teto de 100 por página')]
    public function testListar(): void
    {
        $tenant = $this->tenant();
        $outro = $this->tenant();
        $this->descadastro->descadastrar($tenant, 'maria@exemplo.com', 'campanha_vagas', 'link_do_email', 'Não pedi');
        $this->descadastro->descadastrar($tenant, 'mario@gmail.com', 'campanha_vagas', 'one_click');
        $this->descadastro->descadastrar($tenant, 'zeca@exemplo.com', 'campanha_vagas', 'one_click');
        $this->descadastro->reativar($tenant, 'zeca@exemplo.com', 'campanha_vagas', 'painel');
        $this->descadastro->descadastrar($outro, 'maria@exemplo.com', 'campanha_vagas', 'one_click');

        $lista = $this->descadastro->listar($tenant, null, 1, 10);
        self::assertSame(2, $lista->total, 'o reativado e o do outro escritório ficam de fora');
        self::assertSame(['mario@gmail.com', 'maria@exemplo.com'], array_map(static fn ($i): string => $i->email, $lista->itens));
        self::assertSame(['campanha_vagas', 'link_do_email', 'Não pedi'], [$lista->itens[1]->assunto, $lista->itens[1]->origem, $lista->itens[1]->motivo]);
        self::assertInstanceOf(\DateTimeImmutable::class, $lista->itens[1]->em);

        $busca = $this->descadastro->listar($tenant, 'MARIA@', 1, 10);
        self::assertSame(1, $busca->total);
        self::assertSame(0, $this->descadastro->listar($tenant, '%', 1, 10)->total, 'curinga é texto');

        $pagina2 = $this->descadastro->listar($tenant, 'mari', 2, 1);
        self::assertSame([2, 1, 2], [$pagina2->total, \count($pagina2->itens), $pagina2->pagina]);
        self::assertSame('maria@exemplo.com', $pagina2->itens[0]->email);
        self::assertSame(100, $this->descadastro->listar($tenant, null, 0, 5000)->porPagina);
        self::assertSame(0, $this->descadastro->listar(null, 'maria', 1, 10)->total, 'a plataforma não vê o escritório');
    }

    #[TestDox('Assunto e origem fora de [a-z_]{3,40} e e-mail inválido: InvalidArgumentException')]
    public function testValidacao(): void
    {
        $tenant = $this->tenant();
        foreach ([
            fn () => $this->descadastro->estaDescadastrado($tenant, 'a@b.com', 'Push-Aviso'),
            fn () => $this->descadastro->descadastrar($tenant, 'a@b.com', 'ab', 'link_do_email'),
            fn () => $this->descadastro->descadastrar($tenant, 'a@b.com', 'push_aviso_equipe', 'origem livre'),
            fn () => $this->descadastro->descadastrar($tenant, 'nao-e-email', 'push_aviso_equipe', 'link_do_email'),
            fn () => $this->descadastro->gerarLink($tenant, 'a@b.com', str_repeat('a', 41)),
            fn () => $this->descadastro->reativar($tenant, 'a@b.com', 'push_aviso_equipe', 'Origem'),
            fn () => $this->descadastro->reativar($tenant, 'invalido', 'push_aviso_equipe', 'painel'),
        ] as $chamada) {
            try {
                $chamada();
                self::fail('deveria recusar');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('gerarLink: URL absoluta e assinada da rota pública, sem o endereço legível; lida de volta; alterada, inválida')]
    public function testLink(): void
    {
        $tenant = $this->tenant();
        $link = static::getContainer()->get(LinkDeDescadastro::class);

        $url = $this->descadastro->gerarLink($tenant, 'Ana@Exemplo.com', 'push_aviso_equipe');

        self::assertMatchesRegularExpression('#^https?://[^/]+/email/descadastro\?#', $url);
        self::assertStringContainsString('_hash=', $url);
        self::assertStringNotContainsString('exemplo', strtolower(urldecode($url)));
        $pedido = $link->ler(Request::create($url));
        self::assertNotNull($pedido);
        self::assertSame([(int) $tenant->getId(), 'ana@exemplo.com', 'push_aviso_equipe'], [$pedido->tenantId, $pedido->email, $pedido->assunto]);

        self::assertNull($link->ler(Request::create(str_replace('d=', 'd=x', $url))), 'carga alterada');

        $daPlataforma = $link->ler(Request::create($this->descadastro->gerarLink(null, 'eva@exemplo.com', 'campanha_vagas')));
        self::assertNotNull($daPlataforma);
        self::assertNull($daPlataforma->tenantId, 'link da plataforma');
        self::assertSame('e***@exemplo.com', $daPlataforma->emailMascarado());
        self::assertNull($link->ler(Request::create((string) preg_replace('/&?_hash=[^&]+/', '', $url))), 'sem assinatura');
    }
}
