<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Exception\CadastroInvalidoException;
use App\Cliente\Exception\DocumentoJaCadastradoException;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\DigitoVerificador;

/**
 * Regras dos campos da janela de Cadastro (desenho 1.2.3; E12f), as mesmas para editar e para criar ("Novo cliente",
 * E12i): limites, e-mail, nome e CPF/CNPJ obrigatórios e válidos, CPF/CNPJ de outro cadastro do escritório, CEP e
 * e-mail que não viram "alteração" por formato. Só o campo enviado muda. Não grava: quem chama dá o flush.
 */
final class CamposDoCadastro
{
    /**
     * Chave do formulário => rótulo na lista de alterados do cartão. Público porque o Histórico do cadastro só aceita
     * estes rótulos (nunca valores; {@see HistoricoDoCliente}).
     */
    public const ROTULOS = [
        'nome' => 'nome', 'cpf' => 'CPF', 'rg' => 'RG', 'rg_orgao' => 'órgão emissor', 'nascimento' => 'nascimento',
        'estado_civil' => 'estado civil', 'profissao' => 'profissão', 'nacionalidade' => 'nacionalidade', 'genero' => 'gênero',
        'razao_social' => 'razão social', 'cnpj' => 'CNPJ', 'nome_fantasia' => 'nome fantasia',
        'inscricao_estadual' => 'inscrição estadual', 'inscricao_municipal' => 'inscrição municipal',
        'representante_legal' => 'representante legal', 'representante_cpf' => 'CPF do representante',
        'representante_rg' => 'RG do representante', 'representante_cargo' => 'cargo do representante',
        'cep' => 'CEP', 'logradouro' => 'logradouro', 'numero' => 'número', 'complemento' => 'complemento',
        'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF', 'telefone' => 'telefone', 'whatsapp' => 'WhatsApp',
        'email' => 'e-mail',
    ];

    /** Tamanho das colunas: passar disso viraria erro do banco (500) em vez de mensagem. */
    private const LIMITES = [
        'nome' => 150, 'cpf' => 14, 'rg' => 20, 'rg_orgao' => 100, 'profissao' => 100, 'nacionalidade' => 50,
        'razao_social' => 255, 'cnpj' => 18, 'nome_fantasia' => 255, 'inscricao_estadual' => 20, 'inscricao_municipal' => 20,
        'representante_legal' => 255, 'representante_cpf' => 14, 'representante_rg' => 20, 'representante_cargo' => 100,
        'cep' => 9, 'logradouro' => 255, 'numero' => 20, 'complemento' => 100, 'bairro' => 100, 'cidade' => 100,
        'telefone' => 20, 'whatsapp' => 20, 'email' => 255,
    ];

    private const ABA = [
        'cep' => 'endereco', 'logradouro' => 'endereco', 'numero' => 'endereco', 'complemento' => 'endereco', 'bairro' => 'endereco',
        'cidade' => 'endereco', 'telefone' => 'contato', 'whatsapp' => 'contato', 'email' => 'contato',
    ];

    public const ESTADOS_CIVIS = ['SOLTEIRO', 'CASADO', 'DIVORCIADO', 'SEPARADO', 'VIUVO', 'UNIAO_ESTAVEL'];

    public function __construct(
        private readonly ClientePFRepository $pfRepository,
        private readonly ClientePJRepository $pjRepository,
    ) {
    }

    /**
     * @param array<string, string> $campos chaves de {@see self::ROTULOS}
     *
     * @return list<string> rótulos dos campos que mudaram
     */
    public function aplicar(Cliente $cliente, array $campos, Tenant $tenant): array
    {
        $campos = array_map(static fn ($v): string => trim((string) $v), array_intersect_key($campos, self::ROTULOS));
        foreach (self::LIMITES as $chave => $max) {
            if (isset($campos[$chave]) && mb_strlen($campos[$chave]) > $max) {
                throw new CadastroInvalidoException(ucfirst(self::ROTULOS[$chave]) . ' passa de ' . $max . ' caracteres.', self::ABA[$chave] ?? 'pessoais');
            }
        }
        if (isset($campos['email']) && $campos['email'] !== '' && preg_match('/^\S+@\S+\.\S+$/', $campos['email']) !== 1) {
            throw new CadastroInvalidoException('E-mail em formato inválido.', 'contato');
        }
        $antes = $this->retrato($cliente);

        if ($cliente instanceof ClientePF) {
            $this->aplicarPessoaFisica($cliente, $campos, $tenant);
        } elseif ($cliente instanceof ClientePJ) {
            $this->aplicarPessoaJuridica($cliente, $campos, $tenant);
        }
        $this->aplicarEnderecoEContato($cliente, $campos);

        $alterados = [];
        foreach ($this->retrato($cliente) as $chave => $valor) {
            if (($antes[$chave] ?? '') !== $valor) {
                $alterados[] = self::ROTULOS[$chave];
            }
        }

        return $alterados;
    }

    /** @param array<string, string> $c */
    private function aplicarPessoaFisica(ClientePF $cliente, array $c, Tenant $tenant): void
    {
        if (array_key_exists('nome', $c)) {
            if ($c['nome'] === '') {
                throw new CadastroInvalidoException('Informe o nome completo.');
            }
            if (mb_strlen($c['nome']) > 150) {
                throw new CadastroInvalidoException('O nome completo passa de 150 letras.');
            }
            $cliente->setNomeCompleto((string) preg_replace('/\s+/u', ' ', $c['nome']));
        }
        if (array_key_exists('cpf', $c)) {
            $digitos = DigitoVerificador::digitos($c['cpf']);
            if (!DigitoVerificador::cpfValido($digitos)) {
                throw new CadastroInvalidoException('Informe um CPF válido. Ele identifica o cliente no sistema.');
            }
            if ($digitos !== DigitoVerificador::digitos($cliente->getCpf())) {
                $outro = $this->pfRepository->findOneByCpfDoTenant($digitos, $tenant);
                if ($outro !== null && $outro->getId() !== $cliente->getId()) {
                    throw new DocumentoJaCadastradoException($outro);
                }
                $cliente->setCpf(self::mascaraCpf($digitos));
            }
        }
        if (array_key_exists('rg', $c)) {
            $cliente->setRg($c['rg']);
        }
        if (array_key_exists('rg_orgao', $c)) {
            $cliente->setRgOrgaoExpedidor($c['rg_orgao']);
        }
        if (array_key_exists('nascimento', $c)) {
            $data = $c['nascimento'] === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $c['nascimento']);
            if ($data === false || ($data !== null && $data->format('Y-m-d') !== $c['nascimento'])) {
                throw new CadastroInvalidoException('Data de nascimento inválida.');
            }
            $cliente->setDataNascimento($data);
        }
        if (array_key_exists('estado_civil', $c)) {
            $civil = mb_strtoupper($c['estado_civil']);
            // Valor antigo fora da lista (gravado antes) passa se veio igual: não pode travar o Salvar do resto.
            if ($civil !== '' && !in_array($civil, self::ESTADOS_CIVIS, true) && $civil !== mb_strtoupper((string) $cliente->getEstadoCivil())) {
                throw new CadastroInvalidoException('Estado civil inválido.');
            }
            $cliente->setEstadoCivil($civil !== '' ? $civil : null);
        }
        if (array_key_exists('profissao', $c)) {
            $cliente->setProfissao($c['profissao'] !== '' ? $c['profissao'] : null);
        }
        if (array_key_exists('nacionalidade', $c)) {
            $cliente->setNacionalidade($c['nacionalidade']);
        }
        if (array_key_exists('genero', $c)) {
            $cliente->setGenero($c['genero'] !== '' ? $c['genero'] : null);
        }
    }

    /** @param array<string, string> $c */
    private function aplicarPessoaJuridica(ClientePJ $cliente, array $c, Tenant $tenant): void
    {
        if (array_key_exists('razao_social', $c)) {
            if ($c['razao_social'] === '') {
                throw new CadastroInvalidoException('Informe a razão social.');
            }
            $cliente->setRazaoSocial((string) preg_replace('/\s+/u', ' ', $c['razao_social']));
        }
        if (array_key_exists('cnpj', $c)) {
            $digitos = DigitoVerificador::digitos($c['cnpj']);
            if (!DigitoVerificador::cnpjValido($digitos)) {
                throw new CadastroInvalidoException('Informe um CNPJ válido. Ele identifica o cliente no sistema.');
            }
            if ($digitos !== DigitoVerificador::digitos($cliente->getCnpj())) {
                $outro = $this->pjRepository->findOneByCnpjDoTenant($digitos, $tenant);
                if ($outro !== null && $outro->getId() !== $cliente->getId()) {
                    throw new DocumentoJaCadastradoException($outro);
                }
                $cliente->setCnpj($digitos);
            }
        }
        if (array_key_exists('representante_cpf', $c) && $c['representante_cpf'] !== ''
            && DigitoVerificador::digitos($c['representante_cpf']) !== DigitoVerificador::digitos($cliente->getRepresentanteCpf())) {
            if (!DigitoVerificador::cpfValido($c['representante_cpf'])) {
                throw new CadastroInvalidoException('CPF do representante inválido: confira os dígitos.');
            }
            $cliente->setRepresentanteCpf(self::mascaraCpf(DigitoVerificador::digitos($c['representante_cpf'])));
        } elseif (array_key_exists('representante_cpf', $c) && $c['representante_cpf'] === '') {
            $cliente->setRepresentanteCpf('');
        }
        foreach (['nome_fantasia' => 'setNomeFantasia', 'inscricao_estadual' => 'setInscricaoEstadual', 'inscricao_municipal' => 'setInscricaoMunicipal'] as $chave => $setter) {
            if (array_key_exists($chave, $c)) {
                $cliente->{$setter}($c[$chave] !== '' ? $c[$chave] : null);
            }
        }
        foreach (['representante_legal' => 'setRepresentanteLegal', 'representante_rg' => 'setRepresentanteRg', 'representante_cargo' => 'setRepresentanteCargo'] as $chave => $setter) {
            if (array_key_exists($chave, $c)) {
                $cliente->{$setter}($c[$chave]);
            }
        }
    }

    /** @param array<string, string> $c */
    private function aplicarEnderecoEContato(Cliente $cliente, array $c): void
    {
        // CEP e e-mail só são regravados se mudou o que importa (dígitos; letras sem caixa): o formato antigo
        // ("70000000", "Fulano@X.com") não vira "alteração" no cartão a cada Salvar.
        if (array_key_exists('cep', $c) && DigitoVerificador::digitos($c['cep']) !== DigitoVerificador::digitos($cliente->getCep())) {
            $d = DigitoVerificador::digitos($c['cep']);
            $cliente->setCep(strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : $c['cep']);
        }
        if (array_key_exists('logradouro', $c)) {
            $cliente->setEndereco($c['logradouro']);
        }
        if (array_key_exists('numero', $c)) {
            $cliente->setNumero($c['numero']);
        }
        if (array_key_exists('complemento', $c)) {
            $cliente->setComplemento($c['complemento'] !== '' ? $c['complemento'] : null);
        }
        if (array_key_exists('bairro', $c)) {
            $cliente->setBairro($c['bairro']);
        }
        if (array_key_exists('cidade', $c)) {
            $cliente->setCidade($c['cidade']);
        }
        if (array_key_exists('uf', $c)) {
            $uf = mb_strtoupper($c['uf']);
            if ($uf !== '' && preg_match('/^[A-Z]{2}$/', $uf) !== 1) {
                throw new CadastroInvalidoException('UF inválida.', 'endereco');
            }
            $cliente->setEstado($uf);
        }
        if (array_key_exists('telefone', $c)) {
            $cliente->setTelefoneCelular($c['telefone'] !== '' ? $c['telefone'] : null);
        }
        if (array_key_exists('whatsapp', $c)) {
            $cliente->setWhatsapp($c['whatsapp']);
        }
        if (array_key_exists('email', $c) && mb_strtolower($c['email']) !== mb_strtolower($cliente->getEmail())) {
            $cliente->setEmail(mb_strtolower($c['email']));
        }
    }

    /** @return array<string, string> o valor de cada campo, na ordem das abas (Cliente, Endereço, Contato) */
    private function retrato(Cliente $c): array
    {
        $r = [];
        if ($c instanceof ClientePF) {
            $r = [
                'nome' => $c->getNomeCompleto(), 'cpf' => DigitoVerificador::digitos($c->getCpf()), 'rg' => $c->getRg(),
                'rg_orgao' => $c->getRgOrgaoExpedidor(), 'nascimento' => (string) $c->getDataNascimento()?->format('Y-m-d'),
                'estado_civil' => (string) $c->getEstadoCivil(), 'profissao' => (string) $c->getProfissao(),
                'nacionalidade' => (string) $c->getNacionalidade(), 'genero' => (string) $c->getGenero(),
            ];
        }
        if ($c instanceof ClientePJ) {
            $r = [
                'razao_social' => $c->getRazaoSocial(), 'cnpj' => DigitoVerificador::digitos($c->getCnpj()),
                'nome_fantasia' => (string) $c->getNomeFantasia(), 'inscricao_estadual' => (string) $c->getInscricaoEstadual(),
                'inscricao_municipal' => (string) $c->getInscricaoMunicipal(), 'representante_legal' => $c->getRepresentanteLegal(),
                'representante_cpf' => DigitoVerificador::digitos($c->getRepresentanteCpf()), 'representante_rg' => $c->getRepresentanteRg(),
                'representante_cargo' => $c->getRepresentanteCargo(),
            ];
        }

        return $r + [
            'cep' => DigitoVerificador::digitos($c->getCep()), 'logradouro' => $c->getEndereco(), 'numero' => (string) $c->getNumero(),
            'complemento' => (string) $c->getComplemento(), 'bairro' => (string) $c->getBairro(), 'cidade' => $c->getCidade(),
            'uf' => $c->getEstado(), 'telefone' => (string) $c->getTelefoneCelular(), 'whatsapp' => (string) $c->getWhatsapp(),
            'email' => mb_strtolower($c->getEmail()),
        ];
    }

    private static function mascaraCpf(string $d): string
    {
        return sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9, 2));
    }
}
