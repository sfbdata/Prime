<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Pessoa;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Compat de leitura/escrita de Pessoa::getEmail()/getTelefone()/setEmail()/setTelefone() com a
 * lista (spec de qualificação §6). Cobre a ponte para código legado que ainda chama os setters
 * antigos (ex.: CriarPessoaUseCase) sem precisar ser alterado.
 */
#[CoversClass(Pessoa::class)]
final class PessoaQualificacaoCompatTest extends TestCase
{
    #[Test]
    public function setEmailComTenantCriaItemAtualNaListaEGetEmailDerivaDele(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());

        $pessoa->setEmail('fulano@example.com');

        self::assertSame('fulano@example.com', $pessoa->getEmail());
        self::assertCount(1, $pessoa->getEmails());
        self::assertTrue($pessoa->getEmails()->first()->isAtual());
    }

    #[Test]
    public function setEmailChamadoDeNovoAtualizaOItemAtualExistenteEmVezDeDuplicar(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());

        $pessoa->setEmail('primeiro@example.com');
        $pessoa->setEmail('segundo@example.com');

        self::assertSame('segundo@example.com', $pessoa->getEmail());
        // Não duplica: atualiza o mesmo item atual.
        self::assertCount(1, $pessoa->getEmails());
    }

    #[Test]
    public function getEmailCaiParaColunaSombraQuandoListaEstaVazia(): void
    {
        // Sem tenant atribuído (pessoa ainda não persistida/vinculada a um escritório): o bridge
        // não cria item de lista, só grava a coluna-sombra — comportamento de fallback real.
        $pessoa = new Pessoa();

        $pessoa->setEmail('sombra@example.com');

        self::assertCount(0, $pessoa->getEmails());
        self::assertSame('sombra@example.com', $pessoa->getEmail());
    }

    #[Test]
    public function setTelefoneComTenantCriaItemAtualNaListaEGetTelefoneDerivaDele(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());

        $pessoa->setTelefone('(41) 99999-0000');

        self::assertSame('(41) 99999-0000', $pessoa->getTelefone());
        self::assertCount(1, $pessoa->getTelefones());
        self::assertTrue($pessoa->getTelefones()->first()->isAtual());
    }

    #[Test]
    public function setTelefoneChamadoDeNovoAtualizaOItemAtualExistenteEmVezDeDuplicar(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());

        $pessoa->setTelefone('(41) 99999-0000');
        $pessoa->setTelefone('(41) 98888-1111');

        self::assertSame('(41) 98888-1111', $pessoa->getTelefone());
        self::assertCount(1, $pessoa->getTelefones());
    }

    #[Test]
    public function getTelefoneCaiParaColunaSombraQuandoListaEstaVazia(): void
    {
        $pessoa = new Pessoa();

        $pessoa->setTelefone('(41) 90000-0000');

        self::assertCount(0, $pessoa->getTelefones());
        self::assertSame('(41) 90000-0000', $pessoa->getTelefone());
    }

    #[Test]
    public function getEmailETelefoneSaoNullQuandoNuncaAtribuidos(): void
    {
        $pessoa = new Pessoa();

        self::assertNull($pessoa->getEmail());
        self::assertNull($pessoa->getTelefone());
    }
}
