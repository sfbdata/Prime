<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Contract\ConfiguracaoDaPlataforma;
use App\Shared\Email\IdentidadeDeEmail;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

#[CoversClass(IdentidadeDeEmail::class)]
final class IdentidadeDeEmailTest extends TestCase
{
    #[TestDox('Aplica From, Reply-To e prefixo institucional')]
    public function testAplicaIdentidadeCompleta(): void
    {
        $email = new Email();
        $identidade = new IdentidadeDeEmail(
            'BlueJus <nao-responda@bluejus.test>',
            'infra@bluejus.test',
        );

        $identidade->aplicar($email, 'Mensagem automática');

        self::assertSame('nao-responda@bluejus.test', $email->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $email->getFrom()[0]->getName());
        self::assertSame('infra@bluejus.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame('[BlueJus] Mensagem automática', $email->getSubject());
    }

    #[TestDox('Reply-To vazio é omitido e prefixo existente não é duplicado')]
    public function testConfiguracaoOpcionalSegura(): void
    {
        $email = new Email();
        $identidade = new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', '  ');

        $identidade->aplicar($email, ' [BlueJus] Mensagem automática ');

        self::assertSame([], $email->getReplyTo());
        self::assertSame('[BlueJus] Mensagem automática', $email->getSubject());
    }

    #[TestDox('MAILER_FROM inválido falha sem expor o valor configurado')]
    public function testFromInvalidoFalhaDeFormaControlada(): void
    {
        $valorInvalido = 'segredo-invalido-sem-arroba';

        try {
            new IdentidadeDeEmail($valorInvalido, 'infra@bluejus.test');
            self::fail('A configuração inválida deveria falhar.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Configuração de e-mail inválida em MAILER_FROM.', $e->getMessage());
            self::assertStringNotContainsString($valorInvalido, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[TestDox('MAILER_REPLY_TO inválido falha sem expor o valor configurado')]
    public function testReplyToInvalidoFalhaDeFormaControlada(): void
    {
        $valorInvalido = 'outro-segredo-invalido';

        try {
            new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', $valorInvalido);
            self::fail('A configuração inválida deveria falhar.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Configuração de e-mail inválida em MAILER_REPLY_TO.', $e->getMessage());
            self::assertStringNotContainsString($valorInvalido, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[TestDox('Preserva nome de exibição personalizado configurado em MAILER_FROM')]
    public function testPreservaNomeDeExibicaoPersonalizado(): void
    {
        $email = new Email();
        $identidade = new IdentidadeDeEmail('Equipe BlueJus <equipe@bluejus.test>', '');

        $identidade->aplicar($email, 'Mensagem');

        self::assertSame('Equipe BlueJus', $email->getFrom()[0]->getName());
        self::assertSame('equipe@bluejus.test', $email->getFrom()[0]->getAddress());
    }

    private const ENV_FROM = 'BlueJus <nao-responda@bluejus.com.br>';

    private function aplicado(ConfiguracaoDaPlataforma $config, string $assunto = 'Confirme seu cadastro'): Email
    {
        $email = new Email();
        (new IdentidadeDeEmail(self::ENV_FROM, 'infra@bluejus.test', $config))->aplicar($email, $assunto);

        return $email;
    }

    #[TestDox('Configuração vazia ou só com os padrões: From e prefixo são exatamente os de hoje')]
    public function testConfiguracaoPadraoNaoMudaNada(): void
    {
        foreach ([[], ['nome' => 'BlueJus', 'remetente' => 'nao-responda@bluejus.com.br']] as $valores) {
            $email = $this->aplicado(new ConfiguracaoDaPlataformaFalsa($valores));

            self::assertSame('"BlueJus" <nao-responda@bluejus.com.br>', $email->getFrom()[0]->toString());
            self::assertSame('[BlueJus] Confirme seu cadastro', $email->getSubject());
            self::assertSame('infra@bluejus.test', $email->getReplyTo()[0]->getAddress());
        }
        $identidade = new IdentidadeDeEmail(self::ENV_FROM, '', new ConfiguracaoDaPlataformaFalsa());
        self::assertSame('BlueJus <nao-responda@bluejus.com.br>', $identidade->remetente());
    }

    #[TestDox('Nome e remetente configurados mudam o From, o prefixo e o remetente(); o Reply-To segue o ambiente')]
    public function testConfiguradosMudam(): void
    {
        $config = new ConfiguracaoDaPlataformaFalsa(['nome' => 'Acme Jurídico', 'remetente' => 'avisos@acme.test']);

        $email = $this->aplicado($config);

        self::assertSame('Acme Jurídico', $email->getFrom()[0]->getName());
        self::assertSame('avisos@acme.test', $email->getFrom()[0]->getAddress());
        self::assertSame('[Acme Jurídico] Confirme seu cadastro', $email->getSubject());
        self::assertSame('infra@bluejus.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame('Acme Jurídico <avisos@acme.test>', (new IdentidadeDeEmail(self::ENV_FROM, '', $config))->remetente());
    }

    #[TestDox('Assunto que já traz o prefixo antigo ou o novo não empilha dois prefixos')]
    public function testNaoEmpilhaPrefixo(): void
    {
        $config = new ConfiguracaoDaPlataformaFalsa(['nome' => 'Acme']);

        self::assertSame('[Acme] Aviso', $this->aplicado($config, '[BlueJus] Aviso')->getSubject());
        self::assertSame('[Acme] Aviso', $this->aplicado($config, '[Acme] Aviso')->getSubject());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidos(): iterable
    {
        yield 'nome com quebra de linha' => [['nome' => "Acme\r\nBcc: x@y.test"]];
        yield 'nome com colchete' => [['nome' => 'A] B']];
        yield 'nome com aspas' => [['nome' => 'A"B']];
        yield 'nome longo demais' => [['nome' => str_repeat('a', 61)]];
        yield 'nome em branco' => [['nome' => '   ']];
        yield 'remetente sem arroba' => [['remetente' => 'sem-arroba']];
        yield 'remetente com nome' => [['remetente' => 'X <x@y.test>']];
        yield 'remetente longo demais' => [['remetente' => str_repeat('a', 175) . '@y.test']];
    }

    /** @param array<string, string> $valores */
    #[DataProvider('invalidos')]
    #[TestDox('Valor inválido cai no padrão do ambiente, sem lançar: $_dataName')]
    public function testInvalidoCaiNoPadrao(array $valores): void
    {
        $email = $this->aplicado(new ConfiguracaoDaPlataformaFalsa($valores));

        self::assertSame('"BlueJus" <nao-responda@bluejus.com.br>', $email->getFrom()[0]->toString());
        self::assertSame('[BlueJus] Confirme seu cadastro', $email->getSubject());
    }

    #[TestDox('Leitor que lança não derruba o envio: vale o ambiente')]
    public function testLeitorQueLancaCaiNoPadrao(): void
    {
        $leitor = new class implements ConfiguracaoDaPlataforma {
            public function valor(string $chave): string
            {
                throw new \RuntimeException('banco fora do ar');
            }
        };

        $email = $this->aplicado($leitor);

        self::assertSame('"BlueJus" <nao-responda@bluejus.com.br>', $email->getFrom()[0]->toString());
        self::assertSame('[BlueJus] Confirme seu cadastro', $email->getSubject());
    }

    #[TestDox('A configuração é lida a cada chamada, não no construtor')]
    public function testLePorChamada(): void
    {
        $config = new ConfiguracaoDaPlataformaFalsa();
        $identidade = new IdentidadeDeEmail(self::ENV_FROM, '', $config);
        $antes = $identidade->aplicar(new Email(), 'A');

        $config->definir('nome', 'Depois');
        $depois = $identidade->aplicar(new Email(), 'A');

        self::assertSame('[BlueJus] A', $antes->getSubject());
        self::assertSame('[Depois] A', $depois->getSubject());
    }
}
