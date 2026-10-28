<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Meu Escritório: `public/js/meu-escritorio.js` lido como FOLHA (não há navegador no PHPUnit). Prova o contrato:
 * CSRF no cabeçalho, buscas de CEP e CNPJ no navegador, validações do desenho, toast, copiar e exclusão
 * conferida no servidor. Aparência e fluxo de verdade são smoke do dono.
 */
final class MeuEscritorioJsTest extends TestCase
{
    private function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/meu-escritorio.js');
    }

    #[TestDox('nada de innerHTML/outerHTML/insertAdjacentHTML/document.write: dado entra por textContent e value')]
    public function testSemInnerHtml(): void
    {
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $this->js(), $proibido);
        }
        self::assertStringContainsString('textContent', $this->js());
    }

    #[TestDox('todo POST leva o token no cabeçalho X-CSRF-Token e o multipart da logo leva também o campo _token')]
    public function testCsrf(): void
    {
        $js = $this->js();

        self::assertStringContainsString("'X-CSRF-Token': csrf", $js);
        self::assertStringContainsString("method: 'POST'", $js);
        self::assertStringContainsString("credentials: 'same-origin'", $js);
        self::assertStringContainsString("corpo.append('logo', f);", $js);
        self::assertStringContainsString("corpo.append('_token', csrf);", $js);
        self::assertSame(1, substr_count($js, 'fetch(raiz') + substr_count($js, "method: 'POST'"), 'um único ponto de envio ao servidor');
    }

    #[TestDox('as rotas vêm da marcação (data-url-*), nunca escritas no JS, e o escritório não vai no corpo')]
    public function testRotasDaMarcacao(): void
    {
        $js = $this->js();

        foreach (['urlSalvar', 'urlLogoEnviar', 'urlLogoRemover', 'urlLogo', 'urlExclusaoConferir', 'urlExclusaoFluxo'] as $chave) {
            self::assertStringContainsString('raiz.dataset.' . $chave, $js, $chave);
        }
        self::assertStringNotContainsString('/tenant/', $js);
        self::assertStringNotContainsString('tenantId', $js);
        self::assertStringNotContainsString('tenant_id', $js);
    }

    #[TestDox('CEP (8 dígitos) busca no viacep e CNPJ completo e válido busca na brasilapi, no navegador')]
    public function testBuscasNoNavegador(): void
    {
        $js = $this->js();

        self::assertStringContainsString("fetch('https://viacep.com.br/ws/' + digitos + '/json/')", $js);
        self::assertStringContainsString("fetch('https://brasilapi.com.br/api/cnpj/v1/' + digitos)", $js);
        self::assertStringContainsString("i.dataset.meCampo === 'cep' && n.length === 8", $js);
        self::assertStringContainsString("i.dataset.meCampo === 'doc' && n.length === 14", $js);
        self::assertStringContainsString('if (!cnpjOk(n))', $js);
        // CNPJ preenche só o que está vazio (e o nome só se vazio ou intocado), como o desenho.
        self::assertStringContainsString('!cep.value', $js);
        self::assertStringContainsString('!end.value', $js);
        self::assertStringContainsString('!cid.value', $js);
        self::assertStringContainsString("T('cep_preenchido')", $js);
        self::assertStringContainsString("T('receita_preenchida')", $js);
        self::assertStringContainsString("T('erro_cep')", $js);
    }

    #[TestDox('validação do desenho antes de salvar: nome, tamanho do documento, dígito do CNPJ e e-mail')]
    public function testValidacoes(): void
    {
        $js = $this->js();

        foreach (['erro_nome', 'erro_doc_tamanho', 'erro_cnpj', 'erro_email'] as $chave) {
            self::assertStringContainsString("T('" . $chave . "')", $js, $chave);
        }
        self::assertStringContainsString('doc.length !== 11 && doc.length !== 14', $js);
        self::assertStringContainsString('/^\S+@\S+\.\S+$/', $js);
        self::assertStringContainsString('/^(\d)\1+$/', $js);
        self::assertStringContainsString('JSON.stringify', $js);
    }

    #[TestDox('toast do desenho: 3,2 s, fixo, e os avisos "<rótulo> copiado.", salvo e logo atualizada')]
    public function testToast(): void
    {
        $js = $this->js();

        self::assertStringContainsString('3200', $js);
        self::assertStringContainsString("T('copiado', { rotulo: b.dataset.meCopiar })", $js);
        self::assertStringContainsString("avisar(T('salvo'))", $js);
        self::assertStringContainsString("avisar(T('logo_atualizada'))", $js);
        self::assertStringContainsString('navigator.clipboard', $js);
    }

    #[TestDox('exclusão: botão só habilita com senha e nome conferindo; o servidor confere e só então abre o fluxo existente')]
    public function testExclusao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("exBotao.disabled = !(exSenha.value.length && exConf.value.trim() === (raiz.dataset.nome || ''));", $js);
        self::assertStringContainsString('{ json: { senha: exSenha.value, nome: exConf.value.trim() } }', $js);
        self::assertStringContainsString('window.location.assign(raiz.dataset.urlExclusaoFluxo);', $js);
        // A senha não fica na tela depois do envio.
        self::assertStringContainsString("exSenha.value = '';", $js);
        // O fluxo só abre quando o servidor respondeu ok === true.
        self::assertMatchesRegularExpression('/r\.dados\.ok !== true\)\s*\{[^}]*return;/s', $js);
    }

    #[TestDox('cancelar restaura os valores originais; salvar com sucesso reaplica o escritório devolvido')]
    public function testEdicao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("i.value = i.dataset.original || '';", $js);
        self::assertStringContainsString('aplicaEscritorio(r.dados.escritorio);', $js);
        self::assertStringContainsString("T('nao_informado')", $js);
        self::assertStringContainsString("T('sem_alteracoes')", $js);
    }

    #[TestDox('sem travessão e com chaves do catálogo do JS')]
    public function testTextoLimpo(): void
    {
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $this->js());
        self::assertStringContainsString("'tenant.meu_escritorio.js.' + chave", $this->js());
    }
}
