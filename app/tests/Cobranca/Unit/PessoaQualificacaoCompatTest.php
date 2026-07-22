<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaTelefone;
use App\Entity\Tenant\Tenant;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Compat de leitura/escrita de Pessoa::getEmail()/getTelefone()/setEmail()/setTelefone() com a
 * lista (spec de qualificação §5.4/§6). Cobre a ponte para código legado que ainda chama os
 * setters antigos (ex.: CriarPessoaUseCase) sem precisar ser alterado, e o achado da revisão da
 * branch: a sombra agora é PREFERIDA na leitura (escalar, sem iterar a coleção — evita N+1) e só
 * cai para a derivação-da-lista quando a sombra é null (dado legado pré-transição).
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

    /**
     * Achado da revisão da branch: com a sombra ausente (dado legado que só passou por um
     * Adicionar/MarcarAtual direto na lista, sem o bridge setEmail()), getEmail() ainda cai para
     * a derivação do item atual — não fica null.
     */
    #[Test]
    public function getEmailDerivaDaListaQuandoSombraEhNula(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());
        $item = (new PessoaEmail())->setTenant(new Tenant())->setEmail('da-lista@example.com')->setAtual(true);
        $pessoa->adicionarEmail($item);

        self::assertSame('da-lista@example.com', $pessoa->getEmail());
    }

    /**
     * SPEC §5.4 + achado da revisão (N+1): quando a sombra e a lista divergem (ex.: lista ainda
     * não sincronizada por algum caminho fora dos UseCases oficiais), getEmail() PREFERE a
     * sombra — e prova, com uma coleção espiã, que nem chega a iterar a lista para isso.
     */
    #[Test]
    public function getEmailPrefereSombraSemIterarAListaQuandoAmbasExistem(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());
        $itemDaLista = (new PessoaEmail())->setTenant(new Tenant())->setEmail('da-lista@example.com')->setAtual(true);
        $colecaoEspia = new ColecaoEspiaSemIteracao([$itemDaLista]);
        (new \ReflectionProperty(Pessoa::class, 'emails'))->setValue($pessoa, $colecaoEspia);
        (new \ReflectionProperty(Pessoa::class, 'email'))->setValue($pessoa, 'sombra@example.com');

        self::assertSame('sombra@example.com', $pessoa->getEmail());
        self::assertSame(0, $colecaoEspia->iteracoes, 'getEmail() não deve iterar a lista quando a sombra existe');
    }

    /** Espelho de getEmailDerivaDaListaQuandoSombraEhNula() para telefone. */
    #[Test]
    public function getTelefoneDerivaDaListaQuandoSombraEhNula(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());
        $item = (new PessoaTelefone())->setTenant(new Tenant())->setNumero('(41) 97777-2222')->setAtual(true);
        $pessoa->adicionarTelefone($item);

        self::assertSame('(41) 97777-2222', $pessoa->getTelefone());
    }

    /** Espelho de getEmailPrefereSombraSemIterarAListaQuandoAmbasExistem() para telefone. */
    #[Test]
    public function getTelefonePrefereSombraSemIterarAListaQuandoAmbasExistem(): void
    {
        $pessoa = (new Pessoa())->setTenant(new Tenant());
        $itemDaLista = (new PessoaTelefone())->setTenant(new Tenant())->setNumero('(41) 96666-3333')->setAtual(true);
        $colecaoEspia = new ColecaoEspiaSemIteracao([$itemDaLista]);
        (new \ReflectionProperty(Pessoa::class, 'telefones'))->setValue($pessoa, $colecaoEspia);
        (new \ReflectionProperty(Pessoa::class, 'telefone'))->setValue($pessoa, '(41) 90000-0000');

        self::assertSame('(41) 90000-0000', $pessoa->getTelefone());
        self::assertSame(0, $colecaoEspia->iteracoes, 'getTelefone() não deve iterar a lista quando a sombra existe');
    }
}

/**
 * Coleção espiã: conta quantas vezes foi iterada (getIterator(), acionado por `foreach`), sem
 * mudar o comportamento de ArrayCollection. Usada só para PROVAR que getEmail()/getTelefone() não
 * tocam a coleção quando a sombra já resolve a leitura (achado de N+1 da revisão da branch).
 */
final class ColecaoEspiaSemIteracao extends ArrayCollection
{
    public int $iteracoes = 0;

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        ++$this->iteracoes;

        return parent::getIterator();
    }
}
