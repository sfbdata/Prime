<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\DTO\ClienteResumoOutput;
use App\Cliente\Entity\ClientePF;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase as Contato;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os slots de contato da janela "Detalhes do cliente": cada slot preenchido do cadastro vira
 * uma linha editável, mesmo quando o valor se repete entre eles.
 */
#[CoversClass(ClienteResumoOutput::class)]
final class ClienteResumoOutputContatosTest extends TestCase
{
    private function cliente(?string $celular, ?string $fixo): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Maria da Silva');
        $cliente->setCpf('12345678901');
        $cliente->setEmail('maria@test.com');
        $cliente->setTelefoneCelular($celular);
        $cliente->setTelefoneFixo($fixo);

        return $cliente;
    }

    /** @return list<string> */
    private function campos(ClienteResumoOutput $resumo): array
    {
        return array_column($resumo->contatos, 'campo');
    }

    #[TestDox('fixo igual ao celular: a linha do fixo aparece (com o campo "fixo"), para poder ser editada ou removida')]
    public function testFixoIgualAoCelularAparece(): void
    {
        $resumo = ClienteResumoOutput::montar($this->cliente('(61) 99999-0000', '(61) 99999-0000'), [], null, [], '', true);

        self::assertSame([Contato::CAMPO_CELULAR, Contato::CAMPO_FIXO, Contato::CAMPO_EMAIL], $this->campos($resumo));
        self::assertSame('(61) 99999-0000', $resumo->contatos[1]['valor']);
        self::assertSame('Telefone fixo', $resumo->contatos[1]['rotulo']);
        self::assertNull($resumo->telefoneLivre, 'os dois slots de telefone estão ocupados');
    }

    #[TestDox('fixo diferente do celular: as duas linhas, como antes')]
    public function testFixoDiferenteAparece(): void
    {
        $resumo = ClienteResumoOutput::montar($this->cliente('(61) 99999-0000', '(61) 3333-0000'), [], null, [], '', true);

        self::assertSame([Contato::CAMPO_CELULAR, Contato::CAMPO_FIXO, Contato::CAMPO_EMAIL], $this->campos($resumo));
    }

    #[TestDox('fixo vazio: sem linha do fixo, e "+ Telefone" aponta para ele')]
    public function testFixoVazioNaoAparece(): void
    {
        $resumo = ClienteResumoOutput::montar($this->cliente('(61) 99999-0000', '  '), [], null, [], '', true);

        self::assertSame([Contato::CAMPO_CELULAR, Contato::CAMPO_EMAIL], $this->campos($resumo));
        self::assertSame(Contato::CAMPO_FIXO, $resumo->telefoneLivre);
    }
}
