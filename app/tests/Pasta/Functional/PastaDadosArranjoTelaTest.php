<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
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
 * ARRANJO da aba Dados (proposta B aprovada pelo dono em 2026-08-26):
 * duas colunas — à esquerda os dados da pasta (processo, ação, responsável),
 * à direita os clientes.
 *
 * Todo assert usa combinador de FILHO DIRETO. É a única coisa que o PHPUnit
 * consegue provar sobre layout: `.arranjo > .col--esq .chip` distingue "está na
 * coluna da esquerda" de "existe em algum lugar da página", que continuaria
 * verdade com as duas colunas empilhadas erradas. Borda, fonte e cor seguem
 * invisíveis para o teste — isso é smoke do dono.
 */
#[CoversClass(PastaController::class)]
final class PastaDadosArranjoTelaTest extends JusPrimeWebTestCase
{
    /** @return array{0: EntityManagerInterface, 1: User, 2: Tenant, 3: Pasta} */
    private function criarBase(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Arranjo ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('arranjo_' . uniqid() . '@test.com');
        $user->setFullName('Admin Arranjo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-ARR-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $pasta->setNomeAcao('Execução de Título Extrajudicial');
        $pasta->setResponsavel($user);
        $em->persist($pasta);

        return [$em, $user, $tenant, $pasta];
    }

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('a aba Dados tem exatamente duas colunas, e ambas são filhas diretas do arranjo')]
    public function testDuasColunasFilhasDiretasDoArranjo(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('.pasta-dados-arranjo'), 'o arranjo de duas colunas existe');
        self::assertCount(
            1,
            $crawler->filter('.pasta-dados-arranjo > .pasta-dados-col--esq'),
            'a coluna da esquerda é filha DIRETA do arranjo, não neta'
        );
        self::assertCount(
            1,
            $crawler->filter('.pasta-dados-arranjo > .pasta-dados-col--dir'),
            'a coluna da direita é filha DIRETA do arranjo'
        );
    }

    #[TestDox('processo, ação e responsável ficam na coluna da ESQUERDA')]
    public function testTresCamposDaPastaNaColunaEsquerda(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $esq = '.pasta-dados-arranjo > .pasta-dados-col--esq ';

        foreach (['processo', 'acao', 'responsavel'] as $campo) {
            self::assertCount(
                1,
                $crawler->filter($esq . '[data-campo="' . $campo . '"]'),
                "o campo {$campo} tem de estar na coluna da esquerda"
            );
        }

        // O chip é o controle real; se ele ficar na direita, o arranjo aprovado quebrou.
        self::assertCount(
            1,
            $crawler->filter($esq . '.pasta-resp-chip'),
            'o chip do responsável mora na esquerda (foi o ajuste pedido sobre a proposta B)'
        );
        self::assertCount(
            0,
            $crawler->filter('.pasta-dados-col--dir .pasta-resp-chip'),
            'e NÃO pode aparecer também na direita'
        );
    }

    #[TestDox('os clientes ficam na coluna da DIREITA, e não na esquerda')]
    public function testClientesNaColunaDireita(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $cliente = new ClientePF();
        $cliente->setEmail('arr' . uniqid() . '@test.com');
        $cliente->setCep('80000-000');
        $cliente->setEndereco('Rua Um, 1');
        $cliente->setCidade('Curitiba');
        $cliente->setEstado('PR');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('Joao Batista Moreira');
        $cliente->setCpf('12345678901');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $em->persist($cliente);
        $pasta->addCliente($cliente);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(
            1,
            $crawler->filter('.pasta-dados-arranjo > .pasta-dados-col--dir #clientesList'),
            'a lista de clientes é da coluna da direita'
        );
        self::assertCount(
            0,
            $crawler->filter('.pasta-dados-col--esq #clientesList'),
            'clientes NÃO pode estar na esquerda — é o erro que o arranjo antigo tinha'
        );

        // O botão de vincular subiu para a linha de Clientes quando o cabeçalho
        // "As pessoas" deixou de existir. Se ele sumir, some o caminho de vincular.
        self::assertCount(
            1,
            $crawler->filter('.pasta-dados-col--dir .pasta-clientes-cab [data-bs-target="#modalAdicionarCliente"]'),
            'o botão de vincular fica no cabeçalho da lista de clientes'
        );
    }

    #[TestDox('os títulos de seção que o dono recusou não voltam')]
    public function testSemTitulosDeSecao(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $texto = $crawler->filter('.pasta-dados-arranjo')->text();

        // Recusados explicitamente em 2026-08-26: a régua entre as colunas já
        // separa, e o título repetia o que a aba "Dados da Pasta" ja diz.
        foreach (['O processo', 'As pessoas', 'Informações Gerais'] as $recusado) {
            self::assertStringNotContainsString(
                $recusado,
                $texto,
                "\"{$recusado}\" foi recusado pelo dono e não pode reaparecer"
            );
        }
    }

    #[TestDox('o arranjo não vaza da aba: timeline e arranjo continuam dentro do painel #dados')]
    public function testArranjoNaoVazaDaAba(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* Este é o assert que pega </div> sobrando. Substituir um bloco por outro
           deixa fácil esquecer as tags de fecho do antigo: o Twig continua válido,
           a suíte continua verde, e o único sintoma é o parser fechando os
           ancestrais cedo — o que joga tudo o que vem depois PARA FORA da aba.
           Aconteceu exatamente isso aqui: 3 tags órfãs, 378 aberturas contra 381
           fechamentos, e nada acusou até eu contar. */
        self::assertCount(
            1,
            $crawler->filter('#dados .pasta-dados-arranjo'),
            'o arranjo tem de estar DENTRO do painel da aba Dados'
        );
        self::assertCount(
            1,
            $crawler->filter('#dados #pasta-timeline-card'),
            'a timeline vem depois do arranjo e tem de continuar dentro da mesma aba'
        );
    }

    #[TestDox('todo rótulo da aba usa o mesmo estilo — não sobra o <p class="text-sm text-muted"> antigo')]
    public function testRotulosUnificados(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        // 3 campos da esquerda + o de Clientes na direita.
        self::assertCount(
            4,
            $crawler->filter('.pasta-dados-arranjo .pasta-rotulo'),
            'cada campo tem exatamente um rótulo no estilo novo'
        );
        self::assertCount(
            0,
            $crawler->filter('.pasta-dados-arranjo p.text-sm.text-muted'),
            'o rótulo antigo não pode conviver com o novo — eram três estilos diferentes na mesma faixa'
        );
    }
}
