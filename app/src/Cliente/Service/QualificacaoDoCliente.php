<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Twig\DocumentoBrExtension;

/**
 * Texto de qualificação jurídica do cliente, pronto para colar numa peça
 * ("FULANO, casado(a), engenheiro, nascido(a) em ..., portador(a) do RG nº ...").
 *
 * Origem: `qualificacao()` do desenho aprovado ("02 - EXPEDIENTES 1.2.3.dc.html",
 * L.3256-3271), montado SÓ com o que o cadastro tem. Campo vazio simplesmente não
 * entra no texto — nada de "nº ___" nem valor padrão.
 *
 * Diferenças em relação ao desenho, todas por falta de dado:
 *  - sem SEXO no cadastro → a concordância fica neutra, "o(a)", que é exatamente o
 *    ramo `x` do desenho quando o sexo não é conhecido;
 *  - sem NACIONALIDADE → omitida (o desenho só a preenche quando há valor);
 *  - sem NÚMERO e BAIRRO separados → o "Endereço" entra inteiro, como foi gravado;
 *  - sem WHATSAPP → os dois telefones que existem (celular e fixo) entram como
 *    "telefone".
 *
 * Pessoa jurídica não está no desenho; o texto de PJ usa só campos reais
 * (razão social, CNPJ, endereço, representante e cargo) sem afirmar natureza
 * jurídica — condomínio, por exemplo, não é "pessoa jurídica de direito privado".
 */
final class QualificacaoDoCliente
{
    /** Valores gravados pelo ClientePFType → forma que entra no texto (neutra). */
    private const ESTADO_CIVIL = [
        'SOLTEIRO'      => 'solteiro(a)',
        'CASADO'        => 'casado(a)',
        'DIVORCIADO'    => 'divorciado(a)',
        'VIUVO'         => 'viúvo(a)',
        'UNIAO_ESTAVEL' => 'união estável',
    ];

    private readonly DocumentoBrExtension $documento;

    public function __construct()
    {
        $this->documento = new DocumentoBrExtension();
    }

    public function de(Cliente $cliente): string
    {
        $partes = $cliente instanceof ClientePJ
            ? $this->partesPJ($cliente)
            : $this->partesPF($cliente);

        $endereco = $this->endereco($cliente);
        if ($endereco !== '') {
            $partes[] = ($cliente instanceof ClientePJ ? 'com sede ' : 'residente e domiciliado(a) ') . $endereco;
        }

        $contatos = $this->contatos($cliente);
        if ($contatos !== '') {
            $partes[] = $contatos;
        }

        // Representante por último: depois da sede e dos contatos da EMPRESA —
        // antes deles, o e-mail pareceria ser do representante.
        if ($cliente instanceof ClientePJ) {
            $partes[] = $this->representante($cliente);
        }

        $partes = array_values(array_filter($partes, static fn (string $p): bool => $p !== ''));

        return $partes === [] ? '' : implode(', ', $partes) . '.';
    }

    /** @return list<string> */
    private function partesPF(Cliente $cliente): array
    {
        if (!$cliente instanceof ClientePF) {
            return [self::nome($cliente->getNomeExibicao())];
        }

        $partes = [self::nome($cliente->getNomeCompleto())];

        $civil = self::texto($cliente->getEstadoCivil());
        if ($civil !== '') {
            $partes[] = self::ESTADO_CIVIL[mb_strtoupper($civil)] ?? mb_strtolower($civil);
        }

        $profissao = self::texto($cliente->getProfissao());
        if ($profissao !== '') {
            $partes[] = mb_strtolower($profissao);
        }

        $nascimento = $cliente->getDataNascimento();
        if ($nascimento !== null) {
            $partes[] = 'nascido(a) em ' . $nascimento->format('d/m/Y');
        }

        $rg = self::texto($cliente->getRg());
        if ($rg !== '') {
            $partes[] = 'portador(a) do RG nº ' . $rg;
        }

        $cpf = self::texto($cliente->getCpf());
        if ($cpf !== '') {
            $partes[] = 'inscrito(a) no CPF nº ' . $this->documento->documentoBr($cpf);
        }

        return $partes;
    }

    /** @return list<string> */
    private function partesPJ(ClientePJ $cliente): array
    {
        $partes = [self::nome($cliente->getRazaoSocial())];

        $cnpj = self::texto($cliente->getCnpj());
        if ($cnpj !== '') {
            $partes[] = 'inscrita no CNPJ nº ' . $this->documento->documentoBr($cnpj);
        }

        return $partes;
    }

    /** "neste ato representada por FULANO, síndico, inscrito(a) no CPF nº ..." ou '' sem representante. */
    private function representante(ClientePJ $cliente): string
    {
        $representante = self::texto($cliente->getRepresentanteLegal());
        if ($representante === '') {
            return '';
        }

        $trecho = 'neste ato representada por ' . self::nome($representante);
        $cargo  = self::texto($cliente->getRepresentanteCargo());
        if ($cargo !== '') {
            $trecho .= ', ' . mb_strtolower($cargo);
        }
        $cpf = self::texto($cliente->getRepresentanteCpf());
        if ($cpf !== '') {
            $trecho .= ', inscrito(a) no CPF nº ' . $this->documento->documentoBr($cpf);
        }

        return $trecho;
    }

    /** "na Rua X, 10, Apto 2, Brasília/DF, CEP 70000-000" — só os pedaços que existem. */
    private function endereco(Cliente $cliente): string
    {
        $pedacos = [];

        $logradouro = self::texto($cliente->getEndereco());
        if ($logradouro !== '') {
            $complemento = self::texto($cliente->getComplemento());
            $pedacos[]   = 'na ' . $logradouro . ($complemento !== '' ? ', ' . $complemento : '');
        }

        $cidade = self::texto($cliente->getCidade());
        $uf     = mb_strtoupper(self::texto($cliente->getEstado()));
        if ($cidade !== '') {
            $pedacos[] = $cidade . ($uf !== '' ? '/' . $uf : '');
        }

        $cep = self::texto($cliente->getCep());
        if ($cep !== '') {
            $pedacos[] = 'CEP ' . $cep;
        }

        return implode(', ', $pedacos);
    }

    private function contatos(Cliente $cliente): string
    {
        $pedacos = [];

        $celular = self::texto($cliente->getTelefoneCelular());
        $fixo    = self::texto($cliente->getTelefoneFixo());
        if ($celular !== '') {
            $pedacos[] = 'telefone ' . $celular;
        }
        if ($fixo !== '' && $fixo !== $celular) {
            $pedacos[] = 'telefone ' . $fixo;
        }

        $email = self::texto($cliente->getEmail());
        if ($email !== '') {
            $pedacos[] = 'e-mail ' . $email;
        }

        return implode(', ', $pedacos);
    }

    /** Nome em caixa alta e com espaços normalizados, como o desenho. */
    private static function nome(string $nome): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($nome)) ?? trim($nome));
    }

    private static function texto(?string $valor): string
    {
        return trim((string) $valor);
    }
}
