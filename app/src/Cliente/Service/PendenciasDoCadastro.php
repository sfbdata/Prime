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
 * Origem: regra `cliPend` do desenho aprovado ("02 - EXPEDIENTES 1.2.3.dc.html",
 * L.3249-3255, lista `CLI_REQ` em L.3172), ADAPTADA aos campos que existem aqui. O
 * desenho foi feito sobre um cadastro imaginário; onde ele pede um campo que o
 * sistema não tem, a pendência foi OMITIDA — cobrar o que não há onde preencher
 * deixaria todo cliente "incompleto" para sempre.
 *
 *   Desenho                 Aqui
 *   ──────────────────────  ─────────────────────────────────────────────
 *   Nome completo           ClientePF::nomeCompleto · ClientePJ::razaoSocial
 *   Estado civil            ClientePF::estadoCivil (só PF)
 *   Profissão               ClientePF::profissao (só PF)
 *   RG                      ClientePF::rg (só PF)
 *   CPF                     ClientePF::cpf · ClientePJ::cnpj
 *   Logradouro              Cliente::endereco ("Endereço" — campo único)
 *   Número                  OMITIDO: não existe campo próprio, vai junto do endereço
 *   Bairro                  OMITIDO: não existe campo próprio
 *   Cidade · UF · CEP       Cliente::cidade · Cliente::estado · Cliente::cep
 *   contato (tel/whats/e-mail) Cliente::telefoneCelular · telefoneFixo · email
 *   doc. de identificação   ClienteDocumento categoria IDENTIFICACAO
 *   comprov. de residência  ClienteDocumento categoria COMPROVANTE_RESIDENCIA
 *
 * Pessoa jurídica: o desenho só modela pessoa física. Para PJ vale a parte comum
 * (nome, documento, endereço, contato, anexos); estado civil, profissão e RG não
 * se aplicam, e nada foi inventado no lugar deles (representante legal, por
 * exemplo, não está no desenho).
 *
 * Serviço PURO: lê a entidade e devolve texto. Não consulta banco — os anexos vêm
 * da coleção `documentos` do próprio cliente.
 */
final class PendenciasDoCadastro
{
    /**
     * As pendências, na ordem do desenho, já no texto que a tela mostra
     * ("RG não informado", "Nenhum contato informado"...). Lista vazia = completo.
     *
     * @return list<string>
     */
    public function de(Cliente $cliente): array
    {
        $pendencias = [];

        foreach ($this->camposObrigatorios($cliente) as [$rotulo, $valor]) {
            if (self::vazio($valor)) {
                $pendencias[] = $rotulo . ' não ' . self::informado($rotulo);
            }
        }

        if (self::vazio($cliente->getTelefoneCelular())
            && self::vazio($cliente->getTelefoneFixo())
            && self::vazio($cliente->getEmail())) {
            $pendencias[] = 'Nenhum contato informado';
        }

        if (!$this->temAnexo($cliente, ClienteDocumento::CATEGORIA_IDENTIFICACAO)) {
            $pendencias[] = 'Documento de identificação não anexado';
        }

        if (!$this->temAnexo($cliente, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA)) {
            $pendencias[] = 'Comprovante de residência não anexado';
        }

        return $pendencias;
    }

    public function estaCompleto(Cliente $cliente): bool
    {
        return $this->de($cliente) === [];
    }

    /**
     * @return list<array{0: string, 1: ?string}> [rótulo, valor] na ordem do desenho
     */
    private function camposObrigatorios(Cliente $cliente): array
    {
        $endereco = [
            ['Endereço', $cliente->getEndereco()],
            ['Cidade', $cliente->getCidade()],
            ['UF', $cliente->getEstado()],
            ['CEP', $cliente->getCep()],
        ];

        if ($cliente instanceof ClientePF) {
            return [
                ['Nome completo', $cliente->getNomeCompleto()],
                ['Estado civil', $cliente->getEstadoCivil()],
                ['Profissão', $cliente->getProfissao()],
                ['RG', $cliente->getRg()],
                ['CPF', $cliente->getCpf()],
                ...$endereco,
            ];
        }

        if ($cliente instanceof ClientePJ) {
            return [
                ['Razão social', $cliente->getRazaoSocial()],
                ['CNPJ', $cliente->getCnpj()],
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

    /**
     * Concordância do desenho: "Profissão não informada", "RG não informado".
     * Masculinos: os rótulos que o desenho trata com "o" e os dois que só existem
     * aqui (Endereço). O resto é feminino.
     */
    private static function informado(string $rotulo): string
    {
        return \in_array($rotulo, ['Nome completo', 'Estado civil', 'RG', 'CPF', 'CNPJ', 'Endereço', 'CEP'], true)
            ? 'informado'
            : 'informada';
    }

    private static function vazio(?string $valor): bool
    {
        return $valor === null || trim($valor) === '';
    }
}
