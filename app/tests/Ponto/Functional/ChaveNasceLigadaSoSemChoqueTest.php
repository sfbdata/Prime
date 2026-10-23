<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Auth\DTO\AceitarConviteEscritorioComContaInput;
use App\Auth\UseCase\AceitarConviteEscritorioComContaUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\RegistroDePontoInicial;
use App\Service\TenantBootstrapService;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O vínculo novo nasce com a chave "Registra ponto eletrônico" ligada, salvo choque com outro escritório da pessoa
 * (spec seguranca-b-jornada, regra 5 e §3.1). A pessoa já registra ponto em B de segunda a sexta das 08:00 às 12:00.
 */
#[CoversClass(RegistroDePontoInicial::class)]
#[CoversClass(AceitarConviteEscritorioComContaUseCase::class)]
#[CoversClass(TenantBootstrapService::class)]
final class ChaveNasceLigadaSoSemChoqueTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private KernelBrowser $client;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $b = $this->escritorio('Escritório B');
        $this->pessoa = $this->pessoa('Pessoa de B');
        $this->vincular($this->pessoa, $b);
        $this->jornadaPessoal($this->pessoa, $b, [[[1, 2, 3, 4, 5], '08:00', null, null, '12:00']]);
    }

    #[TestDox('Convite aceito sem choque: a chave nasce ligada')]
    public function testConviteSemChoqueNasceLigada(): void
    {
        $novo = $this->escritorio('Escritório da tarde');
        $this->jornadaPadrao($novo, [[[1, 2, 3, 4, 5], '13:00', null, null, '17:00']]);

        $this->aceitarConvite($novo);

        self::assertTrue($this->chave($novo));
    }

    #[TestDox('Convite aceito com a padrão do escritório novo batendo com B: nasce desligada, e o admin vê o motivo ao ligar')]
    public function testConviteComChoqueNasceDesligada(): void
    {
        $novo = $this->escritorio('Escritório do dia todo');
        $this->jornadaPadrao($novo, [[[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '18:00']]);

        $this->aceitarConvite($novo);

        self::assertFalse($this->chave($novo));
        $admin = $this->pessoa('Admin');
        $this->vincular($admin, $novo, [], true);
        $this->logarComTenant($this->client, $admin, $novo);
        $url = sprintf('/tenant/%d/user/%d/registra-ponto', $novo->getId(), $this->pessoa->getId());
        $recusa = $this->chamarJson($this->client, 'POST', $url, ['registraPonto' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_CHOQUE, '08:00', '12:00')], $recusa);
    }

    #[TestDox('Escritório criado pela própria pessoa, com padrão larga gravada antes: o vínculo dela nasce desligado')]
    public function testEscritorioDaPropriaPessoaComPadraoQueBateNasceDesligado(): void
    {
        $proprio = $this->escritorio('Escritório próprio');
        $this->jornadaPadrao($proprio, [[[1, 2, 3, 4, 5], '07:00', null, null, '19:00']]);

        static::getContainer()->get(TenantBootstrapService::class)->bootstrap($proprio, $this->pessoa);

        self::assertFalse($this->chave($proprio));
    }

    private function aceitarConvite(Tenant $tenant): void
    {
        $token = bin2hex(random_bytes(16));
        $convite = new Invitation(email: (string) $this->pessoa->getEmail(), tokenHash: Invitation::hashDoToken($token), type: 'office', expiresAt: new \DateTimeImmutable('+7 days'));
        $convite->setTenant($tenant);
        $this->em()->persist($convite);
        $this->em()->flush();

        static::getContainer()->get(AceitarConviteEscritorioComContaUseCase::class)
            ->executar(new AceitarConviteEscritorioComContaInput($token, $this->pessoa));
    }

    private function chave(Tenant $tenant): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne(
            'SELECT registra_ponto FROM user_tenant WHERE user_id = ? AND tenant_id = ?',
            [$this->pessoa->getId(), $tenant->getId()],
        );
    }
}
