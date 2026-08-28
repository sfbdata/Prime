<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ARRANJO do modal "Adicionar Cliente" da pasta_show.
 *
 * Os formulários de Pessoa Física e de Pessoa Jurídica moram no MESMO `form`, e os oito campos
 * comuns (email, cep, endereco, cidade, estado, complemento, telefoneCelular, telefoneFixo)
 * repetem o mesmo `name` nos dois blocos. Quem impede o bloco escondido de participar da
 * validação nativa do navegador e do envio é o `disabled` do `fieldset` — esconder com `d-none`
 * NÃO tira o campo do formulário.
 *
 * O defeito que estes testes prendem: com o bloco inativo apenas escondido, o navegador barrava o
 * cadastro por causa de um campo de e-mail que o usuário não enxergava (o preenchimento automático
 * casa por `name` e preenche os dois blocos), e o PHP receberia duas chaves `email`, ficando com a
 * do bloco errado. Trocar os `fieldset` de volta por `div`, ou tirar o `disabled`, derruba aqui.
 *
 * O que o teste NÃO vê: o comportamento do navegador em si. Que campo dentro de `fieldset disabled`
 * não valida nem envia é regra do HTML — o teste prova que a marcação está no lugar, não o
 * navegador. A conferência na tela continua sendo smoke do dono.
 */
#[CoversClass(PastaController::class)]
final class PastaClienteNovoFormArranjoTest extends JusPrimeWebTestCase
{
    /** Campos que existem nos DOIS blocos e por isso dependem do `disabled` para não colidir. */
    private const CAMPOS_COMUNS = [
        'email',
        'cep',
        'endereco',
        'cidade',
        'estado',
        'complemento',
        'telefoneCelular',
        'telefoneFixo',
    ];

    /** @return array{0: EntityManagerInterface, 1: User, 2: Tenant, 3: Pasta} */
    private function criarBase(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Modal Cliente ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('modalcliente_' . uniqid() . '@test.com');
        $user->setFullName('Admin Modal Cliente');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-MC-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $pasta->setNomeAcao('Execução de Título Extrajudicial');
        $pasta->setResponsavel($user);
        $em->persist($pasta);

        return [$em, $user, $tenant, $pasta];
    }

    private function abrirModal(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('os blocos PF e PJ são fieldset e nascem DESABILITADOS, não apenas escondidos')]
    public function testBlocosNascemDesabilitados(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrirModal($client, $pasta);

        foreach (['formPF', 'formPJ'] as $bloco) {
            $no = $crawler->filter('#' . $bloco);
            self::assertCount(1, $no, "o bloco #{$bloco} existe");
            self::assertSame(
                'fieldset',
                $no->nodeName(),
                "#{$bloco} precisa ser fieldset: é o `disabled` dele que tira o bloco inteiro da validação nativa"
            );
            self::assertNotNull(
                $no->attr('disabled'),
                "#{$bloco} sem `disabled` deixa os campos repetidos do bloco escondido serem validados e enviados"
            );
        }
    }

    #[TestDox('nenhum campo de name repetido fica fora dos dois fieldsets')]
    public function testCamposComunsVivemDentroDosFieldsets(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrirModal($client, $pasta);

        foreach (self::CAMPOS_COMUNS as $campo) {
            $seletor = sprintf('#formNovoCliente [name="%s"]', $campo);
            $total   = $crawler->filter($seletor)->count();
            self::assertSame(2, $total, "`{$campo}` aparece uma vez em cada bloco (PF e PJ)");

            $dentro = $crawler->filter(sprintf('#formPF [name="%s"]', $campo))->count()
                + $crawler->filter(sprintf('#formPJ [name="%s"]', $campo))->count();
            self::assertSame(
                $total,
                $dentro,
                "`{$campo}` repetido fora dos fieldsets escapa do `disabled` e volta a colidir no envio"
            );
        }
    }

    #[TestDox('os campos que identificam o tipo de cliente ficam fora dos fieldsets e sempre são enviados')]
    public function testCamposDoEnvelopeFicamForaDosFieldsets(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrirModal($client, $pasta);

        foreach (['_token', 'tipo'] as $campo) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('#formNovoCliente > [name="%s"]', $campo)),
                "`{$campo}` é filho DIRETO do form: dentro de um fieldset desabilitado ele deixaria de ser enviado"
            );
        }
    }
}
