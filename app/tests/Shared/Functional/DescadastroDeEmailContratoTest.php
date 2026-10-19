<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmail;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\LinkDeDescadastro;
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
        self::assertNull($link->ler(Request::create((string) preg_replace('/&?_hash=[^&]+/', '', $url))), 'sem assinatura');
    }
}
