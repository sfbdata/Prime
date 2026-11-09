<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\UseCase\RegistrarTrocaDeLotacaoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaEscritorioSemProcessoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A troca de lotação pela edição do vínculo grava a versão que vale de hoje (spec `docs/specs/ponto-desenho-0811.md`,
 * E6: lotação com vigência). A lotação de outro escritório não é aceita: nada muda.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(RegistrarTrocaDeLotacaoUseCase::class)]
#[Group('ponto')]
final class TrocaDeLotacaoNaEdicaoDoVinculoTest extends JusPrimeWebTestCase
{
    use CriaEscritorioSemProcessoTrait;

    private KernelBrowser $client;
    private Tenant $tenant;
    private TenantRole $secundario;
    private User $paula;
    private User $carla;
    private Lotacao $maceio;
    private Lotacao $brasilia;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tenant = $this->novoEscritorio();
        $this->secundario = $this->novoPerfil($this->tenant, false);
        $this->paula = $this->novaPessoa($this->tenant, 'Paula Primária', $this->novoPerfil($this->tenant, true));
        $this->carla = $this->novaPessoa($this->tenant, 'Carla', $this->secundario);
        $this->maceio = $this->novaLotacao($this->tenant, 'MACEIÓ/AL');
        $this->brasilia = $this->novaLotacao($this->tenant, 'BRASÍLIA/DF');
        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->carla, 'tenant' => $this->tenant])?->setLotacao($this->maceio);
        $this->em()->flush();
    }

    #[TestDox('Trocar a lotação na edição do vínculo grava a versão de antes (desde sempre) e a de hoje')]
    public function testTrocaGravaAVersaoDeHoje(): void
    {
        $this->logarComTenant($this->client, $this->paula, $this->tenant);
        $this->postar((string) $this->brasilia->getId());

        self::assertResponseRedirects();
        self::assertSame($this->brasilia->getId(), $this->lotacaoNoVinculo());
        self::assertSame([
            ['lotacao_id' => $this->maceio->getId(), 'vale_a_partir_de' => null],
            ['lotacao_id' => $this->brasilia->getId(), 'vale_a_partir_de' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
        ], $this->versoes());
    }

    #[TestDox('A lotação de outro escritório é recusada: o vínculo e o histórico não mudam')]
    public function testLotacaoDeOutroEscritorio(): void
    {
        $deLa = $this->novaLotacao($this->novoEscritorio('Outro'), 'BRASÍLIA/DF');
        $this->logarComTenant($this->client, $this->paula, $this->tenant);
        $this->postar((string) $deLa->getId());

        self::assertResponseIsSuccessful();   // a tela volta com o erro, sem gravar
        self::assertSame($this->maceio->getId(), $this->lotacaoNoVinculo());
        self::assertSame([], $this->versoes());
    }

    private function url(): string
    {
        return sprintf('/tenant/%d/user/%d/edit-role', $this->tenant->getId(), $this->carla->getId());
    }

    /** POST direto: o CSRF do form é stateless (valor "csrf-token" + Referer da mesma origem). */
    private function postar(string $lotacao): void
    {
        $campos = [
            'codigoFuncionario' => '',
            'tenantRole'        => (string) $this->secundario->getId(),
            'lotacao'           => $lotacao,
            'newPassword'       => ['first' => '', 'second' => ''],
            '_token'            => 'csrf-token',
        ];
        $this->client->request('POST', $this->url(), ['edit_user_tenant_role' => $campos], [], ['HTTP_REFERER' => 'http://localhost' . $this->url()]);
    }

    private function novaLotacao(Tenant $tenant, string $nome): Lotacao
    {
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($tenant);
        $this->em()->persist($lotacao);
        $this->em()->flush();

        return $lotacao;
    }

    private function lotacaoNoVinculo(): ?int
    {
        $id = $this->em()->getConnection()->fetchOne(
            'SELECT lotacao_id FROM user_tenant WHERE user_id = ? AND tenant_id = ?',
            [$this->carla->getId(), $this->tenant->getId()],
        );

        return $id === null ? null : (int) $id;
    }

    /** @return list<array{lotacao_id: ?int, vale_a_partir_de: ?string}> a inicial primeiro */
    private function versoes(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT lotacao_id, vale_a_partir_de FROM lotacao_vigencia WHERE user_id = ? AND tenant_id = ? ORDER BY vale_a_partir_de NULLS FIRST',
            [$this->carla->getId(), $this->tenant->getId()],
        );
    }
}
