<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClienteDocumento;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Busca de cliente para vincular (`pasta_clientes_buscar`), que agora alimenta também a
 * busca inline do cartão Clientes do trilho (desenho 1.2.3, L.1357-1372 e L.3304).
 *
 * O que se prova: a MÁSCARA do documento é do servidor (busca por nome nunca devolve o CPF
 * inteiro; só a busca pelo CPF inteiro devolve), o selo completo/incompleto/já vinculado, que
 * vinculado só aparece quando pedido (contrato do modal antigo) e que cliente de outro
 * escritório não aparece.
 */
#[CoversClass(PastaController::class)]
final class PastaClientesBuscaControllerTest extends JusPrimeWebTestCase
{
    /** @return array{0: EntityManagerInterface, 1: User, 2: Tenant, 3: Pasta} */
    private function criarBase(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Busca ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('busca_' . uniqid() . '@test.com');
        $user->setFullName('Admin Busca');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-BUSCA-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $pasta->setNomeAcao('Ação de cobrança');
        $pasta->setResponsavel($user);
        $em->persist($pasta);

        return [$em, $user, $tenant, $pasta];
    }

    /** Marca só de letras: dígito no termo viraria busca por documento e casaria CPF alheio. */
    private static function letras(): string
    {
        $marca = '';
        for ($i = 0; $i < 8; $i++) {
            $marca .= chr(random_int(65, 90));
        }

        return $marca;
    }

    private static function cpfAleatorio(): string
    {
        return (string) random_int(10000000000, 99999999999);
    }

    private function criarCliente(EntityManagerInterface $em, Tenant $tenant, string $nome, string $cpf, bool $completo): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setEmail('busca' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf($cpf);
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $em->persist($cliente);

        if ($completo) {
            $cliente->setEstadoCivil('CASADO');
            $cliente->setProfissao('Professora');
            foreach ([ClienteDocumento::CATEGORIA_IDENTIFICACAO, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA] as $categoria) {
                $doc = new ClienteDocumento();
                $doc->setTenant($tenant);
                $doc->setTitulo('Anexo ' . $categoria);
                $doc->setCategoria($categoria);
                $doc->setCaminhoArquivo('arquivo_' . uniqid() . '.pdf');
                $doc->setNomeOriginal('anexo.pdf');
                $doc->setMimeType('application/pdf');
                $doc->setTamanhoBytes(1024);
                $cliente->addDocumento($doc);
                $em->persist($doc);
            }
        }

        return $cliente;
    }

    /** @return list<array<string, mixed>> */
    private function buscar(KernelBrowser $client, Pasta $pasta, string $termo, bool $incluirVinculados = false): array
    {
        $parametros = ['q' => $termo];
        if ($incluirVinculados) {
            $parametros['incluirVinculados'] = '1';
        }

        $client->request('GET', '/pasta/' . $pasta->getId() . '/clientes/buscar', $parametros, [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);
        self::assertResponseIsSuccessful();

        $dados = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);

        return $dados;
    }

    /**
     * @param list<array<string, mixed>> $dados
     * @return array<string, mixed>|null
     */
    private static function itemDe(array $dados, int $clienteId): ?array
    {
        foreach ($dados as $item) {
            if ($item['id'] === $clienteId) {
                return $item;
            }
        }

        return null;
    }

    #[TestDox('busca por nome devolve o CPF MASCARADO (***.456.789-**), nunca o documento inteiro')]
    public function testBuscaPorNomeMascaraDocumento(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $marca   = 'Zuleica' . self::letras();
        $cliente = $this->criarCliente($em, $tenant, $marca . ' Andrade', '12345678901', false);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $item = self::itemDe($this->buscar($client, $pasta, $marca), (int) $cliente->getId());

        self::assertNotNull($item, 'o cliente do escritório é achado pelo nome');
        self::assertSame($cliente->getNomeCompleto(), $item['nome'], 'o nome sai como gravado (setter grava em maiúsculas)');
        self::assertSame('***.456.789-**', $item['documento']);
        self::assertSame('CPF', $item['documentoRotulo']);
        self::assertStringNotContainsString('12345678901', (string) $client->getResponse()->getContent(), 'o CPF inteiro não sai em lugar nenhum da resposta');
    }

    #[TestDox('trecho de dígitos do CPF NÃO casa com o documento: a máscara não é derrotável por pedaços')]
    public function testBuscaPorTrechoDoCpfNaoAchaOCliente(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $cpf     = self::cpfAleatorio();
        $cliente = $this->criarCliente($em, $tenant, 'Trecho ' . self::letras(), $cpf, false);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        // O miolo que a máscara mostra (d4–d9), o começo que ela esconde e o CPF sem o último dígito.
        foreach ([substr($cpf, 3, 6), substr($cpf, 0, 3), substr($cpf, 0, 9), substr($cpf, 0, 10)] as $trecho) {
            self::assertNull(
                self::itemDe($this->buscar($client, $pasta, $trecho), (int) $cliente->getId()),
                'trecho "' . $trecho . '" não pode casar com o documento',
            );
        }

        // Recurso irmão: o CPF inteiro acha — a busca funciona, só o trecho é recusado.
        self::assertNotNull(self::itemDe($this->buscar($client, $pasta, $cpf), (int) $cliente->getId()));
    }

    #[TestDox('busca pelo CPF inteiro (com ou sem pontuação) devolve o documento completo')]
    public function testBuscaPeloCpfInteiroDevolveCompleto(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $cpf     = self::cpfAleatorio();
        $cliente = $this->criarCliente($em, $tenant, 'Inteiro ' . self::letras(), $cpf, false);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        $semPontuacao = self::itemDe($this->buscar($client, $pasta, $cpf), (int) $cliente->getId());
        self::assertNotNull($semPontuacao);
        self::assertSame($cpf, $semPontuacao['documento']);

        $formatado    = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9);
        $comPontuacao = self::itemDe($this->buscar($client, $pasta, $formatado), (int) $cliente->getId());
        self::assertNotNull($comPontuacao, 'o CPF digitado com máscara também acha o cliente');
        self::assertSame($cpf, $comPontuacao['documento']);
    }

    #[TestDox('cliente de OUTRO escritório não aparece, nem pelo nome nem pelo CPF inteiro')]
    public function testOutroEscritorioNaoAparece(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $outroTenant = new Tenant();
        $outroTenant->setName('Tenant Alheio ' . uniqid());
        $em->persist($outroTenant);

        $marca   = 'Godofredo' . self::letras();
        $cpf     = self::cpfAleatorio();
        $meu     = $this->criarCliente($em, $tenant, $marca . ' Daqui', self::cpfAleatorio(), false);
        $alheio  = $this->criarCliente($em, $outroTenant, $marca . ' Dali', $cpf, true);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        $porNome = $this->buscar($client, $pasta, $marca);
        self::assertNotNull(self::itemDe($porNome, (int) $meu->getId()), 'o do próprio escritório aparece — a busca funcionou');
        self::assertNull(self::itemDe($porNome, (int) $alheio->getId()), 'o de outro escritório não aparece pelo nome');

        $porCpf = $this->buscar($client, $pasta, $cpf);
        self::assertNull(self::itemDe($porCpf, (int) $alheio->getId()), 'nem pelo CPF inteiro');
        self::assertStringNotContainsString($cpf, (string) $client->getResponse()->getContent());
    }

    #[TestDox('selo: completo, incompleto e — só quando pedido — já vinculado')]
    public function testSeloDoResultado(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $marca      = 'Selo' . self::letras();
        $completo   = $this->criarCliente($em, $tenant, $marca . ' Completa', self::cpfAleatorio(), true);
        $incompleto = $this->criarCliente($em, $tenant, $marca . ' Incompleto', self::cpfAleatorio(), false);
        $vinculado  = $this->criarCliente($em, $tenant, $marca . ' Vinculado', self::cpfAleatorio(), true);
        $pasta->addCliente($vinculado);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        // Contrato do modal antigo: sem o parâmetro, vinculado NÃO vem.
        $semVinculados = $this->buscar($client, $pasta, $marca);
        self::assertNull(self::itemDe($semVinculados, (int) $vinculado->getId()), 'o modal antigo continua sem receber quem já está vinculado');
        self::assertCount(2, $semVinculados);

        $dados = $this->buscar($client, $pasta, $marca, incluirVinculados: true);
        self::assertCount(3, $dados);

        $itemCompleto = self::itemDe($dados, (int) $completo->getId());
        self::assertNotNull($itemCompleto);
        self::assertSame('completo', $itemCompleto['selo']);
        self::assertTrue($itemCompleto['completo']);
        self::assertFalse($itemCompleto['jaVinculado']);

        $itemIncompleto = self::itemDe($dados, (int) $incompleto->getId());
        self::assertNotNull($itemIncompleto);
        self::assertSame('incompleto', $itemIncompleto['selo']);
        self::assertFalse($itemIncompleto['completo']);

        $itemVinculado = self::itemDe($dados, (int) $vinculado->getId());
        self::assertNotNull($itemVinculado);
        self::assertSame('ja_vinculado', $itemVinculado['selo'], 'já vinculado vence o completo');
        self::assertTrue($itemVinculado['jaVinculado']);
    }

    #[TestDox('termo com menos de 2 caracteres devolve lista vazia')]
    public function testTermoCurtoDevolveVazio(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        self::assertSame([], $this->buscar($client, $pasta, 'a'));
    }
}
