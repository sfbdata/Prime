<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Repository\ClientePFRepository;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\EstadoCivil;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Traduz o RESPONSÁVEL PRINCIPAL de um caso de cobrança (a pessoa cobrada atual) no cliente cadastrado
 * que a pasta judicial recebe como principal — reusando o que já existe no escritório antes de criar.
 * Ver `docs/specs/cobranca-judicializar-cria-pasta.md` §3.
 *
 * Devolve `null` — e isso NÃO é falha — quando não há o que cadastrar. São quatro portas, todas
 * medidas em 2026-08-27 sobre o dado real:
 *
 * 1. sem responsável no caso;
 * 2. sem CPF (202 dos 248 casos hoje): sem identidade não há cadastro, e a pasta nasce só com nome e
 *    ação. Pessoa só com CNPJ também sai por aqui — ver o parágrafo do PJ abaixo;
 * 3. CPF que não tem 11 dígitos: seria inventar um documento;
 * 4. nome com mais de 50 caracteres, que é o tamanho de `cliente_pf.nome_completo` contra os 255 de
 *    `cobranca_pessoa.nome`. Truncar o nome de uma parte processual é inventar dado — o maior hoje
 *    tem 38 caracteres, então isto não dispara, mas se disparar a pasta nasce sem o cadastro em vez
 *    de nascer com meio nome.
 *
 * 🔴 **RG e órgão expedidor entram em BRANCO, de propósito.** As duas colunas são `NOT NULL` e
 * NENHUMA das 260 pessoas cobradas tem RG — exigir derrubaria o cadastro em 100% dos casos. É decisão
 * do dono (spec §3), com a consequência aceita: a tela de edição de cliente vai exigir os dois no
 * primeiro save. Não "consertar" isto apagando o cadastro nem afrouxando a validação da tela.
 *
 * **Só PF.** `ClientePJ` exige razão social, endereço de sede e QUATRO campos do representante legal,
 * todos `NOT NULL` e nenhum deles existe na ficha da cobrança — seriam cinco campos em branco em vez
 * de dois. Como as pessoas cobradas com CNPJ são ZERO hoje, o caminho PJ não existe aqui.
 */
final class ResolvedorClienteDoResponsavel
{
    /** Tamanho de `cliente_pf.nome_completo`. */
    private const MAX_NOME = 50;

    public function __construct(
        private readonly ClientePFRepository $clientePFRepository,
    ) {
    }

    public function resolver(?Pessoa $responsavel, Tenant $tenant, User $usuario): ?ClientePF
    {
        if ($responsavel === null) {
            return null;
        }

        $digitos = NormalizadorDocumento::apenasDigitos($responsavel->getCpf());
        if ($digitos === null || \strlen($digitos) !== 11) {
            return null;
        }

        $nome = trim($responsavel->getNome());
        if ($nome === '' || mb_strlen($nome) > self::MAX_NOME) {
            return null;
        }

        // Reusar antes de criar: o cadastro à mão da pasta RECUSA CPF repetido, mas aqui recusar
        // quebraria a judicialização por um motivo que não é do gestor (spec §3.2).
        $existente = $this->clientePFRepository->findOneByCpfDoTenant($digitos, $tenant);
        if ($existente !== null) {
            return $existente;
        }

        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setCriadoPor($usuario);
        $cliente->setNomeCompleto($nome);
        // FORMATADO: `cliente_pf.cpf` é varchar(14), o tamanho exato da máscara, e é assim que a tela
        // de cadastro grava. A cobrança guarda 11 dígitos — a conversão é aqui.
        $cliente->setCpf($this->formatarCpf($digitos));
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');

        $cliente->setEmail((string) $responsavel->getEmail());

        $celular = $responsavel->getTelefone();
        if ($celular !== null && trim($celular) !== '') {
            $cliente->setTelefoneCelular($celular);
        }

        $this->preencherEndereco($cliente, $responsavel);

        $cliente->setDataNascimento($responsavel->getDataNascimento());
        $cliente->setEstadoCivil($this->estadoCivilQueOFormularioAceita($responsavel->getEstadoCivil()));
        $cliente->setProfissao($responsavel->getProfissao());

        $this->clientePFRepository->save($cliente);

        return $cliente;
    }

    /**
     * `cliente.cep/endereco/cidade/estado` são `NOT NULL`: sem endereço atual na ficha eles vão em
     * branco, pelo mesmo motivo do RG. O endereço da cobrança é granular (logradouro, número, bairro)
     * e o do cliente é uma linha só — a junção é de exibição, não perde dado nenhum.
     */
    private function preencherEndereco(ClientePF $cliente, Pessoa $responsavel): void
    {
        $atual = null;
        foreach ($responsavel->getEnderecos() as $endereco) {
            if ($endereco->isAtual()) {
                $atual = $endereco;
                break;
            }
        }

        if ($atual === null) {
            $cliente->setCep('');
            $cliente->setEndereco('');
            $cliente->setCidade('');
            $cliente->setEstado('');

            return;
        }

        $logradouro = trim($atual->getLogradouro() . ', ' . $atual->getNumero());
        $bairro = trim($atual->getBairro());
        if ($bairro !== '') {
            $logradouro .= ' - ' . $bairro;
        }

        $cliente->setCep(mb_substr($atual->getCep(), 0, 10));
        $cliente->setEndereco(mb_substr($logradouro, 0, 255));
        $cliente->setCidade(mb_substr($atual->getCidade(), 0, 100));
        $cliente->setEstado(mb_substr($atual->getUf(), 0, 2));

        $complemento = $atual->getComplemento();
        if ($complemento !== null && trim($complemento) !== '') {
            $cliente->setComplemento(mb_substr($complemento, 0, 100));
        }
    }

    /**
     * O estado civil do cliente é texto livre no banco, mas o formulário de edição é um `ChoiceType`
     * com cinco opções — e a cobrança tem SEIS (`Separado` não está lá). Gravar um valor fora da lista
     * criaria um cadastro que a própria tela de edição recusa a abrir, então o que não casa fica nulo:
     * campo vazio o gestor preenche, campo inválido ele não consegue nem ver.
     */
    private function estadoCivilQueOFormularioAceita(?EstadoCivil $estadoCivil): ?string
    {
        if ($estadoCivil === null) {
            return null;
        }

        $aceitos = ['SOLTEIRO', 'CASADO', 'DIVORCIADO', 'VIUVO', 'UNIAO_ESTAVEL'];
        $valor = mb_strtoupper($estadoCivil->value);

        return \in_array($valor, $aceitos, true) ? $valor : null;
    }

    private function formatarCpf(string $digitos): string
    {
        return sprintf(
            '%s.%s.%s-%s',
            substr($digitos, 0, 3),
            substr($digitos, 3, 3),
            substr($digitos, 6, 3),
            substr($digitos, 9, 2),
        );
    }
}
