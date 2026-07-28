<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\EstadoCivil;
use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Enum\TipoVinculo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 4 do ajuste 2: "Nova pessoa" dentro do objeto (aba Pessoas). Cadastra a pessoa e a vincula ao
 * objeto num passo só. Gate capacidade `resources.cobranca.gerenciar`, CSRF, anti-IDOR (404).
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoPessoaControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Nova pessoa: cadastra + vincula ao objeto e aparece na aba Pessoas')]
    public function testCriaPessoaVinculadaHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => [
                'nome' => 'Avalista Teste ZZZ',
                'tipoVinculo' => TipoVinculo::Representante->value,
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Avalista Teste ZZZ', $html);
        self::assertStringContainsString('Representante', $html);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vinculos = $em->getRepository(VinculoPessoaObjeto::class)->findBy(['objeto' => $objetoId]);
        self::assertCount(1, $vinculos);
        self::assertSame('Avalista Teste ZZZ', $vinculos[0]->getPessoa()?->getNome());
        self::assertSame(TipoVinculo::Representante, $vinculos[0]->getTipoVinculo());
    }

    #[TestDox('Nova pessoa sem nome: nada é criado')]
    public function testNomeObrigatorio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => ['nome' => '', 'tipoVinculo' => TipoVinculo::Outro->value, '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(VinculoPessoaObjeto::class)->findBy(['objeto' => $objetoId]));
    }

    #[TestDox('IDOR: nova pessoa em objeto de outro escritório → 404')]
    public function testCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId() . '/pessoas', [
            'criar_pessoa_vinculada' => ['nome' => 'X', 'tipoVinculo' => TipoVinculo::Outro->value, '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem capacidade: negado, nada é criado')]
    public function testSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => ['nome' => 'Bloqueado', 'tipoVinculo' => TipoVinculo::Outro->value, '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(VinculoPessoaObjeto::class)->findBy(['objeto' => $objetoId]));
    }

    #[TestDox('B5: nova pessoa com e-mail inválido reabre o modal com o nome digitado e o erro')]
    public function testNovaPessoaInvalidaReabreModalComErroEPreservaODigitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada');

        // Nome ok, e-mail malformado — validação de campo falha.
        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => ['nome' => 'Fulano de Tal Preservado', 'email' => 'nao-e-email', 'tipoVinculo' => TipoVinculo::Outro->value, '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $crawler = $client->followRedirect();

        self::assertSame('modalFichaPessoa', $crawler->filter('[data-modal-erro]')->attr('data-modal-erro'));
        $modalHtml = $crawler->filter('#modalFichaPessoa [data-modo="cadastro"]')->html();
        self::assertStringContainsString('E-mail inválido.', $modalHtml);
        self::assertStringContainsString('Fulano de Tal Preservado', $modalHtml, 'o nome digitado tem de sobreviver ao redirect');
    }

    // ── Cadastro completo pelo modal único (spec `cobranca-modal-unico-pessoa.md`) ────────────────

    #[TestDox('Cadastro completo grava a qualificação inteira e o primeiro endereço, telefone e e-mail')]
    public function testCadastroCompletoGravaQualificacaoEListas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => [
                'nome' => 'Devedora Completa',
                'tipoVinculo' => TipoVinculo::Representante->value,
                'cpf' => '123.456.789-00',
                'dataNascimento' => '1980-05-17',
                'estadoCivil' => EstadoCivil::Casado->value,
                'profissao' => 'Arquiteta',
                'rg' => '12.345.678-9',
                'orgaoEmissorRg' => 'SSP/RJ',
                'enderecoLogradouro' => 'Rua das Palmeiras',
                'enderecoNumero' => '250',
                'enderecoBairro' => 'Centro',
                'enderecoCidade' => 'Niterói',
                'enderecoUf' => 'RJ',
                'enderecoCep' => '24000-000',
                'telefone' => '(21) 98888-7777',
                'tipoTelefone' => TipoTelefone::WhatsApp->value,
                'email' => 'completa@example.com',
                '_token' => $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada'),
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pessoa = $em->getRepository(Pessoa::class)->findOneBy(['nome' => 'Devedora Completa']);
        self::assertNotNull($pessoa);
        self::assertSame(EstadoCivil::Casado, $pessoa->getEstadoCivil());
        self::assertSame('Arquiteta', $pessoa->getProfissao());
        self::assertSame('SSP/RJ', $pessoa->getOrgaoEmissorRg());
        self::assertSame('1980-05-17', $pessoa->getDataNascimento()?->format('Y-m-d'));

        // Cada item entra na LISTA (não só na coluna-sombra) e nasce como o atual dela — é a regra do
        // `Adicionar*PessoaUseCase`, e é o que faz o cadastro casar com o que a edição mostra depois.
        $enderecos = $em->getRepository(PessoaEndereco::class)->findBy(['pessoa' => $pessoa->getId()]);
        self::assertCount(1, $enderecos);
        self::assertSame('Rua das Palmeiras', $enderecos[0]->getLogradouro());
        self::assertTrue($enderecos[0]->isAtual());

        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $pessoa->getId()]);
        self::assertCount(1, $telefones, 'um telefone, não dois — o bridge da Pessoa não pode duplicar o item da lista');
        self::assertSame(TipoTelefone::WhatsApp, $telefones[0]->getTipo(), 'o tipo escolhido no modal tem de chegar ao banco');
        self::assertTrue($telefones[0]->isAtual());

        $emails = $em->getRepository(PessoaEmail::class)->findBy(['pessoa' => $pessoa->getId()]);
        self::assertCount(1, $emails);
        self::assertSame('completa@example.com', $emails[0]->getEmail());
        self::assertTrue($emails[0]->isAtual());
    }

    #[TestDox('Cadastro sem endereço não cria endereço nenhum (o bloco é opcional)')]
    public function testCadastroSemEnderecoNaoCriaEndereco(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => [
                'nome' => 'Sem Endereco',
                'tipoVinculo' => TipoVinculo::Outro->value,
                '_token' => $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada'),
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pessoa = $em->getRepository(Pessoa::class)->findOneBy(['nome' => 'Sem Endereco']);
        self::assertNotNull($pessoa);
        self::assertCount(0, $em->getRepository(PessoaEndereco::class)->findBy(['pessoa' => $pessoa->getId()]));
        self::assertCount(0, $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $pessoa->getId()]));
    }

    #[TestDox('Endereço pela metade recusa o cadastro inteiro — nem a pessoa é criada')]
    public function testEnderecoIncompletoRecusaOCadastro(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        // Só o logradouro: sem cidade, UF nem CEP o endereço não serve para nada — e ele ainda nasceria
        // como o `atual` da pessoa, escondendo a falta atrás de um dado pela metade.
        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => [
                'nome' => 'Meio Endereco',
                'tipoVinculo' => TipoVinculo::Outro->value,
                'enderecoLogradouro' => 'Rua Solta',
                '_token' => $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada'),
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $crawler = $client->followRedirect();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(Pessoa::class)->findOneBy(['nome' => 'Meio Endereco']), 'nada pode ter sido criado');

        // B5: o modal reabre com o digitado e diz o que falta.
        $modalHtml = $crawler->filter('#modalFichaPessoa [data-modo="cadastro"]')->html();
        self::assertStringContainsString('Informe a cidade.', $modalHtml);
        self::assertStringContainsString('Rua Solta', $modalHtml, 'o que foi digitado sobrevive ao redirect');
    }
}
