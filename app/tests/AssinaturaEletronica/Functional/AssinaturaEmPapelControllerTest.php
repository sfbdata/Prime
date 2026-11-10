<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\AssinaturaEmPapelController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Função provisória "Baixar para imprimir" e "Marcar como assinado em papel": quem pode (colaborador, quem criou,
 * Master só onde tem vínculo, nunca outro escritório), o estado final (nada de assinatura digital) e o PDF.
 */
#[CoversClass(AssinaturaEmPapelController::class)]
final class AssinaturaEmPapelControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
    }

    #[TestDox('quem criou o documento marca como assinado em papel: concluído, com a trilha dizendo quem confirmou')]
    public function testQuemCriouMarca(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato']);

        $this->marcar($id);

        self::assertResponseIsSuccessful();
        $detalhe = $this->detalhe($id);
        self::assertSame('concluido', $detalhe['situacao']);
        self::assertTrue($detalhe['emPapel']);
        self::assertMatchesRegularExpression('#^Assinado em papel, confirmado por Advogada Assinatura em \d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $detalhe['eventos'][0]['texto']);
        $linha = $this->listar()['documentos'][0];
        self::assertTrue($linha['emPapel']);
        self::assertSame('concluido', $linha['situacao']);
    }

    #[TestDox('outro colaborador do escritório, só com o módulo, também marca')]
    public function testColaboradorMarca(): void
    {
        [, $tenant] = $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato']);
        $this->logarComo($this->novoUsuario($tenant, 'Beatriz Colega', ['ROLE_USER'], true));

        $this->marcar($id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('confirmado por Beatriz Colega', $this->detalhe($id)['eventos'][0]['texto']);
    }

    #[TestDox('segunda marcação e documento enviado/recusado: 422; sem CSRF: recusado')]
    public function testSegundaVezERecusas(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato']);
        $this->marcar($id);

        $this->marcar($id);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, array_filter($this->detalhe($id)['eventos'], static fn (array $e): bool => str_starts_with($e['texto'], 'Assinado em papel')));

        $outro = $this->salvar(null, ['nome' => 'Recusado']);
        $this->situacao($outro, 'recusado');
        $this->marcar($outro);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/assinatura/documentos/' . $outro . '/assinado-em-papel');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('documento do outro escritório: 404, e nada muda')]
    public function testOutroEscritorio(): void
    {
        $this->entrar(true);
        $alheio = $this->salvar(null, ['nome' => 'Do outro']);
        $this->entrar(true);

        $this->marcar($alheio);

        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/assinatura/documentos/' . $alheio . '/imprimir');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('sem o módulo: 403 ao marcar e ao baixar')]
    public function testSemModulo(): void
    {
        $this->entrar(false);

        $this->marcar(1);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/assinatura/documentos/1/imprimir');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Master em escritório onde não é dono nem colaborador: recusado, sem bypass; no escritório dele marca como qualquer colaborador')]
    public function testMasterSoOndeTemVinculo(): void
    {
        [, $tenant] = $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Contrato']);
        $master = $this->novoUsuario($tenant, 'Master Sem Vinculo', ['ROLE_USER', 'ROLE_SUPER_ADMIN'], false);
        $this->logarComo($master);

        $this->marcar($id);

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403, 404], 'recusado: o Master sem vínculo não passa nem pelo escritório');
        self::assertSame('rascunho', $this->conexao()->fetchOne('SELECT situacao FROM assinatura_eletronica_documento WHERE id = ?', [$id]));

        $this->novoVinculo($master, $tenant);
        $this->logarComo($master);
        $this->marcar($id);

        self::assertResponseIsSuccessful();
        self::assertSame('concluido', $this->detalhe($id)['situacao']);
    }

    #[TestDox('baixar para imprimir devolve um PDF anexo com o texto e o espaço de assinatura')]
    public function testBaixarParaImprimir(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, [
            'nome' => 'Contrato de teste',
            'blocos' => [['k' => 't', 'x' => 'CONTRATO'], ['k' => 'p', 'x' => 'Texto inteiro do documento.']],
            'signatarios' => [['nome' => 'Ana Lima', 'papel' => 'Contratante', 'nivel' => 'simples']],
        ]);

        $this->client->request('GET', '/assinatura/documentos/' . $id . '/imprimir');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $this->client->getInternalResponse()->getContent());
    }

    private function marcar(int $id): void
    {
        $this->client->request('POST', '/assinatura/documentos/' . $id . '/assinado-em-papel', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
    }

    private function logarComo(User $usuario): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy(['user' => $usuario]);
        $tenant = $vinculo?->getTenant() ?? $this->tenantDaSessao;
        $this->logarComTenant($this->client, $usuario, $tenant);
    }

    private ?Tenant $tenantDaSessao = null;

    /** @param list<string> $papeis */
    private function novoUsuario(Tenant $tenant, string $nome, array $papeis, bool $comVinculo): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $usuario = new User();
        $usuario->setEmail('papel_' . uniqid() . '@test.com');
        $usuario->setFullName($nome);
        $usuario->setRoles($papeis);
        $usuario->setIsActive(true);
        $em->persist($usuario);
        $em->flush();
        $this->tenantDaSessao = $tenant;
        if ($comVinculo) {
            $this->novoVinculo($usuario, $tenant);
        }

        return $usuario;
    }

    private function novoVinculo(User $usuario, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // O cliente HTTP limpa o gerenciador entre as chamadas: relê as duas pontas antes de ligar.
        $usuario = $em->find(User::class, $usuario->getId());
        $tenant = $em->find(Tenant::class, $tenant->getId());
        $vinculo = new UserTenant($usuario, $tenant);
        $vinculo->setTenantRole($em->getRepository(TenantRole::class)->findOneBy(['tenant' => $tenant]));
        $em->persist($vinculo);
        $em->flush();
    }
}
