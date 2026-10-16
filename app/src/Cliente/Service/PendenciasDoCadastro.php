<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;

/**
 * O que falta no cadastro de um cliente para ele servir de qualificação numa peça.
 *
 * Regra `cliPend` do desenho 1.2.3 (lista `CLI_REQ`), com os textos dele. Desde a E12f o cadastro tem número e
 * bairro, então nada do desenho fica omitido para pessoa física. Cada pendência diz a aba do Cadastro onde se
 * resolve (`pessoais`, `endereco`, `contato`, `docs`): é ela que acende o ponto laranja da aba e para onde o clique no
 * quadro "Pendências" leva.
 *
 * Pessoa jurídica (o desenho só desenha pessoa física; o dono decidiu adaptar): razão social, CNPJ, representante
 * legal e o CPF dele na aba Cliente; endereço, contato e documentos iguais.
 */
final class PendenciasDoCadastro
{
    /** @return list<string> */
    public function de(Cliente $cliente): array
    {
        return array_column($this->porAba($cliente), 'texto');
    }

    /** @return list<array{texto: string, aba: string}> */
    public function porAba(Cliente $cliente): array
    {
        $pendencias = [];
        foreach ($this->camposObrigatorios($cliente) as [$rotulo, $valor, $aba, $genero]) {
            if (self::vazio($valor)) {
                $pendencias[] = ['texto' => $rotulo . ' não ' . ($genero === 'a' ? 'informada' : 'informado'), 'aba' => $aba];
            }
        }

        if (self::vazio($cliente->getTelefoneCelular())
            && self::vazio($cliente->getTelefoneFixo())
            && self::vazio($cliente->getWhatsapp())
            && self::vazio($cliente->getEmail())) {
            $pendencias[] = ['texto' => 'Nenhum contato informado', 'aba' => 'contato'];
        }

        if (!$this->temAnexo($cliente, ClienteDocumento::CATEGORIA_IDENTIFICACAO)) {
            $pendencias[] = ['texto' => 'Documento de identificação não anexado', 'aba' => 'docs'];
        }

        if (!$this->temAnexo($cliente, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA)) {
            $pendencias[] = ['texto' => 'Comprovante de residência não anexado', 'aba' => 'docs'];
        }

        return $pendencias;
    }

    public function estaCompleto(Cliente $cliente): bool
    {
        return $this->porAba($cliente) === [];
    }

    /** @return list<array{0: string, 1: ?string, 2: string, 3: string}> rótulo, valor, aba, gênero do rótulo */
    private function camposObrigatorios(Cliente $cliente): array
    {
        $endereco = [
            ['Logradouro', $cliente->getEndereco(), 'endereco', 'o'],
            ['Número', $cliente->getNumero(), 'endereco', 'o'],
            ['Bairro', $cliente->getBairro(), 'endereco', 'o'],
            ['Cidade', $cliente->getCidade(), 'endereco', 'a'],
            ['UF', $cliente->getEstado(), 'endereco', 'a'],
            ['CEP', $cliente->getCep(), 'endereco', 'o'],
        ];

        if ($cliente instanceof ClientePF) {
            return [
                ['Nome completo', $cliente->getNomeCompleto(), 'pessoais', 'o'],
                ['Estado civil', $cliente->getEstadoCivil(), 'pessoais', 'o'],
                ['Profissão', $cliente->getProfissao(), 'pessoais', 'a'],
                ['RG', $cliente->getRg(), 'pessoais', 'o'],
                ['CPF', $cliente->getCpf(), 'pessoais', 'o'],
                ...$endereco,
            ];
        }

        if ($cliente instanceof ClientePJ) {
            return [
                ['Razão social', $cliente->getRazaoSocial(), 'pessoais', 'a'],
                ['CNPJ', $cliente->getCnpj(), 'pessoais', 'o'],
                ['Representante legal', $cliente->getRepresentanteLegal(), 'pessoais', 'o'],
                ['CPF do representante', $cliente->getRepresentanteCpf(), 'pessoais', 'o'],
                ...$endereco,
            ];
        }

        return $endereco;
    }

    private function temAnexo(Cliente $cliente, string $categoria): bool
    {
        foreach ($cliente->getDocumentos() as $documento) {
            if ($documento->getCategoria() === $categoria) {
                return true;
            }
        }

        return false;
    }

    private static function vazio(?string $valor): bool
    {
        return $valor === null || trim($valor) === '';
    }
}
